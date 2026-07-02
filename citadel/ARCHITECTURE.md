# CITADEL — Architecture

> The full, operator-grade architecture reference has moved to
> **[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)**. An interactive version of
> the module map is at [`docs/index.html`](docs/index.html).

CITADEL is a **layered pipeline of independent modules** on a global `CITADEL`
namespace. Modules never call each other directly — they communicate through two
data contracts: the **entry** (a normalized file) and the **finding** (a
normalized result). This keeps every analyzer pluggable: adding one rule or
analyzer ripples through scoring, compliance mapping, charts, and exports with no
other changes. The same pure engine runs in the browser, a scan Web Worker, the
Node backend, and the CLI.

See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for the platform, design
principles, component overview (SPA + server + CLI + Action), monorepo layout,
configuration model, request & error contract, security model, observability,
and the multi-engine deep-scan pipeline. Related:
[`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) ·
[`docs/SECURITY.md`](docs/SECURITY.md) ·
[`docs/DISASTER_RECOVERY.md`](docs/DISASTER_RECOVERY.md) ·
[`FRAMEWORKS.md`](FRAMEWORKS.md).
