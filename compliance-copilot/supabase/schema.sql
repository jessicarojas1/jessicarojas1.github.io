-- Compliance Copilot — Supabase Schema
-- Run in Supabase SQL Editor or via psql

-- Extensions
create extension if not exists "uuid-ossp";

-- Controls table
create table if not exists controls (
  id                      uuid primary key default uuid_generate_v4(),
  control_id              text not null unique,          -- e.g. "3.1.1"
  domain                  text not null,                 -- e.g. "AC"
  domain_name             text not null,
  title                   text not null,
  requirement             text not null,
  discussion              text,
  status                  text not null default 'not_implemented'
    check (status in ('implemented','partially_implemented','not_implemented','not_applicable','planned')),
  priority                text not null default 'medium'
    check (priority in ('critical','high','medium','low')),
  cmmc_level              int not null default 2 check (cmmc_level in (1,2,3)),
  implementation_statement text,
  policy_references       text[] default '{}',
  nist_mapping            text[] default '{}',
  notes                   text,
  responsible_role        text,
  last_reviewed           date,
  next_review             date,
  created_at              timestamptz default now(),
  updated_at              timestamptz default now()
);

-- Evidence table
create table if not exists evidence (
  id            uuid primary key default uuid_generate_v4(),
  control_ids   uuid[] default '{}',
  title         text not null,
  description   text,
  type          text not null default 'other'
    check (type in ('policy','procedure','screenshot','log','configuration','test_result','interview','other')),
  file_url      text,
  file_name     text,
  file_size     bigint,
  tags          text[] default '{}',
  uploaded_by   text,
  reviewed      boolean default false,
  expiry_date   date,
  created_at    timestamptz default now(),
  updated_at    timestamptz default now()
);

-- POA&M items
create table if not exists poam_items (
  id                   uuid primary key default uuid_generate_v4(),
  control_id           uuid references controls(id) on delete cascade,
  weakness             text not null,
  remediation          text,
  responsible_party    text,
  scheduled_completion date,
  resources_required   text,
  milestones           text[] default '{}',
  status               text default 'open'
    check (status in ('open','in_progress','completed','risk_accepted')),
  created_at           timestamptz default now(),
  updated_at           timestamptz default now()
);

-- RLS policies (enable after auth setup)
alter table controls   enable row level security;
alter table evidence   enable row level security;
alter table poam_items enable row level security;

-- Allow authenticated users to read all
create policy "Authenticated users can read controls"
  on controls for select to authenticated using (true);
create policy "Authenticated users can update controls"
  on controls for update to authenticated using (true);
create policy "Authenticated users can read evidence"
  on evidence for select to authenticated using (true);
create policy "Authenticated users can insert evidence"
  on evidence for insert to authenticated with check (true);
create policy "Authenticated users can read poam"
  on poam_items for select to authenticated using (true);
create policy "Authenticated users can manage poam"
  on poam_items for all to authenticated using (true);

-- Updated_at trigger
create or replace function update_updated_at()
returns trigger as $$
begin new.updated_at = now(); return new; end;
$$ language plpgsql;

create trigger trg_controls_updated   before update on controls   for each row execute function update_updated_at();
create trigger trg_evidence_updated   before update on evidence   for each row execute function update_updated_at();
create trigger trg_poam_updated       before update on poam_items for each row execute function update_updated_at();
