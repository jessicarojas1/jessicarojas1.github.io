# ADR-0005: PostgreSQL 17 for data and full-text search

- **Status:** Accepted (architecture approved 2026-10-25). Originally proposed
- **Date:** 2026-10-25

## Decision
PostgreSQL 17, extensions `btree_gist` and `pg_trgm`. Effectivity is enforced by `EXCLUDE USING gist (document_id WITH =, scope_id WITH =, effective_period WITH &&) WHERE (status='RELEASED')`. Search uses `tsvector` + GIN with authorization predicates inside the query. No Elasticsearch/OpenSearch in v1.

## Rationale
The invariants live where they cannot be bypassed by a bug in application code. One stateful system to secure, back up and restore. Portable between on-prem and Azure Database for PostgreSQL Flexible Server (extensions allow-listed there).

## Revisit when
Relevance tuning, faceting at scale, or document volume exceeds what Postgres FTS serves within the §17 performance targets.
