# CITADEL — Upload & Archive Security

CITADEL analyzes untrusted source code, so the upload path is treated as a
hostile-input boundary. The analyzer **only reads** uploaded files — it never
executes them — and every extraction step is bounded. All controls live in
`server/server.js` (`buildWorkdir`, `safeJoin`) and the multer config.

## Controls

| Control | Where | Default | Env override |
|---|---|---|---|
| Max single upload size | multer `limits.fileSize` | 150 MB | `MAX_UPLOAD_BYTES` |
| Max file count per upload | multer `limits.files` | 5000 | — |
| Max **decompressed** archive bytes | `buildWorkdir` | 500 MB | `CITADEL_MAX_UNZIP_BYTES` |
| Max archive entries | `buildWorkdir` | 50000 | `CITADEL_MAX_UNZIP_ENTRIES` |
| Per-scan in-memory content budget | engine | 64 MB | `CITADEL_MAX_TOTAL_BYTES` |
| External-scanner concurrency (memory cap) | `scanners.runAll` | 2 | `SCAN_CONCURRENCY` |

## Zip-slip / path traversal

Archives (`.zip/.jar/.war/.apk/.nupkg`) are expanded with `adm-zip`, and every
entry path is resolved through `safeJoin(base, entryName)`:

```js
const p = path.resolve(base, target);
if (p !== base && !p.startsWith(base + path.sep)) throw new Error('path traversal blocked');
```

An entry whose name escapes the extraction root (`../…`, absolute paths,
symlink tricks) is rejected — the archive is then kept as an opaque binary for
analysis rather than extracted, so **nothing is ever written outside the
per-scan workdir**. This is covered by an automated test
(`server/test/api.test.js` → "a zip-slip archive cannot write outside the scan
workdir").

## Decompression bombs

Two independent caps:

- **Entry count:** extraction aborts past `CITADEL_MAX_UNZIP_ENTRIES`.
- **Decompressed size:** a cheap pre-check rejects an entry whose *declared*
  size exceeds the budget, and the running total of **actual** inflated bytes is
  checked too (a lying header cannot undercount). Past the cap, the upload is
  rejected with a clean `400 Upload rejected` (also covered by an automated test).

## Isolation & cleanup

- Each scan extracts into a fresh random workdir under `CITADEL_TMP`.
- Uploaded originals are unlinked immediately after extraction.
- The workdir is removed in a `finally` block whether the scan succeeds or fails.
- The reference Docker/compose deployment mounts that scratch space as a
  non-persistent, `exec=false` tmpfs and runs read-only, cap-dropped,
  `no-new-privileges` — untrusted files never touch persistent disk and cannot
  be executed.

## Malware scanning

When ClamAV (`clamscan`) is installed, deep scans run it over the extracted
tree as one of the external scanners (its findings merge into the report). It is
optional and degrades gracefully when absent.

## What is NOT logged

Audit and application logs record only metadata (action, actor, IP, target id).
Uploaded source code, secret values, passwords, and tokens are never written to
logs; detected secrets are masked (e.g. PANs show only the last four digits).
