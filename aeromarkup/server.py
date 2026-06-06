#!/usr/bin/env python3
"""
AeroMarkup — Flask backend
==========================
Serves the offline-first PWA (static/) and a REST + sync API backed by
PostgreSQL. Designed to run identically on:

  * Render          (web service + managed Postgres)
  * AWS GovCloud     (ECS/Fargate or EKS + RDS for PostgreSQL)
  * Azure Government (Container Apps / AKS + Azure DB for PostgreSQL)

The frontend works fully offline (IndexedDB + service worker). When a
DATABASE_URL is configured and the device is online, the client pushes
changes to /api/sync and pulls peers' changes back — so the same app
works with OR without internet.

Env:
  DATABASE_URL   postgres://user:pass@host:5432/dbname   (optional)
  PORT           listen port (Render sets this; default 8080)
  AUTO_MIGRATE   "1" to apply db/schema.sql on boot (default 1)
"""

import os
import json
import pathlib
from contextlib import contextmanager

from flask import Flask, request, jsonify, send_from_directory, Response

# psycopg is optional so the static app still boots with no DB configured.
try:
    import psycopg
    from psycopg.rows import dict_row
    HAVE_PG = True
except Exception:  # pragma: no cover
    HAVE_PG = False

BASE_DIR   = pathlib.Path(__file__).parent
STATIC_DIR = BASE_DIR / "static"
SCHEMA_SQL = BASE_DIR / "db" / "schema.sql"

DATABASE_URL = os.environ.get("DATABASE_URL", "").strip()
AUTO_MIGRATE = os.environ.get("AUTO_MIGRATE", "1") == "1"

app = Flask(__name__, static_folder=None)


# ── Database helpers ─────────────────────────────────────────────────
def db_enabled() -> bool:
    return HAVE_PG and bool(DATABASE_URL)


@contextmanager
def get_conn():
    """Yield a Postgres connection with dict rows, committing on success.

    search_path is pinned to the dedicated `aeromarkup` schema (then
    `public` for shared extensions like pgcrypto) so this app is safe to
    run inside a database shared with other applications.
    """
    conn = psycopg.connect(
        DATABASE_URL,
        row_factory=dict_row,
        options="-c search_path=aeromarkup,public",
    )
    try:
        yield conn
        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


def migrate():
    """Apply schema.sql once at startup (idempotent)."""
    if not db_enabled() or not SCHEMA_SQL.exists():
        return
    sql = SCHEMA_SQL.read_text()
    with get_conn() as conn:
        conn.execute(sql)
    app.logger.info("AeroMarkup: schema applied.")


def require_db():
    if not db_enabled():
        return jsonify({
            "error": "no_database",
            "detail": "DATABASE_URL not configured; running in offline-only mode."
        }), 503
    return None


# ── Static PWA ───────────────────────────────────────────────────────
@app.get("/")
def index():
    return send_from_directory(STATIC_DIR, "index.html")


@app.get("/<path:path>")
def static_files(path):
    target = STATIC_DIR / path
    if target.is_file():
        return send_from_directory(STATIC_DIR, path)
    # SPA fallback
    return send_from_directory(STATIC_DIR, "index.html")


# ── Health / readiness ───────────────────────────────────────────────
@app.get("/api/health")
def health():
    ok_db = False
    if db_enabled():
        try:
            with get_conn() as conn:
                conn.execute("SELECT 1")
            ok_db = True
        except Exception as e:  # pragma: no cover
            app.logger.warning("DB health failed: %s", e)
    return jsonify({
        "status": "ok",
        "database": "connected" if ok_db else ("configured" if db_enabled() else "offline"),
        "mode": "online" if db_enabled() else "offline-only",
    })


# ── Projects ─────────────────────────────────────────────────────────
@app.get("/api/projects")
def list_projects():
    if (r := require_db()):
        return r
    with get_conn() as conn:
        rows = conn.execute(
            "SELECT * FROM projects ORDER BY updated_at DESC"
        ).fetchall()
    return jsonify(rows)


@app.post("/api/projects")
def create_project():
    if (r := require_db()):
        return r
    d = request.get_json(force=True) or {}
    with get_conn() as conn:
        row = conn.execute(
            """INSERT INTO projects (name, description, category, tail_number, part_number)
               VALUES (%(name)s, %(description)s,
                       COALESCE(%(category)s,'aerospace'),
                       %(tail_number)s, %(part_number)s)
               RETURNING *""",
            {
                "name": d.get("name", "Untitled Project"),
                "description": d.get("description"),
                "category": d.get("category"),
                "tail_number": d.get("tail_number"),
                "part_number": d.get("part_number"),
            },
        ).fetchone()
    return jsonify(row), 201


# ── Drawings ─────────────────────────────────────────────────────────
@app.get("/api/projects/<project_id>/drawings")
def list_drawings(project_id):
    if (r := require_db()):
        return r
    with get_conn() as conn:
        rows = conn.execute(
            "SELECT * FROM drawings WHERE project_id = %s ORDER BY updated_at DESC",
            (project_id,),
        ).fetchall()
    return jsonify(rows)


@app.post("/api/projects/<project_id>/drawings")
def create_drawing(project_id):
    if (r := require_db()):
        return r
    d = request.get_json(force=True) or {}
    with get_conn() as conn:
        row = conn.execute(
            """INSERT INTO drawings
                 (project_id, title, background_kind, background_data,
                  background_name, width, height, client_uid)
               VALUES (%(pid)s, %(title)s,
                       COALESCE(%(bk)s,'blank'), %(bd)s, %(bn)s,
                       COALESCE(%(w)s,1600), COALESCE(%(h)s,1200), %(cuid)s)
               ON CONFLICT (client_uid) DO UPDATE SET title = EXCLUDED.title
               RETURNING *""",
            {
                "pid": project_id,
                "title": d.get("title", "Untitled Drawing"),
                "bk": d.get("background_kind"),
                "bd": d.get("background_data"),
                "bn": d.get("background_name"),
                "w": d.get("width"),
                "h": d.get("height"),
                "cuid": d.get("client_uid"),
            },
        ).fetchone()
    return jsonify(row), 201


@app.get("/api/drawings/<drawing_id>")
def get_drawing(drawing_id):
    """Return a drawing plus all its strokes and annotations."""
    if (r := require_db()):
        return r
    with get_conn() as conn:
        drawing = conn.execute(
            "SELECT * FROM drawings WHERE id = %s", (drawing_id,)
        ).fetchone()
        if not drawing:
            return jsonify({"error": "not_found"}), 404
        strokes = conn.execute(
            "SELECT * FROM strokes WHERE drawing_id = %s ORDER BY created_at",
            (drawing_id,),
        ).fetchall()
        annotations = conn.execute(
            "SELECT * FROM annotations WHERE drawing_id = %s ORDER BY created_at",
            (drawing_id,),
        ).fetchall()
    return jsonify({"drawing": drawing, "strokes": strokes, "annotations": annotations})


# ── Sync: the heart of offline<->online reconciliation ───────────────
@app.post("/api/sync")
def sync():
    """
    Push a batch of offline changes and pull back anything newer.

    Request:  {
        "drawing": {...optional drawing upsert...},
        "strokes": [ {...}, ... ],
        "annotations": [ {...}, ... ],
        "device_id": "uuid",
        "since": <last sync_log seq this device has seen>
    }
    Response: { "ok": true, "cursor": <max seq>,
                "changes": [ {seq, entity, op, payload}, ... ] }
    """
    if (r := require_db()):
        return r
    d = request.get_json(force=True) or {}
    device = d.get("device_id", "unknown")
    since = int(d.get("since") or 0)
    drawing = d.get("drawing") or {}
    drawing_id = drawing.get("id")

    with get_conn() as conn:
        # 1) Upsert drawing (by client_uid for offline-created records)
        if drawing.get("client_uid"):
            row = conn.execute(
                """INSERT INTO drawings
                     (project_id, title, background_kind, background_data,
                      background_name, width, height, client_uid)
                   VALUES (%(pid)s, %(title)s, COALESCE(%(bk)s,'blank'),
                           %(bd)s, %(bn)s, COALESCE(%(w)s,1600),
                           COALESCE(%(h)s,1200), %(cuid)s)
                   ON CONFLICT (client_uid) DO UPDATE
                     SET title = EXCLUDED.title,
                         background_data = EXCLUDED.background_data,
                         version = drawings.version + 1
                   RETURNING id""",
                {
                    "pid": drawing.get("project_id"),
                    "title": drawing.get("title", "Untitled Drawing"),
                    "bk": drawing.get("background_kind"),
                    "bd": drawing.get("background_data"),
                    "bn": drawing.get("background_name"),
                    "w": drawing.get("width"),
                    "h": drawing.get("height"),
                    "cuid": drawing["client_uid"],
                },
            ).fetchone()
            drawing_id = row["id"]

        # 2) Insert strokes (idempotent on client_uid)
        for s in d.get("strokes", []):
            conn.execute(
                """INSERT INTO strokes
                     (drawing_id, tool, color, width, opacity, points, client_uid)
                   VALUES (%(did)s, COALESCE(%(tool)s,'pen'),
                           COALESCE(%(color)s,'#ff5811'),
                           COALESCE(%(width)s,3), COALESCE(%(opacity)s,1),
                           %(points)s::jsonb, %(cuid)s)
                   ON CONFLICT (client_uid) DO NOTHING
                   RETURNING id""",
                {
                    "did": drawing_id,
                    "tool": s.get("tool"),
                    "color": s.get("color"),
                    "width": s.get("width"),
                    "opacity": s.get("opacity"),
                    "points": json.dumps(s.get("points", [])),
                    "cuid": s.get("client_uid"),
                },
            )
            conn.execute(
                """INSERT INTO sync_log (drawing_id, entity, entity_id, op, payload, device_id)
                   SELECT %s,'stroke', id,'create', %s::jsonb, %s
                   FROM strokes WHERE client_uid = %s""",
                (drawing_id, json.dumps(s), device, s.get("client_uid")),
            )

        # 3) Insert/update annotations (idempotent on client_uid)
        for a in d.get("annotations", []):
            conn.execute(
                """INSERT INTO annotations
                     (drawing_id, kind, x, y, x2, y2, width, height, text,
                      color, stroke_w, font_size, meta, client_uid)
                   VALUES (%(did)s, COALESCE(%(kind)s,'note'),
                           %(x)s,%(y)s,%(x2)s,%(y2)s,%(w)s,%(h)s,%(text)s,
                           COALESCE(%(color)s,'#ffcc00'),
                           COALESCE(%(sw)s,2), COALESCE(%(fs)s,16),
                           COALESCE(%(meta)s,'{}')::jsonb, %(cuid)s)
                   ON CONFLICT (client_uid) DO UPDATE
                     SET text = EXCLUDED.text, x = EXCLUDED.x, y = EXCLUDED.y,
                         x2 = EXCLUDED.x2, y2 = EXCLUDED.y2,
                         color = EXCLUDED.color""",
                {
                    "did": drawing_id, "kind": a.get("kind"),
                    "x": a.get("x", 0), "y": a.get("y", 0),
                    "x2": a.get("x2"), "y2": a.get("y2"),
                    "w": a.get("width"), "h": a.get("height"),
                    "text": a.get("text"), "color": a.get("color"),
                    "sw": a.get("stroke_w"), "fs": a.get("font_size"),
                    "meta": json.dumps(a.get("meta", {})),
                    "cuid": a.get("client_uid"),
                },
            )
            conn.execute(
                """INSERT INTO sync_log (drawing_id, entity, entity_id, op, payload, device_id)
                   SELECT %s,'annotation', id,'create', %s::jsonb, %s
                   FROM annotations WHERE client_uid = %s""",
                (drawing_id, json.dumps(a), device, a.get("client_uid")),
            )

        # 4) Pull back changes from OTHER devices newer than `since`
        changes, cursor = [], since
        if drawing_id:
            changes = conn.execute(
                """SELECT seq, entity, entity_id, op, payload
                   FROM sync_log
                   WHERE drawing_id = %s AND seq > %s
                     AND (device_id IS DISTINCT FROM %s)
                   ORDER BY seq ASC LIMIT 1000""",
                (drawing_id, since, device),
            ).fetchall()
            cur = conn.execute(
                "SELECT COALESCE(MAX(seq),%s) AS c FROM sync_log WHERE drawing_id = %s",
                (since, drawing_id),
            ).fetchone()
            cursor = cur["c"]

    return jsonify({"ok": True, "drawing_id": drawing_id,
                    "cursor": cursor, "changes": changes})


# ── Boot ─────────────────────────────────────────────────────────────
if AUTO_MIGRATE:
    try:
        migrate()
    except Exception as e:  # pragma: no cover
        app.logger.warning("Auto-migrate skipped: %s", e)


if __name__ == "__main__":
    port = int(os.environ.get("PORT", 8080))
    app.run(host="0.0.0.0", port=port)
