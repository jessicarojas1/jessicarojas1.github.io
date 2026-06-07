"""Shared helpers for bulk CSV import: template generation + row parsing.

Uses only the stdlib ``csv`` module (no new dependencies). Callers supply the
list of importable columns (with an example row) for the template, and a
per-row builder/inserter for the import itself.
"""

from __future__ import annotations

import csv
import io
from collections.abc import Callable

from fastapi import Response, UploadFile

from app.schemas.common import ImportResult, ImportRowError


def template_response(filename: str, columns: list[str], example: list[str]) -> Response:
    """Build a CSV download (header row + one example row) as an attachment."""
    buffer = io.StringIO()
    writer = csv.writer(buffer)
    writer.writerow(columns)
    writer.writerow(example)
    return Response(
        content=buffer.getvalue(),
        media_type="text/csv",
        headers={"Content-Disposition": f'attachment; filename="{filename}"'},
    )


def _read_rows(file: UploadFile) -> list[dict[str, str]]:
    raw = file.file.read()
    text = raw.decode("utf-8-sig") if isinstance(raw, bytes) else raw
    reader = csv.DictReader(io.StringIO(text))
    return list(reader)


def clean(value: str | None) -> str | None:
    """Normalize a CSV cell: strip whitespace, treat empty string as ``None``."""
    if value is None:
        return None
    value = value.strip()
    return value or None


def import_rows(
    file: UploadFile,
    build_and_insert: Callable[[dict[str, str]], None],
) -> ImportResult:
    """Iterate CSV rows, calling ``build_and_insert`` per row.

    Errors on a single row are collected and the row is skipped; processing
    continues for the remaining rows. The caller is responsible for committing
    the surrounding transaction once this returns.
    """
    result = ImportResult()
    rows = _read_rows(file)
    for index, row in enumerate(rows):
        # Row number is 1-based and accounts for the header line.
        line_no = index + 2
        try:
            build_and_insert(row)
            result.created += 1
        except Exception as exc:  # noqa: BLE001 - collect per-row, keep going
            result.failed += 1
            result.errors.append(ImportRowError(row=line_no, message=str(exc)))
    return result
