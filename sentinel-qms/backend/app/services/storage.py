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
