-- Migration 032 — Remove the Change Requests and Account Reviews modules.
--
-- The application surface (sidebar nav, routes, controllers, views) for these two
-- modules is removed in the same change. Dropping the tables is destructive and
-- intentional (explicit product decision). CASCADE also drops their child tables'
-- FKs, indexes, and the tenant_isolation RLS policies.
--
-- NOTE: the Incidents module UI is also removed, but its tables are deliberately
-- KEPT — the retained "Incident SLA" feature (incident_sla_policies /
-- incident_sla_events) depends on the incidents table.

DROP TABLE IF EXISTS change_request_updates CASCADE;
DROP TABLE IF EXISTS change_requests        CASCADE;
DROP TABLE IF EXISTS account_review_items   CASCADE;
DROP TABLE IF EXISTS account_reviews        CASCADE;
