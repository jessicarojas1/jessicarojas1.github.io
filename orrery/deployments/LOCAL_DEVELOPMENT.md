# ORRERY — Local Development

Operator guide for running ORRERY on a workstation during the discovery phase.

## 1. Deployment architecture

A single PHP process serves the discovery microsite from `public/` via the front
controller. No database or external identity is required in discovery.

## 2. Topology

```
Browser ──> PHP built-in server (:8080)  OR  Docker container (:8080)
                     └── public/index.php ──> app/Views/discovery.php
                                          └── /health (JSON)
```

## 3. Prerequisites

- PHP 8.2+ **or** Docker.
- (Optional, Phase 1+) PostgreSQL 14+, an Entra ID app registration.

## 4. Identity & credentials

None in discovery. Phase 1 uses Entra OIDC; store client secret via environment,
never in source. Prefer workload identity where available over static secrets.

## 5. Environment variables

| Variable | Example | Purpose |
|----------|---------|---------|
| `APP_ENV` | `discovery` | Runtime mode |
| `PORT` | `8080` | Listen port (Docker) |

(Phase 1 adds `DATABASE_URL`, `ENTRA_*`, `GRAPH_SCOPES` — see `../docs/DEPLOYMENT.md`.)

## 6. Configuration references

Routing and headers are in `public/index.php`. Content is `app/Views/discovery.php`.

## 7. Run

**PHP built-in server:**
```bash
cd orrery
php -S 0.0.0.0:8080 -t public
```

**Docker:**
```bash
cd orrery
docker build -t orrery:discovery .
docker run --rm -p 8080:8080 orrery:discovery
```

## 8. Verification

```bash
curl -fsS http://localhost:8080/health          # {"status":"ok",...}
```
Open http://localhost:8080 — the Discovery Package should render, including the
two Mermaid diagrams (they degrade to source text if the CDN is blocked).

## 9. Day-2 / troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Blank page / 500 | PHP < 8.2 | Upgrade PHP |
| Diagrams show as text | CDN blocked | Expected offline; Phase 1 will vendor Mermaid |
| 404 on a path | Discovery serves only `/` and `/health` | Use those routes |
| Container unhealthy | Health check failing | `docker logs <id>`; confirm port 8080 |
