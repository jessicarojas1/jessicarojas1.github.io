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


def audit(conn, actor, action, entity_type, entity_id, detail=None):
    """Append an immutable activity-log row inside the caller's transaction.

    All values are bound as parameters (no string concatenation of input).
    """
    conn.execute(
        """INSERT INTO audit_log (actor, action, entity_type, entity_id, detail, source)
           VALUES (%(actor)s, %(action)s, %(etype)s, %(eid)s,
                   COALESCE(%(detail)s,'{}')::jsonb, 'api')""",
        {
            "actor": actor,
            "action": action,
            "etype": entity_type,
            "eid": entity_id,
            "detail": json.dumps(detail or {}),
        },
    )


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
            """INSERT INTO projects
                 (name, description, category, tail_number, part_number,
                  serial_number, work_order, classification, program_id)
               VALUES (%(name)s, %(description)s,
                       COALESCE(%(category)s,'aerospace'),
                       %(tail_number)s, %(part_number)s,
                       %(serial_number)s, %(work_order)s,
                       COALESCE(%(classification)s,'CUI'), %(program_id)s)
               RETURNING *""",
            {
                "name": d.get("name", "Untitled Project"),
                "description": d.get("description"),
                "category": d.get("category"),
                "tail_number": d.get("tail_number"),
                "part_number": d.get("part_number"),
                "serial_number": d.get("serial_number"),
                "work_order": d.get("work_order"),
                "classification": d.get("classification"),
                "program_id": d.get("program_id"),
            },
        ).fetchone()
        audit(conn, d.get("actor", "system"), "create", "project",
              row["id"], {"name": row["name"]})
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
                  background_name, width, height, client_uid,
                  drawing_number, revision, units, scale_ratio,
                  status, classification)
               VALUES (%(pid)s, %(title)s,
                       COALESCE(%(bk)s,'blank'), %(bd)s, %(bn)s,
                       COALESCE(%(w)s,1600), COALESCE(%(h)s,1200), %(cuid)s,
                       %(dnum)s, COALESCE(%(rev)s,'A'),
                       COALESCE(%(units)s,'in'), %(scale)s,
                       COALESCE(%(status)s,'draft'),
                       COALESCE(%(classification)s,'CUI'))
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
                "dnum": d.get("drawing_number"),
                "rev": d.get("revision"),
                "units": d.get("units"),
                "scale": d.get("scale_ratio"),
                "status": d.get("status"),
                "classification": d.get("classification"),
            },
        ).fetchone()
        audit(conn, d.get("actor", "system"), "create", "drawing",
              row["id"], {"title": row["title"]})
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


# ── Dashboard ────────────────────────────────────────────────────────
@app.get("/api/dashboard")
def dashboard():
    if (r := require_db()):
        return r
    with get_conn() as conn:
        counts = conn.execute(
            """SELECT
                 (SELECT COUNT(*) FROM projects)                            AS projects,
                 (SELECT COUNT(*) FROM drawings)                            AS drawings,
                 (SELECT COUNT(*) FROM ncrs WHERE status = 'open')          AS open_ncrs,
                 (SELECT COUNT(*) FROM ncrs WHERE severity = 'critical'
                                              AND status <> 'closed')        AS critical_ncrs,
                 (SELECT COUNT(*) FROM approvals a
                    WHERE a.action = 'submit'
                      AND NOT EXISTS (
                        SELECT 1 FROM approvals later
                        WHERE later.entity_type = a.entity_type
                          AND later.entity_id   = a.entity_id
                          AND later.action IN ('approve','reject','release')
                          AND later.created_at >= a.created_at))            AS pending_approvals
            """
        ).fetchone()
        recent = conn.execute(
            "SELECT * FROM audit_log ORDER BY seq DESC LIMIT 15"
        ).fetchall()
    return jsonify({**counts, "recent_activity": recent})


# ── Programs ─────────────────────────────────────────────────────────
@app.get("/api/programs")
def list_programs():
    if (r := require_db()):
        return r
    with get_conn() as conn:
        rows = conn.execute(
            "SELECT * FROM programs ORDER BY created_at DESC"
        ).fetchall()
    return jsonify(rows)


@app.post("/api/programs")
def create_program():
    if (r := require_db()):
        return r
    d = request.get_json(force=True) or {}
    with get_conn() as conn:
        row = conn.execute(
            """INSERT INTO programs (name, code, description, classification)
               VALUES (%(name)s, %(code)s, %(description)s,
                       COALESCE(%(classification)s,'CUI'))
               RETURNING *""",
            {
                "name": d.get("name", "Untitled Program"),
                "code": d.get("code"),
                "description": d.get("description"),
                "classification": d.get("classification"),
            },
        ).fetchone()
        audit(conn, d.get("actor", "system"), "create", "program",
              row["id"], {"name": row["name"]})
    return jsonify(row), 201


# ── NCRs ─────────────────────────────────────────────────────────────
@app.get("/api/ncrs")
def list_ncrs():
    if (r := require_db()):
        return r
    project_id = request.args.get("project_id")
    status = request.args.get("status")
    sql = "SELECT * FROM ncrs"
    where, params = [], {}
    if project_id:
        where.append("project_id = %(pid)s")
        params["pid"] = project_id
    if status:
        where.append("status = %(status)s")
        params["status"] = status
    if where:
        sql += " WHERE " + " AND ".join(where)
    sql += " ORDER BY created_at DESC"
    with get_conn() as conn:
        rows = conn.execute(sql, params).fetchall()
    return jsonify(rows)


@app.post("/api/ncrs")
def create_ncr():
    if (r := require_db()):
        return r
    d = request.get_json(force=True) or {}
    with get_conn() as conn:
        row = conn.execute(
            """INSERT INTO ncrs
                 (project_id, drawing_id, annotation_id, ncr_number, title,
                  description, severity, defect_type, status, disposition,
                  disposition_notes, raised_by, assigned_to, due_date,
                  classification, client_uid)
               VALUES (%(project_id)s, %(drawing_id)s, %(annotation_id)s,
                       %(ncr_number)s, %(title)s, %(description)s,
                       COALESCE(%(severity)s,'minor'), %(defect_type)s,
                       COALESCE(%(status)s,'open'), %(disposition)s,
                       %(disposition_notes)s, %(raised_by)s, %(assigned_to)s,
                       %(due_date)s, COALESCE(%(classification)s,'CUI'),
                       %(client_uid)s)
               ON CONFLICT (client_uid) DO UPDATE
                 SET title = EXCLUDED.title,
                     description = EXCLUDED.description,
                     severity = EXCLUDED.severity,
                     defect_type = EXCLUDED.defect_type,
                     status = EXCLUDED.status,
                     disposition = EXCLUDED.disposition,
                     disposition_notes = EXCLUDED.disposition_notes,
                     assigned_to = EXCLUDED.assigned_to,
                     due_date = EXCLUDED.due_date
               RETURNING *""",
            {
                "project_id": d.get("project_id"),
                "drawing_id": d.get("drawing_id"),
                "annotation_id": d.get("annotation_id"),
                "ncr_number": d.get("ncr_number"),
                "title": d.get("title", "Untitled NCR"),
                "description": d.get("description"),
                "severity": d.get("severity"),
                "defect_type": d.get("defect_type"),
                "status": d.get("status"),
                "disposition": d.get("disposition"),
                "disposition_notes": d.get("disposition_notes"),
                "raised_by": d.get("raised_by"),
                "assigned_to": d.get("assigned_to"),
                "due_date": d.get("due_date"),
                "classification": d.get("classification"),
                "client_uid": d.get("client_uid"),
            },
        ).fetchone()
        audit(conn, d.get("actor", "system"), "upsert", "ncr",
              row["id"], {"status": row["status"], "severity": row["severity"]})
    return jsonify(row), 201


@app.patch("/api/ncrs/<ncr_id>")
def update_ncr(ncr_id):
    if (r := require_db()):
        return r
    d = request.get_json(force=True) or {}
    with get_conn() as conn:
        row = conn.execute(
            """UPDATE ncrs SET
                 status            = COALESCE(%(status)s, status),
                 disposition       = COALESCE(%(disposition)s, disposition),
                 disposition_notes = COALESCE(%(disposition_notes)s, disposition_notes),
                 assigned_to       = COALESCE(%(assigned_to)s, assigned_to)
               WHERE id = %(id)s
               RETURNING *""",
            {
                "id": ncr_id,
                "status": d.get("status"),
                "disposition": d.get("disposition"),
                "disposition_notes": d.get("disposition_notes"),
                "assigned_to": d.get("assigned_to"),
            },
        ).fetchone()
        if not row:
            return jsonify({"error": "not_found"}), 404
        audit(conn, d.get("actor", "system"), "update", "ncr",
              row["id"], {"status": row["status"],
                          "disposition": row["disposition"]})
    return jsonify(row)


# ── Inspections ──────────────────────────────────────────────────────
@app.get("/api/inspections")
def list_inspections():
    if (r := require_db()):
        return r
    project_id = request.args.get("project_id")
    sql = "SELECT * FROM inspections"
    params = {}
    if project_id:
        sql += " WHERE project_id = %(pid)s"
        params["pid"] = project_id
    sql += " ORDER BY created_at DESC"
    with get_conn() as conn:
        rows = conn.execute(sql, params).fetchall()
    return jsonify(rows)


@app.post("/api/inspections")
def create_inspection():
    if (r := require_db()):
        return r
    d = request.get_json(force=True) or {}
    with get_conn() as conn:
        row = conn.execute(
            """INSERT INTO inspections
                 (project_id, drawing_id, type, result, inspector_id,
                  performed_at, notes, client_uid)
               VALUES (%(project_id)s, %(drawing_id)s, %(type)s,
                       COALESCE(%(result)s,'pending'), %(inspector_id)s,
                       %(performed_at)s, %(notes)s, %(client_uid)s)
               ON CONFLICT (client_uid) DO UPDATE
                 SET result = EXCLUDED.result,
                     notes = EXCLUDED.notes,
                     performed_at = EXCLUDED.performed_at
               RETURNING *""",
            {
                "project_id": d.get("project_id"),
                "drawing_id": d.get("drawing_id"),
                "type": d.get("type"),
                "result": d.get("result"),
                "inspector_id": d.get("inspector_id"),
                "performed_at": d.get("performed_at"),
                "notes": d.get("notes"),
                "client_uid": d.get("client_uid"),
            },
        ).fetchone()
        audit(conn, d.get("actor", "system"), "create", "inspection",
              row["id"], {"result": row["result"]})
    return jsonify(row), 201


@app.post("/api/inspections/<inspection_id>/items")
def add_inspection_item(inspection_id):
    if (r := require_db()):
        return r
    d = request.get_json(force=True) or {}
    with get_conn() as conn:
        row = conn.execute(
            """INSERT INTO inspection_items
                 (inspection_id, label, result, notes, sort)
               VALUES (%(iid)s, %(label)s, COALESCE(%(result)s,'na'),
                       %(notes)s, COALESCE(%(sort)s,0))
               RETURNING *""",
            {
                "iid": inspection_id,
                "label": d.get("label", "Item"),
                "result": d.get("result"),
                "notes": d.get("notes"),
                "sort": d.get("sort"),
            },
        ).fetchone()
    return jsonify(row), 201


# ── Approvals (e-signature / workflow trail) ─────────────────────────
@app.get("/api/approvals")
def list_approvals():
    if (r := require_db()):
        return r
    entity_type = request.args.get("entity_type")
    entity_id = request.args.get("entity_id")
    sql = "SELECT * FROM approvals"
    where, params = [], {}
    if entity_type:
        where.append("entity_type = %(et)s")
        params["et"] = entity_type
    if entity_id:
        where.append("entity_id = %(eid)s")
        params["eid"] = entity_id
    if where:
        sql += " WHERE " + " AND ".join(where)
    sql += " ORDER BY created_at DESC"
    with get_conn() as conn:
        rows = conn.execute(sql, params).fetchall()
    return jsonify(rows)


@app.post("/api/approvals")
def create_approval():
    if (r := require_db()):
        return r
    d = request.get_json(force=True) or {}
    entity_type = d.get("entity_type")
    entity_id = d.get("entity_id")
    action = d.get("action")
    with get_conn() as conn:
        row = conn.execute(
            """INSERT INTO approvals
                 (entity_type, entity_id, action, actor_id, actor_name,
                  signature_hash, comment, client_uid)
               VALUES (%(entity_type)s, %(entity_id)s, %(action)s,
                       %(actor_id)s, %(actor_name)s, %(signature_hash)s,
                       %(comment)s, %(client_uid)s)
               ON CONFLICT (client_uid) DO NOTHING
               RETURNING *""",
            {
                "entity_type": entity_type,
                "entity_id": entity_id,
                "action": action,
                "actor_id": d.get("actor_id"),
                "actor_name": d.get("actor_name"),
                "signature_hash": d.get("signature_hash"),
                "comment": d.get("comment"),
                "client_uid": d.get("client_uid"),
            },
        ).fetchone()
        # Idempotent replay (client_uid conflict) -> fetch existing row
        if not row and d.get("client_uid"):
            row = conn.execute(
                "SELECT * FROM approvals WHERE client_uid = %s",
                (d.get("client_uid"),),
            ).fetchone()

        # Drive drawing lifecycle status on approve/release
        if entity_type == "drawing" and entity_id and action in ("approve", "release"):
            new_status = "approved" if action == "approve" else "released"
            conn.execute(
                "UPDATE drawings SET status = %(s)s WHERE id = %(id)s",
                {"s": new_status, "id": entity_id},
            )

        audit(conn, d.get("actor_name") or d.get("actor", "system"),
              action or "approval", entity_type, entity_id,
              {"comment": d.get("comment")})
    return jsonify(row), 201


# ── Comments ─────────────────────────────────────────────────────────
@app.get("/api/comments")
def list_comments():
    if (r := require_db()):
        return r
    entity_type = request.args.get("entity_type")
    entity_id = request.args.get("entity_id")
    sql = "SELECT * FROM comments"
    where, params = [], {}
    if entity_type:
        where.append("entity_type = %(et)s")
        params["et"] = entity_type
    if entity_id:
        where.append("entity_id = %(eid)s")
        params["eid"] = entity_id
    if where:
        sql += " WHERE " + " AND ".join(where)
    sql += " ORDER BY created_at ASC"
    with get_conn() as conn:
        rows = conn.execute(sql, params).fetchall()
    return jsonify(rows)


@app.post("/api/comments")
def create_comment():
    if (r := require_db()):
        return r
    d = request.get_json(force=True) or {}
    with get_conn() as conn:
        row = conn.execute(
            """INSERT INTO comments
                 (entity_type, entity_id, author, body, client_uid)
               VALUES (%(entity_type)s, %(entity_id)s, %(author)s,
                       %(body)s, %(client_uid)s)
               ON CONFLICT (client_uid) DO NOTHING
               RETURNING *""",
            {
                "entity_type": d.get("entity_type"),
                "entity_id": d.get("entity_id"),
                "author": d.get("author"),
                "body": d.get("body", ""),
                "client_uid": d.get("client_uid"),
            },
        ).fetchone()
        if not row and d.get("client_uid"):
            row = conn.execute(
                "SELECT * FROM comments WHERE client_uid = %s",
                (d.get("client_uid"),),
            ).fetchone()
    return jsonify(row), 201


# ── Audit log ────────────────────────────────────────────────────────
@app.get("/api/audit")
def list_audit():
    if (r := require_db()):
        return r
    try:
        limit = int(request.args.get("limit", 50))
    except (TypeError, ValueError):
        limit = 50
    limit = max(1, min(limit, 1000))
    with get_conn() as conn:
        rows = conn.execute(
            "SELECT * FROM audit_log ORDER BY seq DESC LIMIT %s",
            (limit,),
        ).fetchall()
    return jsonify(rows)


# ── Users ────────────────────────────────────────────────────────────
@app.get("/api/users")
def list_users():
    if (r := require_db()):
        return r
    with get_conn() as conn:
        rows = conn.execute(
            """SELECT id, username, display_name, email, role,
                      created_at, updated_at
               FROM users ORDER BY username"""
        ).fetchall()
    return jsonify(rows)


@app.post("/api/users")
def create_user():
    if (r := require_db()):
        return r
    d = request.get_json(force=True) or {}
    with get_conn() as conn:
        row = conn.execute(
            """INSERT INTO users
                 (username, display_name, email, password_hash, role)
               VALUES (%(username)s, %(display_name)s, %(email)s,
                       %(password_hash)s, COALESCE(%(role)s,'engineer'))
               ON CONFLICT (username) DO UPDATE
                 SET display_name = EXCLUDED.display_name,
                     email = EXCLUDED.email,
                     role = EXCLUDED.role
               RETURNING id, username, display_name, email, role,
                         created_at, updated_at""",
            {
                "username": d.get("username"),
                "display_name": d.get("display_name"),
                "email": d.get("email"),
                "password_hash": d.get("password_hash"),
                "role": d.get("role"),
            },
        ).fetchone()
    return jsonify(row), 201


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
                      background_name, width, height, client_uid,
                      view_kind, model_format, model_name, model_data)
                   VALUES (%(pid)s, %(title)s, COALESCE(%(bk)s,'blank'),
                           %(bd)s, %(bn)s, COALESCE(%(w)s,1600),
                           COALESCE(%(h)s,1200), %(cuid)s,
                           COALESCE(%(vk)s,'2d'), %(mf)s, %(mn)s, %(md)s)
                   ON CONFLICT (client_uid) DO UPDATE
                     SET title = EXCLUDED.title,
                         background_data = EXCLUDED.background_data,
                         view_kind = EXCLUDED.view_kind,
                         model_format = EXCLUDED.model_format,
                         model_name = EXCLUDED.model_name,
                         model_data = EXCLUDED.model_data,
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
                    "vk": drawing.get("view_kind"),
                    "mf": drawing.get("model_format"),
                    "mn": drawing.get("model_name"),
                    "md": drawing.get("model_data"),
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
