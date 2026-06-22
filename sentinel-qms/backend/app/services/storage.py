"""Storage backend abstraction: AWS GovCloud S3, Azure Gov Blob, or local disk."""

from __future__ import annotations

import contextlib
import hashlib
import os
import uuid
from abc import ABC, abstractmethod
from dataclasses import dataclass
from pathlib import Path

from app.core.config import settings

# Allowlisted upload content types and corresponding extensions.
ALLOWED_CONTENT_TYPES: dict[str, str] = {
    "application/pdf": ".pdf",
    "image/png": ".png",
    "image/jpeg": ".jpg",
    "image/tiff": ".tif",
    "text/csv": ".csv",
    "text/plain": ".txt",
    "application/msword": ".doc",
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document": ".docx",
    "application/vnd.ms-excel": ".xls",
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet": ".xlsx",
    "application/zip": ".zip",
}

# Curated magic-byte signatures for the allowed types. Each entry maps a declared
# content type to the leading byte prefixes that legitimately identify that
# format. ``sniff_matches_declared`` uses this as the AUTHORITATIVE check so a
# spoofed client Content-Type cannot smuggle a mismatched payload past the
# allowlist. Container-based OOXML files (.docx/.xlsx) and legacy OLE docs
# (.doc/.xls) share the ZIP / OLE2 container signatures, so those are grouped.
_ZIP_SIGS = (b"PK\x03\x04", b"PK\x05\x06", b"PK\x07\x08")  # incl. empty/spanned archives
_OLE2_SIG = (b"\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1",)  # legacy MS Office (doc/xls)
_MAGIC_SIGNATURES: dict[str, tuple[bytes, ...]] = {
    "application/pdf": (b"%PDF-",),
    "image/png": (b"\x89PNG\r\n\x1a\n",),
    "image/jpeg": (b"\xff\xd8\xff",),
    "image/tiff": (b"II*\x00", b"MM\x00*"),
    "application/zip": _ZIP_SIGS,
    # OOXML files are ZIP containers.
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document": _ZIP_SIGS,
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet": _ZIP_SIGS,
    # Legacy binary Office documents are OLE2 compound files.
    "application/msword": _OLE2_SIG,
    "application/vnd.ms-excel": _OLE2_SIG,
}
# Text formats (text/plain, text/csv) have no reliable magic bytes; they are
# accepted when the leading bytes decode as UTF-8 / ASCII and contain no NUL.
_TEXT_TYPES = frozenset({"text/plain", "text/csv"})


@dataclass
class StoredObject:
    key: str
    size_bytes: int
    checksum_sha256: str
    content_type: str
    backend: str


class StorageBackend(ABC):
    backend_name: str = "abstract"

    @abstractmethod
    def save(self, data: bytes, *, content_type: str, original_filename: str) -> StoredObject: ...

    @abstractmethod
    def load(self, key: str) -> bytes: ...

    @abstractmethod
    def delete(self, key: str) -> None: ...

    @abstractmethod
    def presigned_url(self, key: str, *, expires: int = 900) -> str | None: ...

    @staticmethod
    def _randomized_key(original_filename: str, content_type: str) -> str:
        ext = ALLOWED_CONTENT_TYPES.get(content_type) or Path(original_filename).suffix.lower()
        return f"{uuid.uuid4().hex}{ext}"

    @staticmethod
    def _checksum(data: bytes) -> str:
        return hashlib.sha256(data).hexdigest()


class LocalStorage(StorageBackend):
    backend_name = "local"

    def __init__(self, base_dir: str):
        self.base = Path(base_dir)
        self.base.mkdir(parents=True, exist_ok=True)

    def _path(self, key: str) -> Path:
        # Guard against path traversal: keys are randomized basenames only.
        safe = Path(key).name
        return self.base / safe

    def save(self, data: bytes, *, content_type: str, original_filename: str) -> StoredObject:
        key = self._randomized_key(original_filename, content_type)
        path = self._path(key)
        path.write_bytes(data)
        return StoredObject(
            key=key,
            size_bytes=len(data),
            checksum_sha256=self._checksum(data),
            content_type=content_type,
            backend=self.backend_name,
        )

    def load(self, key: str) -> bytes:
        return self._path(key).read_bytes()

    def delete(self, key: str) -> None:
        p = self._path(key)
        if p.exists():
            p.unlink()

    def presigned_url(self, key: str, *, expires: int = 900) -> str | None:
        return None  # served via the download endpoint for local backend


class S3Storage(StorageBackend):
    """AWS GovCloud S3 backend. Imports boto3 lazily so the dep is optional."""

    backend_name = "s3"

    def __init__(self, bucket: str, region: str, endpoint_url: str | None = None):
        import boto3
        from botocore.config import Config

        self.bucket = bucket
        # A custom endpoint (e.g. MinIO) needs path-style addressing and does not
        # support SSE-KMS; real AWS GovCloud S3 keeps virtual-host + KMS.
        self.use_kms = not endpoint_url
        client_kwargs: dict = {"region_name": region}
        if endpoint_url:
            client_kwargs["endpoint_url"] = endpoint_url
            client_kwargs["config"] = Config(s3={"addressing_style": "path"})
        self.client = boto3.client("s3", **client_kwargs)

    def save(self, data: bytes, *, content_type: str, original_filename: str) -> StoredObject:
        key = self._randomized_key(original_filename, content_type)
        put_kwargs: dict = {
            "Bucket": self.bucket,
            "Key": key,
            "Body": data,
            "ContentType": content_type,
        }
        if self.use_kms:
            put_kwargs["ServerSideEncryption"] = "aws:kms"
        self.client.put_object(**put_kwargs)
        return StoredObject(
            key=key,
            size_bytes=len(data),
            checksum_sha256=self._checksum(data),
            content_type=content_type,
            backend=self.backend_name,
        )

    def load(self, key: str) -> bytes:
        obj = self.client.get_object(Bucket=self.bucket, Key=key)
        return obj["Body"].read()

    def delete(self, key: str) -> None:
        self.client.delete_object(Bucket=self.bucket, Key=key)

    def presigned_url(self, key: str, *, expires: int = 900) -> str | None:
        return self.client.generate_presigned_url(
            "get_object", Params={"Bucket": self.bucket, "Key": key}, ExpiresIn=expires
        )


class AzureBlobStorage(StorageBackend):
    """Azure Government Blob backend. Imports azure SDK lazily."""

    backend_name = "azure_blob"

    def __init__(self, connection_string: str, container: str):
        from azure.storage.blob import BlobServiceClient

        self.service = BlobServiceClient.from_connection_string(connection_string)
        self.container = container
        # Container may already exist.
        with contextlib.suppress(Exception):
            self.service.create_container(container)

    def _client(self, key: str):
        return self.service.get_blob_client(container=self.container, blob=key)

    def save(self, data: bytes, *, content_type: str, original_filename: str) -> StoredObject:
        from azure.storage.blob import ContentSettings

        key = self._randomized_key(original_filename, content_type)
        self._client(key).upload_blob(
            data, overwrite=True, content_settings=ContentSettings(content_type=content_type)
        )
        return StoredObject(
            key=key,
            size_bytes=len(data),
            checksum_sha256=self._checksum(data),
            content_type=content_type,
            backend=self.backend_name,
        )

    def load(self, key: str) -> bytes:
        return self._client(key).download_blob().readall()

    def delete(self, key: str) -> None:
        self._client(key).delete_blob()

    def presigned_url(self, key: str, *, expires: int = 900) -> str | None:
        return None  # SAS generation omitted; download proxied via API


_backend: StorageBackend | None = None


def get_storage() -> StorageBackend:
    """Return the configured storage backend (singleton)."""
    global _backend
    if _backend is not None:
        return _backend

    if settings.STORAGE_BACKEND == "s3" and settings.S3_BUCKET:
        _backend = S3Storage(
            settings.S3_BUCKET,
            settings.S3_REGION,
            endpoint_url=settings.S3_ENDPOINT_URL or None,
        )
    elif settings.STORAGE_BACKEND == "azure_blob" and settings.AZURE_STORAGE_CONNECTION_STRING:
        _backend = AzureBlobStorage(
            settings.AZURE_STORAGE_CONNECTION_STRING, settings.AZURE_STORAGE_CONTAINER
        )
    else:
        _backend = LocalStorage(os.path.abspath(settings.LOCAL_STORAGE_DIR))
    return _backend


def is_allowed_content_type(content_type: str) -> bool:
    return content_type in ALLOWED_CONTENT_TYPES


def _looks_like_text(head: bytes) -> bool:
    """Heuristic for plain-text/CSV: no NUL bytes and valid UTF-8 decode."""
    if b"\x00" in head:
        return False
    try:
        head.decode("utf-8")
    except UnicodeDecodeError:
        # A multibyte sequence may be split at the sampled boundary; tolerate a
        # short trailing fragment but reject obvious binary content.
        try:
            head[: max(0, len(head) - 3)].decode("utf-8")
        except UnicodeDecodeError:
            return False
    return True


def sniff_matches_declared(data: bytes, declared_type: str) -> bool:
    """Authoritatively validate file content against its declared content type.

    Reads the leading magic bytes of ``data`` and confirms they match a
    signature registered for ``declared_type``. Text types (which have no
    reliable signature) are accepted when the head decodes as text. Returns
    ``False`` on any mismatch so the caller can reject the upload — this is the
    real defense; the client-supplied Content-Type is not trusted on its own.
    """
    if declared_type in _TEXT_TYPES:
        return _looks_like_text(data[:512])
    sigs = _MAGIC_SIGNATURES.get(declared_type)
    if not sigs:
        # Allowlisted but no signature mapping → cannot verify; fail-closed.
        return False
    return any(data.startswith(sig) for sig in sigs)
