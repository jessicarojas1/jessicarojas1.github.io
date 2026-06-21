'use strict';
/* CITADEL — multi-tenancy (schema-per-tenant isolation, H5).
 *
 * Opt-in via CITADEL_MULTITENANT=1 (and a Postgres DATABASE_URL — schema-per-
 * tenant requires a real database). Each tenant gets its OWN Postgres schema
 * (namespace) holding the full citadel_* table set, so one tenant's data is
 * physically separated from another's: a query scoped to tenant A's schema
 * cannot read tenant B's rows even if a WHERE clause is forgotten. A small
 * registry table in the public schema maps tenant slug -> schema.
 *
 * Tenant resolution per request: the `X-Citadel-Tenant` header, then a
 * `?tenant=` query param, then a subdomain of CITADEL_BASE_DOMAIN
 * (acme.citadel.example.com -> acme). Slugs are strictly validated and the
 * derived schema name is additionally quote-validated in db.js, so a tenant
 * identifier can never be used to inject SQL or escape its schema.
 *
 * NOTE: enabling per-request routing also requires the auth/session caches to be
 * keyed per tenant (see server README — staged rollout). This module provides
 * the isolation primitives, the registry, provisioning, and request resolution.
 */
const db = require('./db');

const SCHEMA_PREFIX = 'citadel_t_';

function multitenant() { return process.env.CITADEL_MULTITENANT === '1'; }
function enabled() { return multitenant() && db.enabled(); }

// Tenant slug rules: lowercase alphanumeric with internal single hyphens (so it
// can double as a DNS subdomain label), 2–32 chars, no leading/trailing hyphen,
// no double hyphen. Conservative on purpose — this string drives a schema name.
const SLUG_RE = /^[a-z0-9](?:[a-z0-9]|-(?=[a-z0-9])){1,31}$/;
function valid(slug) { return typeof slug === 'string' && SLUG_RE.test(slug); }

// Derive the Postgres schema name from a slug. Hyphens (legal in DNS labels) map
// to underscores (legal in SQL identifiers). Throws on an invalid slug.
function schemaFor(slug) {
  if (!valid(slug)) throw new Error('Invalid tenant slug.');
  return SCHEMA_PREFIX + slug.replace(/-/g, '_');
}

const REGISTRY_DDL = `CREATE TABLE IF NOT EXISTS citadel_tenants (
  slug        text PRIMARY KEY,
  name        text,
  schema_name text NOT NULL,
  active      boolean NOT NULL DEFAULT true,
  created_at  timestamptz NOT NULL DEFAULT now()
);`;

// The registry lives in the public schema (shared). Created outside any tenant
// scope so it lands in public regardless of ambient context.
async function ensureRegistry() {
  if (!db.enabled()) return;
  await db.query(REGISTRY_DDL);
}

async function list() {
  if (!db.enabled()) return [];
  await ensureRegistry();
  const r = await db.query('SELECT slug, name, schema_name, active, created_at FROM citadel_tenants ORDER BY created_at');
  return r.rows.map(x => ({
    slug: x.slug, name: x.name, schema: x.schema_name, active: x.active,
    createdAt: (x.created_at instanceof Date ? x.created_at.toISOString() : x.created_at)
  }));
}

async function get(slug) {
  if (!valid(slug) || !db.enabled()) return null;
  await ensureRegistry();
  const r = await db.query('SELECT slug, name, schema_name, active FROM citadel_tenants WHERE slug=$1', [slug]);
  if (!r.rows.length) return null;
  const x = r.rows[0];
  return { slug: x.slug, name: x.name, schema: x.schema_name, active: x.active };
}

// Create + provision a tenant: validate, build its schema with the full table
// set, then register it. Idempotent-safe: rejects an existing slug.
async function create({ slug, name }) {
  if (!db.enabled()) throw new Error('Multi-tenancy requires DATABASE_URL.');
  if (!valid(slug)) throw new Error('Invalid tenant slug (2–32 chars: lowercase letters, digits, internal hyphens).');
  await ensureRegistry();
  if (await get(slug)) throw new Error('Tenant already exists.');
  const schema = schemaFor(slug);
  await db.applySchemaTo(schema);   // CREATE SCHEMA + citadel_* tables within it
  await db.query('INSERT INTO citadel_tenants(slug,name,schema_name) VALUES($1,$2,$3)', [slug, name || slug, schema]);
  return { slug, name: name || slug, schema, active: true };
}

// Soft-deactivate a tenant (keeps its schema/data; blocks request resolution).
async function deactivate(slug) {
  if (!valid(slug) || !db.enabled()) return false;
  await ensureRegistry();
  await db.query('UPDATE citadel_tenants SET active=false WHERE slug=$1', [slug]);
  return true;
}

// Resolve the tenant slug for a request: header -> ?tenant= -> subdomain.
// Returns a validated slug or null. Never throws.
function resolveSlug(req) {
  if (!req) return null;
  const headers = req.headers || {};
  let s = String(headers['x-citadel-tenant'] || '').trim().toLowerCase();
  if (!s && req.query && req.query.tenant) s = String(req.query.tenant).trim().toLowerCase();
  if (!s) {
    const host = String(headers.host || '').split(':')[0].toLowerCase();
    const base = (process.env.CITADEL_BASE_DOMAIN || '').toLowerCase();
    if (base && host.endsWith('.' + base)) s = host.slice(0, host.length - base.length - 1);
  }
  return valid(s) ? s : null;
}

module.exports = { enabled, multitenant, valid, schemaFor, ensureRegistry, list, get, create, deactivate, resolveSlug, SCHEMA_PREFIX };
