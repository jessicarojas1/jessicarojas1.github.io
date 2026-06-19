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
import secrets
import pathlib
from functools import wraps
from contextlib import contextmanager

from flask import (Flask, request, jsonify, send_from_directory, Response,
                   g, make_response)
from werkzeug.security import generate_password_hash, check_password_hash
from itsdangerous import URLSafeTimedSerializer, BadSignature, SignatureExpired

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

ENVIRONMENT = os.environ.get("ENVIRONMENT", "production").strip().lower()
IS_PROD = ENVIRONMENT not in ("development", "dev", "local", "test")

# Session/token configuration. Sessions are stateless, signed tokens carried in
# an HttpOnly cookie (no token in localStorage → not exfiltratable via XSS).
SESSION_COOKIE = "am_session"
CSRF_COOKIE = "am_csrf"
SESSION_TTL = int(os.environ.get("SESSION_TTL_SECONDS", str(12 * 3600)))

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


# ── Authentication & authorization ───────────────────────────────────
def _load_secret() -> str:
    """Server signing key. Fails closed in production when a real backend
    (a configured database) is present; permissive only for the DB-less,
    offline-only static demo where there is nothing to authenticate."""
    s = os.environ.get("AEROMARKUP_SECRET", "").strip()
    if len(s) >= 32:
        return s
    if db_enabled():
        if IS_PROD:
            raise RuntimeError(
                "AEROMARKUP_SECRET is missing or too weak (need >= 32 chars). "
                "Refusing to start an authenticated backend without a strong key."
            )
        app.logger.warning(
            "AEROMARKUP_SECRET unset; using an ephemeral dev secret "
            "(sessions will reset on restart). Do NOT use in production."
        )
    return secrets.token_urlsafe(48)


SECRET = _load_secret()
_signer = URLSafeTimedSerializer(SECRET, salt="aeromarkup-session")

# Endpoints reachable without a session. Everything else under /api/ requires
# a valid session; static PWA assets (the app shell, login screen) are public.
PUBLIC_API = {
    "/api/health",
    "/api/auth/status",
    "/api/auth/login",
    "/api/auth/bootstrap",
}

# Capability matrix — mirrors static/js/session.js so client and server agree.
CAP = {
    "drawing.edit":       {"engineer", "admin"},
    "drawing.submit":     {"engineer", "approver", "admin"},
    "drawing.approve":    {"approver", "admin"},
    "drawing.release":    {"approver", "admin"},
    "ncr.create":         {"engineer", "inspector", "admin"},
    "ncr.disposition":    {"approver", "admin"},
    "inspection.perform": {"inspector", "admin"},
    "project.manage":     {"engineer", "admin"},
    "user.manage":        {"admin"},
    "audit.read":         {"approver", "admin"},
}


def _can(user, action) -> bool:
    if not user:
        return False
    if user.get("role") == "admin":
        return True
    allowed = CAP.get(action)
    return user.get("role") in allowed if allowed else True


def requires(action):
    """Decorator enforcing a capability on an already-authenticated request."""
    def deco(fn):
        @wraps(fn)
        def wrapper(*a, **k):
            if not _can(getattr(g, "user", None), action):
                return jsonify({"error": "forbidden", "need": action}), 403
            return fn(*a, **k)
        return wrapper
    return deco


def _verify_session():
    tok = request.cookies.get(SESSION_COOKIE)
    if not tok:
        return None
    try:
        return _signer.loads(tok, max_age=SESSION_TTL)
    except (BadSignature, SignatureExpired):
        return None


@app.before_request
def _auth_gate():
    """Authenticate every /api/ request (except the public set) and enforce a
    double-submit CSRF token on all state-changing methods."""
    p = request.path
    if not p.startswith("/api/"):
        return  # static PWA shell / assets are public
    if p in PUBLIC_API:
        return
    user = _verify_session()
    if not user:
        return jsonify({"error": "unauthorized"}), 401
    g.user = user
    if request.method in ("POST", "PUT", "PATCH", "DELETE"):
        sent = request.headers.get("X-CSRF-Token", "")
        cookie = request.cookies.get(CSRF_COOKIE, "")
        if not sent or not cookie or not secrets.compare_digest(sent, cookie):
            return jsonify({"error": "csrf_failed"}), 403


def _issue_session(u):
    """Build the user claims, sign a session cookie, and return the login JSON.
    The CSRF token is a separate, JS-readable cookie (double-submit pattern)."""
    user = {
        "uid": str(u["id"]),
        "username": u["username"],
        "name": u.get("display_name") or u["username"],
        "role": u["role"],
    }
    token = _signer.dumps(user)
    csrf = secrets.token_urlsafe(32)
    resp = make_response(jsonify({"ok": True, "user": user, "csrf": csrf}))
    resp.set_cookie(SESSION_COOKIE, token, max_age=SESSION_TTL, httponly=True,
                    secure=IS_PROD, samesite="Strict", path="/")
    resp.set_cookie(CSRF_COOKIE, csrf, max_age=SESSION_TTL, httponly=False,
                    secure=IS_PROD, samesite="Strict", path="/")
    return resp


def _count_password_users(conn) -> int:
    return conn.execute(
        "SELECT COUNT(*) AS c FROM users WHERE password_hash IS NOT NULL"
    ).fetchone()["c"]


def actor_name() -> str:
    """Authoritative actor for audit / e-signatures — from the session, never
    from client-supplied request fields."""
    u = getattr(g, "user", None)
    return (u or {}).get("name") or (u or {}).get("username") or "system"


@app.get("/api/auth/status")
def auth_status():
    """Public: lets the client decide whether to show login vs. first-run setup."""
    if not db_enabled():
        return jsonify({"db": False, "needs_bootstrap": False})
    with get_conn() as conn:
        needs = _count_password_users(conn) == 0
    return jsonify({"db": True, "needs_bootstrap": needs})


@app.post("/api/auth/login")
def auth_login():
    if (r := require_db()):
        return r
    d = request.get_json(force=True) or {}
    username = (d.get("username") or "").strip()
    password = d.get("password") or ""
    if not username or not password:
        return jsonify({"error": "missing_credentials"}), 400
    with get_conn() as conn:
        u = conn.execute(
            """SELECT id, username, display_name, role, password_hash
               FROM users WHERE username = %s""",
            (username,),
        ).fetchone()
    if not u or not u["password_hash"] or not check_password_hash(u["password_hash"], password):
        return jsonify({"error": "invalid_credentials"}), 401
    return _issue_session(u)


@app.post("/api/auth/bootstrap")
def auth_bootstrap():
    """First-run setup: create the initial admin. Allowed only while no user has
    a password set, so there is never a shipped default credential."""
    if (r := require_db()):
        return r
    d = request.get_json(force=True) or {}
    username = (d.get("username") or "").strip()
    password = d.get("password") or ""
    if len(username) < 3 or len(password) < 8:
        return jsonify({"error": "weak_credentials",
                        "detail": "username >= 3 chars, password >= 8 chars"}), 400
    with get_conn() as conn:
        if _count_password_users(conn) > 0:
            return jsonify({"error": "already_initialized"}), 403
        u = conn.execute(
            """INSERT INTO users (username, display_name, email, password_hash, role)
               VALUES (%(u)s, %(n)s, %(e)s, %(ph)s, 'admin')
               ON CONFLICT (username) DO UPDATE
                 SET password_hash = EXCLUDED.password_hash,
                     display_name  = EXCLUDED.display_name,
                     role          = 'admin'
               RETURNING id, username, display_name, role""",
            {"u": username, "n": d.get("display_name") or username,
             "e": d.get("email"), "ph": generate_password_hash(password)},
        ).fetchone()
        audit(conn, username, "bootstrap", "user", str(u["id"]), {"role": "admin"})
    return _issue_session(u)


@app.get("/api/auth/me")
def auth_me():
    return jsonify({"user": g.user})


@app.post("/api/auth/logout")
def auth_logout():
    resp = make_response(jsonify({"ok": True}))
    resp.delete_cookie(SESSION_COOKIE, path="/")
    resp.delete_cookie(CSRF_COOKIE, path="/")
    return resp


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
@requires("project.manage")
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
        audit(conn, actor_name(), "create", "project",
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
@requires("drawing.edit")
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
        audit(conn, actor_name(), "create", "drawing",
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
@requires("project.manage")
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
        audit(conn, actor_name(), "create", "program",
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
@requires("ncr.create")
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
                "raised_by": d.get("raised_by") or actor_name(),
                "assigned_to": d.get("assigned_to"),
                "due_date": d.get("due_date"),
                "classification": d.get("classification"),
                "client_uid": d.get("client_uid"),
            },
        ).fetchone()
        audit(conn, actor_name(), "upsert", "ncr",
              row["id"], {"status": row["status"], "severity": row["severity"]})
    return jsonify(row), 201


@app.patch("/api/ncrs/<ncr_id>")
@requires("ncr.create")
def update_ncr(ncr_id):
    if (r := require_db()):
        return r
    d = request.get_json(force=True) or {}
    # Dispositioning an NCR is an approver-only action (e-signature equivalent).
    if (d.get("disposition") or d.get("disposition_notes")) and not _can(g.user, "ncr.disposition"):
        return jsonify({"error": "forbidden", "need": "ncr.disposition"}), 403
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
        audit(conn, actor_name(), "update", "ncr",
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
@requires("inspection.perform")
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
        audit(conn, actor_name(), "create", "inspection",
              row["id"], {"result": row["result"]})
    return jsonify(row), 201


@app.post("/api/inspections/<inspection_id>/items")
@requires("inspection.perform")
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


# action -> capability required to record that approval/e-signature
_APPROVAL_CAP = {
    "submit":  "drawing.submit",
    "approve": "drawing.approve",
    "release": "drawing.release",
    "reject":  "drawing.approve",
}


@app.post("/api/approvals")
def create_approval():
    if (r := require_db()):
        return r
    d = request.get_json(force=True) or {}
    entity_type = d.get("entity_type")
    entity_id = d.get("entity_id")
    action = d.get("action")
    need = _APPROVAL_CAP.get(action)
    if need and not _can(g.user, need):
        return jsonify({"error": "forbidden", "need": need}), 403
    # The signer is the authenticated user — never trust client-supplied identity
    # for an e-signature / approval record.
    actor_id = g.user.get("uid")
    signer = actor_name()
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
                "actor_id": actor_id,
                "actor_name": signer,
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

        audit(conn, signer, action or "approval", entity_type, entity_id,
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
                "author": actor_name(),
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
@requires("audit.read")
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
@requires("user.manage")
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
@requires("user.manage")
def create_user():
    if (r := require_db()):
        return r
    d = request.get_json(force=True) or {}
    username = (d.get("username") or "").strip()
    if not username:
        return jsonify({"error": "missing_username"}), 400
    # Passwords are hashed server-side. A client-supplied hash is never trusted.
    password = d.get("password") or ""
    if password and len(password) < 8:
        return jsonify({"error": "weak_password", "detail": "password >= 8 chars"}), 400
    pw_hash = generate_password_hash(password) if password else None
    role = d.get("role") or "engineer"
    if role not in {"viewer", "engineer", "inspector", "approver", "admin"}:
        return jsonify({"error": "invalid_role"}), 400
    with get_conn() as conn:
        # On update, only overwrite the password when a new one is provided.
        row = conn.execute(
            """INSERT INTO users
                 (username, display_name, email, password_hash, role)
               VALUES (%(username)s, %(display_name)s, %(email)s,
                       %(password_hash)s, %(role)s)
               ON CONFLICT (username) DO UPDATE
                 SET display_name = EXCLUDED.display_name,
                     email = EXCLUDED.email,
                     role = EXCLUDED.role,
                     password_hash = COALESCE(EXCLUDED.password_hash, users.password_hash)
               RETURNING id, username, display_name, email, role,
                         created_at, updated_at""",
            {
                "username": username,
                "display_name": d.get("display_name"),
                "email": d.get("email"),
                "password_hash": pw_hash,
                "role": role,
            },
        ).fetchone()
        audit(conn, actor_name(), "upsert", "user", str(row["id"]),
              {"role": row["role"]})
    return jsonify(row), 201


# ── Sync: the heart of offline<->online reconciliation ───────────────
@app.post("/api/sync")
@requires("drawing.edit")
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

        # Return the canonical drawing meta so other devices can pull the
        # 2D background or 3D model that was uploaded elsewhere.
        drawing_meta = None
        if drawing_id:
            drawing_meta = conn.execute(
                """SELECT id, title, view_kind, background_kind, background_data,
                          model_format, model_name, model_data, width, height
                   FROM drawings WHERE id = %s""",
                (drawing_id,),
            ).fetchone()

    return jsonify({"ok": True, "drawing_id": drawing_id,
                    "cursor": cursor, "changes": changes, "drawing": drawing_meta})


# ── Boot ─────────────────────────────────────────────────────────────
if AUTO_MIGRATE:
    try:
        migrate()
    except Exception as e:  # pragma: no cover
        app.logger.warning("Auto-migrate skipped: %s", e)


if __name__ == "__main__":
    port = int(os.environ.get("PORT", 8080))
    app.run(host="0.0.0.0", port=port)
