-- =====================================================================
-- AEGIS GRC — COMPLETE database schema (AUTO-GENERATED, do not hand-edit)
-- =====================================================================
-- This is the full schema as produced by the authoritative installer
-- (install.php = schema.sql + database/migrations/*), captured via pg_dump.
-- It contains ALL 122 tables, 196 indexes and 448 constraints.
--
-- Use this for: understanding the complete data model, or bootstrapping a
-- fresh database for inspection. It is schema-only (NO seed data) and creates
-- objects in the `aegis` schema. Tables/indexes are IF NOT EXISTS; constraints
-- are not, so run it once against a fresh database.
--
-- AUTHORITATIVE setup path remains install.php (schema + migrations + seed).
-- Regenerate after adding a migration:
--   php install.php   # against a fresh DB
--   pg_dump "$DATABASE_URL" --schema-only --no-owner --no-privileges --schema=aegis \
--     | sed -E 's/^CREATE SCHEMA aegis;/CREATE SCHEMA IF NOT EXISTS aegis;/; \
--               s/^CREATE TABLE /CREATE TABLE IF NOT EXISTS /; \
--               s/^CREATE (UNIQUE )?INDEX /CREATE \1INDEX IF NOT EXISTS /'
-- =====================================================================

--
-- PostgreSQL database dump
--

\restrict 69b5oeru0PkoFR9Em0Wcwon5TaTn0KCUJwAISnf98imOaxV1mApSGq1GhzQDKDF

-- Dumped from database version 16.13 (Ubuntu 16.13-0ubuntu0.24.04.1)
-- Dumped by pg_dump version 16.13 (Ubuntu 16.13-0ubuntu0.24.04.1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: aegis; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA IF NOT EXISTS aegis;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: active_sessions; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.active_sessions (
    id character varying(255) NOT NULL,
    user_id integer NOT NULL,
    ip_address character varying(45),
    user_agent text,
    last_seen_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: activity_log; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.activity_log (
    id integer NOT NULL,
    user_id integer,
    action character varying(255) NOT NULL,
    entity_type character varying(100),
    entity_id integer,
    changes text,
    ip_address character varying(50),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    log_hash character varying(64),
    user_agent character varying(500)
);


--
-- Name: activity_log_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.activity_log_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: activity_log_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.activity_log_id_seq OWNED BY aegis.activity_log.id;


--
-- Name: alert_configs; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.alert_configs (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    type character varying(100) NOT NULL,
    trigger_config jsonb DEFAULT '{}'::jsonb NOT NULL,
    recipients jsonb DEFAULT '[]'::jsonb NOT NULL,
    channels jsonb DEFAULT '["in_app"]'::jsonb NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: alert_configs_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.alert_configs_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: alert_configs_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.alert_configs_id_seq OWNED BY aegis.alert_configs.id;


--
-- Name: alerts; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.alerts (
    id integer NOT NULL,
    type character varying(100) NOT NULL,
    title character varying(500) NOT NULL,
    message text,
    severity character varying(50) DEFAULT 'info'::character varying NOT NULL,
    user_id integer,
    related_type character varying(100),
    related_id integer,
    is_read boolean DEFAULT false NOT NULL,
    read_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: alerts_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.alerts_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: alerts_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.alerts_id_seq OWNED BY aegis.alerts.id;


--
-- Name: api_keys; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.api_keys (
    id integer NOT NULL,
    user_id integer,
    name character varying(255) NOT NULL,
    key_prefix character varying(20) NOT NULL,
    key_hash character varying(255) NOT NULL,
    permissions jsonb DEFAULT '["read"]'::jsonb NOT NULL,
    last_used timestamp without time zone,
    expires_at timestamp without time zone,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: api_keys_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.api_keys_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: api_keys_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.api_keys_id_seq OWNED BY aegis.api_keys.id;


--
-- Name: approval_request_steps; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.approval_request_steps (
    id integer NOT NULL,
    request_id integer NOT NULL,
    step_number integer NOT NULL,
    label character varying(255) NOT NULL,
    required_role character varying(50),
    required_user_id integer,
    actioned_by integer,
    decision character varying(50),
    notes text,
    due_at timestamp without time zone,
    actioned_at timestamp without time zone
);


--
-- Name: approval_request_steps_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.approval_request_steps_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: approval_request_steps_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.approval_request_steps_id_seq OWNED BY aegis.approval_request_steps.id;


--
-- Name: approval_requests; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.approval_requests (
    id integer NOT NULL,
    template_id integer NOT NULL,
    entity_type character varying(100) NOT NULL,
    entity_id integer NOT NULL,
    requested_by integer NOT NULL,
    current_step integer DEFAULT 1 NOT NULL,
    status character varying(50) DEFAULT 'pending'::character varying NOT NULL,
    completed_at timestamp without time zone,
    context_data jsonb,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: approval_requests_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.approval_requests_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: approval_requests_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.approval_requests_id_seq OWNED BY aegis.approval_requests.id;


--
-- Name: approval_template_steps; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.approval_template_steps (
    id integer NOT NULL,
    template_id integer NOT NULL,
    step_number integer NOT NULL,
    label character varying(255) NOT NULL,
    required_role character varying(50),
    required_user_id integer,
    allow_delegation boolean DEFAULT true NOT NULL,
    due_hours integer DEFAULT 48 NOT NULL
);


--
-- Name: approval_template_steps_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.approval_template_steps_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: approval_template_steps_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.approval_template_steps_id_seq OWNED BY aegis.approval_template_steps.id;


--
-- Name: approval_templates; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.approval_templates (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    entity_type character varying(100) NOT NULL,
    trigger_condition jsonb DEFAULT '{}'::jsonb NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: approval_templates_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.approval_templates_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: approval_templates_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.approval_templates_id_seq OWNED BY aegis.approval_templates.id;


--
-- Name: asset_risk_links; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.asset_risk_links (
    id integer NOT NULL,
    asset_id integer NOT NULL,
    risk_id integer NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.asset_risk_links FORCE ROW LEVEL SECURITY;


--
-- Name: asset_risk_links_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.asset_risk_links_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: asset_risk_links_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.asset_risk_links_id_seq OWNED BY aegis.asset_risk_links.id;


--
-- Name: assets; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.assets (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    asset_type character varying(100) DEFAULT 'server'::character varying NOT NULL,
    criticality character varying(50) DEFAULT 'medium'::character varying NOT NULL,
    classification character varying(50) DEFAULT 'internal'::character varying NOT NULL,
    status character varying(50) DEFAULT 'active'::character varying NOT NULL,
    owner_id integer,
    location character varying(255),
    ip_address inet,
    hostname character varying(255),
    vendor character varying(255),
    version character varying(100),
    last_scanned date,
    last_reviewed date,
    tags jsonb DEFAULT '[]'::jsonb NOT NULL,
    description text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    asset_code character varying(20),
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.assets FORCE ROW LEVEL SECURITY;


--
-- Name: assets_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.assets_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: assets_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.assets_id_seq OWNED BY aegis.assets.id;


--
-- Name: audit_findings; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.audit_findings (
    id integer NOT NULL,
    finding_number character varying(20) NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    severity character varying(20) DEFAULT 'medium'::character varying,
    status character varying(30) DEFAULT 'open'::character varying,
    source character varying(50) DEFAULT 'external_audit'::character varying,
    audit_name character varying(255),
    auditor_name character varying(255),
    objective_id integer,
    package_id integer,
    owner_id integer,
    deadline date,
    response_notes text,
    closed_at timestamp without time zone,
    created_by integer,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now(),
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.audit_findings FORCE ROW LEVEL SECURITY;


--
-- Name: audit_findings_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.audit_findings_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: audit_findings_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.audit_findings_id_seq OWNED BY aegis.audit_findings.id;


--
-- Name: audit_items; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.audit_items (
    id integer NOT NULL,
    audit_id integer,
    objective_id integer,
    status character varying(50) DEFAULT 'not_assessed'::character varying NOT NULL,
    finding text,
    evidence text,
    notes text,
    risk_level character varying(50),
    remediation text,
    remediation_due date,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.audit_items FORCE ROW LEVEL SECURITY;


--
-- Name: audit_items_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.audit_items_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: audit_items_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.audit_items_id_seq OWNED BY aegis.audit_items.id;


--
-- Name: audit_schedules; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.audit_schedules (
    id integer NOT NULL,
    package_id integer,
    frequency character varying(50) DEFAULT 'annual'::character varying NOT NULL,
    last_audit_date date,
    next_due_date date,
    assigned_auditor integer,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.audit_schedules FORCE ROW LEVEL SECURITY;


--
-- Name: audit_schedules_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.audit_schedules_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: audit_schedules_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.audit_schedules_id_seq OWNED BY aegis.audit_schedules.id;


--
-- Name: audits; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.audits (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    package_id integer,
    audit_type character varying(100) DEFAULT 'internal'::character varying NOT NULL,
    frequency character varying(50),
    status character varying(50) DEFAULT 'planned'::character varying NOT NULL,
    scheduled_date date,
    start_date date,
    completed_date date,
    auditor_id integer,
    created_by integer,
    notes text,
    score numeric(5,2),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL,
    audit_number character varying(20)
);

ALTER TABLE ONLY aegis.audits FORCE ROW LEVEL SECURITY;


--
-- Name: audits_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.audits_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: audits_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.audits_id_seq OWNED BY aegis.audits.id;


--
-- Name: automation_logs; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.automation_logs (
    id integer NOT NULL,
    rule_id integer NOT NULL,
    triggered_at timestamp without time zone DEFAULT now(),
    status character varying(20) DEFAULT 'success'::character varying,
    details text
);


--
-- Name: automation_logs_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.automation_logs_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: automation_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.automation_logs_id_seq OWNED BY aegis.automation_logs.id;


--
-- Name: automation_rules; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.automation_rules (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    trigger_type character varying(50) NOT NULL,
    trigger_config jsonb DEFAULT '{}'::jsonb,
    action_type character varying(50) NOT NULL,
    action_config jsonb DEFAULT '{}'::jsonb,
    is_active boolean DEFAULT true,
    last_triggered_at timestamp without time zone,
    trigger_count integer DEFAULT 0,
    created_by integer,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: automation_rules_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.automation_rules_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: automation_rules_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.automation_rules_id_seq OWNED BY aegis.automation_rules.id;


--
-- Name: awareness_assignments; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.awareness_assignments (
    id integer NOT NULL,
    program_id integer NOT NULL,
    user_id integer NOT NULL,
    completed boolean DEFAULT false,
    completed_at timestamp without time zone,
    notes text,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.awareness_assignments FORCE ROW LEVEL SECURITY;


--
-- Name: awareness_assignments_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.awareness_assignments_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: awareness_assignments_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.awareness_assignments_id_seq OWNED BY aegis.awareness_assignments.id;


--
-- Name: awareness_programs; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.awareness_programs (
    id integer NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    content_type character varying(30) DEFAULT 'document'::character varying,
    content_body text,
    content_url character varying(500),
    due_date date,
    status character varying(20) DEFAULT 'active'::character varying,
    created_by integer,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now(),
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.awareness_programs FORCE ROW LEVEL SECURITY;


--
-- Name: awareness_programs_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.awareness_programs_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: awareness_programs_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.awareness_programs_id_seq OWNED BY aegis.awareness_programs.id;


--
-- Name: bcp_exercises; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.bcp_exercises (
    id integer NOT NULL,
    plan_id integer NOT NULL,
    exercise_type character varying(100) DEFAULT 'tabletop'::character varying NOT NULL,
    name character varying(255) NOT NULL,
    scheduled_date date,
    conducted_date date,
    outcome character varying(50),
    findings text,
    lessons_learned text,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.bcp_exercises FORCE ROW LEVEL SECURITY;


--
-- Name: bcp_exercises_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.bcp_exercises_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: bcp_exercises_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.bcp_exercises_id_seq OWNED BY aegis.bcp_exercises.id;


--
-- Name: bcp_plan_sections; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.bcp_plan_sections (
    id integer NOT NULL,
    plan_id integer NOT NULL,
    section_type character varying(100) NOT NULL,
    title character varying(255) NOT NULL,
    content text,
    sort_order integer DEFAULT 0 NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.bcp_plan_sections FORCE ROW LEVEL SECURITY;


--
-- Name: bcp_plan_sections_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.bcp_plan_sections_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: bcp_plan_sections_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.bcp_plan_sections_id_seq OWNED BY aegis.bcp_plan_sections.id;


--
-- Name: bcp_plans; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.bcp_plans (
    id integer NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    version character varying(50) DEFAULT '1.0'::character varying NOT NULL,
    status character varying(50) DEFAULT 'draft'::character varying NOT NULL,
    owner_id integer,
    rto_hours integer,
    rpo_hours integer,
    last_tested date,
    next_test_date date,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    plan_code character varying(20),
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.bcp_plans FORCE ROW LEVEL SECURITY;


--
-- Name: bcp_plans_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.bcp_plans_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: bcp_plans_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.bcp_plans_id_seq OWNED BY aegis.bcp_plans.id;


--
-- Name: compliance_objectives; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.compliance_objectives (
    id integer NOT NULL,
    package_id integer,
    parent_id integer,
    code character varying(100) NOT NULL,
    title text NOT NULL,
    description text,
    category character varying(255),
    level integer DEFAULT 1 NOT NULL,
    weight numeric(5,2) DEFAULT 1.0,
    sort_order integer DEFAULT 0,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.compliance_objectives FORCE ROW LEVEL SECURITY;


--
-- Name: compliance_objectives_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.compliance_objectives_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: compliance_objectives_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.compliance_objectives_id_seq OWNED BY aegis.compliance_objectives.id;


--
-- Name: compliance_packages; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.compliance_packages (
    id integer NOT NULL,
    standard_id integer,
    name character varying(255) NOT NULL,
    version character varying(50),
    description text,
    price numeric(10,2),
    objectives_count integer DEFAULT 0,
    is_active boolean DEFAULT true NOT NULL,
    imported_by integer,
    imported_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.compliance_packages FORCE ROW LEVEL SECURITY;


--
-- Name: compliance_packages_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.compliance_packages_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: compliance_packages_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.compliance_packages_id_seq OWNED BY aegis.compliance_packages.id;


--
-- Name: control_implementations; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.control_implementations (
    id integer NOT NULL,
    objective_id integer,
    status character varying(50) DEFAULT 'not_started'::character varying NOT NULL,
    implementation_notes text,
    evidence text,
    assigned_to integer,
    due_date date,
    last_reviewed timestamp without time zone,
    reviewed_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.control_implementations FORCE ROW LEVEL SECURITY;


--
-- Name: control_implementations_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.control_implementations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: control_implementations_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.control_implementations_id_seq OWNED BY aegis.control_implementations.id;


--
-- Name: control_mappings; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.control_mappings (
    id integer NOT NULL,
    source_obj_id integer NOT NULL,
    target_obj_id integer NOT NULL,
    mapping_type character varying(50) DEFAULT 'equivalent'::character varying NOT NULL,
    notes text,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.control_mappings FORCE ROW LEVEL SECURITY;


--
-- Name: control_mappings_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.control_mappings_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: control_mappings_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.control_mappings_id_seq OWNED BY aegis.control_mappings.id;


--
-- Name: control_tests; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.control_tests (
    id integer NOT NULL,
    objective_id integer NOT NULL,
    package_id integer NOT NULL,
    test_date date DEFAULT CURRENT_DATE NOT NULL,
    tester_id integer,
    result character varying(20) NOT NULL,
    effectiveness integer,
    method character varying(50),
    findings text,
    evidence_refs text,
    next_test_date date,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL,
    CONSTRAINT control_tests_effectiveness_check CHECK (((effectiveness >= 0) AND (effectiveness <= 100))),
    CONSTRAINT control_tests_result_check CHECK (((result)::text = ANY ((ARRAY['pass'::character varying, 'fail'::character varying, 'partial'::character varying, 'not_tested'::character varying])::text[])))
);

ALTER TABLE ONLY aegis.control_tests FORCE ROW LEVEL SECURITY;


--
-- Name: control_tests_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.control_tests_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: control_tests_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.control_tests_id_seq OWNED BY aegis.control_tests.id;


--
-- Name: cui_inventory; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.cui_inventory (
    id integer NOT NULL,
    inventory_number character varying(20) NOT NULL,
    data_description text NOT NULL,
    cui_category character varying(100),
    asset_id integer,
    system_name character varying(255),
    location_description text,
    storage_type character varying(50) DEFAULT 'database'::character varying,
    access_controls text,
    is_encrypted boolean DEFAULT false,
    encryption_details text,
    data_owner character varying(255),
    created_by integer,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now(),
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.cui_inventory FORCE ROW LEVEL SECURITY;


--
-- Name: cui_inventory_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.cui_inventory_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: cui_inventory_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.cui_inventory_id_seq OWNED BY aegis.cui_inventory.id;


--
-- Name: custom_dashboards; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.custom_dashboards (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    is_shared boolean DEFAULT false,
    owner_id integer,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: custom_dashboards_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.custom_dashboards_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: custom_dashboards_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.custom_dashboards_id_seq OWNED BY aegis.custom_dashboards.id;


--
-- Name: custom_field_definitions; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.custom_field_definitions (
    id integer NOT NULL,
    entity_type character varying(100) NOT NULL,
    name character varying(100) NOT NULL,
    label character varying(255) NOT NULL,
    field_type character varying(50) DEFAULT 'text'::character varying NOT NULL,
    options jsonb,
    is_required boolean DEFAULT false NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: custom_field_definitions_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.custom_field_definitions_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: custom_field_definitions_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.custom_field_definitions_id_seq OWNED BY aegis.custom_field_definitions.id;


--
-- Name: custom_field_values; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.custom_field_values (
    id integer NOT NULL,
    definition_id integer NOT NULL,
    entity_type character varying(100) NOT NULL,
    entity_id integer NOT NULL,
    value_text text,
    value_number numeric,
    value_date date,
    value_json jsonb,
    updated_by integer,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: custom_field_values_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.custom_field_values_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: custom_field_values_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.custom_field_values_id_seq OWNED BY aegis.custom_field_values.id;


--
-- Name: dashboard_widgets; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.dashboard_widgets (
    id integer NOT NULL,
    dashboard_id integer NOT NULL,
    widget_type character varying(50) NOT NULL,
    title character varying(255) NOT NULL,
    config jsonb DEFAULT '{}'::jsonb,
    "position" integer DEFAULT 0,
    created_at timestamp without time zone DEFAULT now()
);


--
-- Name: dashboard_widgets_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.dashboard_widgets_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: dashboard_widgets_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.dashboard_widgets_id_seq OWNED BY aegis.dashboard_widgets.id;


--
-- Name: data_retention_policies; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.data_retention_policies (
    id integer NOT NULL,
    entity_type character varying(100) NOT NULL,
    retention_days integer DEFAULT 365 NOT NULL,
    action character varying(30) DEFAULT 'delete'::character varying NOT NULL,
    is_enabled boolean DEFAULT false NOT NULL,
    last_run_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: data_retention_policies_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.data_retention_policies_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: data_retention_policies_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.data_retention_policies_id_seq OWNED BY aegis.data_retention_policies.id;


--
-- Name: data_subject_requests; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.data_subject_requests (
    id integer NOT NULL,
    request_type character varying(50),
    subject_name character varying(255),
    subject_email character varying(255),
    description text,
    status character varying(20) DEFAULT 'open'::character varying,
    due_date date,
    completed_at timestamp without time zone,
    assigned_to integer,
    notes text,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now(),
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.data_subject_requests FORCE ROW LEVEL SECURITY;


--
-- Name: data_subject_requests_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.data_subject_requests_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: data_subject_requests_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.data_subject_requests_id_seq OWNED BY aegis.data_subject_requests.id;


--
-- Name: document_versions; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.document_versions (
    id integer NOT NULL,
    document_id integer NOT NULL,
    version character varying(50) NOT NULL,
    file_name character varying(500),
    stored_name character varying(500),
    mime_type character varying(200),
    file_size integer,
    file_hash character varying(64),
    change_summary text,
    uploaded_by integer,
    uploaded_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.document_versions FORCE ROW LEVEL SECURITY;


--
-- Name: document_versions_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.document_versions_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: document_versions_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.document_versions_id_seq OWNED BY aegis.document_versions.id;


--
-- Name: documents; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.documents (
    id integer NOT NULL,
    title character varying(500) NOT NULL,
    doc_number character varying(100),
    description text,
    category character varying(100),
    classification character varying(50) DEFAULT 'internal'::character varying NOT NULL,
    status character varying(50) DEFAULT 'draft'::character varying NOT NULL,
    current_version character varying(50) DEFAULT '1.0'::character varying NOT NULL,
    owner_id integer,
    approver_id integer,
    review_frequency character varying(50) DEFAULT 'annual'::character varying,
    next_review_date date,
    expiry_date date,
    approved_at timestamp without time zone,
    published_at timestamp without time zone,
    tags jsonb DEFAULT '[]'::jsonb,
    dlp_metadata jsonb DEFAULT '{}'::jsonb,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.documents FORCE ROW LEVEL SECURITY;


--
-- Name: documents_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.documents_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: documents_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.documents_id_seq OWNED BY aegis.documents.id;


--
-- Name: email_bounces; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.email_bounces (
    id integer NOT NULL,
    email character varying(255) NOT NULL,
    bounce_type character varying(50) DEFAULT 'hard'::character varying NOT NULL,
    reason text,
    recorded_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT email_bounces_bounce_type_check CHECK (((bounce_type)::text = ANY ((ARRAY['hard'::character varying, 'soft'::character varying, 'complaint'::character varying])::text[])))
);


--
-- Name: email_bounces_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.email_bounces_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: email_bounces_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.email_bounces_id_seq OWNED BY aegis.email_bounces.id;


--
-- Name: email_templates; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.email_templates (
    id integer NOT NULL,
    type character varying(100) NOT NULL,
    name character varying(255) NOT NULL,
    subject character varying(500) NOT NULL,
    body_html text NOT NULL,
    body_text text,
    variables jsonb DEFAULT '[]'::jsonb NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    updated_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: email_templates_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.email_templates_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: email_templates_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.email_templates_id_seq OWNED BY aegis.email_templates.id;


--
-- Name: email_unsubscribes; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.email_unsubscribes (
    id integer NOT NULL,
    user_id integer,
    email character varying(255) NOT NULL,
    token character varying(64) NOT NULL,
    notification_type character varying(100),
    unsubscribed_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: email_unsubscribes_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.email_unsubscribes_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: email_unsubscribes_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.email_unsubscribes_id_seq OWNED BY aegis.email_unsubscribes.id;


--
-- Name: email_verification_tokens; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.email_verification_tokens (
    id integer NOT NULL,
    user_id integer NOT NULL,
    token_hash character varying(64) NOT NULL,
    expires_at timestamp without time zone NOT NULL,
    used_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: email_verification_tokens_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.email_verification_tokens_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: email_verification_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.email_verification_tokens_id_seq OWNED BY aegis.email_verification_tokens.id;


--
-- Name: entity_tags; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.entity_tags (
    id integer NOT NULL,
    tag_id integer NOT NULL,
    entity_type character varying(30) NOT NULL,
    entity_id integer NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: entity_tags_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.entity_tags_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: entity_tags_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.entity_tags_id_seq OWNED BY aegis.entity_tags.id;


--
-- Name: evidence; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.evidence (
    id integer NOT NULL,
    entity_type character varying(50) NOT NULL,
    entity_id integer NOT NULL,
    filename character varying(255) NOT NULL,
    stored_name character varying(255) NOT NULL,
    file_size integer,
    mime_type character varying(100),
    uploaded_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.evidence FORCE ROW LEVEL SECURITY;


--
-- Name: evidence_files; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.evidence_files (
    id integer NOT NULL,
    entity_type character varying(50) NOT NULL,
    entity_id integer NOT NULL,
    original_name character varying(255) NOT NULL,
    stored_name character varying(255) NOT NULL,
    mime_type character varying(100),
    file_size integer,
    file_hash character varying(64),
    description text,
    expires_at timestamp without time zone,
    uploaded_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.evidence_files FORCE ROW LEVEL SECURITY;


--
-- Name: evidence_files_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.evidence_files_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: evidence_files_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.evidence_files_id_seq OWNED BY aegis.evidence_files.id;


--
-- Name: evidence_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.evidence_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: evidence_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.evidence_id_seq OWNED BY aegis.evidence.id;


--
-- Name: finding_updates; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.finding_updates (
    id integer NOT NULL,
    finding_id integer NOT NULL,
    user_id integer,
    content text NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.finding_updates FORCE ROW LEVEL SECURITY;


--
-- Name: finding_updates_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.finding_updates_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: finding_updates_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.finding_updates_id_seq OWNED BY aegis.finding_updates.id;


--
-- Name: grc_project_links; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.grc_project_links (
    id integer NOT NULL,
    project_id integer NOT NULL,
    entity_type character varying(50) NOT NULL,
    entity_id integer NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.grc_project_links FORCE ROW LEVEL SECURITY;


--
-- Name: grc_project_links_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.grc_project_links_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: grc_project_links_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.grc_project_links_id_seq OWNED BY aegis.grc_project_links.id;


--
-- Name: grc_project_tasks; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.grc_project_tasks (
    id integer NOT NULL,
    project_id integer NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    status character varying(20) DEFAULT 'todo'::character varying,
    assigned_to integer,
    due_date date,
    created_at timestamp without time zone DEFAULT now(),
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.grc_project_tasks FORCE ROW LEVEL SECURITY;


--
-- Name: grc_project_tasks_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.grc_project_tasks_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: grc_project_tasks_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.grc_project_tasks_id_seq OWNED BY aegis.grc_project_tasks.id;


--
-- Name: grc_projects; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.grc_projects (
    id integer NOT NULL,
    project_code character varying(20) NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    status character varying(30) DEFAULT 'planning'::character varying,
    priority character varying(20) DEFAULT 'medium'::character varying,
    start_date date,
    end_date date,
    budget_planned numeric(12,2),
    budget_actual numeric(12,2),
    project_lead integer,
    created_by integer,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now(),
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.grc_projects FORCE ROW LEVEL SECURITY;


--
-- Name: grc_projects_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.grc_projects_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: grc_projects_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.grc_projects_id_seq OWNED BY aegis.grc_projects.id;


--
-- Name: incident_playbook_runs; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.incident_playbook_runs (
    id integer NOT NULL,
    incident_id integer NOT NULL,
    playbook_id integer NOT NULL,
    started_by integer,
    started_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    completed_at timestamp without time zone
);


--
-- Name: incident_playbook_runs_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.incident_playbook_runs_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: incident_playbook_runs_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.incident_playbook_runs_id_seq OWNED BY aegis.incident_playbook_runs.id;


--
-- Name: incident_sla_events; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.incident_sla_events (
    id integer NOT NULL,
    incident_id integer NOT NULL,
    event_type character varying(30) NOT NULL,
    occurred_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    recorded_by integer,
    notes text,
    tenant_id bigint DEFAULT 1 NOT NULL,
    CONSTRAINT incident_sla_events_event_type_check CHECK (((event_type)::text = ANY ((ARRAY['acknowledged'::character varying, 'resolved'::character varying, 'escalated'::character varying, 'breach'::character varying])::text[])))
);

ALTER TABLE ONLY aegis.incident_sla_events FORCE ROW LEVEL SECURITY;


--
-- Name: incident_sla_events_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.incident_sla_events_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: incident_sla_events_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.incident_sla_events_id_seq OWNED BY aegis.incident_sla_events.id;


--
-- Name: incident_sla_policies; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.incident_sla_policies (
    id integer NOT NULL,
    severity character varying(20) NOT NULL,
    acknowledge_hours integer DEFAULT 4 NOT NULL,
    resolve_hours integer DEFAULT 72 NOT NULL,
    escalate_hours integer,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: incident_sla_policies_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.incident_sla_policies_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: incident_sla_policies_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.incident_sla_policies_id_seq OWNED BY aegis.incident_sla_policies.id;


--
-- Name: incident_updates; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.incident_updates (
    id integer NOT NULL,
    incident_id integer NOT NULL,
    user_id integer,
    content text NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.incident_updates FORCE ROW LEVEL SECURITY;


--
-- Name: incident_updates_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.incident_updates_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: incident_updates_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.incident_updates_id_seq OWNED BY aegis.incident_updates.id;


--
-- Name: incidents; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.incidents (
    id integer NOT NULL,
    incident_number character varying(20) NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    severity character varying(20) DEFAULT 'medium'::character varying NOT NULL,
    category character varying(100),
    status character varying(20) DEFAULT 'open'::character varying NOT NULL,
    reported_by integer,
    assigned_to integer,
    affected_systems text,
    impact_description text,
    detected_at timestamp without time zone,
    resolved_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL,
    CONSTRAINT incidents_severity_check CHECK (((severity)::text = ANY ((ARRAY['critical'::character varying, 'high'::character varying, 'medium'::character varying, 'low'::character varying])::text[]))),
    CONSTRAINT incidents_status_check CHECK (((status)::text = ANY ((ARRAY['open'::character varying, 'investigating'::character varying, 'resolved'::character varying, 'closed'::character varying])::text[])))
);

ALTER TABLE ONLY aegis.incidents FORCE ROW LEVEL SECURITY;


--
-- Name: incidents_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.incidents_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: incidents_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.incidents_id_seq OWNED BY aegis.incidents.id;


--
-- Name: issue_updates; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.issue_updates (
    id integer NOT NULL,
    issue_id integer NOT NULL,
    user_id integer,
    content text NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.issue_updates FORCE ROW LEVEL SECURITY;


--
-- Name: issue_updates_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.issue_updates_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: issue_updates_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.issue_updates_id_seq OWNED BY aegis.issue_updates.id;


--
-- Name: issues; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.issues (
    id integer NOT NULL,
    issue_number character varying(20) NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    severity character varying(20) DEFAULT 'medium'::character varying NOT NULL,
    status character varying(20) DEFAULT 'open'::character varying NOT NULL,
    source_type character varying(100),
    source_id integer,
    assigned_to integer,
    created_by integer,
    due_date date,
    resolved_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL,
    CONSTRAINT issues_severity_check CHECK (((severity)::text = ANY ((ARRAY['critical'::character varying, 'high'::character varying, 'medium'::character varying, 'low'::character varying])::text[]))),
    CONSTRAINT issues_status_check CHECK (((status)::text = ANY ((ARRAY['open'::character varying, 'in_progress'::character varying, 'resolved'::character varying, 'closed'::character varying])::text[])))
);

ALTER TABLE ONLY aegis.issues FORCE ROW LEVEL SECURITY;


--
-- Name: issues_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.issues_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: issues_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.issues_id_seq OWNED BY aegis.issues.id;


--
-- Name: kri_values; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.kri_values (
    id integer NOT NULL,
    kri_id integer NOT NULL,
    value numeric(15,4) NOT NULL,
    recorded_at date DEFAULT CURRENT_DATE NOT NULL,
    notes text,
    recorded_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.kri_values FORCE ROW LEVEL SECURITY;


--
-- Name: kri_values_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.kri_values_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: kri_values_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.kri_values_id_seq OWNED BY aegis.kri_values.id;


--
-- Name: kris; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.kris (
    id integer NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    unit character varying(50) DEFAULT 'count'::character varying NOT NULL,
    direction character varying(10) DEFAULT 'higher_worse'::character varying NOT NULL,
    threshold_green numeric(15,4) NOT NULL,
    threshold_amber numeric(15,4) NOT NULL,
    threshold_red numeric(15,4) NOT NULL,
    frequency character varying(20) DEFAULT 'monthly'::character varying NOT NULL,
    owner_id integer,
    linked_risk_id integer,
    is_active boolean DEFAULT true NOT NULL,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL,
    CONSTRAINT kris_direction_check CHECK (((direction)::text = ANY ((ARRAY['higher_worse'::character varying, 'lower_worse'::character varying])::text[]))),
    CONSTRAINT kris_frequency_check CHECK (((frequency)::text = ANY ((ARRAY['daily'::character varying, 'weekly'::character varying, 'monthly'::character varying, 'quarterly'::character varying])::text[])))
);

ALTER TABLE ONLY aegis.kris FORCE ROW LEVEL SECURITY;


--
-- Name: kris_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.kris_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: kris_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.kris_id_seq OWNED BY aegis.kris.id;


--
-- Name: metrics_snapshots; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.metrics_snapshots (
    id integer NOT NULL,
    snapshot_date date DEFAULT CURRENT_DATE NOT NULL,
    compliance_pct numeric(5,2),
    risk_health numeric(5,2),
    policy_health numeric(5,2),
    audit_health numeric(5,2),
    grc_score numeric(5,2),
    open_risks integer,
    critical_risks integer,
    open_incidents integer,
    critical_incidents integer,
    open_issues integer,
    overdue_reviews integer,
    vendor_count integer,
    active_audits integer,
    details jsonb,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: metrics_snapshots_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.metrics_snapshots_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: metrics_snapshots_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.metrics_snapshots_id_seq OWNED BY aegis.metrics_snapshots.id;


--
-- Name: mfa_backup_codes; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.mfa_backup_codes (
    id integer NOT NULL,
    user_id integer NOT NULL,
    code_hash character varying(255) NOT NULL,
    used_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: mfa_backup_codes_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.mfa_backup_codes_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: mfa_backup_codes_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.mfa_backup_codes_id_seq OWNED BY aegis.mfa_backup_codes.id;


--
-- Name: notification_log; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.notification_log (
    id integer NOT NULL,
    notification_type character varying(100) NOT NULL,
    entity_id integer,
    recipient_email character varying(255),
    sent_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: notification_log_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.notification_log_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: notification_log_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.notification_log_id_seq OWNED BY aegis.notification_log.id;


--
-- Name: odp_entries; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.odp_entries (
    id integer NOT NULL,
    objective_id integer NOT NULL,
    parameter_name character varying(255) NOT NULL,
    parameter_value text,
    notes text,
    updated_by integer,
    updated_at timestamp without time zone DEFAULT now(),
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.odp_entries FORCE ROW LEVEL SECURITY;


--
-- Name: odp_entries_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.odp_entries_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: odp_entries_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.odp_entries_id_seq OWNED BY aegis.odp_entries.id;


--
-- Name: password_reset_tokens; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.password_reset_tokens (
    id integer NOT NULL,
    user_id integer NOT NULL,
    token_hash character varying(64) NOT NULL,
    expires_at timestamp without time zone NOT NULL,
    used boolean DEFAULT false NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: password_reset_tokens_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.password_reset_tokens_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: password_reset_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.password_reset_tokens_id_seq OWNED BY aegis.password_reset_tokens.id;


--
-- Name: php_sessions; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.php_sessions (
    id character varying(128) NOT NULL,
    data text DEFAULT ''::text NOT NULL,
    expires_at timestamp with time zone NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: playbook_step_completions; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.playbook_step_completions (
    id integer NOT NULL,
    run_id integer NOT NULL,
    step_id integer NOT NULL,
    completed_by integer,
    completed_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    notes text
);


--
-- Name: playbook_step_completions_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.playbook_step_completions_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: playbook_step_completions_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.playbook_step_completions_id_seq OWNED BY aegis.playbook_step_completions.id;


--
-- Name: playbook_steps; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.playbook_steps (
    id integer NOT NULL,
    playbook_id integer NOT NULL,
    step_number integer NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    owner_role character varying(50),
    due_minutes integer,
    sort_order integer DEFAULT 0 NOT NULL
);


--
-- Name: playbook_steps_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.playbook_steps_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: playbook_steps_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.playbook_steps_id_seq OWNED BY aegis.playbook_steps.id;


--
-- Name: playbooks; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.playbooks (
    id integer NOT NULL,
    title character varying(255) NOT NULL,
    category character varying(50) DEFAULT 'general'::character varying NOT NULL,
    severity_filter character varying(20),
    description text,
    is_active boolean DEFAULT true NOT NULL,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: playbooks_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.playbooks_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: playbooks_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.playbooks_id_seq OWNED BY aegis.playbooks.id;


--
-- Name: poam_items; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.poam_items (
    id integer NOT NULL,
    poam_number character varying(20) NOT NULL,
    title character varying(255) NOT NULL,
    weakness_description text,
    resource_requirements text,
    scheduled_completion date,
    status character varying(20) DEFAULT 'open'::character varying,
    objective_id integer,
    package_id integer,
    owner_id integer,
    created_by integer,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now(),
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.poam_items FORCE ROW LEVEL SECURITY;


--
-- Name: poam_items_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.poam_items_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: poam_items_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.poam_items_id_seq OWNED BY aegis.poam_items.id;


--
-- Name: poam_milestones; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.poam_milestones (
    id integer NOT NULL,
    poam_id integer NOT NULL,
    description text NOT NULL,
    due_date date,
    is_complete boolean DEFAULT false,
    completed_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT now(),
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.poam_milestones FORCE ROW LEVEL SECURITY;


--
-- Name: poam_milestones_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.poam_milestones_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: poam_milestones_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.poam_milestones_id_seq OWNED BY aegis.poam_milestones.id;


--
-- Name: policies; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.policies (
    id integer NOT NULL,
    title character varying(500) NOT NULL,
    policy_number character varying(100),
    description text,
    content text,
    version character varying(50) DEFAULT '1.0'::character varying NOT NULL,
    status character varying(50) DEFAULT 'draft'::character varying NOT NULL,
    category character varying(255),
    owner_id integer,
    approver_id integer,
    review_frequency character varying(50) DEFAULT 'annual'::character varying,
    next_review_date date,
    approved_at timestamp without time zone,
    published_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.policies FORCE ROW LEVEL SECURITY;


--
-- Name: policies_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.policies_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: policies_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.policies_id_seq OWNED BY aegis.policies.id;


--
-- Name: policy_attestation_campaigns; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.policy_attestation_campaigns (
    id integer NOT NULL,
    policy_id integer NOT NULL,
    title character varying(255) NOT NULL,
    due_date date,
    is_active boolean DEFAULT true NOT NULL,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.policy_attestation_campaigns FORCE ROW LEVEL SECURITY;


--
-- Name: policy_attestation_campaigns_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.policy_attestation_campaigns_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: policy_attestation_campaigns_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.policy_attestation_campaigns_id_seq OWNED BY aegis.policy_attestation_campaigns.id;


--
-- Name: policy_attestations; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.policy_attestations (
    id integer NOT NULL,
    policy_id integer NOT NULL,
    user_id integer NOT NULL,
    attested_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    ip_address character varying(45),
    notes text,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.policy_attestations FORCE ROW LEVEL SECURITY;


--
-- Name: policy_attestations_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.policy_attestations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: policy_attestations_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.policy_attestations_id_seq OWNED BY aegis.policy_attestations.id;


--
-- Name: policy_mappings; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.policy_mappings (
    id integer NOT NULL,
    policy_id integer,
    objective_id integer,
    notes text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.policy_mappings FORCE ROW LEVEL SECURITY;


--
-- Name: policy_mappings_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.policy_mappings_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: policy_mappings_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.policy_mappings_id_seq OWNED BY aegis.policy_mappings.id;


--
-- Name: policy_reviews; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.policy_reviews (
    id integer NOT NULL,
    policy_id integer,
    reviewer_id integer,
    scheduled_date date,
    completed_date date,
    status character varying(50) DEFAULT 'pending'::character varying NOT NULL,
    outcome character varying(50),
    notes text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.policy_reviews FORCE ROW LEVEL SECURITY;


--
-- Name: policy_reviews_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.policy_reviews_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: policy_reviews_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.policy_reviews_id_seq OWNED BY aegis.policy_reviews.id;


--
-- Name: policy_versions; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.policy_versions (
    id integer NOT NULL,
    policy_id integer,
    version character varying(50) NOT NULL,
    content text,
    change_summary text,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.policy_versions FORCE ROW LEVEL SECURITY;


--
-- Name: policy_versions_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.policy_versions_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: policy_versions_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.policy_versions_id_seq OWNED BY aegis.policy_versions.id;


--
-- Name: privacy_records; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.privacy_records (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    controller_name character varying(255),
    processor_name character varying(255),
    purpose text,
    legal_basis character varying(50),
    data_subject_categories text,
    data_categories text,
    recipients text,
    third_country_transfers text,
    retention_period character varying(255),
    security_measures text,
    dpia_required boolean DEFAULT false,
    dpia_completed boolean DEFAULT false,
    dpia_date date,
    status character varying(20) DEFAULT 'active'::character varying,
    created_by integer,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now(),
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.privacy_records FORCE ROW LEVEL SECURITY;


--
-- Name: privacy_records_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.privacy_records_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: privacy_records_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.privacy_records_id_seq OWNED BY aegis.privacy_records.id;


--
-- Name: questionnaire_answers; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.questionnaire_answers (
    id integer NOT NULL,
    response_id integer NOT NULL,
    question_id integer NOT NULL,
    answer_text text,
    score numeric(6,2),
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.questionnaire_answers FORCE ROW LEVEL SECURITY;


--
-- Name: questionnaire_answers_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.questionnaire_answers_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: questionnaire_answers_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.questionnaire_answers_id_seq OWNED BY aegis.questionnaire_answers.id;


--
-- Name: questionnaire_assignments; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.questionnaire_assignments (
    id integer NOT NULL,
    questionnaire_id integer NOT NULL,
    entity_type character varying(100),
    entity_id integer,
    assigned_to integer,
    due_date date,
    status character varying(50) DEFAULT 'pending'::character varying NOT NULL,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.questionnaire_assignments FORCE ROW LEVEL SECURITY;


--
-- Name: questionnaire_assignments_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.questionnaire_assignments_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: questionnaire_assignments_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.questionnaire_assignments_id_seq OWNED BY aegis.questionnaire_assignments.id;


--
-- Name: questionnaire_questions; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.questionnaire_questions (
    id integer NOT NULL,
    questionnaire_id integer NOT NULL,
    section character varying(255) DEFAULT 'General'::character varying NOT NULL,
    question_text text NOT NULL,
    question_type character varying(50) DEFAULT 'text'::character varying NOT NULL,
    options jsonb,
    weight smallint DEFAULT 1 NOT NULL,
    is_required boolean DEFAULT true NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.questionnaire_questions FORCE ROW LEVEL SECURITY;


--
-- Name: questionnaire_questions_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.questionnaire_questions_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: questionnaire_questions_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.questionnaire_questions_id_seq OWNED BY aegis.questionnaire_questions.id;


--
-- Name: questionnaire_responses; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.questionnaire_responses (
    id integer NOT NULL,
    assignment_id integer NOT NULL,
    submitted_by integer,
    submitted_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    total_score numeric(6,2),
    max_score numeric(6,2),
    reviewer_notes text,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.questionnaire_responses FORCE ROW LEVEL SECURITY;


--
-- Name: questionnaire_responses_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.questionnaire_responses_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: questionnaire_responses_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.questionnaire_responses_id_seq OWNED BY aegis.questionnaire_responses.id;


--
-- Name: questionnaires; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.questionnaires (
    id integer NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    entity_type character varying(100) DEFAULT 'general'::character varying NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.questionnaires FORCE ROW LEVEL SECURITY;


--
-- Name: questionnaires_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.questionnaires_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: questionnaires_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.questionnaires_id_seq OWNED BY aegis.questionnaires.id;


--
-- Name: raci_assignments; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.raci_assignments (
    id integer NOT NULL,
    package_id integer NOT NULL,
    objective_id integer NOT NULL,
    user_id integer NOT NULL,
    raci_role character varying(20) NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.raci_assignments FORCE ROW LEVEL SECURITY;


--
-- Name: raci_assignments_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.raci_assignments_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: raci_assignments_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.raci_assignments_id_seq OWNED BY aegis.raci_assignments.id;


--
-- Name: rate_limits; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.rate_limits (
    key character varying(255) NOT NULL,
    attempts integer DEFAULT 0 NOT NULL,
    window_start timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    blocked_until timestamp without time zone
);


--
-- Name: report_schedule_logs; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.report_schedule_logs (
    id integer NOT NULL,
    schedule_id integer NOT NULL,
    sent_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    recipients jsonb,
    status character varying(50) DEFAULT 'sent'::character varying NOT NULL,
    error text
);


--
-- Name: report_schedule_logs_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.report_schedule_logs_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: report_schedule_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.report_schedule_logs_id_seq OWNED BY aegis.report_schedule_logs.id;


--
-- Name: report_schedules; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.report_schedules (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    report_type character varying(100) NOT NULL,
    frequency character varying(50) DEFAULT 'weekly'::character varying NOT NULL,
    day_of_week integer DEFAULT 1,
    day_of_month integer DEFAULT 1,
    send_time time without time zone DEFAULT '08:00:00'::time without time zone NOT NULL,
    recipients jsonb DEFAULT '[]'::jsonb NOT NULL,
    filters jsonb DEFAULT '{}'::jsonb NOT NULL,
    format character varying(10) DEFAULT 'html'::character varying NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    last_sent_at timestamp without time zone,
    next_send_at timestamp without time zone,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT report_schedules_format_check CHECK (((format)::text = ANY ((ARRAY['html'::character varying, 'csv'::character varying, 'both'::character varying])::text[]))),
    CONSTRAINT report_schedules_frequency_check CHECK (((frequency)::text = ANY ((ARRAY['daily'::character varying, 'weekly'::character varying, 'monthly'::character varying, 'quarterly'::character varying])::text[])))
);


--
-- Name: report_schedules_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.report_schedules_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: report_schedules_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.report_schedules_id_seq OWNED BY aegis.report_schedules.id;


--
-- Name: risk_acceptances; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.risk_acceptances (
    id integer NOT NULL,
    risk_id integer NOT NULL,
    accepted_by integer NOT NULL,
    acceptance_reason text NOT NULL,
    conditions text,
    valid_until date NOT NULL,
    status character varying(20) DEFAULT 'active'::character varying NOT NULL,
    risk_score_at_acceptance integer,
    risk_level_at_acceptance character varying(20),
    renewal_required boolean DEFAULT false NOT NULL,
    renewed_from integer,
    revoked_by integer,
    revoked_at timestamp without time zone,
    revocation_reason text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL,
    CONSTRAINT risk_acceptances_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'expired'::character varying, 'revoked'::character varying, 'superseded'::character varying])::text[])))
);

ALTER TABLE ONLY aegis.risk_acceptances FORCE ROW LEVEL SECURITY;


--
-- Name: risk_acceptances_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.risk_acceptances_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: risk_acceptances_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.risk_acceptances_id_seq OWNED BY aegis.risk_acceptances.id;


--
-- Name: risk_appetite; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.risk_appetite (
    id integer NOT NULL,
    category character varying(100) NOT NULL,
    appetite character varying(20) NOT NULL,
    statement text NOT NULL,
    max_score integer,
    updated_by integer,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    amber_threshold integer,
    red_threshold integer,
    CONSTRAINT risk_appetite_appetite_check CHECK (((appetite)::text = ANY ((ARRAY['zero'::character varying, 'low'::character varying, 'moderate'::character varying, 'high'::character varying])::text[])))
);


--
-- Name: COLUMN risk_appetite.amber_threshold; Type: COMMENT; Schema: aegis; Owner: -
--

COMMENT ON COLUMN aegis.risk_appetite.amber_threshold IS 'Score at or above which risk shows amber (warning) on heat maps';


--
-- Name: COLUMN risk_appetite.red_threshold; Type: COMMENT; Schema: aegis; Owner: -
--

COMMENT ON COLUMN aegis.risk_appetite.red_threshold IS 'Score at or above which risk shows red (critical) on heat maps';


--
-- Name: risk_appetite_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.risk_appetite_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: risk_appetite_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.risk_appetite_id_seq OWNED BY aegis.risk_appetite.id;


--
-- Name: risk_bowtie_barriers; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.risk_bowtie_barriers (
    id integer NOT NULL,
    risk_id integer NOT NULL,
    side character varying(10) NOT NULL,
    description text NOT NULL,
    barrier_type character varying(30) DEFAULT 'control'::character varying NOT NULL,
    effectiveness character varying(20) DEFAULT 'partial'::character varying NOT NULL,
    control_implementation_id integer,
    sort_order integer DEFAULT 0 NOT NULL,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL,
    CONSTRAINT risk_bowtie_barriers_barrier_type_check CHECK (((barrier_type)::text = ANY ((ARRAY['control'::character varying, 'procedure'::character varying, 'training'::character varying, 'technology'::character varying, 'monitoring'::character varying])::text[]))),
    CONSTRAINT risk_bowtie_barriers_effectiveness_check CHECK (((effectiveness)::text = ANY ((ARRAY['degraded'::character varying, 'partial'::character varying, 'substantial'::character varying, 'full'::character varying])::text[]))),
    CONSTRAINT risk_bowtie_barriers_side_check CHECK (((side)::text = ANY ((ARRAY['left'::character varying, 'right'::character varying])::text[])))
);

ALTER TABLE ONLY aegis.risk_bowtie_barriers FORCE ROW LEVEL SECURITY;


--
-- Name: risk_bowtie_barriers_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.risk_bowtie_barriers_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: risk_bowtie_barriers_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.risk_bowtie_barriers_id_seq OWNED BY aegis.risk_bowtie_barriers.id;


--
-- Name: risk_bowtie_causes; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.risk_bowtie_causes (
    id integer NOT NULL,
    risk_id integer NOT NULL,
    description text NOT NULL,
    cause_type character varying(30) DEFAULT 'threat'::character varying NOT NULL,
    likelihood_contribution character varying(10) DEFAULT 'medium'::character varying NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL,
    CONSTRAINT risk_bowtie_causes_cause_type_check CHECK (((cause_type)::text = ANY ((ARRAY['threat'::character varying, 'vulnerability'::character varying, 'hazard'::character varying, 'event'::character varying])::text[]))),
    CONSTRAINT risk_bowtie_causes_likelihood_contribution_check CHECK (((likelihood_contribution)::text = ANY ((ARRAY['low'::character varying, 'medium'::character varying, 'high'::character varying])::text[])))
);

ALTER TABLE ONLY aegis.risk_bowtie_causes FORCE ROW LEVEL SECURITY;


--
-- Name: risk_bowtie_causes_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.risk_bowtie_causes_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: risk_bowtie_causes_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.risk_bowtie_causes_id_seq OWNED BY aegis.risk_bowtie_causes.id;


--
-- Name: risk_bowtie_consequences; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.risk_bowtie_consequences (
    id integer NOT NULL,
    risk_id integer NOT NULL,
    description text NOT NULL,
    consequence_type character varying(30) DEFAULT 'impact'::character varying NOT NULL,
    severity character varying(20) DEFAULT 'medium'::character varying NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL,
    CONSTRAINT risk_bowtie_consequences_consequence_type_check CHECK (((consequence_type)::text = ANY ((ARRAY['financial'::character varying, 'operational'::character varying, 'reputational'::character varying, 'legal'::character varying, 'safety'::character varying, 'impact'::character varying])::text[]))),
    CONSTRAINT risk_bowtie_consequences_severity_check CHECK (((severity)::text = ANY ((ARRAY['low'::character varying, 'medium'::character varying, 'high'::character varying, 'critical'::character varying])::text[])))
);

ALTER TABLE ONLY aegis.risk_bowtie_consequences FORCE ROW LEVEL SECURITY;


--
-- Name: risk_bowtie_consequences_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.risk_bowtie_consequences_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: risk_bowtie_consequences_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.risk_bowtie_consequences_id_seq OWNED BY aegis.risk_bowtie_consequences.id;


--
-- Name: risk_categories; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.risk_categories (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    color character varying(50) DEFAULT '#6366f1'::character varying,
    sort_order integer DEFAULT 0,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: risk_categories_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.risk_categories_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: risk_categories_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.risk_categories_id_seq OWNED BY aegis.risk_categories.id;


--
-- Name: risk_control_links; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.risk_control_links (
    id integer NOT NULL,
    risk_id integer NOT NULL,
    control_implementation_id integer NOT NULL,
    effectiveness character varying(20) DEFAULT 'partial'::character varying NOT NULL,
    notes text,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL,
    CONSTRAINT risk_control_links_effectiveness_check CHECK (((effectiveness)::text = ANY ((ARRAY['none'::character varying, 'partial'::character varying, 'substantial'::character varying, 'full'::character varying])::text[])))
);

ALTER TABLE ONLY aegis.risk_control_links FORCE ROW LEVEL SECURITY;


--
-- Name: risk_control_links_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.risk_control_links_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: risk_control_links_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.risk_control_links_id_seq OWNED BY aegis.risk_control_links.id;


--
-- Name: risk_exceptions; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.risk_exceptions (
    id integer NOT NULL,
    risk_id integer NOT NULL,
    requested_by integer NOT NULL,
    approved_by integer,
    status character varying(30) DEFAULT 'pending'::character varying NOT NULL,
    exception_type character varying(30) DEFAULT 'accept'::character varying NOT NULL,
    rationale text NOT NULL,
    compensating_controls text,
    residual_risk_acknowledged boolean DEFAULT false NOT NULL,
    expiry_date date,
    approved_at timestamp without time zone,
    rejected_at timestamp without time zone,
    rejection_reason text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.risk_exceptions FORCE ROW LEVEL SECURITY;


--
-- Name: risk_exceptions_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.risk_exceptions_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: risk_exceptions_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.risk_exceptions_id_seq OWNED BY aegis.risk_exceptions.id;


--
-- Name: risk_matrix_config; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.risk_matrix_config (
    id integer NOT NULL,
    name character varying(255) DEFAULT 'Default'::character varying NOT NULL,
    rows integer DEFAULT 5 NOT NULL,
    cols integer DEFAULT 5 NOT NULL,
    row_label character varying(50) DEFAULT 'Likelihood'::character varying NOT NULL,
    col_label character varying(50) DEFAULT 'Impact'::character varying NOT NULL,
    row_labels jsonb DEFAULT '["Rare", "Unlikely", "Possible", "Likely", "Almost Certain"]'::jsonb NOT NULL,
    col_labels jsonb DEFAULT '["Negligible", "Minor", "Moderate", "Major", "Critical"]'::jsonb NOT NULL,
    thresholds jsonb DEFAULT '{"low": 4, "high": 14, "medium": 9, "critical": 25}'::jsonb NOT NULL,
    colors jsonb DEFAULT '{"low": "#22c55e", "high": "#f97316", "medium": "#f59e0b", "critical": "#ef4444"}'::jsonb NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    cells jsonb DEFAULT '{}'::jsonb NOT NULL,
    description text
);


--
-- Name: risk_matrix_config_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.risk_matrix_config_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: risk_matrix_config_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.risk_matrix_config_id_seq OWNED BY aegis.risk_matrix_config.id;


--
-- Name: risk_related_links; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.risk_related_links (
    id integer NOT NULL,
    risk_id integer NOT NULL,
    related_id integer NOT NULL,
    link_type character varying(50) DEFAULT 'related'::character varying NOT NULL,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL,
    CONSTRAINT risk_related_links_link_type_check CHECK (((link_type)::text = ANY ((ARRAY['related'::character varying, 'causes'::character varying, 'caused_by'::character varying, 'aggregates'::character varying])::text[])))
);

ALTER TABLE ONLY aegis.risk_related_links FORCE ROW LEVEL SECURITY;


--
-- Name: risk_related_links_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.risk_related_links_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: risk_related_links_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.risk_related_links_id_seq OWNED BY aegis.risk_related_links.id;


--
-- Name: risk_review_items; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.risk_review_items (
    id integer NOT NULL,
    review_id integer NOT NULL,
    risk_id integer NOT NULL,
    status character varying(30) DEFAULT 'pending'::character varying NOT NULL,
    score_confirmed boolean,
    new_likelihood integer,
    new_impact integer,
    treatment_adequate boolean,
    action_required text,
    reviewer_notes text,
    reviewed_by integer,
    reviewed_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL,
    CONSTRAINT risk_review_items_new_impact_check CHECK (((new_impact >= 1) AND (new_impact <= 5))),
    CONSTRAINT risk_review_items_new_likelihood_check CHECK (((new_likelihood >= 1) AND (new_likelihood <= 5))),
    CONSTRAINT risk_review_items_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'reviewed'::character varying, 'escalated'::character varying, 'deferred'::character varying, 'not_applicable'::character varying])::text[])))
);

ALTER TABLE ONLY aegis.risk_review_items FORCE ROW LEVEL SECURITY;


--
-- Name: risk_review_items_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.risk_review_items_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: risk_review_items_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.risk_review_items_id_seq OWNED BY aegis.risk_review_items.id;


--
-- Name: risk_reviews; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.risk_reviews (
    id integer NOT NULL,
    title character varying(500) NOT NULL,
    review_type character varying(50) DEFAULT 'periodic'::character varying NOT NULL,
    scheduled_date date NOT NULL,
    completed_date date,
    status character varying(30) DEFAULT 'planned'::character varying NOT NULL,
    lead_reviewer_id integer,
    scope_description text,
    scope_filter jsonb DEFAULT '{}'::jsonb NOT NULL,
    total_risks integer DEFAULT 0 NOT NULL,
    reviewed_count integer DEFAULT 0 NOT NULL,
    escalated_count integer DEFAULT 0 NOT NULL,
    conclusion text,
    sign_off_by integer,
    sign_off_at timestamp without time zone,
    sign_off_notes text,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL,
    CONSTRAINT risk_reviews_review_type_check CHECK (((review_type)::text = ANY ((ARRAY['periodic'::character varying, 'triggered'::character varying, 'ad_hoc'::character varying, 'board'::character varying])::text[]))),
    CONSTRAINT risk_reviews_status_check CHECK (((status)::text = ANY ((ARRAY['planned'::character varying, 'in_progress'::character varying, 'completed'::character varying, 'cancelled'::character varying])::text[])))
);

ALTER TABLE ONLY aegis.risk_reviews FORCE ROW LEVEL SECURITY;


--
-- Name: risk_reviews_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.risk_reviews_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: risk_reviews_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.risk_reviews_id_seq OWNED BY aegis.risk_reviews.id;


--
-- Name: risk_scenarios; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.risk_scenarios (
    id integer NOT NULL,
    risk_id integer NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    scenario_type character varying(30) DEFAULT 'stress'::character varying NOT NULL,
    likelihood_multiplier numeric(4,2) DEFAULT 1.0 NOT NULL,
    impact_multiplier numeric(4,2) DEFAULT 1.0 NOT NULL,
    scenario_likelihood integer,
    scenario_impact integer,
    scenario_score integer,
    financial_impact_est numeric(15,2),
    probability numeric(5,2),
    assumptions text,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL,
    CONSTRAINT risk_scenarios_scenario_impact_check CHECK (((scenario_impact >= 1) AND (scenario_impact <= 5))),
    CONSTRAINT risk_scenarios_scenario_likelihood_check CHECK (((scenario_likelihood >= 1) AND (scenario_likelihood <= 5))),
    CONSTRAINT risk_scenarios_scenario_type_check CHECK (((scenario_type)::text = ANY ((ARRAY['stress'::character varying, 'base'::character varying, 'optimistic'::character varying, 'catastrophic'::character varying, 'regulatory'::character varying])::text[])))
);

ALTER TABLE ONLY aegis.risk_scenarios FORCE ROW LEVEL SECURITY;


--
-- Name: risk_scenarios_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.risk_scenarios_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: risk_scenarios_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.risk_scenarios_id_seq OWNED BY aegis.risk_scenarios.id;


--
-- Name: risk_score_history; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.risk_score_history (
    id integer NOT NULL,
    risk_id integer NOT NULL,
    likelihood integer NOT NULL,
    impact integer NOT NULL,
    score integer NOT NULL,
    residual_likelihood integer,
    residual_impact integer,
    residual_score integer,
    status character varying(50),
    treatment_strategies jsonb,
    changed_by integer,
    note text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.risk_score_history FORCE ROW LEVEL SECURITY;


--
-- Name: risk_score_history_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.risk_score_history_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: risk_score_history_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.risk_score_history_id_seq OWNED BY aegis.risk_score_history.id;


--
-- Name: risk_treatments; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.risk_treatments (
    id integer NOT NULL,
    risk_id integer,
    treatment_type character varying(50) NOT NULL,
    description text NOT NULL,
    cost_estimate numeric(12,2),
    effort character varying(50),
    due_date date,
    status character varying(50) DEFAULT 'planned'::character varying NOT NULL,
    owner_id integer,
    completion_date date,
    completion_notes text,
    notes text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.risk_treatments FORCE ROW LEVEL SECURITY;


--
-- Name: risk_treatments_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.risk_treatments_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: risk_treatments_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.risk_treatments_id_seq OWNED BY aegis.risk_treatments.id;


--
-- Name: risks; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.risks (
    id integer NOT NULL,
    title character varying(500) NOT NULL,
    risk_id character varying(100),
    description text,
    category_id integer,
    likelihood integer DEFAULT 3 NOT NULL,
    impact integer DEFAULT 3 NOT NULL,
    inherent_score integer DEFAULT 0 NOT NULL,
    residual_likelihood integer,
    residual_impact integer,
    residual_score integer DEFAULT 0 NOT NULL,
    status character varying(50) DEFAULT 'open'::character varying NOT NULL,
    treatment_type character varying(50),
    treatment_strategies jsonb DEFAULT '[]'::jsonb NOT NULL,
    treatment_description text,
    owner_id integer,
    review_date date,
    identified_date date DEFAULT CURRENT_DATE NOT NULL,
    tags jsonb DEFAULT '[]'::jsonb,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    velocity integer DEFAULT 3,
    proximity character varying(20) DEFAULT 'medium_term'::character varying,
    financial_min numeric(15,2),
    financial_likely numeric(15,2),
    financial_max numeric(15,2),
    financial_currency character varying(3) DEFAULT 'USD'::character varying,
    parent_risk_id integer,
    assessment_status character varying(20) DEFAULT 'draft'::character varying NOT NULL,
    reviewed_by integer,
    reviewed_at timestamp without time zone,
    review_notes text,
    risk_source character varying(50),
    confidence character varying(10) DEFAULT 'medium'::character varying,
    target_likelihood integer,
    target_impact integer,
    target_score integer DEFAULT 0 NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL,
    source character varying(100),
    source_external_id character varying(500),
    CONSTRAINT risks_assessment_status_check CHECK (((assessment_status)::text = ANY ((ARRAY['draft'::character varying, 'pending_review'::character varying, 'approved'::character varying])::text[]))),
    CONSTRAINT risks_confidence_check CHECK (((confidence)::text = ANY ((ARRAY['low'::character varying, 'medium'::character varying, 'high'::character varying])::text[]))),
    CONSTRAINT risks_impact_check CHECK (((impact >= 1) AND (impact <= 5))),
    CONSTRAINT risks_likelihood_check CHECK (((likelihood >= 1) AND (likelihood <= 5))),
    CONSTRAINT risks_proximity_check CHECK (((proximity)::text = ANY ((ARRAY['immediate'::character varying, 'short_term'::character varying, 'medium_term'::character varying, 'long_term'::character varying])::text[]))),
    CONSTRAINT risks_residual_impact_check CHECK (((residual_impact >= 1) AND (residual_impact <= 5))),
    CONSTRAINT risks_residual_likelihood_check CHECK (((residual_likelihood >= 1) AND (residual_likelihood <= 5))),
    CONSTRAINT risks_risk_source_check CHECK ((((risk_source)::text = ANY ((ARRAY['strategic'::character varying, 'operational'::character varying, 'financial'::character varying, 'compliance'::character varying, 'technology'::character varying, 'reputational'::character varying, 'external'::character varying, 'people'::character varying, 'project'::character varying])::text[])) OR (risk_source IS NULL))),
    CONSTRAINT risks_target_impact_check CHECK (((target_impact >= 1) AND (target_impact <= 5))),
    CONSTRAINT risks_target_likelihood_check CHECK (((target_likelihood >= 1) AND (target_likelihood <= 5))),
    CONSTRAINT risks_velocity_check CHECK (((velocity >= 1) AND (velocity <= 5)))
);

ALTER TABLE ONLY aegis.risks FORCE ROW LEVEL SECURITY;


--
-- Name: risks_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.risks_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: risks_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.risks_id_seq OWNED BY aegis.risks.id;


--
-- Name: settings; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.settings (
    key character varying(255) NOT NULL,
    value text,
    type character varying(50) DEFAULT 'string'::character varying,
    description text,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: shared_responsibility; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.shared_responsibility (
    id integer NOT NULL,
    package_id integer NOT NULL,
    objective_id integer NOT NULL,
    responsibility character varying(20) DEFAULT 'customer'::character varying,
    provider_name character varying(255),
    customer_notes text,
    provider_notes text,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.shared_responsibility FORCE ROW LEVEL SECURITY;


--
-- Name: shared_responsibility_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.shared_responsibility_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: shared_responsibility_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.shared_responsibility_id_seq OWNED BY aegis.shared_responsibility.id;


--
-- Name: ssp_control_statements; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.ssp_control_statements (
    id integer NOT NULL,
    ssp_id integer NOT NULL,
    objective_id integer NOT NULL,
    implementation_statement text,
    responsible_roles text,
    objective_responses text,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.ssp_control_statements FORCE ROW LEVEL SECURITY;


--
-- Name: ssp_control_statements_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.ssp_control_statements_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ssp_control_statements_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.ssp_control_statements_id_seq OWNED BY aegis.ssp_control_statements.id;


--
-- Name: ssp_packages; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.ssp_packages (
    id integer NOT NULL,
    ssp_id integer NOT NULL,
    package_id integer NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.ssp_packages FORCE ROW LEVEL SECURITY;


--
-- Name: ssp_packages_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.ssp_packages_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ssp_packages_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.ssp_packages_id_seq OWNED BY aegis.ssp_packages.id;


--
-- Name: ssp_plans; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.ssp_plans (
    id integer NOT NULL,
    title character varying(255) NOT NULL,
    system_name character varying(255),
    system_description text,
    system_owner character varying(255),
    system_owner_email character varying(255),
    information_owner character varying(255),
    authorizing_official character varying(255),
    authorization_boundary text,
    network_architecture text,
    data_flow text,
    operational_status character varying(50) DEFAULT 'operational'::character varying,
    system_type character varying(50) DEFAULT 'major_application'::character varying,
    confidentiality_impact character varying(20) DEFAULT 'moderate'::character varying,
    integrity_impact character varying(20) DEFAULT 'moderate'::character varying,
    availability_impact character varying(20) DEFAULT 'moderate'::character varying,
    authorization_date date,
    next_review_date date,
    created_by integer,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now(),
    version character varying(20) DEFAULT '1.0'::character varying,
    revision integer DEFAULT 0,
    authorizing_signature character varying(255),
    signature_date date,
    network_arch_filename character varying(500),
    network_arch_data bytea,
    data_flow_filename character varying(500),
    data_flow_data bytea,
    company_name character varying(255),
    duns_number character varying(50),
    cage_code character varying(50),
    framework character varying(255),
    assessment_scope text,
    presentation_mode character varying(50) DEFAULT 'standard'::character varying,
    approval_status character varying(50),
    approval_date date,
    approval_notes text,
    approver_name character varying(255),
    approver_title character varying(255),
    certifying_official_name character varying(255),
    certifying_official_title character varying(255),
    certification_date date,
    certification_statement text,
    boundary_description text,
    info_systems_apps text,
    endpoints_user_devices text,
    servers_storage text,
    physical_security text,
    access_control_auth text,
    general_system_purpose text,
    topology_description text,
    maintenance_info text,
    system_details text,
    team_contacts jsonb DEFAULT '[]'::jsonb,
    contracts jsonb DEFAULT '[]'::jsonb,
    data_inventory jsonb DEFAULT '[]'::jsonb,
    hardware_inventory jsonb DEFAULT '[]'::jsonb,
    software_inventory jsonb DEFAULT '[]'::jsonb,
    network_devices jsonb DEFAULT '[]'::jsonb,
    other_connected_systems jsonb DEFAULT '[]'::jsonb,
    server_inventory jsonb DEFAULT '[]'::jsonb,
    user_device_types jsonb DEFAULT '[]'::jsonb,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.ssp_plans FORCE ROW LEVEL SECURITY;


--
-- Name: ssp_plans_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.ssp_plans_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ssp_plans_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.ssp_plans_id_seq OWNED BY aegis.ssp_plans.id;


--
-- Name: standards; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.standards (
    id integer NOT NULL,
    code character varying(100) NOT NULL,
    name character varying(255) NOT NULL,
    version character varying(50),
    description text,
    category character varying(100),
    authority character varying(255),
    url character varying(500),
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: standards_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.standards_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: standards_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.standards_id_seq OWNED BY aegis.standards.id;


--
-- Name: tags; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.tags (
    id integer NOT NULL,
    name character varying(50) NOT NULL,
    color character varying(7) DEFAULT '#6366f1'::character varying NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: tags_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.tags_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: tags_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.tags_id_seq OWNED BY aegis.tags.id;


--
-- Name: tenants; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.tenants (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    slug character varying(100) NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: tenants_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.tenants_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: tenants_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.tenants_id_seq OWNED BY aegis.tenants.id;


--
-- Name: threat_risk_links; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.threat_risk_links (
    id integer NOT NULL,
    threat_id integer NOT NULL,
    risk_id integer NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.threat_risk_links FORCE ROW LEVEL SECURITY;


--
-- Name: threat_risk_links_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.threat_risk_links_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: threat_risk_links_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.threat_risk_links_id_seq OWNED BY aegis.threat_risk_links.id;


--
-- Name: threats; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.threats (
    id integer NOT NULL,
    title character varying(255) NOT NULL,
    category character varying(30) DEFAULT 'technology'::character varying NOT NULL,
    description text,
    likelihood integer,
    impact integer,
    status character varying(20) DEFAULT 'active'::character varying NOT NULL,
    source character varying(255),
    mitigations text,
    owner_id integer,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    threat_number character varying(20),
    tenant_id bigint DEFAULT 1 NOT NULL,
    CONSTRAINT threats_category_check CHECK (((category)::text = ANY ((ARRAY['people'::character varying, 'process'::character varying, 'technology'::character varying, 'natural'::character varying, 'regulatory'::character varying, 'financial'::character varying])::text[]))),
    CONSTRAINT threats_impact_check CHECK (((impact >= 1) AND (impact <= 5))),
    CONSTRAINT threats_likelihood_check CHECK (((likelihood >= 1) AND (likelihood <= 5))),
    CONSTRAINT threats_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'mitigated'::character varying, 'accepted'::character varying, 'retired'::character varying])::text[])))
);

ALTER TABLE ONLY aegis.threats FORCE ROW LEVEL SECURITY;


--
-- Name: threats_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.threats_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: threats_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.threats_id_seq OWNED BY aegis.threats.id;


--
-- Name: treatment_milestones; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.treatment_milestones (
    id integer NOT NULL,
    plan_id integer NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    due_date date,
    completed_at timestamp without time zone,
    completed_by integer,
    sort_order integer DEFAULT 0 NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL
);

ALTER TABLE ONLY aegis.treatment_milestones FORCE ROW LEVEL SECURITY;


--
-- Name: treatment_milestones_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.treatment_milestones_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: treatment_milestones_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.treatment_milestones_id_seq OWNED BY aegis.treatment_milestones.id;


--
-- Name: treatment_plans; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.treatment_plans (
    id integer NOT NULL,
    risk_id integer NOT NULL,
    title character varying(255) NOT NULL,
    strategy character varying(20) DEFAULT 'mitigate'::character varying NOT NULL,
    target_score integer,
    owner_id integer,
    start_date date,
    target_date date,
    status character varying(20) DEFAULT 'draft'::character varying NOT NULL,
    description text,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    plan_code character varying(20),
    tenant_id bigint DEFAULT 1 NOT NULL,
    CONSTRAINT treatment_plans_status_check CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'active'::character varying, 'completed'::character varying, 'cancelled'::character varying])::text[]))),
    CONSTRAINT treatment_plans_strategy_check CHECK (((strategy)::text = ANY ((ARRAY['mitigate'::character varying, 'transfer'::character varying, 'accept'::character varying, 'avoid'::character varying])::text[])))
);

ALTER TABLE ONLY aegis.treatment_plans FORCE ROW LEVEL SECURITY;


--
-- Name: treatment_plans_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.treatment_plans_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: treatment_plans_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.treatment_plans_id_seq OWNED BY aegis.treatment_plans.id;


--
-- Name: user_notification_prefs; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.user_notification_prefs (
    id integer NOT NULL,
    user_id integer NOT NULL,
    notification_type character varying(100) NOT NULL,
    enabled boolean DEFAULT true NOT NULL,
    digest_mode character varying(50) DEFAULT 'immediate'::character varying NOT NULL,
    digest_time time without time zone DEFAULT '08:00:00'::time without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT user_notification_prefs_digest_mode_check CHECK (((digest_mode)::text = ANY ((ARRAY['immediate'::character varying, 'daily'::character varying, 'weekly'::character varying])::text[])))
);


--
-- Name: user_notification_prefs_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.user_notification_prefs_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: user_notification_prefs_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.user_notification_prefs_id_seq OWNED BY aegis.user_notification_prefs.id;


--
-- Name: user_permissions; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.user_permissions (
    id integer NOT NULL,
    user_id integer NOT NULL,
    module character varying(100) NOT NULL,
    permission character varying(50) NOT NULL,
    granted_by integer,
    granted_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: user_permissions_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.user_permissions_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: user_permissions_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.user_permissions_id_seq OWNED BY aegis.user_permissions.id;


--
-- Name: users; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.users (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    password_hash character varying(255) NOT NULL,
    role character varying(50) DEFAULT 'viewer'::character varying NOT NULL,
    department character varying(255),
    job_title character varying(255),
    is_active boolean DEFAULT true NOT NULL,
    is_platform_admin boolean DEFAULT false NOT NULL,
    last_login timestamp without time zone,
    email_verified_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL,
    mfa_secret character varying(64),
    mfa_enabled boolean DEFAULT false,
    sso_provider character varying(100),
    sso_subject character varying(500),
    sso_only boolean DEFAULT false NOT NULL
);

ALTER TABLE ONLY aegis.users FORCE ROW LEVEL SECURITY;


--
-- Name: users_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.users_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.users_id_seq OWNED BY aegis.users.id;


--
-- Name: vendor_assessments; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.vendor_assessments (
    id integer NOT NULL,
    vendor_id integer NOT NULL,
    assessment_type character varying(50) DEFAULT 'security'::character varying NOT NULL,
    status character varying(20) DEFAULT 'planned'::character varying NOT NULL,
    assessed_by integer,
    scheduled_date date,
    completed_date date,
    score integer,
    findings text,
    recommendations text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL,
    CONSTRAINT vendor_assessments_score_check CHECK (((score >= 0) AND (score <= 100))),
    CONSTRAINT vendor_assessments_status_check CHECK (((status)::text = ANY ((ARRAY['planned'::character varying, 'in_progress'::character varying, 'completed'::character varying, 'cancelled'::character varying])::text[])))
);

ALTER TABLE ONLY aegis.vendor_assessments FORCE ROW LEVEL SECURITY;


--
-- Name: vendor_assessments_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.vendor_assessments_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: vendor_assessments_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.vendor_assessments_id_seq OWNED BY aegis.vendor_assessments.id;


--
-- Name: vendor_contracts; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.vendor_contracts (
    id integer NOT NULL,
    vendor_id integer NOT NULL,
    title character varying(255) NOT NULL,
    contract_number character varying(100),
    status character varying(20) DEFAULT 'active'::character varying NOT NULL,
    value numeric(15,2),
    currency character varying(3) DEFAULT 'USD'::character varying NOT NULL,
    start_date date NOT NULL,
    end_date date,
    auto_renewal boolean DEFAULT false NOT NULL,
    renewal_notice_days integer DEFAULT 30,
    description text,
    owner_id integer,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL,
    CONSTRAINT vendor_contracts_status_check CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'active'::character varying, 'expired'::character varying, 'terminated'::character varying])::text[])))
);

ALTER TABLE ONLY aegis.vendor_contracts FORCE ROW LEVEL SECURITY;


--
-- Name: vendor_contracts_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.vendor_contracts_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: vendor_contracts_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.vendor_contracts_id_seq OWNED BY aegis.vendor_contracts.id;


--
-- Name: vendor_portal_tokens; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.vendor_portal_tokens (
    id integer NOT NULL,
    vendor_id integer NOT NULL,
    token_hash character varying(64) NOT NULL,
    title character varying(255) DEFAULT 'Vendor Self-Assessment'::character varying NOT NULL,
    questions jsonb DEFAULT '[]'::jsonb NOT NULL,
    expires_at timestamp without time zone NOT NULL,
    used_at timestamp without time zone,
    response jsonb,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: vendor_portal_tokens_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.vendor_portal_tokens_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: vendor_portal_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.vendor_portal_tokens_id_seq OWNED BY aegis.vendor_portal_tokens.id;


--
-- Name: vendors; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.vendors (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    category character varying(100),
    status character varying(20) DEFAULT 'active'::character varying NOT NULL,
    risk_rating character varying(20) DEFAULT 'medium'::character varying,
    contact_name character varying(255),
    contact_email character varying(255),
    contact_phone character varying(50),
    website character varying(255),
    description text,
    notes text,
    owner_id integer,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tenant_id bigint DEFAULT 1 NOT NULL,
    CONSTRAINT vendors_risk_rating_check CHECK (((risk_rating)::text = ANY ((ARRAY['critical'::character varying, 'high'::character varying, 'medium'::character varying, 'low'::character varying])::text[]))),
    CONSTRAINT vendors_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'inactive'::character varying, 'under_review'::character varying])::text[])))
);

ALTER TABLE ONLY aegis.vendors FORCE ROW LEVEL SECURITY;


--
-- Name: vendors_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.vendors_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: vendors_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.vendors_id_seq OWNED BY aegis.vendors.id;


--
-- Name: webhook_deliveries; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.webhook_deliveries (
    id integer NOT NULL,
    endpoint_id integer NOT NULL,
    event_type character varying(100) NOT NULL,
    payload jsonb NOT NULL,
    status character varying(50) DEFAULT 'pending'::character varying NOT NULL,
    attempts smallint DEFAULT 0 NOT NULL,
    response_code smallint,
    response_body text,
    next_retry_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    delivered_at timestamp without time zone
);


--
-- Name: webhook_deliveries_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.webhook_deliveries_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: webhook_deliveries_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.webhook_deliveries_id_seq OWNED BY aegis.webhook_deliveries.id;


--
-- Name: webhook_endpoints; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.webhook_endpoints (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    url text NOT NULL,
    secret character varying(255),
    event_types jsonb DEFAULT '[]'::jsonb NOT NULL,
    provider character varying(50) DEFAULT 'generic'::character varying NOT NULL,
    custom_headers jsonb DEFAULT '{}'::jsonb NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: webhook_endpoints_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.webhook_endpoints_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: webhook_endpoints_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.webhook_endpoints_id_seq OWNED BY aegis.webhook_endpoints.id;


--
-- Name: workflow_executions; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.workflow_executions (
    id integer NOT NULL,
    workflow_id integer NOT NULL,
    triggered_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    trigger_data jsonb,
    actions_taken jsonb,
    status character varying(50) DEFAULT 'success'::character varying NOT NULL,
    error_message text
);


--
-- Name: workflow_executions_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.workflow_executions_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: workflow_executions_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.workflow_executions_id_seq OWNED BY aegis.workflow_executions.id;


--
-- Name: workflows; Type: TABLE; Schema: aegis; Owner: -
--

CREATE TABLE IF NOT EXISTS aegis.workflows (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    trigger_type character varying(100) NOT NULL,
    trigger_config jsonb DEFAULT '{}'::jsonb NOT NULL,
    actions jsonb DEFAULT '[]'::jsonb NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    last_triggered_at timestamp without time zone,
    cooldown_seconds integer DEFAULT 3600 NOT NULL
);


--
-- Name: workflows_id_seq; Type: SEQUENCE; Schema: aegis; Owner: -
--

CREATE SEQUENCE aegis.workflows_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: workflows_id_seq; Type: SEQUENCE OWNED BY; Schema: aegis; Owner: -
--

ALTER SEQUENCE aegis.workflows_id_seq OWNED BY aegis.workflows.id;


--
-- Name: activity_log id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.activity_log ALTER COLUMN id SET DEFAULT nextval('aegis.activity_log_id_seq'::regclass);


--
-- Name: alert_configs id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.alert_configs ALTER COLUMN id SET DEFAULT nextval('aegis.alert_configs_id_seq'::regclass);


--
-- Name: alerts id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.alerts ALTER COLUMN id SET DEFAULT nextval('aegis.alerts_id_seq'::regclass);


--
-- Name: api_keys id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.api_keys ALTER COLUMN id SET DEFAULT nextval('aegis.api_keys_id_seq'::regclass);


--
-- Name: approval_request_steps id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.approval_request_steps ALTER COLUMN id SET DEFAULT nextval('aegis.approval_request_steps_id_seq'::regclass);


--
-- Name: approval_requests id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.approval_requests ALTER COLUMN id SET DEFAULT nextval('aegis.approval_requests_id_seq'::regclass);


--
-- Name: approval_template_steps id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.approval_template_steps ALTER COLUMN id SET DEFAULT nextval('aegis.approval_template_steps_id_seq'::regclass);


--
-- Name: approval_templates id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.approval_templates ALTER COLUMN id SET DEFAULT nextval('aegis.approval_templates_id_seq'::regclass);


--
-- Name: asset_risk_links id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.asset_risk_links ALTER COLUMN id SET DEFAULT nextval('aegis.asset_risk_links_id_seq'::regclass);


--
-- Name: assets id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.assets ALTER COLUMN id SET DEFAULT nextval('aegis.assets_id_seq'::regclass);


--
-- Name: audit_findings id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.audit_findings ALTER COLUMN id SET DEFAULT nextval('aegis.audit_findings_id_seq'::regclass);


--
-- Name: audit_items id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.audit_items ALTER COLUMN id SET DEFAULT nextval('aegis.audit_items_id_seq'::regclass);


--
-- Name: audit_schedules id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.audit_schedules ALTER COLUMN id SET DEFAULT nextval('aegis.audit_schedules_id_seq'::regclass);


--
-- Name: audits id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.audits ALTER COLUMN id SET DEFAULT nextval('aegis.audits_id_seq'::regclass);


--
-- Name: automation_logs id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.automation_logs ALTER COLUMN id SET DEFAULT nextval('aegis.automation_logs_id_seq'::regclass);


--
-- Name: automation_rules id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.automation_rules ALTER COLUMN id SET DEFAULT nextval('aegis.automation_rules_id_seq'::regclass);


--
-- Name: awareness_assignments id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.awareness_assignments ALTER COLUMN id SET DEFAULT nextval('aegis.awareness_assignments_id_seq'::regclass);


--
-- Name: awareness_programs id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.awareness_programs ALTER COLUMN id SET DEFAULT nextval('aegis.awareness_programs_id_seq'::regclass);


--
-- Name: bcp_exercises id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.bcp_exercises ALTER COLUMN id SET DEFAULT nextval('aegis.bcp_exercises_id_seq'::regclass);


--
-- Name: bcp_plan_sections id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.bcp_plan_sections ALTER COLUMN id SET DEFAULT nextval('aegis.bcp_plan_sections_id_seq'::regclass);


--
-- Name: bcp_plans id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.bcp_plans ALTER COLUMN id SET DEFAULT nextval('aegis.bcp_plans_id_seq'::regclass);


--
-- Name: compliance_objectives id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.compliance_objectives ALTER COLUMN id SET DEFAULT nextval('aegis.compliance_objectives_id_seq'::regclass);


--
-- Name: compliance_packages id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.compliance_packages ALTER COLUMN id SET DEFAULT nextval('aegis.compliance_packages_id_seq'::regclass);


--
-- Name: control_implementations id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.control_implementations ALTER COLUMN id SET DEFAULT nextval('aegis.control_implementations_id_seq'::regclass);


--
-- Name: control_mappings id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.control_mappings ALTER COLUMN id SET DEFAULT nextval('aegis.control_mappings_id_seq'::regclass);


--
-- Name: control_tests id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.control_tests ALTER COLUMN id SET DEFAULT nextval('aegis.control_tests_id_seq'::regclass);


--
-- Name: cui_inventory id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.cui_inventory ALTER COLUMN id SET DEFAULT nextval('aegis.cui_inventory_id_seq'::regclass);


--
-- Name: custom_dashboards id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.custom_dashboards ALTER COLUMN id SET DEFAULT nextval('aegis.custom_dashboards_id_seq'::regclass);


--
-- Name: custom_field_definitions id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.custom_field_definitions ALTER COLUMN id SET DEFAULT nextval('aegis.custom_field_definitions_id_seq'::regclass);


--
-- Name: custom_field_values id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.custom_field_values ALTER COLUMN id SET DEFAULT nextval('aegis.custom_field_values_id_seq'::regclass);


--
-- Name: dashboard_widgets id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.dashboard_widgets ALTER COLUMN id SET DEFAULT nextval('aegis.dashboard_widgets_id_seq'::regclass);


--
-- Name: data_retention_policies id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.data_retention_policies ALTER COLUMN id SET DEFAULT nextval('aegis.data_retention_policies_id_seq'::regclass);


--
-- Name: data_subject_requests id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.data_subject_requests ALTER COLUMN id SET DEFAULT nextval('aegis.data_subject_requests_id_seq'::regclass);


--
-- Name: document_versions id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.document_versions ALTER COLUMN id SET DEFAULT nextval('aegis.document_versions_id_seq'::regclass);


--
-- Name: documents id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.documents ALTER COLUMN id SET DEFAULT nextval('aegis.documents_id_seq'::regclass);


--
-- Name: email_bounces id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.email_bounces ALTER COLUMN id SET DEFAULT nextval('aegis.email_bounces_id_seq'::regclass);


--
-- Name: email_templates id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.email_templates ALTER COLUMN id SET DEFAULT nextval('aegis.email_templates_id_seq'::regclass);


--
-- Name: email_unsubscribes id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.email_unsubscribes ALTER COLUMN id SET DEFAULT nextval('aegis.email_unsubscribes_id_seq'::regclass);


--
-- Name: email_verification_tokens id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.email_verification_tokens ALTER COLUMN id SET DEFAULT nextval('aegis.email_verification_tokens_id_seq'::regclass);


--
-- Name: entity_tags id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.entity_tags ALTER COLUMN id SET DEFAULT nextval('aegis.entity_tags_id_seq'::regclass);


--
-- Name: evidence id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.evidence ALTER COLUMN id SET DEFAULT nextval('aegis.evidence_id_seq'::regclass);


--
-- Name: evidence_files id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.evidence_files ALTER COLUMN id SET DEFAULT nextval('aegis.evidence_files_id_seq'::regclass);


--
-- Name: finding_updates id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.finding_updates ALTER COLUMN id SET DEFAULT nextval('aegis.finding_updates_id_seq'::regclass);


--
-- Name: grc_project_links id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.grc_project_links ALTER COLUMN id SET DEFAULT nextval('aegis.grc_project_links_id_seq'::regclass);


--
-- Name: grc_project_tasks id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.grc_project_tasks ALTER COLUMN id SET DEFAULT nextval('aegis.grc_project_tasks_id_seq'::regclass);


--
-- Name: grc_projects id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.grc_projects ALTER COLUMN id SET DEFAULT nextval('aegis.grc_projects_id_seq'::regclass);


--
-- Name: incident_playbook_runs id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.incident_playbook_runs ALTER COLUMN id SET DEFAULT nextval('aegis.incident_playbook_runs_id_seq'::regclass);


--
-- Name: incident_sla_events id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.incident_sla_events ALTER COLUMN id SET DEFAULT nextval('aegis.incident_sla_events_id_seq'::regclass);


--
-- Name: incident_sla_policies id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.incident_sla_policies ALTER COLUMN id SET DEFAULT nextval('aegis.incident_sla_policies_id_seq'::regclass);


--
-- Name: incident_updates id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.incident_updates ALTER COLUMN id SET DEFAULT nextval('aegis.incident_updates_id_seq'::regclass);


--
-- Name: incidents id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.incidents ALTER COLUMN id SET DEFAULT nextval('aegis.incidents_id_seq'::regclass);


--
-- Name: issue_updates id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.issue_updates ALTER COLUMN id SET DEFAULT nextval('aegis.issue_updates_id_seq'::regclass);


--
-- Name: issues id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.issues ALTER COLUMN id SET DEFAULT nextval('aegis.issues_id_seq'::regclass);


--
-- Name: kri_values id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.kri_values ALTER COLUMN id SET DEFAULT nextval('aegis.kri_values_id_seq'::regclass);


--
-- Name: kris id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.kris ALTER COLUMN id SET DEFAULT nextval('aegis.kris_id_seq'::regclass);


--
-- Name: metrics_snapshots id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.metrics_snapshots ALTER COLUMN id SET DEFAULT nextval('aegis.metrics_snapshots_id_seq'::regclass);


--
-- Name: mfa_backup_codes id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.mfa_backup_codes ALTER COLUMN id SET DEFAULT nextval('aegis.mfa_backup_codes_id_seq'::regclass);


--
-- Name: notification_log id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.notification_log ALTER COLUMN id SET DEFAULT nextval('aegis.notification_log_id_seq'::regclass);


--
-- Name: odp_entries id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.odp_entries ALTER COLUMN id SET DEFAULT nextval('aegis.odp_entries_id_seq'::regclass);


--
-- Name: password_reset_tokens id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.password_reset_tokens ALTER COLUMN id SET DEFAULT nextval('aegis.password_reset_tokens_id_seq'::regclass);


--
-- Name: playbook_step_completions id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.playbook_step_completions ALTER COLUMN id SET DEFAULT nextval('aegis.playbook_step_completions_id_seq'::regclass);


--
-- Name: playbook_steps id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.playbook_steps ALTER COLUMN id SET DEFAULT nextval('aegis.playbook_steps_id_seq'::regclass);


--
-- Name: playbooks id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.playbooks ALTER COLUMN id SET DEFAULT nextval('aegis.playbooks_id_seq'::regclass);


--
-- Name: poam_items id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.poam_items ALTER COLUMN id SET DEFAULT nextval('aegis.poam_items_id_seq'::regclass);


--
-- Name: poam_milestones id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.poam_milestones ALTER COLUMN id SET DEFAULT nextval('aegis.poam_milestones_id_seq'::regclass);


--
-- Name: policies id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policies ALTER COLUMN id SET DEFAULT nextval('aegis.policies_id_seq'::regclass);


--
-- Name: policy_attestation_campaigns id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policy_attestation_campaigns ALTER COLUMN id SET DEFAULT nextval('aegis.policy_attestation_campaigns_id_seq'::regclass);


--
-- Name: policy_attestations id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policy_attestations ALTER COLUMN id SET DEFAULT nextval('aegis.policy_attestations_id_seq'::regclass);


--
-- Name: policy_mappings id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policy_mappings ALTER COLUMN id SET DEFAULT nextval('aegis.policy_mappings_id_seq'::regclass);


--
-- Name: policy_reviews id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policy_reviews ALTER COLUMN id SET DEFAULT nextval('aegis.policy_reviews_id_seq'::regclass);


--
-- Name: policy_versions id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policy_versions ALTER COLUMN id SET DEFAULT nextval('aegis.policy_versions_id_seq'::regclass);


--
-- Name: privacy_records id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.privacy_records ALTER COLUMN id SET DEFAULT nextval('aegis.privacy_records_id_seq'::regclass);


--
-- Name: questionnaire_answers id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.questionnaire_answers ALTER COLUMN id SET DEFAULT nextval('aegis.questionnaire_answers_id_seq'::regclass);


--
-- Name: questionnaire_assignments id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.questionnaire_assignments ALTER COLUMN id SET DEFAULT nextval('aegis.questionnaire_assignments_id_seq'::regclass);


--
-- Name: questionnaire_questions id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.questionnaire_questions ALTER COLUMN id SET DEFAULT nextval('aegis.questionnaire_questions_id_seq'::regclass);


--
-- Name: questionnaire_responses id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.questionnaire_responses ALTER COLUMN id SET DEFAULT nextval('aegis.questionnaire_responses_id_seq'::regclass);


--
-- Name: questionnaires id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.questionnaires ALTER COLUMN id SET DEFAULT nextval('aegis.questionnaires_id_seq'::regclass);


--
-- Name: raci_assignments id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.raci_assignments ALTER COLUMN id SET DEFAULT nextval('aegis.raci_assignments_id_seq'::regclass);


--
-- Name: report_schedule_logs id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.report_schedule_logs ALTER COLUMN id SET DEFAULT nextval('aegis.report_schedule_logs_id_seq'::regclass);


--
-- Name: report_schedules id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.report_schedules ALTER COLUMN id SET DEFAULT nextval('aegis.report_schedules_id_seq'::regclass);


--
-- Name: risk_acceptances id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_acceptances ALTER COLUMN id SET DEFAULT nextval('aegis.risk_acceptances_id_seq'::regclass);


--
-- Name: risk_appetite id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_appetite ALTER COLUMN id SET DEFAULT nextval('aegis.risk_appetite_id_seq'::regclass);


--
-- Name: risk_bowtie_barriers id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_bowtie_barriers ALTER COLUMN id SET DEFAULT nextval('aegis.risk_bowtie_barriers_id_seq'::regclass);


--
-- Name: risk_bowtie_causes id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_bowtie_causes ALTER COLUMN id SET DEFAULT nextval('aegis.risk_bowtie_causes_id_seq'::regclass);


--
-- Name: risk_bowtie_consequences id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_bowtie_consequences ALTER COLUMN id SET DEFAULT nextval('aegis.risk_bowtie_consequences_id_seq'::regclass);


--
-- Name: risk_categories id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_categories ALTER COLUMN id SET DEFAULT nextval('aegis.risk_categories_id_seq'::regclass);


--
-- Name: risk_control_links id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_control_links ALTER COLUMN id SET DEFAULT nextval('aegis.risk_control_links_id_seq'::regclass);


--
-- Name: risk_exceptions id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_exceptions ALTER COLUMN id SET DEFAULT nextval('aegis.risk_exceptions_id_seq'::regclass);


--
-- Name: risk_matrix_config id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_matrix_config ALTER COLUMN id SET DEFAULT nextval('aegis.risk_matrix_config_id_seq'::regclass);


--
-- Name: risk_related_links id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_related_links ALTER COLUMN id SET DEFAULT nextval('aegis.risk_related_links_id_seq'::regclass);


--
-- Name: risk_review_items id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_review_items ALTER COLUMN id SET DEFAULT nextval('aegis.risk_review_items_id_seq'::regclass);


--
-- Name: risk_reviews id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_reviews ALTER COLUMN id SET DEFAULT nextval('aegis.risk_reviews_id_seq'::regclass);


--
-- Name: risk_scenarios id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_scenarios ALTER COLUMN id SET DEFAULT nextval('aegis.risk_scenarios_id_seq'::regclass);


--
-- Name: risk_score_history id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_score_history ALTER COLUMN id SET DEFAULT nextval('aegis.risk_score_history_id_seq'::regclass);


--
-- Name: risk_treatments id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_treatments ALTER COLUMN id SET DEFAULT nextval('aegis.risk_treatments_id_seq'::regclass);


--
-- Name: risks id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risks ALTER COLUMN id SET DEFAULT nextval('aegis.risks_id_seq'::regclass);


--
-- Name: shared_responsibility id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.shared_responsibility ALTER COLUMN id SET DEFAULT nextval('aegis.shared_responsibility_id_seq'::regclass);


--
-- Name: ssp_control_statements id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.ssp_control_statements ALTER COLUMN id SET DEFAULT nextval('aegis.ssp_control_statements_id_seq'::regclass);


--
-- Name: ssp_packages id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.ssp_packages ALTER COLUMN id SET DEFAULT nextval('aegis.ssp_packages_id_seq'::regclass);


--
-- Name: ssp_plans id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.ssp_plans ALTER COLUMN id SET DEFAULT nextval('aegis.ssp_plans_id_seq'::regclass);


--
-- Name: standards id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.standards ALTER COLUMN id SET DEFAULT nextval('aegis.standards_id_seq'::regclass);


--
-- Name: tags id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.tags ALTER COLUMN id SET DEFAULT nextval('aegis.tags_id_seq'::regclass);


--
-- Name: tenants id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.tenants ALTER COLUMN id SET DEFAULT nextval('aegis.tenants_id_seq'::regclass);


--
-- Name: threat_risk_links id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.threat_risk_links ALTER COLUMN id SET DEFAULT nextval('aegis.threat_risk_links_id_seq'::regclass);


--
-- Name: threats id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.threats ALTER COLUMN id SET DEFAULT nextval('aegis.threats_id_seq'::regclass);


--
-- Name: treatment_milestones id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.treatment_milestones ALTER COLUMN id SET DEFAULT nextval('aegis.treatment_milestones_id_seq'::regclass);


--
-- Name: treatment_plans id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.treatment_plans ALTER COLUMN id SET DEFAULT nextval('aegis.treatment_plans_id_seq'::regclass);


--
-- Name: user_notification_prefs id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.user_notification_prefs ALTER COLUMN id SET DEFAULT nextval('aegis.user_notification_prefs_id_seq'::regclass);


--
-- Name: user_permissions id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.user_permissions ALTER COLUMN id SET DEFAULT nextval('aegis.user_permissions_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.users ALTER COLUMN id SET DEFAULT nextval('aegis.users_id_seq'::regclass);


--
-- Name: vendor_assessments id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.vendor_assessments ALTER COLUMN id SET DEFAULT nextval('aegis.vendor_assessments_id_seq'::regclass);


--
-- Name: vendor_contracts id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.vendor_contracts ALTER COLUMN id SET DEFAULT nextval('aegis.vendor_contracts_id_seq'::regclass);


--
-- Name: vendor_portal_tokens id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.vendor_portal_tokens ALTER COLUMN id SET DEFAULT nextval('aegis.vendor_portal_tokens_id_seq'::regclass);


--
-- Name: vendors id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.vendors ALTER COLUMN id SET DEFAULT nextval('aegis.vendors_id_seq'::regclass);


--
-- Name: webhook_deliveries id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.webhook_deliveries ALTER COLUMN id SET DEFAULT nextval('aegis.webhook_deliveries_id_seq'::regclass);


--
-- Name: webhook_endpoints id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.webhook_endpoints ALTER COLUMN id SET DEFAULT nextval('aegis.webhook_endpoints_id_seq'::regclass);


--
-- Name: workflow_executions id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.workflow_executions ALTER COLUMN id SET DEFAULT nextval('aegis.workflow_executions_id_seq'::regclass);


--
-- Name: workflows id; Type: DEFAULT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.workflows ALTER COLUMN id SET DEFAULT nextval('aegis.workflows_id_seq'::regclass);


--
-- Name: active_sessions active_sessions_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.active_sessions
    ADD CONSTRAINT active_sessions_pkey PRIMARY KEY (id);


--
-- Name: activity_log activity_log_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.activity_log
    ADD CONSTRAINT activity_log_pkey PRIMARY KEY (id);


--
-- Name: alert_configs alert_configs_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.alert_configs
    ADD CONSTRAINT alert_configs_pkey PRIMARY KEY (id);


--
-- Name: alerts alerts_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.alerts
    ADD CONSTRAINT alerts_pkey PRIMARY KEY (id);


--
-- Name: api_keys api_keys_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.api_keys
    ADD CONSTRAINT api_keys_pkey PRIMARY KEY (id);


--
-- Name: approval_request_steps approval_request_steps_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.approval_request_steps
    ADD CONSTRAINT approval_request_steps_pkey PRIMARY KEY (id);


--
-- Name: approval_request_steps approval_request_steps_request_id_step_number_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.approval_request_steps
    ADD CONSTRAINT approval_request_steps_request_id_step_number_key UNIQUE (request_id, step_number);


--
-- Name: approval_requests approval_requests_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.approval_requests
    ADD CONSTRAINT approval_requests_pkey PRIMARY KEY (id);


--
-- Name: approval_template_steps approval_template_steps_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.approval_template_steps
    ADD CONSTRAINT approval_template_steps_pkey PRIMARY KEY (id);


--
-- Name: approval_template_steps approval_template_steps_template_id_step_number_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.approval_template_steps
    ADD CONSTRAINT approval_template_steps_template_id_step_number_key UNIQUE (template_id, step_number);


--
-- Name: approval_templates approval_templates_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.approval_templates
    ADD CONSTRAINT approval_templates_pkey PRIMARY KEY (id);


--
-- Name: asset_risk_links asset_risk_links_asset_id_risk_id_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.asset_risk_links
    ADD CONSTRAINT asset_risk_links_asset_id_risk_id_key UNIQUE (asset_id, risk_id);


--
-- Name: asset_risk_links asset_risk_links_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.asset_risk_links
    ADD CONSTRAINT asset_risk_links_pkey PRIMARY KEY (id);


--
-- Name: assets assets_asset_code_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.assets
    ADD CONSTRAINT assets_asset_code_key UNIQUE (asset_code);


--
-- Name: assets assets_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.assets
    ADD CONSTRAINT assets_pkey PRIMARY KEY (id);


--
-- Name: audit_findings audit_findings_finding_number_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.audit_findings
    ADD CONSTRAINT audit_findings_finding_number_key UNIQUE (finding_number);


--
-- Name: audit_findings audit_findings_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.audit_findings
    ADD CONSTRAINT audit_findings_pkey PRIMARY KEY (id);


--
-- Name: audit_items audit_items_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.audit_items
    ADD CONSTRAINT audit_items_pkey PRIMARY KEY (id);


--
-- Name: audit_schedules audit_schedules_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.audit_schedules
    ADD CONSTRAINT audit_schedules_pkey PRIMARY KEY (id);


--
-- Name: audits audits_audit_number_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.audits
    ADD CONSTRAINT audits_audit_number_key UNIQUE (audit_number);


--
-- Name: audits audits_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.audits
    ADD CONSTRAINT audits_pkey PRIMARY KEY (id);


--
-- Name: automation_logs automation_logs_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.automation_logs
    ADD CONSTRAINT automation_logs_pkey PRIMARY KEY (id);


--
-- Name: automation_rules automation_rules_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.automation_rules
    ADD CONSTRAINT automation_rules_pkey PRIMARY KEY (id);


--
-- Name: awareness_assignments awareness_assignments_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.awareness_assignments
    ADD CONSTRAINT awareness_assignments_pkey PRIMARY KEY (id);


--
-- Name: awareness_assignments awareness_assignments_program_id_user_id_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.awareness_assignments
    ADD CONSTRAINT awareness_assignments_program_id_user_id_key UNIQUE (program_id, user_id);


--
-- Name: awareness_programs awareness_programs_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.awareness_programs
    ADD CONSTRAINT awareness_programs_pkey PRIMARY KEY (id);


--
-- Name: bcp_exercises bcp_exercises_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.bcp_exercises
    ADD CONSTRAINT bcp_exercises_pkey PRIMARY KEY (id);


--
-- Name: bcp_plan_sections bcp_plan_sections_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.bcp_plan_sections
    ADD CONSTRAINT bcp_plan_sections_pkey PRIMARY KEY (id);


--
-- Name: bcp_plans bcp_plans_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.bcp_plans
    ADD CONSTRAINT bcp_plans_pkey PRIMARY KEY (id);


--
-- Name: bcp_plans bcp_plans_plan_code_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.bcp_plans
    ADD CONSTRAINT bcp_plans_plan_code_key UNIQUE (plan_code);


--
-- Name: compliance_objectives compliance_objectives_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.compliance_objectives
    ADD CONSTRAINT compliance_objectives_pkey PRIMARY KEY (id);


--
-- Name: compliance_packages compliance_packages_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.compliance_packages
    ADD CONSTRAINT compliance_packages_pkey PRIMARY KEY (id);


--
-- Name: control_implementations control_implementations_objective_id_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.control_implementations
    ADD CONSTRAINT control_implementations_objective_id_key UNIQUE (objective_id);


--
-- Name: control_implementations control_implementations_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.control_implementations
    ADD CONSTRAINT control_implementations_pkey PRIMARY KEY (id);


--
-- Name: control_mappings control_mappings_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.control_mappings
    ADD CONSTRAINT control_mappings_pkey PRIMARY KEY (id);


--
-- Name: control_mappings control_mappings_source_obj_id_target_obj_id_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.control_mappings
    ADD CONSTRAINT control_mappings_source_obj_id_target_obj_id_key UNIQUE (source_obj_id, target_obj_id);


--
-- Name: control_tests control_tests_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.control_tests
    ADD CONSTRAINT control_tests_pkey PRIMARY KEY (id);


--
-- Name: cui_inventory cui_inventory_inventory_number_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.cui_inventory
    ADD CONSTRAINT cui_inventory_inventory_number_key UNIQUE (inventory_number);


--
-- Name: cui_inventory cui_inventory_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.cui_inventory
    ADD CONSTRAINT cui_inventory_pkey PRIMARY KEY (id);


--
-- Name: custom_dashboards custom_dashboards_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.custom_dashboards
    ADD CONSTRAINT custom_dashboards_pkey PRIMARY KEY (id);


--
-- Name: custom_field_definitions custom_field_definitions_entity_type_name_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.custom_field_definitions
    ADD CONSTRAINT custom_field_definitions_entity_type_name_key UNIQUE (entity_type, name);


--
-- Name: custom_field_definitions custom_field_definitions_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.custom_field_definitions
    ADD CONSTRAINT custom_field_definitions_pkey PRIMARY KEY (id);


--
-- Name: custom_field_values custom_field_values_definition_id_entity_type_entity_id_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.custom_field_values
    ADD CONSTRAINT custom_field_values_definition_id_entity_type_entity_id_key UNIQUE (definition_id, entity_type, entity_id);


--
-- Name: custom_field_values custom_field_values_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.custom_field_values
    ADD CONSTRAINT custom_field_values_pkey PRIMARY KEY (id);


--
-- Name: dashboard_widgets dashboard_widgets_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.dashboard_widgets
    ADD CONSTRAINT dashboard_widgets_pkey PRIMARY KEY (id);


--
-- Name: data_retention_policies data_retention_policies_entity_type_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.data_retention_policies
    ADD CONSTRAINT data_retention_policies_entity_type_key UNIQUE (entity_type);


--
-- Name: data_retention_policies data_retention_policies_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.data_retention_policies
    ADD CONSTRAINT data_retention_policies_pkey PRIMARY KEY (id);


--
-- Name: data_subject_requests data_subject_requests_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.data_subject_requests
    ADD CONSTRAINT data_subject_requests_pkey PRIMARY KEY (id);


--
-- Name: document_versions document_versions_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.document_versions
    ADD CONSTRAINT document_versions_pkey PRIMARY KEY (id);


--
-- Name: documents documents_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.documents
    ADD CONSTRAINT documents_pkey PRIMARY KEY (id);


--
-- Name: email_bounces email_bounces_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.email_bounces
    ADD CONSTRAINT email_bounces_pkey PRIMARY KEY (id);


--
-- Name: email_templates email_templates_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.email_templates
    ADD CONSTRAINT email_templates_pkey PRIMARY KEY (id);


--
-- Name: email_templates email_templates_type_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.email_templates
    ADD CONSTRAINT email_templates_type_key UNIQUE (type);


--
-- Name: email_unsubscribes email_unsubscribes_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.email_unsubscribes
    ADD CONSTRAINT email_unsubscribes_pkey PRIMARY KEY (id);


--
-- Name: email_unsubscribes email_unsubscribes_token_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.email_unsubscribes
    ADD CONSTRAINT email_unsubscribes_token_key UNIQUE (token);


--
-- Name: email_verification_tokens email_verification_tokens_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.email_verification_tokens
    ADD CONSTRAINT email_verification_tokens_pkey PRIMARY KEY (id);


--
-- Name: email_verification_tokens email_verification_tokens_token_hash_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.email_verification_tokens
    ADD CONSTRAINT email_verification_tokens_token_hash_key UNIQUE (token_hash);


--
-- Name: entity_tags entity_tags_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.entity_tags
    ADD CONSTRAINT entity_tags_pkey PRIMARY KEY (id);


--
-- Name: evidence_files evidence_files_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.evidence_files
    ADD CONSTRAINT evidence_files_pkey PRIMARY KEY (id);


--
-- Name: evidence evidence_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.evidence
    ADD CONSTRAINT evidence_pkey PRIMARY KEY (id);


--
-- Name: finding_updates finding_updates_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.finding_updates
    ADD CONSTRAINT finding_updates_pkey PRIMARY KEY (id);


--
-- Name: grc_project_links grc_project_links_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.grc_project_links
    ADD CONSTRAINT grc_project_links_pkey PRIMARY KEY (id);


--
-- Name: grc_project_links grc_project_links_project_id_entity_type_entity_id_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.grc_project_links
    ADD CONSTRAINT grc_project_links_project_id_entity_type_entity_id_key UNIQUE (project_id, entity_type, entity_id);


--
-- Name: grc_project_tasks grc_project_tasks_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.grc_project_tasks
    ADD CONSTRAINT grc_project_tasks_pkey PRIMARY KEY (id);


--
-- Name: grc_projects grc_projects_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.grc_projects
    ADD CONSTRAINT grc_projects_pkey PRIMARY KEY (id);


--
-- Name: grc_projects grc_projects_project_code_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.grc_projects
    ADD CONSTRAINT grc_projects_project_code_key UNIQUE (project_code);


--
-- Name: incident_playbook_runs incident_playbook_runs_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.incident_playbook_runs
    ADD CONSTRAINT incident_playbook_runs_pkey PRIMARY KEY (id);


--
-- Name: incident_sla_events incident_sla_events_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.incident_sla_events
    ADD CONSTRAINT incident_sla_events_pkey PRIMARY KEY (id);


--
-- Name: incident_sla_policies incident_sla_policies_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.incident_sla_policies
    ADD CONSTRAINT incident_sla_policies_pkey PRIMARY KEY (id);


--
-- Name: incident_sla_policies incident_sla_policies_severity_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.incident_sla_policies
    ADD CONSTRAINT incident_sla_policies_severity_key UNIQUE (severity);


--
-- Name: incident_updates incident_updates_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.incident_updates
    ADD CONSTRAINT incident_updates_pkey PRIMARY KEY (id);


--
-- Name: incidents incidents_incident_number_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.incidents
    ADD CONSTRAINT incidents_incident_number_key UNIQUE (incident_number);


--
-- Name: incidents incidents_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.incidents
    ADD CONSTRAINT incidents_pkey PRIMARY KEY (id);


--
-- Name: issue_updates issue_updates_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.issue_updates
    ADD CONSTRAINT issue_updates_pkey PRIMARY KEY (id);


--
-- Name: issues issues_issue_number_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.issues
    ADD CONSTRAINT issues_issue_number_key UNIQUE (issue_number);


--
-- Name: issues issues_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.issues
    ADD CONSTRAINT issues_pkey PRIMARY KEY (id);


--
-- Name: kri_values kri_values_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.kri_values
    ADD CONSTRAINT kri_values_pkey PRIMARY KEY (id);


--
-- Name: kris kris_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.kris
    ADD CONSTRAINT kris_pkey PRIMARY KEY (id);


--
-- Name: metrics_snapshots metrics_snapshots_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.metrics_snapshots
    ADD CONSTRAINT metrics_snapshots_pkey PRIMARY KEY (id);


--
-- Name: metrics_snapshots metrics_snapshots_snapshot_date_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.metrics_snapshots
    ADD CONSTRAINT metrics_snapshots_snapshot_date_key UNIQUE (snapshot_date);


--
-- Name: mfa_backup_codes mfa_backup_codes_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.mfa_backup_codes
    ADD CONSTRAINT mfa_backup_codes_pkey PRIMARY KEY (id);


--
-- Name: notification_log notification_log_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.notification_log
    ADD CONSTRAINT notification_log_pkey PRIMARY KEY (id);


--
-- Name: odp_entries odp_entries_objective_id_parameter_name_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.odp_entries
    ADD CONSTRAINT odp_entries_objective_id_parameter_name_key UNIQUE (objective_id, parameter_name);


--
-- Name: odp_entries odp_entries_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.odp_entries
    ADD CONSTRAINT odp_entries_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_user_id_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_user_id_key UNIQUE (user_id);


--
-- Name: php_sessions php_sessions_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.php_sessions
    ADD CONSTRAINT php_sessions_pkey PRIMARY KEY (id);


--
-- Name: playbook_step_completions playbook_step_completions_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.playbook_step_completions
    ADD CONSTRAINT playbook_step_completions_pkey PRIMARY KEY (id);


--
-- Name: playbook_steps playbook_steps_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.playbook_steps
    ADD CONSTRAINT playbook_steps_pkey PRIMARY KEY (id);


--
-- Name: playbooks playbooks_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.playbooks
    ADD CONSTRAINT playbooks_pkey PRIMARY KEY (id);


--
-- Name: poam_items poam_items_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.poam_items
    ADD CONSTRAINT poam_items_pkey PRIMARY KEY (id);


--
-- Name: poam_items poam_items_poam_number_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.poam_items
    ADD CONSTRAINT poam_items_poam_number_key UNIQUE (poam_number);


--
-- Name: poam_milestones poam_milestones_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.poam_milestones
    ADD CONSTRAINT poam_milestones_pkey PRIMARY KEY (id);


--
-- Name: policies policies_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policies
    ADD CONSTRAINT policies_pkey PRIMARY KEY (id);


--
-- Name: policy_attestation_campaigns policy_attestation_campaigns_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policy_attestation_campaigns
    ADD CONSTRAINT policy_attestation_campaigns_pkey PRIMARY KEY (id);


--
-- Name: policy_attestations policy_attestations_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policy_attestations
    ADD CONSTRAINT policy_attestations_pkey PRIMARY KEY (id);


--
-- Name: policy_mappings policy_mappings_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policy_mappings
    ADD CONSTRAINT policy_mappings_pkey PRIMARY KEY (id);


--
-- Name: policy_mappings policy_mappings_policy_id_objective_id_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policy_mappings
    ADD CONSTRAINT policy_mappings_policy_id_objective_id_key UNIQUE (policy_id, objective_id);


--
-- Name: policy_reviews policy_reviews_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policy_reviews
    ADD CONSTRAINT policy_reviews_pkey PRIMARY KEY (id);


--
-- Name: policy_versions policy_versions_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policy_versions
    ADD CONSTRAINT policy_versions_pkey PRIMARY KEY (id);


--
-- Name: privacy_records privacy_records_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.privacy_records
    ADD CONSTRAINT privacy_records_pkey PRIMARY KEY (id);


--
-- Name: questionnaire_answers questionnaire_answers_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.questionnaire_answers
    ADD CONSTRAINT questionnaire_answers_pkey PRIMARY KEY (id);


--
-- Name: questionnaire_answers questionnaire_answers_response_id_question_id_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.questionnaire_answers
    ADD CONSTRAINT questionnaire_answers_response_id_question_id_key UNIQUE (response_id, question_id);


--
-- Name: questionnaire_assignments questionnaire_assignments_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.questionnaire_assignments
    ADD CONSTRAINT questionnaire_assignments_pkey PRIMARY KEY (id);


--
-- Name: questionnaire_questions questionnaire_questions_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.questionnaire_questions
    ADD CONSTRAINT questionnaire_questions_pkey PRIMARY KEY (id);


--
-- Name: questionnaire_responses questionnaire_responses_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.questionnaire_responses
    ADD CONSTRAINT questionnaire_responses_pkey PRIMARY KEY (id);


--
-- Name: questionnaires questionnaires_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.questionnaires
    ADD CONSTRAINT questionnaires_pkey PRIMARY KEY (id);


--
-- Name: raci_assignments raci_assignments_package_id_objective_id_user_id_raci_role_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.raci_assignments
    ADD CONSTRAINT raci_assignments_package_id_objective_id_user_id_raci_role_key UNIQUE (package_id, objective_id, user_id, raci_role);


--
-- Name: raci_assignments raci_assignments_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.raci_assignments
    ADD CONSTRAINT raci_assignments_pkey PRIMARY KEY (id);


--
-- Name: rate_limits rate_limits_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.rate_limits
    ADD CONSTRAINT rate_limits_pkey PRIMARY KEY (key);


--
-- Name: report_schedule_logs report_schedule_logs_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.report_schedule_logs
    ADD CONSTRAINT report_schedule_logs_pkey PRIMARY KEY (id);


--
-- Name: report_schedules report_schedules_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.report_schedules
    ADD CONSTRAINT report_schedules_pkey PRIMARY KEY (id);


--
-- Name: risk_acceptances risk_acceptances_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_acceptances
    ADD CONSTRAINT risk_acceptances_pkey PRIMARY KEY (id);


--
-- Name: risk_appetite risk_appetite_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_appetite
    ADD CONSTRAINT risk_appetite_pkey PRIMARY KEY (id);


--
-- Name: risk_bowtie_barriers risk_bowtie_barriers_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_bowtie_barriers
    ADD CONSTRAINT risk_bowtie_barriers_pkey PRIMARY KEY (id);


--
-- Name: risk_bowtie_causes risk_bowtie_causes_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_bowtie_causes
    ADD CONSTRAINT risk_bowtie_causes_pkey PRIMARY KEY (id);


--
-- Name: risk_bowtie_consequences risk_bowtie_consequences_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_bowtie_consequences
    ADD CONSTRAINT risk_bowtie_consequences_pkey PRIMARY KEY (id);


--
-- Name: risk_categories risk_categories_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_categories
    ADD CONSTRAINT risk_categories_pkey PRIMARY KEY (id);


--
-- Name: risk_control_links risk_control_links_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_control_links
    ADD CONSTRAINT risk_control_links_pkey PRIMARY KEY (id);


--
-- Name: risk_control_links risk_control_links_risk_id_control_implementation_id_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_control_links
    ADD CONSTRAINT risk_control_links_risk_id_control_implementation_id_key UNIQUE (risk_id, control_implementation_id);


--
-- Name: risk_exceptions risk_exceptions_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_exceptions
    ADD CONSTRAINT risk_exceptions_pkey PRIMARY KEY (id);


--
-- Name: risk_matrix_config risk_matrix_config_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_matrix_config
    ADD CONSTRAINT risk_matrix_config_pkey PRIMARY KEY (id);


--
-- Name: risk_related_links risk_related_links_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_related_links
    ADD CONSTRAINT risk_related_links_pkey PRIMARY KEY (id);


--
-- Name: risk_related_links risk_related_links_risk_id_related_id_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_related_links
    ADD CONSTRAINT risk_related_links_risk_id_related_id_key UNIQUE (risk_id, related_id);


--
-- Name: risk_review_items risk_review_items_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_review_items
    ADD CONSTRAINT risk_review_items_pkey PRIMARY KEY (id);


--
-- Name: risk_review_items risk_review_items_review_id_risk_id_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_review_items
    ADD CONSTRAINT risk_review_items_review_id_risk_id_key UNIQUE (review_id, risk_id);


--
-- Name: risk_reviews risk_reviews_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_reviews
    ADD CONSTRAINT risk_reviews_pkey PRIMARY KEY (id);


--
-- Name: risk_scenarios risk_scenarios_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_scenarios
    ADD CONSTRAINT risk_scenarios_pkey PRIMARY KEY (id);


--
-- Name: risk_score_history risk_score_history_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_score_history
    ADD CONSTRAINT risk_score_history_pkey PRIMARY KEY (id);


--
-- Name: risk_treatments risk_treatments_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_treatments
    ADD CONSTRAINT risk_treatments_pkey PRIMARY KEY (id);


--
-- Name: risks risks_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risks
    ADD CONSTRAINT risks_pkey PRIMARY KEY (id);


--
-- Name: settings settings_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.settings
    ADD CONSTRAINT settings_pkey PRIMARY KEY (key);


--
-- Name: shared_responsibility shared_responsibility_package_id_objective_id_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.shared_responsibility
    ADD CONSTRAINT shared_responsibility_package_id_objective_id_key UNIQUE (package_id, objective_id);


--
-- Name: shared_responsibility shared_responsibility_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.shared_responsibility
    ADD CONSTRAINT shared_responsibility_pkey PRIMARY KEY (id);


--
-- Name: ssp_control_statements ssp_control_statements_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.ssp_control_statements
    ADD CONSTRAINT ssp_control_statements_pkey PRIMARY KEY (id);


--
-- Name: ssp_control_statements ssp_control_statements_ssp_id_objective_id_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.ssp_control_statements
    ADD CONSTRAINT ssp_control_statements_ssp_id_objective_id_key UNIQUE (ssp_id, objective_id);


--
-- Name: ssp_packages ssp_packages_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.ssp_packages
    ADD CONSTRAINT ssp_packages_pkey PRIMARY KEY (id);


--
-- Name: ssp_packages ssp_packages_ssp_id_package_id_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.ssp_packages
    ADD CONSTRAINT ssp_packages_ssp_id_package_id_key UNIQUE (ssp_id, package_id);


--
-- Name: ssp_plans ssp_plans_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.ssp_plans
    ADD CONSTRAINT ssp_plans_pkey PRIMARY KEY (id);


--
-- Name: standards standards_code_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.standards
    ADD CONSTRAINT standards_code_key UNIQUE (code);


--
-- Name: standards standards_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.standards
    ADD CONSTRAINT standards_pkey PRIMARY KEY (id);


--
-- Name: tags tags_name_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.tags
    ADD CONSTRAINT tags_name_key UNIQUE (name);


--
-- Name: tags tags_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.tags
    ADD CONSTRAINT tags_pkey PRIMARY KEY (id);


--
-- Name: tenants tenants_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.tenants
    ADD CONSTRAINT tenants_pkey PRIMARY KEY (id);


--
-- Name: tenants tenants_slug_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.tenants
    ADD CONSTRAINT tenants_slug_key UNIQUE (slug);


--
-- Name: threat_risk_links threat_risk_links_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.threat_risk_links
    ADD CONSTRAINT threat_risk_links_pkey PRIMARY KEY (id);


--
-- Name: threats threats_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.threats
    ADD CONSTRAINT threats_pkey PRIMARY KEY (id);


--
-- Name: threats threats_threat_number_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.threats
    ADD CONSTRAINT threats_threat_number_key UNIQUE (threat_number);


--
-- Name: treatment_milestones treatment_milestones_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.treatment_milestones
    ADD CONSTRAINT treatment_milestones_pkey PRIMARY KEY (id);


--
-- Name: treatment_plans treatment_plans_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.treatment_plans
    ADD CONSTRAINT treatment_plans_pkey PRIMARY KEY (id);


--
-- Name: treatment_plans treatment_plans_plan_code_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.treatment_plans
    ADD CONSTRAINT treatment_plans_plan_code_key UNIQUE (plan_code);


--
-- Name: entity_tags uq_entity_tag; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.entity_tags
    ADD CONSTRAINT uq_entity_tag UNIQUE (tag_id, entity_type, entity_id);


--
-- Name: incident_playbook_runs uq_incident_playbook; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.incident_playbook_runs
    ADD CONSTRAINT uq_incident_playbook UNIQUE (incident_id, playbook_id);


--
-- Name: policy_attestations uq_policy_attestation; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policy_attestations
    ADD CONSTRAINT uq_policy_attestation UNIQUE (policy_id, user_id);


--
-- Name: playbook_step_completions uq_run_step; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.playbook_step_completions
    ADD CONSTRAINT uq_run_step UNIQUE (run_id, step_id);


--
-- Name: threat_risk_links uq_threat_risk; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.threat_risk_links
    ADD CONSTRAINT uq_threat_risk UNIQUE (threat_id, risk_id);


--
-- Name: user_notification_prefs user_notification_prefs_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.user_notification_prefs
    ADD CONSTRAINT user_notification_prefs_pkey PRIMARY KEY (id);


--
-- Name: user_notification_prefs user_notification_prefs_user_id_notification_type_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.user_notification_prefs
    ADD CONSTRAINT user_notification_prefs_user_id_notification_type_key UNIQUE (user_id, notification_type);


--
-- Name: user_permissions user_permissions_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.user_permissions
    ADD CONSTRAINT user_permissions_pkey PRIMARY KEY (id);


--
-- Name: user_permissions user_permissions_user_id_module_permission_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.user_permissions
    ADD CONSTRAINT user_permissions_user_id_module_permission_key UNIQUE (user_id, module, permission);


--
-- Name: users users_email_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.users
    ADD CONSTRAINT users_email_key UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: vendor_assessments vendor_assessments_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.vendor_assessments
    ADD CONSTRAINT vendor_assessments_pkey PRIMARY KEY (id);


--
-- Name: vendor_contracts vendor_contracts_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.vendor_contracts
    ADD CONSTRAINT vendor_contracts_pkey PRIMARY KEY (id);


--
-- Name: vendor_portal_tokens vendor_portal_tokens_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.vendor_portal_tokens
    ADD CONSTRAINT vendor_portal_tokens_pkey PRIMARY KEY (id);


--
-- Name: vendor_portal_tokens vendor_portal_tokens_token_hash_key; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.vendor_portal_tokens
    ADD CONSTRAINT vendor_portal_tokens_token_hash_key UNIQUE (token_hash);


--
-- Name: vendors vendors_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.vendors
    ADD CONSTRAINT vendors_pkey PRIMARY KEY (id);


--
-- Name: webhook_deliveries webhook_deliveries_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.webhook_deliveries
    ADD CONSTRAINT webhook_deliveries_pkey PRIMARY KEY (id);


--
-- Name: webhook_endpoints webhook_endpoints_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.webhook_endpoints
    ADD CONSTRAINT webhook_endpoints_pkey PRIMARY KEY (id);


--
-- Name: workflow_executions workflow_executions_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.workflow_executions
    ADD CONSTRAINT workflow_executions_pkey PRIMARY KEY (id);


--
-- Name: workflows workflows_pkey; Type: CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.workflows
    ADD CONSTRAINT workflows_pkey PRIMARY KEY (id);


--
-- Name: idx_active_sessions_last_seen; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_active_sessions_last_seen ON aegis.active_sessions USING btree (last_seen_at);


--
-- Name: idx_active_sessions_user; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_active_sessions_user ON aegis.active_sessions USING btree (user_id);


--
-- Name: idx_ai_audit; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_ai_audit ON aegis.audit_items USING btree (audit_id);


--
-- Name: idx_al_created; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_al_created ON aegis.activity_log USING btree (created_at);


--
-- Name: idx_al_entity; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_al_entity ON aegis.activity_log USING btree (entity_type, entity_id);


--
-- Name: idx_al_user; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_al_user ON aegis.activity_log USING btree (user_id);


--
-- Name: idx_alerts_user; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_alerts_user ON aegis.alerts USING btree (user_id, is_read);


--
-- Name: idx_ar_entity; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_ar_entity ON aegis.approval_requests USING btree (entity_type, entity_id);


--
-- Name: idx_ar_status; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_ar_status ON aegis.approval_requests USING btree (status);


--
-- Name: idx_ars_request; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_ars_request ON aegis.approval_request_steps USING btree (request_id);


--
-- Name: idx_asset_risk_links_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_asset_risk_links_tenant ON aegis.asset_risk_links USING btree (tenant_id);


--
-- Name: idx_assets_criticality; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_assets_criticality ON aegis.assets USING btree (criticality);


--
-- Name: idx_assets_status; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_assets_status ON aegis.assets USING btree (status);


--
-- Name: idx_assets_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_assets_tenant ON aegis.assets USING btree (tenant_id);


--
-- Name: idx_assets_type; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_assets_type ON aegis.assets USING btree (asset_type);


--
-- Name: idx_audit_findings_severity; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_audit_findings_severity ON aegis.audit_findings USING btree (severity);


--
-- Name: idx_audit_findings_status; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_audit_findings_status ON aegis.audit_findings USING btree (status);


--
-- Name: idx_audit_findings_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_audit_findings_tenant ON aegis.audit_findings USING btree (tenant_id);


--
-- Name: idx_audit_items_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_audit_items_tenant ON aegis.audit_items USING btree (tenant_id);


--
-- Name: idx_audit_schedules_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_audit_schedules_tenant ON aegis.audit_schedules USING btree (tenant_id);


--
-- Name: idx_audits_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_audits_tenant ON aegis.audits USING btree (tenant_id);


--
-- Name: idx_automation_logs_rule; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_automation_logs_rule ON aegis.automation_logs USING btree (rule_id);


--
-- Name: idx_automation_rules_active; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_automation_rules_active ON aegis.automation_rules USING btree (is_active);


--
-- Name: idx_awareness_assignments_program; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_awareness_assignments_program ON aegis.awareness_assignments USING btree (program_id);


--
-- Name: idx_awareness_assignments_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_awareness_assignments_tenant ON aegis.awareness_assignments USING btree (tenant_id);


--
-- Name: idx_awareness_programs_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_awareness_programs_tenant ON aegis.awareness_programs USING btree (tenant_id);


--
-- Name: idx_bcp_exercises_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_bcp_exercises_tenant ON aegis.bcp_exercises USING btree (tenant_id);


--
-- Name: idx_bcp_plan_sections_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_bcp_plan_sections_tenant ON aegis.bcp_plan_sections USING btree (tenant_id);


--
-- Name: idx_bcp_plans_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_bcp_plans_tenant ON aegis.bcp_plans USING btree (tenant_id);


--
-- Name: idx_cfv_entity; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_cfv_entity ON aegis.custom_field_values USING btree (entity_type, entity_id);


--
-- Name: idx_ci_objective; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_ci_objective ON aegis.control_implementations USING btree (objective_id);


--
-- Name: idx_ci_status; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_ci_status ON aegis.control_implementations USING btree (status);


--
-- Name: idx_cm_source; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_cm_source ON aegis.control_mappings USING btree (source_obj_id);


--
-- Name: idx_cm_target; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_cm_target ON aegis.control_mappings USING btree (target_obj_id);


--
-- Name: idx_co_package; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_co_package ON aegis.compliance_objectives USING btree (package_id);


--
-- Name: idx_co_parent; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_co_parent ON aegis.compliance_objectives USING btree (parent_id);


--
-- Name: idx_compliance_objectives_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_compliance_objectives_tenant ON aegis.compliance_objectives USING btree (tenant_id);


--
-- Name: idx_compliance_packages_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_compliance_packages_tenant ON aegis.compliance_packages USING btree (tenant_id);


--
-- Name: idx_control_implementations_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_control_implementations_tenant ON aegis.control_implementations USING btree (tenant_id);


--
-- Name: idx_control_mappings_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_control_mappings_tenant ON aegis.control_mappings USING btree (tenant_id);


--
-- Name: idx_control_tests_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_control_tests_tenant ON aegis.control_tests USING btree (tenant_id);


--
-- Name: idx_ct_objective; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_ct_objective ON aegis.control_tests USING btree (objective_id);


--
-- Name: idx_ct_package; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_ct_package ON aegis.control_tests USING btree (package_id);


--
-- Name: idx_cui_inventory_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_cui_inventory_tenant ON aegis.cui_inventory USING btree (tenant_id);


--
-- Name: idx_cui_inventory_type; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_cui_inventory_type ON aegis.cui_inventory USING btree (storage_type);


--
-- Name: idx_dashboard_widgets; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_dashboard_widgets ON aegis.dashboard_widgets USING btree (dashboard_id);


--
-- Name: idx_data_subject_requests_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_data_subject_requests_tenant ON aegis.data_subject_requests USING btree (tenant_id);


--
-- Name: idx_docs_expiry; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_docs_expiry ON aegis.documents USING btree (expiry_date);


--
-- Name: idx_docs_owner; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_docs_owner ON aegis.documents USING btree (owner_id);


--
-- Name: idx_docs_status; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_docs_status ON aegis.documents USING btree (status);


--
-- Name: idx_document_versions_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_document_versions_tenant ON aegis.document_versions USING btree (tenant_id);


--
-- Name: idx_documents_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_documents_tenant ON aegis.documents USING btree (tenant_id);


--
-- Name: idx_dsr_status; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_dsr_status ON aegis.data_subject_requests USING btree (status);


--
-- Name: idx_dv_document; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_dv_document ON aegis.document_versions USING btree (document_id);


--
-- Name: idx_eb_email; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_eb_email ON aegis.email_bounces USING btree (email);


--
-- Name: idx_ef_entity; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_ef_entity ON aegis.evidence_files USING btree (entity_type, entity_id);


--
-- Name: idx_entity_tags_entity; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_entity_tags_entity ON aegis.entity_tags USING btree (entity_type, entity_id);


--
-- Name: idx_entity_tags_tag; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_entity_tags_tag ON aegis.entity_tags USING btree (tag_id);


--
-- Name: idx_eu_email; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_eu_email ON aegis.email_unsubscribes USING btree (email);


--
-- Name: idx_eu_token; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_eu_token ON aegis.email_unsubscribes USING btree (token);


--
-- Name: idx_evidence_entity; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_evidence_entity ON aegis.evidence USING btree (entity_type, entity_id);


--
-- Name: idx_evidence_files_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_evidence_files_tenant ON aegis.evidence_files USING btree (tenant_id);


--
-- Name: idx_evidence_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_evidence_tenant ON aegis.evidence USING btree (tenant_id);


--
-- Name: idx_evt_user; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_evt_user ON aegis.email_verification_tokens USING btree (user_id);


--
-- Name: idx_finding_updates; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_finding_updates ON aegis.finding_updates USING btree (finding_id);


--
-- Name: idx_finding_updates_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_finding_updates_tenant ON aegis.finding_updates USING btree (tenant_id);


--
-- Name: idx_grc_project_links; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_grc_project_links ON aegis.grc_project_links USING btree (project_id);


--
-- Name: idx_grc_project_links_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_grc_project_links_tenant ON aegis.grc_project_links USING btree (tenant_id);


--
-- Name: idx_grc_project_tasks; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_grc_project_tasks ON aegis.grc_project_tasks USING btree (project_id);


--
-- Name: idx_grc_project_tasks_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_grc_project_tasks_tenant ON aegis.grc_project_tasks USING btree (tenant_id);


--
-- Name: idx_grc_projects_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_grc_projects_tenant ON aegis.grc_projects USING btree (tenant_id);


--
-- Name: idx_incident_sla_events_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_incident_sla_events_tenant ON aegis.incident_sla_events USING btree (tenant_id);


--
-- Name: idx_incident_updates_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_incident_updates_tenant ON aegis.incident_updates USING btree (tenant_id);


--
-- Name: idx_incidents_severity; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_incidents_severity ON aegis.incidents USING btree (severity);


--
-- Name: idx_incidents_status; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_incidents_status ON aegis.incidents USING btree (status);


--
-- Name: idx_incidents_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_incidents_tenant ON aegis.incidents USING btree (tenant_id);


--
-- Name: idx_issue_updates_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_issue_updates_tenant ON aegis.issue_updates USING btree (tenant_id);


--
-- Name: idx_issues_status; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_issues_status ON aegis.issues USING btree (status);


--
-- Name: idx_issues_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_issues_tenant ON aegis.issues USING btree (tenant_id);


--
-- Name: idx_iu_incident; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_iu_incident ON aegis.incident_updates USING btree (incident_id);


--
-- Name: idx_kri_values_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_kri_values_tenant ON aegis.kri_values USING btree (tenant_id);


--
-- Name: idx_kris_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_kris_tenant ON aegis.kris USING btree (tenant_id);


--
-- Name: idx_kv_kri; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_kv_kri ON aegis.kri_values USING btree (kri_id);


--
-- Name: idx_kv_recorded; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_kv_recorded ON aegis.kri_values USING btree (recorded_at);


--
-- Name: idx_mbc_user; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_mbc_user ON aegis.mfa_backup_codes USING btree (user_id);


--
-- Name: idx_ms_date; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_ms_date ON aegis.metrics_snapshots USING btree (snapshot_date DESC);


--
-- Name: idx_nl_recipient; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_nl_recipient ON aegis.notification_log USING btree (recipient_email);


--
-- Name: idx_nl_sent_at; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_nl_sent_at ON aegis.notification_log USING btree (sent_at);


--
-- Name: idx_nl_type; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_nl_type ON aegis.notification_log USING btree (notification_type, entity_id, sent_at);


--
-- Name: idx_odp_entries_objective; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_odp_entries_objective ON aegis.odp_entries USING btree (objective_id);


--
-- Name: idx_odp_entries_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_odp_entries_tenant ON aegis.odp_entries USING btree (tenant_id);


--
-- Name: idx_pa_policy; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_pa_policy ON aegis.policy_attestations USING btree (policy_id);


--
-- Name: idx_pa_user; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_pa_user ON aegis.policy_attestations USING btree (user_id);


--
-- Name: idx_php_sessions_expires; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_php_sessions_expires ON aegis.php_sessions USING btree (expires_at);


--
-- Name: idx_pm_objective; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_pm_objective ON aegis.policy_mappings USING btree (objective_id);


--
-- Name: idx_pm_policy; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_pm_policy ON aegis.policy_mappings USING btree (policy_id);


--
-- Name: idx_poam_items_package_id; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_poam_items_package_id ON aegis.poam_items USING btree (package_id);


--
-- Name: idx_poam_items_status; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_poam_items_status ON aegis.poam_items USING btree (status);


--
-- Name: idx_poam_items_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_poam_items_tenant ON aegis.poam_items USING btree (tenant_id);


--
-- Name: idx_poam_milestones_poam; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_poam_milestones_poam ON aegis.poam_milestones USING btree (poam_id);


--
-- Name: idx_poam_milestones_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_poam_milestones_tenant ON aegis.poam_milestones USING btree (tenant_id);


--
-- Name: idx_policies_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_policies_tenant ON aegis.policies USING btree (tenant_id);


--
-- Name: idx_policy_attestation_campaigns_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_policy_attestation_campaigns_tenant ON aegis.policy_attestation_campaigns USING btree (tenant_id);


--
-- Name: idx_policy_attestations_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_policy_attestations_tenant ON aegis.policy_attestations USING btree (tenant_id);


--
-- Name: idx_policy_mappings_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_policy_mappings_tenant ON aegis.policy_mappings USING btree (tenant_id);


--
-- Name: idx_policy_reviews_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_policy_reviews_tenant ON aegis.policy_reviews USING btree (tenant_id);


--
-- Name: idx_policy_versions_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_policy_versions_tenant ON aegis.policy_versions USING btree (tenant_id);


--
-- Name: idx_privacy_records_status; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_privacy_records_status ON aegis.privacy_records USING btree (status);


--
-- Name: idx_privacy_records_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_privacy_records_tenant ON aegis.privacy_records USING btree (tenant_id);


--
-- Name: idx_prt_token; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_prt_token ON aegis.password_reset_tokens USING btree (token_hash);


--
-- Name: idx_ps_playbook; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_ps_playbook ON aegis.playbook_steps USING btree (playbook_id);


--
-- Name: idx_psc_run; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_psc_run ON aegis.playbook_step_completions USING btree (run_id);


--
-- Name: idx_qq_questionnaire; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_qq_questionnaire ON aegis.questionnaire_questions USING btree (questionnaire_id);


--
-- Name: idx_questionnaire_answers_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_questionnaire_answers_tenant ON aegis.questionnaire_answers USING btree (tenant_id);


--
-- Name: idx_questionnaire_assignments_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_questionnaire_assignments_tenant ON aegis.questionnaire_assignments USING btree (tenant_id);


--
-- Name: idx_questionnaire_questions_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_questionnaire_questions_tenant ON aegis.questionnaire_questions USING btree (tenant_id);


--
-- Name: idx_questionnaire_responses_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_questionnaire_responses_tenant ON aegis.questionnaire_responses USING btree (tenant_id);


--
-- Name: idx_questionnaires_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_questionnaires_tenant ON aegis.questionnaires USING btree (tenant_id);


--
-- Name: idx_ra_risk_id; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_ra_risk_id ON aegis.risk_acceptances USING btree (risk_id);


--
-- Name: idx_ra_status; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_ra_status ON aegis.risk_acceptances USING btree (status);


--
-- Name: idx_raci_assignments_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_raci_assignments_tenant ON aegis.raci_assignments USING btree (tenant_id);


--
-- Name: idx_raci_package; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_raci_package ON aegis.raci_assignments USING btree (package_id);


--
-- Name: idx_rbb_risk_id; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_rbb_risk_id ON aegis.risk_bowtie_barriers USING btree (risk_id);


--
-- Name: idx_rbc_risk_id; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_rbc_risk_id ON aegis.risk_bowtie_causes USING btree (risk_id);


--
-- Name: idx_rbcons_risk_id; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_rbcons_risk_id ON aegis.risk_bowtie_consequences USING btree (risk_id);


--
-- Name: idx_rcl_control; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_rcl_control ON aegis.risk_control_links USING btree (control_implementation_id);


--
-- Name: idx_rcl_risk; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_rcl_risk ON aegis.risk_control_links USING btree (risk_id);


--
-- Name: idx_risk_acceptances_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_risk_acceptances_tenant ON aegis.risk_acceptances USING btree (tenant_id);


--
-- Name: idx_risk_bowtie_barriers_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_risk_bowtie_barriers_tenant ON aegis.risk_bowtie_barriers USING btree (tenant_id);


--
-- Name: idx_risk_bowtie_causes_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_risk_bowtie_causes_tenant ON aegis.risk_bowtie_causes USING btree (tenant_id);


--
-- Name: idx_risk_bowtie_consequences_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_risk_bowtie_consequences_tenant ON aegis.risk_bowtie_consequences USING btree (tenant_id);


--
-- Name: idx_risk_control_links_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_risk_control_links_tenant ON aegis.risk_control_links USING btree (tenant_id);


--
-- Name: idx_risk_exceptions_expiry; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_risk_exceptions_expiry ON aegis.risk_exceptions USING btree (expiry_date) WHERE (expiry_date IS NOT NULL);


--
-- Name: idx_risk_exceptions_risk_id; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_risk_exceptions_risk_id ON aegis.risk_exceptions USING btree (risk_id);


--
-- Name: idx_risk_exceptions_status; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_risk_exceptions_status ON aegis.risk_exceptions USING btree (status);


--
-- Name: idx_risk_exceptions_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_risk_exceptions_tenant ON aegis.risk_exceptions USING btree (tenant_id);


--
-- Name: idx_risk_related_links_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_risk_related_links_tenant ON aegis.risk_related_links USING btree (tenant_id);


--
-- Name: idx_risk_review_items_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_risk_review_items_tenant ON aegis.risk_review_items USING btree (tenant_id);


--
-- Name: idx_risk_reviews_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_risk_reviews_tenant ON aegis.risk_reviews USING btree (tenant_id);


--
-- Name: idx_risk_scenarios_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_risk_scenarios_tenant ON aegis.risk_scenarios USING btree (tenant_id);


--
-- Name: idx_risk_score_history_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_risk_score_history_tenant ON aegis.risk_score_history USING btree (tenant_id);


--
-- Name: idx_risk_treatments_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_risk_treatments_tenant ON aegis.risk_treatments USING btree (tenant_id);


--
-- Name: idx_risks_assessment; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_risks_assessment ON aegis.risks USING btree (assessment_status);


--
-- Name: idx_risks_inherent_score; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_risks_inherent_score ON aegis.risks USING btree (inherent_score);


--
-- Name: idx_risks_owner; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_risks_owner ON aegis.risks USING btree (owner_id);


--
-- Name: idx_risks_parent; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_risks_parent ON aegis.risks USING btree (parent_risk_id) WHERE (parent_risk_id IS NOT NULL);


--
-- Name: idx_risks_residual_score; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_risks_residual_score ON aegis.risks USING btree (residual_score);


--
-- Name: idx_risks_source; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_risks_source ON aegis.risks USING btree (risk_source) WHERE (risk_source IS NOT NULL);


--
-- Name: idx_risks_source_ext; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_risks_source_ext ON aegis.risks USING btree (source_external_id) WHERE (source_external_id IS NOT NULL);


--
-- Name: idx_risks_status; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_risks_status ON aegis.risks USING btree (status);


--
-- Name: idx_risks_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_risks_tenant ON aegis.risks USING btree (tenant_id);


--
-- Name: idx_rr_scheduled; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_rr_scheduled ON aegis.risk_reviews USING btree (scheduled_date);


--
-- Name: idx_rr_status; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_rr_status ON aegis.risk_reviews USING btree (status);


--
-- Name: idx_rri_review; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_rri_review ON aegis.risk_review_items USING btree (review_id);


--
-- Name: idx_rri_risk; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_rri_risk ON aegis.risk_review_items USING btree (risk_id);


--
-- Name: idx_rrl_related; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_rrl_related ON aegis.risk_related_links USING btree (related_id);


--
-- Name: idx_rrl_risk; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_rrl_risk ON aegis.risk_related_links USING btree (risk_id);


--
-- Name: idx_rs_risk_id; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_rs_risk_id ON aegis.risk_scenarios USING btree (risk_id);


--
-- Name: idx_rsh_created; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_rsh_created ON aegis.risk_score_history USING btree (created_at);


--
-- Name: idx_rsh_risk; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_rsh_risk ON aegis.risk_score_history USING btree (risk_id, created_at);


--
-- Name: idx_rt_status; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_rt_status ON aegis.risk_treatments USING btree (status);


--
-- Name: idx_shared_resp_package; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_shared_resp_package ON aegis.shared_responsibility USING btree (package_id);


--
-- Name: idx_shared_responsibility_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_shared_responsibility_tenant ON aegis.shared_responsibility USING btree (tenant_id);


--
-- Name: idx_sla_incident; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_sla_incident ON aegis.incident_sla_events USING btree (incident_id);


--
-- Name: idx_ssp_control_statements_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_ssp_control_statements_tenant ON aegis.ssp_control_statements USING btree (tenant_id);


--
-- Name: idx_ssp_packages_ssp; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_ssp_packages_ssp ON aegis.ssp_packages USING btree (ssp_id);


--
-- Name: idx_ssp_packages_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_ssp_packages_tenant ON aegis.ssp_packages USING btree (tenant_id);


--
-- Name: idx_ssp_plans_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_ssp_plans_tenant ON aegis.ssp_plans USING btree (tenant_id);


--
-- Name: idx_ssp_statements_ssp; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_ssp_statements_ssp ON aegis.ssp_control_statements USING btree (ssp_id);


--
-- Name: idx_threat_risk_links_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_threat_risk_links_tenant ON aegis.threat_risk_links USING btree (tenant_id);


--
-- Name: idx_threats_category; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_threats_category ON aegis.threats USING btree (category);


--
-- Name: idx_threats_status; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_threats_status ON aegis.threats USING btree (status);


--
-- Name: idx_threats_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_threats_tenant ON aegis.threats USING btree (tenant_id);


--
-- Name: idx_tm_plan; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_tm_plan ON aegis.treatment_milestones USING btree (plan_id);


--
-- Name: idx_tp_risk; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_tp_risk ON aegis.treatment_plans USING btree (risk_id);


--
-- Name: idx_tp_status; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_tp_status ON aegis.treatment_plans USING btree (status);


--
-- Name: idx_treatment_milestones_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_treatment_milestones_tenant ON aegis.treatment_milestones USING btree (tenant_id);


--
-- Name: idx_treatment_plans_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_treatment_plans_tenant ON aegis.treatment_plans USING btree (tenant_id);


--
-- Name: idx_trl_risk; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_trl_risk ON aegis.threat_risk_links USING btree (risk_id);


--
-- Name: idx_trl_threat; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_trl_threat ON aegis.threat_risk_links USING btree (threat_id);


--
-- Name: idx_unp_user; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_unp_user ON aegis.user_notification_prefs USING btree (user_id);


--
-- Name: idx_up_user; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_up_user ON aegis.user_permissions USING btree (user_id);


--
-- Name: idx_users_sso; Type: INDEX; Schema: aegis; Owner: -
--

CREATE UNIQUE INDEX IF NOT EXISTS idx_users_sso ON aegis.users USING btree (sso_provider, sso_subject) WHERE ((sso_provider IS NOT NULL) AND (sso_subject IS NOT NULL));


--
-- Name: idx_users_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_users_tenant ON aegis.users USING btree (tenant_id);


--
-- Name: idx_va_vendor; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_va_vendor ON aegis.vendor_assessments USING btree (vendor_id);


--
-- Name: idx_vc_end_date; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_vc_end_date ON aegis.vendor_contracts USING btree (end_date);


--
-- Name: idx_vc_vendor; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_vc_vendor ON aegis.vendor_contracts USING btree (vendor_id);


--
-- Name: idx_vendor_assessments_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_vendor_assessments_tenant ON aegis.vendor_assessments USING btree (tenant_id);


--
-- Name: idx_vendor_contracts_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_vendor_contracts_tenant ON aegis.vendor_contracts USING btree (tenant_id);


--
-- Name: idx_vendors_status; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_vendors_status ON aegis.vendors USING btree (status);


--
-- Name: idx_vendors_tenant; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_vendors_tenant ON aegis.vendors USING btree (tenant_id);


--
-- Name: idx_vpt_token; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_vpt_token ON aegis.vendor_portal_tokens USING btree (token_hash);


--
-- Name: idx_vpt_vendor; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_vpt_vendor ON aegis.vendor_portal_tokens USING btree (vendor_id);


--
-- Name: idx_wd_endpoint; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_wd_endpoint ON aegis.webhook_deliveries USING btree (endpoint_id);


--
-- Name: idx_wd_status_retry; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_wd_status_retry ON aegis.webhook_deliveries USING btree (status, next_retry_at);


--
-- Name: idx_we_workflow; Type: INDEX; Schema: aegis; Owner: -
--

CREATE INDEX IF NOT EXISTS idx_we_workflow ON aegis.workflow_executions USING btree (workflow_id, triggered_at DESC);


--
-- Name: active_sessions active_sessions_user_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.active_sessions
    ADD CONSTRAINT active_sessions_user_id_fkey FOREIGN KEY (user_id) REFERENCES aegis.users(id) ON DELETE CASCADE;


--
-- Name: activity_log activity_log_user_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.activity_log
    ADD CONSTRAINT activity_log_user_id_fkey FOREIGN KEY (user_id) REFERENCES aegis.users(id);


--
-- Name: alerts alerts_user_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.alerts
    ADD CONSTRAINT alerts_user_id_fkey FOREIGN KEY (user_id) REFERENCES aegis.users(id);


--
-- Name: api_keys api_keys_user_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.api_keys
    ADD CONSTRAINT api_keys_user_id_fkey FOREIGN KEY (user_id) REFERENCES aegis.users(id) ON DELETE CASCADE;


--
-- Name: approval_request_steps approval_request_steps_actioned_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.approval_request_steps
    ADD CONSTRAINT approval_request_steps_actioned_by_fkey FOREIGN KEY (actioned_by) REFERENCES aegis.users(id);


--
-- Name: approval_request_steps approval_request_steps_request_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.approval_request_steps
    ADD CONSTRAINT approval_request_steps_request_id_fkey FOREIGN KEY (request_id) REFERENCES aegis.approval_requests(id) ON DELETE CASCADE;


--
-- Name: approval_request_steps approval_request_steps_required_user_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.approval_request_steps
    ADD CONSTRAINT approval_request_steps_required_user_id_fkey FOREIGN KEY (required_user_id) REFERENCES aegis.users(id);


--
-- Name: approval_requests approval_requests_requested_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.approval_requests
    ADD CONSTRAINT approval_requests_requested_by_fkey FOREIGN KEY (requested_by) REFERENCES aegis.users(id);


--
-- Name: approval_requests approval_requests_template_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.approval_requests
    ADD CONSTRAINT approval_requests_template_id_fkey FOREIGN KEY (template_id) REFERENCES aegis.approval_templates(id);


--
-- Name: approval_template_steps approval_template_steps_required_user_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.approval_template_steps
    ADD CONSTRAINT approval_template_steps_required_user_id_fkey FOREIGN KEY (required_user_id) REFERENCES aegis.users(id);


--
-- Name: approval_template_steps approval_template_steps_template_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.approval_template_steps
    ADD CONSTRAINT approval_template_steps_template_id_fkey FOREIGN KEY (template_id) REFERENCES aegis.approval_templates(id) ON DELETE CASCADE;


--
-- Name: approval_templates approval_templates_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.approval_templates
    ADD CONSTRAINT approval_templates_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: asset_risk_links asset_risk_links_asset_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.asset_risk_links
    ADD CONSTRAINT asset_risk_links_asset_id_fkey FOREIGN KEY (asset_id) REFERENCES aegis.assets(id) ON DELETE CASCADE;


--
-- Name: asset_risk_links asset_risk_links_risk_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.asset_risk_links
    ADD CONSTRAINT asset_risk_links_risk_id_fkey FOREIGN KEY (risk_id) REFERENCES aegis.risks(id) ON DELETE CASCADE;


--
-- Name: asset_risk_links asset_risk_links_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.asset_risk_links
    ADD CONSTRAINT asset_risk_links_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: assets assets_owner_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.assets
    ADD CONSTRAINT assets_owner_id_fkey FOREIGN KEY (owner_id) REFERENCES aegis.users(id);


--
-- Name: assets assets_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.assets
    ADD CONSTRAINT assets_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: audit_findings audit_findings_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.audit_findings
    ADD CONSTRAINT audit_findings_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: audit_findings audit_findings_objective_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.audit_findings
    ADD CONSTRAINT audit_findings_objective_id_fkey FOREIGN KEY (objective_id) REFERENCES aegis.compliance_objectives(id) ON DELETE SET NULL;


--
-- Name: audit_findings audit_findings_owner_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.audit_findings
    ADD CONSTRAINT audit_findings_owner_id_fkey FOREIGN KEY (owner_id) REFERENCES aegis.users(id);


--
-- Name: audit_findings audit_findings_package_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.audit_findings
    ADD CONSTRAINT audit_findings_package_id_fkey FOREIGN KEY (package_id) REFERENCES aegis.compliance_packages(id) ON DELETE SET NULL;


--
-- Name: audit_findings audit_findings_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.audit_findings
    ADD CONSTRAINT audit_findings_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: audit_items audit_items_audit_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.audit_items
    ADD CONSTRAINT audit_items_audit_id_fkey FOREIGN KEY (audit_id) REFERENCES aegis.audits(id) ON DELETE CASCADE;


--
-- Name: audit_items audit_items_objective_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.audit_items
    ADD CONSTRAINT audit_items_objective_id_fkey FOREIGN KEY (objective_id) REFERENCES aegis.compliance_objectives(id);


--
-- Name: audit_items audit_items_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.audit_items
    ADD CONSTRAINT audit_items_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: audit_schedules audit_schedules_assigned_auditor_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.audit_schedules
    ADD CONSTRAINT audit_schedules_assigned_auditor_fkey FOREIGN KEY (assigned_auditor) REFERENCES aegis.users(id);


--
-- Name: audit_schedules audit_schedules_package_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.audit_schedules
    ADD CONSTRAINT audit_schedules_package_id_fkey FOREIGN KEY (package_id) REFERENCES aegis.compliance_packages(id);


--
-- Name: audit_schedules audit_schedules_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.audit_schedules
    ADD CONSTRAINT audit_schedules_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: audits audits_auditor_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.audits
    ADD CONSTRAINT audits_auditor_id_fkey FOREIGN KEY (auditor_id) REFERENCES aegis.users(id);


--
-- Name: audits audits_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.audits
    ADD CONSTRAINT audits_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: audits audits_package_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.audits
    ADD CONSTRAINT audits_package_id_fkey FOREIGN KEY (package_id) REFERENCES aegis.compliance_packages(id);


--
-- Name: audits audits_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.audits
    ADD CONSTRAINT audits_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: automation_logs automation_logs_rule_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.automation_logs
    ADD CONSTRAINT automation_logs_rule_id_fkey FOREIGN KEY (rule_id) REFERENCES aegis.automation_rules(id) ON DELETE CASCADE;


--
-- Name: automation_rules automation_rules_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.automation_rules
    ADD CONSTRAINT automation_rules_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: awareness_assignments awareness_assignments_program_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.awareness_assignments
    ADD CONSTRAINT awareness_assignments_program_id_fkey FOREIGN KEY (program_id) REFERENCES aegis.awareness_programs(id) ON DELETE CASCADE;


--
-- Name: awareness_assignments awareness_assignments_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.awareness_assignments
    ADD CONSTRAINT awareness_assignments_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: awareness_assignments awareness_assignments_user_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.awareness_assignments
    ADD CONSTRAINT awareness_assignments_user_id_fkey FOREIGN KEY (user_id) REFERENCES aegis.users(id) ON DELETE CASCADE;


--
-- Name: awareness_programs awareness_programs_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.awareness_programs
    ADD CONSTRAINT awareness_programs_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: awareness_programs awareness_programs_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.awareness_programs
    ADD CONSTRAINT awareness_programs_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: bcp_exercises bcp_exercises_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.bcp_exercises
    ADD CONSTRAINT bcp_exercises_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: bcp_exercises bcp_exercises_plan_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.bcp_exercises
    ADD CONSTRAINT bcp_exercises_plan_id_fkey FOREIGN KEY (plan_id) REFERENCES aegis.bcp_plans(id) ON DELETE CASCADE;


--
-- Name: bcp_exercises bcp_exercises_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.bcp_exercises
    ADD CONSTRAINT bcp_exercises_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: bcp_plan_sections bcp_plan_sections_plan_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.bcp_plan_sections
    ADD CONSTRAINT bcp_plan_sections_plan_id_fkey FOREIGN KEY (plan_id) REFERENCES aegis.bcp_plans(id) ON DELETE CASCADE;


--
-- Name: bcp_plan_sections bcp_plan_sections_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.bcp_plan_sections
    ADD CONSTRAINT bcp_plan_sections_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: bcp_plans bcp_plans_owner_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.bcp_plans
    ADD CONSTRAINT bcp_plans_owner_id_fkey FOREIGN KEY (owner_id) REFERENCES aegis.users(id);


--
-- Name: bcp_plans bcp_plans_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.bcp_plans
    ADD CONSTRAINT bcp_plans_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: compliance_objectives compliance_objectives_package_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.compliance_objectives
    ADD CONSTRAINT compliance_objectives_package_id_fkey FOREIGN KEY (package_id) REFERENCES aegis.compliance_packages(id) ON DELETE CASCADE;


--
-- Name: compliance_objectives compliance_objectives_parent_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.compliance_objectives
    ADD CONSTRAINT compliance_objectives_parent_id_fkey FOREIGN KEY (parent_id) REFERENCES aegis.compliance_objectives(id);


--
-- Name: compliance_objectives compliance_objectives_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.compliance_objectives
    ADD CONSTRAINT compliance_objectives_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: compliance_packages compliance_packages_imported_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.compliance_packages
    ADD CONSTRAINT compliance_packages_imported_by_fkey FOREIGN KEY (imported_by) REFERENCES aegis.users(id);


--
-- Name: compliance_packages compliance_packages_standard_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.compliance_packages
    ADD CONSTRAINT compliance_packages_standard_id_fkey FOREIGN KEY (standard_id) REFERENCES aegis.standards(id) ON DELETE CASCADE;


--
-- Name: compliance_packages compliance_packages_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.compliance_packages
    ADD CONSTRAINT compliance_packages_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: control_implementations control_implementations_assigned_to_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.control_implementations
    ADD CONSTRAINT control_implementations_assigned_to_fkey FOREIGN KEY (assigned_to) REFERENCES aegis.users(id);


--
-- Name: control_implementations control_implementations_objective_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.control_implementations
    ADD CONSTRAINT control_implementations_objective_id_fkey FOREIGN KEY (objective_id) REFERENCES aegis.compliance_objectives(id) ON DELETE CASCADE;


--
-- Name: control_implementations control_implementations_reviewed_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.control_implementations
    ADD CONSTRAINT control_implementations_reviewed_by_fkey FOREIGN KEY (reviewed_by) REFERENCES aegis.users(id);


--
-- Name: control_implementations control_implementations_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.control_implementations
    ADD CONSTRAINT control_implementations_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: control_mappings control_mappings_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.control_mappings
    ADD CONSTRAINT control_mappings_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: control_mappings control_mappings_source_obj_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.control_mappings
    ADD CONSTRAINT control_mappings_source_obj_id_fkey FOREIGN KEY (source_obj_id) REFERENCES aegis.compliance_objectives(id) ON DELETE CASCADE;


--
-- Name: control_mappings control_mappings_target_obj_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.control_mappings
    ADD CONSTRAINT control_mappings_target_obj_id_fkey FOREIGN KEY (target_obj_id) REFERENCES aegis.compliance_objectives(id) ON DELETE CASCADE;


--
-- Name: control_mappings control_mappings_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.control_mappings
    ADD CONSTRAINT control_mappings_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: control_tests control_tests_objective_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.control_tests
    ADD CONSTRAINT control_tests_objective_id_fkey FOREIGN KEY (objective_id) REFERENCES aegis.compliance_objectives(id) ON DELETE CASCADE;


--
-- Name: control_tests control_tests_package_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.control_tests
    ADD CONSTRAINT control_tests_package_id_fkey FOREIGN KEY (package_id) REFERENCES aegis.compliance_packages(id) ON DELETE CASCADE;


--
-- Name: control_tests control_tests_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.control_tests
    ADD CONSTRAINT control_tests_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: control_tests control_tests_tester_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.control_tests
    ADD CONSTRAINT control_tests_tester_id_fkey FOREIGN KEY (tester_id) REFERENCES aegis.users(id);


--
-- Name: cui_inventory cui_inventory_asset_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.cui_inventory
    ADD CONSTRAINT cui_inventory_asset_id_fkey FOREIGN KEY (asset_id) REFERENCES aegis.assets(id) ON DELETE SET NULL;


--
-- Name: cui_inventory cui_inventory_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.cui_inventory
    ADD CONSTRAINT cui_inventory_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: cui_inventory cui_inventory_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.cui_inventory
    ADD CONSTRAINT cui_inventory_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: custom_dashboards custom_dashboards_owner_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.custom_dashboards
    ADD CONSTRAINT custom_dashboards_owner_id_fkey FOREIGN KEY (owner_id) REFERENCES aegis.users(id) ON DELETE CASCADE;


--
-- Name: custom_field_definitions custom_field_definitions_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.custom_field_definitions
    ADD CONSTRAINT custom_field_definitions_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: custom_field_values custom_field_values_definition_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.custom_field_values
    ADD CONSTRAINT custom_field_values_definition_id_fkey FOREIGN KEY (definition_id) REFERENCES aegis.custom_field_definitions(id) ON DELETE CASCADE;


--
-- Name: custom_field_values custom_field_values_updated_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.custom_field_values
    ADD CONSTRAINT custom_field_values_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES aegis.users(id);


--
-- Name: dashboard_widgets dashboard_widgets_dashboard_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.dashboard_widgets
    ADD CONSTRAINT dashboard_widgets_dashboard_id_fkey FOREIGN KEY (dashboard_id) REFERENCES aegis.custom_dashboards(id) ON DELETE CASCADE;


--
-- Name: data_subject_requests data_subject_requests_assigned_to_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.data_subject_requests
    ADD CONSTRAINT data_subject_requests_assigned_to_fkey FOREIGN KEY (assigned_to) REFERENCES aegis.users(id);


--
-- Name: data_subject_requests data_subject_requests_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.data_subject_requests
    ADD CONSTRAINT data_subject_requests_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: document_versions document_versions_document_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.document_versions
    ADD CONSTRAINT document_versions_document_id_fkey FOREIGN KEY (document_id) REFERENCES aegis.documents(id) ON DELETE CASCADE;


--
-- Name: document_versions document_versions_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.document_versions
    ADD CONSTRAINT document_versions_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: document_versions document_versions_uploaded_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.document_versions
    ADD CONSTRAINT document_versions_uploaded_by_fkey FOREIGN KEY (uploaded_by) REFERENCES aegis.users(id);


--
-- Name: documents documents_approver_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.documents
    ADD CONSTRAINT documents_approver_id_fkey FOREIGN KEY (approver_id) REFERENCES aegis.users(id);


--
-- Name: documents documents_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.documents
    ADD CONSTRAINT documents_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: documents documents_owner_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.documents
    ADD CONSTRAINT documents_owner_id_fkey FOREIGN KEY (owner_id) REFERENCES aegis.users(id);


--
-- Name: documents documents_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.documents
    ADD CONSTRAINT documents_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: email_templates email_templates_updated_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.email_templates
    ADD CONSTRAINT email_templates_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES aegis.users(id);


--
-- Name: email_unsubscribes email_unsubscribes_user_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.email_unsubscribes
    ADD CONSTRAINT email_unsubscribes_user_id_fkey FOREIGN KEY (user_id) REFERENCES aegis.users(id) ON DELETE SET NULL;


--
-- Name: email_verification_tokens email_verification_tokens_user_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.email_verification_tokens
    ADD CONSTRAINT email_verification_tokens_user_id_fkey FOREIGN KEY (user_id) REFERENCES aegis.users(id) ON DELETE CASCADE;


--
-- Name: entity_tags entity_tags_tag_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.entity_tags
    ADD CONSTRAINT entity_tags_tag_id_fkey FOREIGN KEY (tag_id) REFERENCES aegis.tags(id) ON DELETE CASCADE;


--
-- Name: evidence_files evidence_files_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.evidence_files
    ADD CONSTRAINT evidence_files_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: evidence_files evidence_files_uploaded_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.evidence_files
    ADD CONSTRAINT evidence_files_uploaded_by_fkey FOREIGN KEY (uploaded_by) REFERENCES aegis.users(id);


--
-- Name: evidence evidence_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.evidence
    ADD CONSTRAINT evidence_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: evidence evidence_uploaded_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.evidence
    ADD CONSTRAINT evidence_uploaded_by_fkey FOREIGN KEY (uploaded_by) REFERENCES aegis.users(id);


--
-- Name: finding_updates finding_updates_finding_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.finding_updates
    ADD CONSTRAINT finding_updates_finding_id_fkey FOREIGN KEY (finding_id) REFERENCES aegis.audit_findings(id) ON DELETE CASCADE;


--
-- Name: finding_updates finding_updates_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.finding_updates
    ADD CONSTRAINT finding_updates_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: finding_updates finding_updates_user_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.finding_updates
    ADD CONSTRAINT finding_updates_user_id_fkey FOREIGN KEY (user_id) REFERENCES aegis.users(id);


--
-- Name: grc_project_links grc_project_links_project_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.grc_project_links
    ADD CONSTRAINT grc_project_links_project_id_fkey FOREIGN KEY (project_id) REFERENCES aegis.grc_projects(id) ON DELETE CASCADE;


--
-- Name: grc_project_links grc_project_links_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.grc_project_links
    ADD CONSTRAINT grc_project_links_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: grc_project_tasks grc_project_tasks_assigned_to_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.grc_project_tasks
    ADD CONSTRAINT grc_project_tasks_assigned_to_fkey FOREIGN KEY (assigned_to) REFERENCES aegis.users(id);


--
-- Name: grc_project_tasks grc_project_tasks_project_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.grc_project_tasks
    ADD CONSTRAINT grc_project_tasks_project_id_fkey FOREIGN KEY (project_id) REFERENCES aegis.grc_projects(id) ON DELETE CASCADE;


--
-- Name: grc_project_tasks grc_project_tasks_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.grc_project_tasks
    ADD CONSTRAINT grc_project_tasks_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: grc_projects grc_projects_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.grc_projects
    ADD CONSTRAINT grc_projects_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: grc_projects grc_projects_project_lead_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.grc_projects
    ADD CONSTRAINT grc_projects_project_lead_fkey FOREIGN KEY (project_lead) REFERENCES aegis.users(id);


--
-- Name: grc_projects grc_projects_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.grc_projects
    ADD CONSTRAINT grc_projects_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: incident_playbook_runs incident_playbook_runs_incident_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.incident_playbook_runs
    ADD CONSTRAINT incident_playbook_runs_incident_id_fkey FOREIGN KEY (incident_id) REFERENCES aegis.incidents(id) ON DELETE CASCADE;


--
-- Name: incident_playbook_runs incident_playbook_runs_playbook_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.incident_playbook_runs
    ADD CONSTRAINT incident_playbook_runs_playbook_id_fkey FOREIGN KEY (playbook_id) REFERENCES aegis.playbooks(id);


--
-- Name: incident_playbook_runs incident_playbook_runs_started_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.incident_playbook_runs
    ADD CONSTRAINT incident_playbook_runs_started_by_fkey FOREIGN KEY (started_by) REFERENCES aegis.users(id);


--
-- Name: incident_sla_events incident_sla_events_incident_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.incident_sla_events
    ADD CONSTRAINT incident_sla_events_incident_id_fkey FOREIGN KEY (incident_id) REFERENCES aegis.incidents(id) ON DELETE CASCADE;


--
-- Name: incident_sla_events incident_sla_events_recorded_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.incident_sla_events
    ADD CONSTRAINT incident_sla_events_recorded_by_fkey FOREIGN KEY (recorded_by) REFERENCES aegis.users(id);


--
-- Name: incident_sla_events incident_sla_events_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.incident_sla_events
    ADD CONSTRAINT incident_sla_events_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: incident_updates incident_updates_incident_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.incident_updates
    ADD CONSTRAINT incident_updates_incident_id_fkey FOREIGN KEY (incident_id) REFERENCES aegis.incidents(id) ON DELETE CASCADE;


--
-- Name: incident_updates incident_updates_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.incident_updates
    ADD CONSTRAINT incident_updates_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: incident_updates incident_updates_user_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.incident_updates
    ADD CONSTRAINT incident_updates_user_id_fkey FOREIGN KEY (user_id) REFERENCES aegis.users(id);


--
-- Name: incidents incidents_assigned_to_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.incidents
    ADD CONSTRAINT incidents_assigned_to_fkey FOREIGN KEY (assigned_to) REFERENCES aegis.users(id);


--
-- Name: incidents incidents_reported_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.incidents
    ADD CONSTRAINT incidents_reported_by_fkey FOREIGN KEY (reported_by) REFERENCES aegis.users(id);


--
-- Name: incidents incidents_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.incidents
    ADD CONSTRAINT incidents_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: issue_updates issue_updates_issue_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.issue_updates
    ADD CONSTRAINT issue_updates_issue_id_fkey FOREIGN KEY (issue_id) REFERENCES aegis.issues(id) ON DELETE CASCADE;


--
-- Name: issue_updates issue_updates_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.issue_updates
    ADD CONSTRAINT issue_updates_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: issue_updates issue_updates_user_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.issue_updates
    ADD CONSTRAINT issue_updates_user_id_fkey FOREIGN KEY (user_id) REFERENCES aegis.users(id);


--
-- Name: issues issues_assigned_to_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.issues
    ADD CONSTRAINT issues_assigned_to_fkey FOREIGN KEY (assigned_to) REFERENCES aegis.users(id);


--
-- Name: issues issues_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.issues
    ADD CONSTRAINT issues_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: issues issues_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.issues
    ADD CONSTRAINT issues_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: kri_values kri_values_kri_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.kri_values
    ADD CONSTRAINT kri_values_kri_id_fkey FOREIGN KEY (kri_id) REFERENCES aegis.kris(id) ON DELETE CASCADE;


--
-- Name: kri_values kri_values_recorded_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.kri_values
    ADD CONSTRAINT kri_values_recorded_by_fkey FOREIGN KEY (recorded_by) REFERENCES aegis.users(id);


--
-- Name: kri_values kri_values_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.kri_values
    ADD CONSTRAINT kri_values_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: kris kris_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.kris
    ADD CONSTRAINT kris_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: kris kris_linked_risk_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.kris
    ADD CONSTRAINT kris_linked_risk_id_fkey FOREIGN KEY (linked_risk_id) REFERENCES aegis.risks(id) ON DELETE SET NULL;


--
-- Name: kris kris_owner_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.kris
    ADD CONSTRAINT kris_owner_id_fkey FOREIGN KEY (owner_id) REFERENCES aegis.users(id);


--
-- Name: kris kris_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.kris
    ADD CONSTRAINT kris_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: mfa_backup_codes mfa_backup_codes_user_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.mfa_backup_codes
    ADD CONSTRAINT mfa_backup_codes_user_id_fkey FOREIGN KEY (user_id) REFERENCES aegis.users(id) ON DELETE CASCADE;


--
-- Name: odp_entries odp_entries_objective_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.odp_entries
    ADD CONSTRAINT odp_entries_objective_id_fkey FOREIGN KEY (objective_id) REFERENCES aegis.compliance_objectives(id) ON DELETE CASCADE;


--
-- Name: odp_entries odp_entries_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.odp_entries
    ADD CONSTRAINT odp_entries_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: odp_entries odp_entries_updated_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.odp_entries
    ADD CONSTRAINT odp_entries_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES aegis.users(id);


--
-- Name: password_reset_tokens password_reset_tokens_user_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_user_id_fkey FOREIGN KEY (user_id) REFERENCES aegis.users(id) ON DELETE CASCADE;


--
-- Name: playbook_step_completions playbook_step_completions_completed_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.playbook_step_completions
    ADD CONSTRAINT playbook_step_completions_completed_by_fkey FOREIGN KEY (completed_by) REFERENCES aegis.users(id);


--
-- Name: playbook_step_completions playbook_step_completions_run_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.playbook_step_completions
    ADD CONSTRAINT playbook_step_completions_run_id_fkey FOREIGN KEY (run_id) REFERENCES aegis.incident_playbook_runs(id) ON DELETE CASCADE;


--
-- Name: playbook_step_completions playbook_step_completions_step_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.playbook_step_completions
    ADD CONSTRAINT playbook_step_completions_step_id_fkey FOREIGN KEY (step_id) REFERENCES aegis.playbook_steps(id) ON DELETE CASCADE;


--
-- Name: playbook_steps playbook_steps_playbook_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.playbook_steps
    ADD CONSTRAINT playbook_steps_playbook_id_fkey FOREIGN KEY (playbook_id) REFERENCES aegis.playbooks(id) ON DELETE CASCADE;


--
-- Name: playbooks playbooks_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.playbooks
    ADD CONSTRAINT playbooks_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: poam_items poam_items_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.poam_items
    ADD CONSTRAINT poam_items_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: poam_items poam_items_objective_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.poam_items
    ADD CONSTRAINT poam_items_objective_id_fkey FOREIGN KEY (objective_id) REFERENCES aegis.compliance_objectives(id) ON DELETE SET NULL;


--
-- Name: poam_items poam_items_owner_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.poam_items
    ADD CONSTRAINT poam_items_owner_id_fkey FOREIGN KEY (owner_id) REFERENCES aegis.users(id);


--
-- Name: poam_items poam_items_package_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.poam_items
    ADD CONSTRAINT poam_items_package_id_fkey FOREIGN KEY (package_id) REFERENCES aegis.compliance_packages(id) ON DELETE SET NULL;


--
-- Name: poam_items poam_items_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.poam_items
    ADD CONSTRAINT poam_items_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: poam_milestones poam_milestones_poam_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.poam_milestones
    ADD CONSTRAINT poam_milestones_poam_id_fkey FOREIGN KEY (poam_id) REFERENCES aegis.poam_items(id) ON DELETE CASCADE;


--
-- Name: poam_milestones poam_milestones_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.poam_milestones
    ADD CONSTRAINT poam_milestones_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: policies policies_approver_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policies
    ADD CONSTRAINT policies_approver_id_fkey FOREIGN KEY (approver_id) REFERENCES aegis.users(id);


--
-- Name: policies policies_owner_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policies
    ADD CONSTRAINT policies_owner_id_fkey FOREIGN KEY (owner_id) REFERENCES aegis.users(id);


--
-- Name: policies policies_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policies
    ADD CONSTRAINT policies_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: policy_attestation_campaigns policy_attestation_campaigns_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policy_attestation_campaigns
    ADD CONSTRAINT policy_attestation_campaigns_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: policy_attestation_campaigns policy_attestation_campaigns_policy_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policy_attestation_campaigns
    ADD CONSTRAINT policy_attestation_campaigns_policy_id_fkey FOREIGN KEY (policy_id) REFERENCES aegis.policies(id) ON DELETE CASCADE;


--
-- Name: policy_attestation_campaigns policy_attestation_campaigns_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policy_attestation_campaigns
    ADD CONSTRAINT policy_attestation_campaigns_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: policy_attestations policy_attestations_policy_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policy_attestations
    ADD CONSTRAINT policy_attestations_policy_id_fkey FOREIGN KEY (policy_id) REFERENCES aegis.policies(id) ON DELETE CASCADE;


--
-- Name: policy_attestations policy_attestations_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policy_attestations
    ADD CONSTRAINT policy_attestations_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: policy_attestations policy_attestations_user_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policy_attestations
    ADD CONSTRAINT policy_attestations_user_id_fkey FOREIGN KEY (user_id) REFERENCES aegis.users(id) ON DELETE CASCADE;


--
-- Name: policy_mappings policy_mappings_objective_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policy_mappings
    ADD CONSTRAINT policy_mappings_objective_id_fkey FOREIGN KEY (objective_id) REFERENCES aegis.compliance_objectives(id) ON DELETE CASCADE;


--
-- Name: policy_mappings policy_mappings_policy_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policy_mappings
    ADD CONSTRAINT policy_mappings_policy_id_fkey FOREIGN KEY (policy_id) REFERENCES aegis.policies(id) ON DELETE CASCADE;


--
-- Name: policy_mappings policy_mappings_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policy_mappings
    ADD CONSTRAINT policy_mappings_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: policy_reviews policy_reviews_policy_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policy_reviews
    ADD CONSTRAINT policy_reviews_policy_id_fkey FOREIGN KEY (policy_id) REFERENCES aegis.policies(id) ON DELETE CASCADE;


--
-- Name: policy_reviews policy_reviews_reviewer_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policy_reviews
    ADD CONSTRAINT policy_reviews_reviewer_id_fkey FOREIGN KEY (reviewer_id) REFERENCES aegis.users(id);


--
-- Name: policy_reviews policy_reviews_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policy_reviews
    ADD CONSTRAINT policy_reviews_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: policy_versions policy_versions_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policy_versions
    ADD CONSTRAINT policy_versions_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: policy_versions policy_versions_policy_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policy_versions
    ADD CONSTRAINT policy_versions_policy_id_fkey FOREIGN KEY (policy_id) REFERENCES aegis.policies(id) ON DELETE CASCADE;


--
-- Name: policy_versions policy_versions_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.policy_versions
    ADD CONSTRAINT policy_versions_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: privacy_records privacy_records_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.privacy_records
    ADD CONSTRAINT privacy_records_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: privacy_records privacy_records_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.privacy_records
    ADD CONSTRAINT privacy_records_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: questionnaire_answers questionnaire_answers_question_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.questionnaire_answers
    ADD CONSTRAINT questionnaire_answers_question_id_fkey FOREIGN KEY (question_id) REFERENCES aegis.questionnaire_questions(id);


--
-- Name: questionnaire_answers questionnaire_answers_response_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.questionnaire_answers
    ADD CONSTRAINT questionnaire_answers_response_id_fkey FOREIGN KEY (response_id) REFERENCES aegis.questionnaire_responses(id) ON DELETE CASCADE;


--
-- Name: questionnaire_answers questionnaire_answers_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.questionnaire_answers
    ADD CONSTRAINT questionnaire_answers_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: questionnaire_assignments questionnaire_assignments_assigned_to_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.questionnaire_assignments
    ADD CONSTRAINT questionnaire_assignments_assigned_to_fkey FOREIGN KEY (assigned_to) REFERENCES aegis.users(id);


--
-- Name: questionnaire_assignments questionnaire_assignments_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.questionnaire_assignments
    ADD CONSTRAINT questionnaire_assignments_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: questionnaire_assignments questionnaire_assignments_questionnaire_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.questionnaire_assignments
    ADD CONSTRAINT questionnaire_assignments_questionnaire_id_fkey FOREIGN KEY (questionnaire_id) REFERENCES aegis.questionnaires(id);


--
-- Name: questionnaire_assignments questionnaire_assignments_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.questionnaire_assignments
    ADD CONSTRAINT questionnaire_assignments_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: questionnaire_questions questionnaire_questions_questionnaire_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.questionnaire_questions
    ADD CONSTRAINT questionnaire_questions_questionnaire_id_fkey FOREIGN KEY (questionnaire_id) REFERENCES aegis.questionnaires(id) ON DELETE CASCADE;


--
-- Name: questionnaire_questions questionnaire_questions_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.questionnaire_questions
    ADD CONSTRAINT questionnaire_questions_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: questionnaire_responses questionnaire_responses_assignment_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.questionnaire_responses
    ADD CONSTRAINT questionnaire_responses_assignment_id_fkey FOREIGN KEY (assignment_id) REFERENCES aegis.questionnaire_assignments(id) ON DELETE CASCADE;


--
-- Name: questionnaire_responses questionnaire_responses_submitted_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.questionnaire_responses
    ADD CONSTRAINT questionnaire_responses_submitted_by_fkey FOREIGN KEY (submitted_by) REFERENCES aegis.users(id);


--
-- Name: questionnaire_responses questionnaire_responses_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.questionnaire_responses
    ADD CONSTRAINT questionnaire_responses_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: questionnaires questionnaires_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.questionnaires
    ADD CONSTRAINT questionnaires_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: questionnaires questionnaires_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.questionnaires
    ADD CONSTRAINT questionnaires_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: raci_assignments raci_assignments_objective_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.raci_assignments
    ADD CONSTRAINT raci_assignments_objective_id_fkey FOREIGN KEY (objective_id) REFERENCES aegis.compliance_objectives(id) ON DELETE CASCADE;


--
-- Name: raci_assignments raci_assignments_package_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.raci_assignments
    ADD CONSTRAINT raci_assignments_package_id_fkey FOREIGN KEY (package_id) REFERENCES aegis.compliance_packages(id) ON DELETE CASCADE;


--
-- Name: raci_assignments raci_assignments_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.raci_assignments
    ADD CONSTRAINT raci_assignments_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: raci_assignments raci_assignments_user_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.raci_assignments
    ADD CONSTRAINT raci_assignments_user_id_fkey FOREIGN KEY (user_id) REFERENCES aegis.users(id) ON DELETE CASCADE;


--
-- Name: report_schedule_logs report_schedule_logs_schedule_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.report_schedule_logs
    ADD CONSTRAINT report_schedule_logs_schedule_id_fkey FOREIGN KEY (schedule_id) REFERENCES aegis.report_schedules(id) ON DELETE CASCADE;


--
-- Name: report_schedules report_schedules_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.report_schedules
    ADD CONSTRAINT report_schedules_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: risk_acceptances risk_acceptances_accepted_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_acceptances
    ADD CONSTRAINT risk_acceptances_accepted_by_fkey FOREIGN KEY (accepted_by) REFERENCES aegis.users(id);


--
-- Name: risk_acceptances risk_acceptances_renewed_from_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_acceptances
    ADD CONSTRAINT risk_acceptances_renewed_from_fkey FOREIGN KEY (renewed_from) REFERENCES aegis.risk_acceptances(id);


--
-- Name: risk_acceptances risk_acceptances_revoked_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_acceptances
    ADD CONSTRAINT risk_acceptances_revoked_by_fkey FOREIGN KEY (revoked_by) REFERENCES aegis.users(id);


--
-- Name: risk_acceptances risk_acceptances_risk_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_acceptances
    ADD CONSTRAINT risk_acceptances_risk_id_fkey FOREIGN KEY (risk_id) REFERENCES aegis.risks(id) ON DELETE CASCADE;


--
-- Name: risk_acceptances risk_acceptances_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_acceptances
    ADD CONSTRAINT risk_acceptances_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: risk_appetite risk_appetite_updated_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_appetite
    ADD CONSTRAINT risk_appetite_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES aegis.users(id);


--
-- Name: risk_bowtie_barriers risk_bowtie_barriers_control_implementation_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_bowtie_barriers
    ADD CONSTRAINT risk_bowtie_barriers_control_implementation_id_fkey FOREIGN KEY (control_implementation_id) REFERENCES aegis.control_implementations(id) ON DELETE SET NULL;


--
-- Name: risk_bowtie_barriers risk_bowtie_barriers_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_bowtie_barriers
    ADD CONSTRAINT risk_bowtie_barriers_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: risk_bowtie_barriers risk_bowtie_barriers_risk_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_bowtie_barriers
    ADD CONSTRAINT risk_bowtie_barriers_risk_id_fkey FOREIGN KEY (risk_id) REFERENCES aegis.risks(id) ON DELETE CASCADE;


--
-- Name: risk_bowtie_barriers risk_bowtie_barriers_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_bowtie_barriers
    ADD CONSTRAINT risk_bowtie_barriers_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: risk_bowtie_causes risk_bowtie_causes_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_bowtie_causes
    ADD CONSTRAINT risk_bowtie_causes_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: risk_bowtie_causes risk_bowtie_causes_risk_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_bowtie_causes
    ADD CONSTRAINT risk_bowtie_causes_risk_id_fkey FOREIGN KEY (risk_id) REFERENCES aegis.risks(id) ON DELETE CASCADE;


--
-- Name: risk_bowtie_causes risk_bowtie_causes_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_bowtie_causes
    ADD CONSTRAINT risk_bowtie_causes_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: risk_bowtie_consequences risk_bowtie_consequences_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_bowtie_consequences
    ADD CONSTRAINT risk_bowtie_consequences_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: risk_bowtie_consequences risk_bowtie_consequences_risk_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_bowtie_consequences
    ADD CONSTRAINT risk_bowtie_consequences_risk_id_fkey FOREIGN KEY (risk_id) REFERENCES aegis.risks(id) ON DELETE CASCADE;


--
-- Name: risk_bowtie_consequences risk_bowtie_consequences_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_bowtie_consequences
    ADD CONSTRAINT risk_bowtie_consequences_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: risk_control_links risk_control_links_control_implementation_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_control_links
    ADD CONSTRAINT risk_control_links_control_implementation_id_fkey FOREIGN KEY (control_implementation_id) REFERENCES aegis.control_implementations(id) ON DELETE CASCADE;


--
-- Name: risk_control_links risk_control_links_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_control_links
    ADD CONSTRAINT risk_control_links_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: risk_control_links risk_control_links_risk_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_control_links
    ADD CONSTRAINT risk_control_links_risk_id_fkey FOREIGN KEY (risk_id) REFERENCES aegis.risks(id) ON DELETE CASCADE;


--
-- Name: risk_control_links risk_control_links_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_control_links
    ADD CONSTRAINT risk_control_links_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: risk_exceptions risk_exceptions_approved_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_exceptions
    ADD CONSTRAINT risk_exceptions_approved_by_fkey FOREIGN KEY (approved_by) REFERENCES aegis.users(id);


--
-- Name: risk_exceptions risk_exceptions_requested_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_exceptions
    ADD CONSTRAINT risk_exceptions_requested_by_fkey FOREIGN KEY (requested_by) REFERENCES aegis.users(id);


--
-- Name: risk_exceptions risk_exceptions_risk_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_exceptions
    ADD CONSTRAINT risk_exceptions_risk_id_fkey FOREIGN KEY (risk_id) REFERENCES aegis.risks(id) ON DELETE CASCADE;


--
-- Name: risk_exceptions risk_exceptions_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_exceptions
    ADD CONSTRAINT risk_exceptions_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: risk_related_links risk_related_links_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_related_links
    ADD CONSTRAINT risk_related_links_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: risk_related_links risk_related_links_related_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_related_links
    ADD CONSTRAINT risk_related_links_related_id_fkey FOREIGN KEY (related_id) REFERENCES aegis.risks(id) ON DELETE CASCADE;


--
-- Name: risk_related_links risk_related_links_risk_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_related_links
    ADD CONSTRAINT risk_related_links_risk_id_fkey FOREIGN KEY (risk_id) REFERENCES aegis.risks(id) ON DELETE CASCADE;


--
-- Name: risk_related_links risk_related_links_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_related_links
    ADD CONSTRAINT risk_related_links_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: risk_review_items risk_review_items_review_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_review_items
    ADD CONSTRAINT risk_review_items_review_id_fkey FOREIGN KEY (review_id) REFERENCES aegis.risk_reviews(id) ON DELETE CASCADE;


--
-- Name: risk_review_items risk_review_items_reviewed_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_review_items
    ADD CONSTRAINT risk_review_items_reviewed_by_fkey FOREIGN KEY (reviewed_by) REFERENCES aegis.users(id);


--
-- Name: risk_review_items risk_review_items_risk_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_review_items
    ADD CONSTRAINT risk_review_items_risk_id_fkey FOREIGN KEY (risk_id) REFERENCES aegis.risks(id) ON DELETE CASCADE;


--
-- Name: risk_review_items risk_review_items_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_review_items
    ADD CONSTRAINT risk_review_items_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: risk_reviews risk_reviews_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_reviews
    ADD CONSTRAINT risk_reviews_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: risk_reviews risk_reviews_lead_reviewer_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_reviews
    ADD CONSTRAINT risk_reviews_lead_reviewer_id_fkey FOREIGN KEY (lead_reviewer_id) REFERENCES aegis.users(id);


--
-- Name: risk_reviews risk_reviews_sign_off_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_reviews
    ADD CONSTRAINT risk_reviews_sign_off_by_fkey FOREIGN KEY (sign_off_by) REFERENCES aegis.users(id);


--
-- Name: risk_reviews risk_reviews_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_reviews
    ADD CONSTRAINT risk_reviews_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: risk_scenarios risk_scenarios_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_scenarios
    ADD CONSTRAINT risk_scenarios_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: risk_scenarios risk_scenarios_risk_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_scenarios
    ADD CONSTRAINT risk_scenarios_risk_id_fkey FOREIGN KEY (risk_id) REFERENCES aegis.risks(id) ON DELETE CASCADE;


--
-- Name: risk_scenarios risk_scenarios_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_scenarios
    ADD CONSTRAINT risk_scenarios_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: risk_score_history risk_score_history_changed_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_score_history
    ADD CONSTRAINT risk_score_history_changed_by_fkey FOREIGN KEY (changed_by) REFERENCES aegis.users(id);


--
-- Name: risk_score_history risk_score_history_risk_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_score_history
    ADD CONSTRAINT risk_score_history_risk_id_fkey FOREIGN KEY (risk_id) REFERENCES aegis.risks(id) ON DELETE CASCADE;


--
-- Name: risk_score_history risk_score_history_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_score_history
    ADD CONSTRAINT risk_score_history_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: risk_treatments risk_treatments_owner_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_treatments
    ADD CONSTRAINT risk_treatments_owner_id_fkey FOREIGN KEY (owner_id) REFERENCES aegis.users(id);


--
-- Name: risk_treatments risk_treatments_risk_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_treatments
    ADD CONSTRAINT risk_treatments_risk_id_fkey FOREIGN KEY (risk_id) REFERENCES aegis.risks(id) ON DELETE CASCADE;


--
-- Name: risk_treatments risk_treatments_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risk_treatments
    ADD CONSTRAINT risk_treatments_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: risks risks_category_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risks
    ADD CONSTRAINT risks_category_id_fkey FOREIGN KEY (category_id) REFERENCES aegis.risk_categories(id);


--
-- Name: risks risks_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risks
    ADD CONSTRAINT risks_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: risks risks_owner_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risks
    ADD CONSTRAINT risks_owner_id_fkey FOREIGN KEY (owner_id) REFERENCES aegis.users(id);


--
-- Name: risks risks_parent_risk_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risks
    ADD CONSTRAINT risks_parent_risk_id_fkey FOREIGN KEY (parent_risk_id) REFERENCES aegis.risks(id);


--
-- Name: risks risks_reviewed_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risks
    ADD CONSTRAINT risks_reviewed_by_fkey FOREIGN KEY (reviewed_by) REFERENCES aegis.users(id);


--
-- Name: risks risks_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.risks
    ADD CONSTRAINT risks_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: shared_responsibility shared_responsibility_objective_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.shared_responsibility
    ADD CONSTRAINT shared_responsibility_objective_id_fkey FOREIGN KEY (objective_id) REFERENCES aegis.compliance_objectives(id) ON DELETE CASCADE;


--
-- Name: shared_responsibility shared_responsibility_package_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.shared_responsibility
    ADD CONSTRAINT shared_responsibility_package_id_fkey FOREIGN KEY (package_id) REFERENCES aegis.compliance_packages(id) ON DELETE CASCADE;


--
-- Name: shared_responsibility shared_responsibility_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.shared_responsibility
    ADD CONSTRAINT shared_responsibility_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: ssp_control_statements ssp_control_statements_objective_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.ssp_control_statements
    ADD CONSTRAINT ssp_control_statements_objective_id_fkey FOREIGN KEY (objective_id) REFERENCES aegis.compliance_objectives(id) ON DELETE CASCADE;


--
-- Name: ssp_control_statements ssp_control_statements_ssp_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.ssp_control_statements
    ADD CONSTRAINT ssp_control_statements_ssp_id_fkey FOREIGN KEY (ssp_id) REFERENCES aegis.ssp_plans(id) ON DELETE CASCADE;


--
-- Name: ssp_control_statements ssp_control_statements_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.ssp_control_statements
    ADD CONSTRAINT ssp_control_statements_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: ssp_packages ssp_packages_package_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.ssp_packages
    ADD CONSTRAINT ssp_packages_package_id_fkey FOREIGN KEY (package_id) REFERENCES aegis.compliance_packages(id) ON DELETE CASCADE;


--
-- Name: ssp_packages ssp_packages_ssp_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.ssp_packages
    ADD CONSTRAINT ssp_packages_ssp_id_fkey FOREIGN KEY (ssp_id) REFERENCES aegis.ssp_plans(id) ON DELETE CASCADE;


--
-- Name: ssp_packages ssp_packages_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.ssp_packages
    ADD CONSTRAINT ssp_packages_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: ssp_plans ssp_plans_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.ssp_plans
    ADD CONSTRAINT ssp_plans_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: ssp_plans ssp_plans_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.ssp_plans
    ADD CONSTRAINT ssp_plans_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: threat_risk_links threat_risk_links_risk_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.threat_risk_links
    ADD CONSTRAINT threat_risk_links_risk_id_fkey FOREIGN KEY (risk_id) REFERENCES aegis.risks(id) ON DELETE CASCADE;


--
-- Name: threat_risk_links threat_risk_links_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.threat_risk_links
    ADD CONSTRAINT threat_risk_links_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: threat_risk_links threat_risk_links_threat_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.threat_risk_links
    ADD CONSTRAINT threat_risk_links_threat_id_fkey FOREIGN KEY (threat_id) REFERENCES aegis.threats(id) ON DELETE CASCADE;


--
-- Name: threats threats_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.threats
    ADD CONSTRAINT threats_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: threats threats_owner_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.threats
    ADD CONSTRAINT threats_owner_id_fkey FOREIGN KEY (owner_id) REFERENCES aegis.users(id);


--
-- Name: threats threats_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.threats
    ADD CONSTRAINT threats_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: treatment_milestones treatment_milestones_completed_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.treatment_milestones
    ADD CONSTRAINT treatment_milestones_completed_by_fkey FOREIGN KEY (completed_by) REFERENCES aegis.users(id);


--
-- Name: treatment_milestones treatment_milestones_plan_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.treatment_milestones
    ADD CONSTRAINT treatment_milestones_plan_id_fkey FOREIGN KEY (plan_id) REFERENCES aegis.treatment_plans(id) ON DELETE CASCADE;


--
-- Name: treatment_milestones treatment_milestones_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.treatment_milestones
    ADD CONSTRAINT treatment_milestones_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: treatment_plans treatment_plans_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.treatment_plans
    ADD CONSTRAINT treatment_plans_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: treatment_plans treatment_plans_owner_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.treatment_plans
    ADD CONSTRAINT treatment_plans_owner_id_fkey FOREIGN KEY (owner_id) REFERENCES aegis.users(id);


--
-- Name: treatment_plans treatment_plans_risk_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.treatment_plans
    ADD CONSTRAINT treatment_plans_risk_id_fkey FOREIGN KEY (risk_id) REFERENCES aegis.risks(id) ON DELETE CASCADE;


--
-- Name: treatment_plans treatment_plans_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.treatment_plans
    ADD CONSTRAINT treatment_plans_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: user_notification_prefs user_notification_prefs_user_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.user_notification_prefs
    ADD CONSTRAINT user_notification_prefs_user_id_fkey FOREIGN KEY (user_id) REFERENCES aegis.users(id) ON DELETE CASCADE;


--
-- Name: user_permissions user_permissions_granted_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.user_permissions
    ADD CONSTRAINT user_permissions_granted_by_fkey FOREIGN KEY (granted_by) REFERENCES aegis.users(id);


--
-- Name: user_permissions user_permissions_user_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.user_permissions
    ADD CONSTRAINT user_permissions_user_id_fkey FOREIGN KEY (user_id) REFERENCES aegis.users(id) ON DELETE CASCADE;


--
-- Name: users users_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.users
    ADD CONSTRAINT users_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: vendor_assessments vendor_assessments_assessed_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.vendor_assessments
    ADD CONSTRAINT vendor_assessments_assessed_by_fkey FOREIGN KEY (assessed_by) REFERENCES aegis.users(id);


--
-- Name: vendor_assessments vendor_assessments_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.vendor_assessments
    ADD CONSTRAINT vendor_assessments_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: vendor_assessments vendor_assessments_vendor_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.vendor_assessments
    ADD CONSTRAINT vendor_assessments_vendor_id_fkey FOREIGN KEY (vendor_id) REFERENCES aegis.vendors(id) ON DELETE CASCADE;


--
-- Name: vendor_contracts vendor_contracts_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.vendor_contracts
    ADD CONSTRAINT vendor_contracts_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: vendor_contracts vendor_contracts_owner_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.vendor_contracts
    ADD CONSTRAINT vendor_contracts_owner_id_fkey FOREIGN KEY (owner_id) REFERENCES aegis.users(id);


--
-- Name: vendor_contracts vendor_contracts_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.vendor_contracts
    ADD CONSTRAINT vendor_contracts_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: vendor_contracts vendor_contracts_vendor_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.vendor_contracts
    ADD CONSTRAINT vendor_contracts_vendor_id_fkey FOREIGN KEY (vendor_id) REFERENCES aegis.vendors(id) ON DELETE CASCADE;


--
-- Name: vendor_portal_tokens vendor_portal_tokens_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.vendor_portal_tokens
    ADD CONSTRAINT vendor_portal_tokens_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: vendor_portal_tokens vendor_portal_tokens_vendor_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.vendor_portal_tokens
    ADD CONSTRAINT vendor_portal_tokens_vendor_id_fkey FOREIGN KEY (vendor_id) REFERENCES aegis.vendors(id) ON DELETE CASCADE;


--
-- Name: vendors vendors_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.vendors
    ADD CONSTRAINT vendors_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: vendors vendors_owner_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.vendors
    ADD CONSTRAINT vendors_owner_id_fkey FOREIGN KEY (owner_id) REFERENCES aegis.users(id);


--
-- Name: vendors vendors_tenant_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.vendors
    ADD CONSTRAINT vendors_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES aegis.tenants(id);


--
-- Name: webhook_deliveries webhook_deliveries_endpoint_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.webhook_deliveries
    ADD CONSTRAINT webhook_deliveries_endpoint_id_fkey FOREIGN KEY (endpoint_id) REFERENCES aegis.webhook_endpoints(id) ON DELETE CASCADE;


--
-- Name: webhook_endpoints webhook_endpoints_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.webhook_endpoints
    ADD CONSTRAINT webhook_endpoints_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: workflow_executions workflow_executions_workflow_id_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.workflow_executions
    ADD CONSTRAINT workflow_executions_workflow_id_fkey FOREIGN KEY (workflow_id) REFERENCES aegis.workflows(id) ON DELETE CASCADE;


--
-- Name: workflows workflows_created_by_fkey; Type: FK CONSTRAINT; Schema: aegis; Owner: -
--

ALTER TABLE ONLY aegis.workflows
    ADD CONSTRAINT workflows_created_by_fkey FOREIGN KEY (created_by) REFERENCES aegis.users(id);


--
-- Name: asset_risk_links; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.asset_risk_links ENABLE ROW LEVEL SECURITY;

--
-- Name: assets; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.assets ENABLE ROW LEVEL SECURITY;

--
-- Name: audit_findings; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.audit_findings ENABLE ROW LEVEL SECURITY;

--
-- Name: audit_items; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.audit_items ENABLE ROW LEVEL SECURITY;

--
-- Name: audit_schedules; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.audit_schedules ENABLE ROW LEVEL SECURITY;

--
-- Name: audits; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.audits ENABLE ROW LEVEL SECURITY;

--
-- Name: awareness_assignments; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.awareness_assignments ENABLE ROW LEVEL SECURITY;

--
-- Name: awareness_programs; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.awareness_programs ENABLE ROW LEVEL SECURITY;

--
-- Name: bcp_exercises; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.bcp_exercises ENABLE ROW LEVEL SECURITY;

--
-- Name: bcp_plan_sections; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.bcp_plan_sections ENABLE ROW LEVEL SECURITY;

--
-- Name: bcp_plans; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.bcp_plans ENABLE ROW LEVEL SECURITY;

--
-- Name: compliance_objectives; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.compliance_objectives ENABLE ROW LEVEL SECURITY;

--
-- Name: compliance_packages; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.compliance_packages ENABLE ROW LEVEL SECURITY;

--
-- Name: control_implementations; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.control_implementations ENABLE ROW LEVEL SECURITY;

--
-- Name: control_mappings; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.control_mappings ENABLE ROW LEVEL SECURITY;

--
-- Name: control_tests; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.control_tests ENABLE ROW LEVEL SECURITY;

--
-- Name: cui_inventory; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.cui_inventory ENABLE ROW LEVEL SECURITY;

--
-- Name: data_subject_requests; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.data_subject_requests ENABLE ROW LEVEL SECURITY;

--
-- Name: document_versions; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.document_versions ENABLE ROW LEVEL SECURITY;

--
-- Name: documents; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.documents ENABLE ROW LEVEL SECURITY;

--
-- Name: evidence; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.evidence ENABLE ROW LEVEL SECURITY;

--
-- Name: evidence_files; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.evidence_files ENABLE ROW LEVEL SECURITY;

--
-- Name: finding_updates; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.finding_updates ENABLE ROW LEVEL SECURITY;

--
-- Name: grc_project_links; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.grc_project_links ENABLE ROW LEVEL SECURITY;

--
-- Name: grc_project_tasks; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.grc_project_tasks ENABLE ROW LEVEL SECURITY;

--
-- Name: grc_projects; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.grc_projects ENABLE ROW LEVEL SECURITY;

--
-- Name: incident_sla_events; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.incident_sla_events ENABLE ROW LEVEL SECURITY;

--
-- Name: incident_updates; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.incident_updates ENABLE ROW LEVEL SECURITY;

--
-- Name: incidents; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.incidents ENABLE ROW LEVEL SECURITY;

--
-- Name: issue_updates; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.issue_updates ENABLE ROW LEVEL SECURITY;

--
-- Name: issues; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.issues ENABLE ROW LEVEL SECURITY;

--
-- Name: kri_values; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.kri_values ENABLE ROW LEVEL SECURITY;

--
-- Name: kris; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.kris ENABLE ROW LEVEL SECURITY;

--
-- Name: odp_entries; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.odp_entries ENABLE ROW LEVEL SECURITY;

--
-- Name: poam_items; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.poam_items ENABLE ROW LEVEL SECURITY;

--
-- Name: poam_milestones; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.poam_milestones ENABLE ROW LEVEL SECURITY;

--
-- Name: policies; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.policies ENABLE ROW LEVEL SECURITY;

--
-- Name: policy_attestation_campaigns; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.policy_attestation_campaigns ENABLE ROW LEVEL SECURITY;

--
-- Name: policy_attestations; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.policy_attestations ENABLE ROW LEVEL SECURITY;

--
-- Name: policy_mappings; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.policy_mappings ENABLE ROW LEVEL SECURITY;

--
-- Name: policy_reviews; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.policy_reviews ENABLE ROW LEVEL SECURITY;

--
-- Name: policy_versions; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.policy_versions ENABLE ROW LEVEL SECURITY;

--
-- Name: privacy_records; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.privacy_records ENABLE ROW LEVEL SECURITY;

--
-- Name: questionnaire_answers; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.questionnaire_answers ENABLE ROW LEVEL SECURITY;

--
-- Name: questionnaire_assignments; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.questionnaire_assignments ENABLE ROW LEVEL SECURITY;

--
-- Name: questionnaire_questions; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.questionnaire_questions ENABLE ROW LEVEL SECURITY;

--
-- Name: questionnaire_responses; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.questionnaire_responses ENABLE ROW LEVEL SECURITY;

--
-- Name: questionnaires; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.questionnaires ENABLE ROW LEVEL SECURITY;

--
-- Name: raci_assignments; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.raci_assignments ENABLE ROW LEVEL SECURITY;

--
-- Name: risk_acceptances; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.risk_acceptances ENABLE ROW LEVEL SECURITY;

--
-- Name: risk_bowtie_barriers; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.risk_bowtie_barriers ENABLE ROW LEVEL SECURITY;

--
-- Name: risk_bowtie_causes; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.risk_bowtie_causes ENABLE ROW LEVEL SECURITY;

--
-- Name: risk_bowtie_consequences; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.risk_bowtie_consequences ENABLE ROW LEVEL SECURITY;

--
-- Name: risk_control_links; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.risk_control_links ENABLE ROW LEVEL SECURITY;

--
-- Name: risk_exceptions; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.risk_exceptions ENABLE ROW LEVEL SECURITY;

--
-- Name: risk_related_links; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.risk_related_links ENABLE ROW LEVEL SECURITY;

--
-- Name: risk_review_items; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.risk_review_items ENABLE ROW LEVEL SECURITY;

--
-- Name: risk_reviews; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.risk_reviews ENABLE ROW LEVEL SECURITY;

--
-- Name: risk_scenarios; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.risk_scenarios ENABLE ROW LEVEL SECURITY;

--
-- Name: risk_score_history; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.risk_score_history ENABLE ROW LEVEL SECURITY;

--
-- Name: risk_treatments; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.risk_treatments ENABLE ROW LEVEL SECURITY;

--
-- Name: risks; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.risks ENABLE ROW LEVEL SECURITY;

--
-- Name: shared_responsibility; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.shared_responsibility ENABLE ROW LEVEL SECURITY;

--
-- Name: ssp_control_statements; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.ssp_control_statements ENABLE ROW LEVEL SECURITY;

--
-- Name: ssp_packages; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.ssp_packages ENABLE ROW LEVEL SECURITY;

--
-- Name: ssp_plans; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.ssp_plans ENABLE ROW LEVEL SECURITY;

--
-- Name: asset_risk_links tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.asset_risk_links USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: assets tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.assets USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: audit_findings tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.audit_findings USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: audit_items tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.audit_items USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: audit_schedules tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.audit_schedules USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: audits tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.audits USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: awareness_assignments tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.awareness_assignments USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: awareness_programs tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.awareness_programs USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: bcp_exercises tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.bcp_exercises USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: bcp_plan_sections tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.bcp_plan_sections USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: bcp_plans tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.bcp_plans USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: compliance_objectives tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.compliance_objectives USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: compliance_packages tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.compliance_packages USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: control_implementations tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.control_implementations USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: control_mappings tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.control_mappings USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: control_tests tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.control_tests USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: cui_inventory tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.cui_inventory USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: data_subject_requests tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.data_subject_requests USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: document_versions tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.document_versions USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: documents tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.documents USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: evidence tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.evidence USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: evidence_files tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.evidence_files USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: finding_updates tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.finding_updates USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: grc_project_links tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.grc_project_links USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: grc_project_tasks tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.grc_project_tasks USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: grc_projects tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.grc_projects USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: incident_sla_events tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.incident_sla_events USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: incident_updates tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.incident_updates USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: incidents tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.incidents USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: issue_updates tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.issue_updates USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: issues tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.issues USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: kri_values tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.kri_values USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: kris tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.kris USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: odp_entries tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.odp_entries USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: poam_items tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.poam_items USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: poam_milestones tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.poam_milestones USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: policies tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.policies USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: policy_attestation_campaigns tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.policy_attestation_campaigns USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: policy_attestations tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.policy_attestations USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: policy_mappings tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.policy_mappings USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: policy_reviews tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.policy_reviews USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: policy_versions tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.policy_versions USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: privacy_records tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.privacy_records USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: questionnaire_answers tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.questionnaire_answers USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: questionnaire_assignments tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.questionnaire_assignments USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: questionnaire_questions tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.questionnaire_questions USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: questionnaire_responses tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.questionnaire_responses USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: questionnaires tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.questionnaires USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: raci_assignments tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.raci_assignments USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: risk_acceptances tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.risk_acceptances USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: risk_bowtie_barriers tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.risk_bowtie_barriers USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: risk_bowtie_causes tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.risk_bowtie_causes USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: risk_bowtie_consequences tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.risk_bowtie_consequences USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: risk_control_links tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.risk_control_links USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: risk_exceptions tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.risk_exceptions USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: risk_related_links tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.risk_related_links USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: risk_review_items tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.risk_review_items USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: risk_reviews tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.risk_reviews USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: risk_scenarios tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.risk_scenarios USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: risk_score_history tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.risk_score_history USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: risk_treatments tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.risk_treatments USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: risks tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.risks USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: shared_responsibility tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.shared_responsibility USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: ssp_control_statements tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.ssp_control_statements USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: ssp_packages tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.ssp_packages USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: ssp_plans tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.ssp_plans USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: threat_risk_links tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.threat_risk_links USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: threats tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.threats USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: treatment_milestones tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.treatment_milestones USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: treatment_plans tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.treatment_plans USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: users tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.users USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: vendor_assessments tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.vendor_assessments USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: vendor_contracts tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.vendor_contracts USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: vendors tenant_isolation; Type: POLICY; Schema: aegis; Owner: -
--

CREATE POLICY tenant_isolation ON aegis.vendors USING (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint))) WITH CHECK (((NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text) IS NULL) OR (tenant_id = (NULLIF(current_setting('aegis.tenant_id'::text, true), ''::text))::bigint)));


--
-- Name: threat_risk_links; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.threat_risk_links ENABLE ROW LEVEL SECURITY;

--
-- Name: threats; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.threats ENABLE ROW LEVEL SECURITY;

--
-- Name: treatment_milestones; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.treatment_milestones ENABLE ROW LEVEL SECURITY;

--
-- Name: treatment_plans; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.treatment_plans ENABLE ROW LEVEL SECURITY;

--
-- Name: users; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.users ENABLE ROW LEVEL SECURITY;

--
-- Name: vendor_assessments; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.vendor_assessments ENABLE ROW LEVEL SECURITY;

--
-- Name: vendor_contracts; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.vendor_contracts ENABLE ROW LEVEL SECURITY;

--
-- Name: vendors; Type: ROW SECURITY; Schema: aegis; Owner: -
--

ALTER TABLE aegis.vendors ENABLE ROW LEVEL SECURITY;

--
-- PostgreSQL database dump complete
--

\unrestrict 69b5oeru0PkoFR9Em0Wcwon5TaTn0KCUJwAISnf98imOaxV1mApSGq1GhzQDKDF

