
-- =========================================================================
-- EventOS Foundation Schema
-- =========================================================================

-- Enums
create type public.org_role as enum ('owner', 'admin', 'member', 'viewer');
create type public.invitation_status as enum ('pending', 'accepted', 'revoked', 'expired');

-- =========================================================================
-- user_profiles
-- =========================================================================
create table public.user_profiles (
  id uuid primary key references auth.users(id) on delete cascade,
  email text not null,
  full_name text,
  avatar_url text,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);
grant select, insert, update on public.user_profiles to authenticated;
grant all on public.user_profiles to service_role;
alter table public.user_profiles enable row level security;

create policy "profiles readable by authenticated" on public.user_profiles
  for select to authenticated using (true);
create policy "users update own profile" on public.user_profiles
  for update to authenticated using (auth.uid() = id) with check (auth.uid() = id);
create policy "users insert own profile" on public.user_profiles
  for insert to authenticated with check (auth.uid() = id);

-- =========================================================================
-- organizations
-- =========================================================================
create table public.organizations (
  id uuid primary key default gen_random_uuid(),
  name text not null,
  slug text not null unique,
  logo_url text,
  created_by uuid not null references auth.users(id),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);
create index organizations_created_by_idx on public.organizations(created_by);
grant select, insert, update, delete on public.organizations to authenticated;
grant all on public.organizations to service_role;
alter table public.organizations enable row level security;

-- =========================================================================
-- organization_members
-- =========================================================================
create table public.organization_members (
  id uuid primary key default gen_random_uuid(),
  organization_id uuid not null references public.organizations(id) on delete cascade,
  user_id uuid not null references auth.users(id) on delete cascade,
  role public.org_role not null default 'member',
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  unique (organization_id, user_id)
);
create index organization_members_user_idx on public.organization_members(user_id);
create index organization_members_org_idx on public.organization_members(organization_id);
grant select, insert, update, delete on public.organization_members to authenticated;
grant all on public.organization_members to service_role;
alter table public.organization_members enable row level security;

-- =========================================================================
-- permissions catalog
-- =========================================================================
create table public.permissions (
  key text primary key,
  description text not null,
  created_at timestamptz not null default now()
);
grant select on public.permissions to authenticated;
grant all on public.permissions to service_role;
alter table public.permissions enable row level security;
create policy "permissions readable" on public.permissions
  for select to authenticated using (true);

create table public.role_permissions (
  role public.org_role not null,
  permission_key text not null references public.permissions(key) on delete cascade,
  primary key (role, permission_key)
);
grant select on public.role_permissions to authenticated;
grant all on public.role_permissions to service_role;
alter table public.role_permissions enable row level security;
create policy "role_permissions readable" on public.role_permissions
  for select to authenticated using (true);

-- Seed permission catalog
insert into public.permissions(key, description) values
  ('organization.view',      'View the current organization'),
  ('organization.update',    'Update organization details'),
  ('organization.delete',    'Delete the organization'),
  ('member.view',            'View members of the organization'),
  ('member.invite',          'Invite new members'),
  ('member.update',          'Change a member''s role'),
  ('member.remove',          'Remove a member from the organization'),
  ('invitation.view',        'View pending invitations'),
  ('invitation.revoke',      'Revoke a pending invitation'),
  ('settings.view',          'View organization settings'),
  ('settings.update',        'Update organization settings'),
  ('audit.view',             'View the audit log');

-- Role -> permission mapping
insert into public.role_permissions(role, permission_key)
select 'owner'::public.org_role, key from public.permissions;

insert into public.role_permissions(role, permission_key)
select 'admin'::public.org_role, key from public.permissions
  where key not in ('organization.delete');

insert into public.role_permissions(role, permission_key) values
  ('member', 'organization.view'),
  ('member', 'member.view'),
  ('member', 'invitation.view'),
  ('member', 'settings.view'),
  ('viewer', 'organization.view'),
  ('viewer', 'member.view'),
  ('viewer', 'settings.view');

-- =========================================================================
-- invitations
-- =========================================================================
create table public.invitations (
  id uuid primary key default gen_random_uuid(),
  organization_id uuid not null references public.organizations(id) on delete cascade,
  email text not null,
  role public.org_role not null default 'member',
  token text not null unique default encode(gen_random_bytes(24), 'hex'),
  status public.invitation_status not null default 'pending',
  invited_by uuid not null references auth.users(id),
  expires_at timestamptz not null default (now() + interval '14 days'),
  accepted_at timestamptz,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);
create index invitations_org_idx on public.invitations(organization_id);
create index invitations_email_idx on public.invitations(lower(email));
create unique index invitations_pending_unique on public.invitations(organization_id, lower(email))
  where status = 'pending';
grant select, insert, update, delete on public.invitations to authenticated;
grant all on public.invitations to service_role;
alter table public.invitations enable row level security;

-- =========================================================================
-- audit_logs
-- =========================================================================
create table public.audit_logs (
  id uuid primary key default gen_random_uuid(),
  organization_id uuid references public.organizations(id) on delete cascade,
  actor_id uuid references auth.users(id) on delete set null,
  action text not null,
  target_type text,
  target_id text,
  metadata jsonb not null default '{}'::jsonb,
  created_at timestamptz not null default now()
);
create index audit_logs_org_idx on public.audit_logs(organization_id, created_at desc);
grant select, insert on public.audit_logs to authenticated;
grant all on public.audit_logs to service_role;
alter table public.audit_logs enable row level security;

-- =========================================================================
-- organization_settings
-- =========================================================================
create table public.organization_settings (
  organization_id uuid primary key references public.organizations(id) on delete cascade,
  timezone text not null default 'UTC',
  locale text not null default 'en',
  preferences jsonb not null default '{}'::jsonb,
  updated_at timestamptz not null default now()
);
grant select, insert, update on public.organization_settings to authenticated;
grant all on public.organization_settings to service_role;
alter table public.organization_settings enable row level security;

-- =========================================================================
-- Helper functions (security definer, avoid RLS recursion)
-- =========================================================================
create or replace function public.is_org_member(_org uuid, _user uuid)
returns boolean language sql stable security definer set search_path = public as $$
  select exists (
    select 1 from public.organization_members
    where organization_id = _org and user_id = _user
  );
$$;

create or replace function public.get_org_role(_org uuid, _user uuid)
returns public.org_role language sql stable security definer set search_path = public as $$
  select role from public.organization_members
    where organization_id = _org and user_id = _user;
$$;

create or replace function public.has_org_role(_org uuid, _user uuid, _role public.org_role)
returns boolean language sql stable security definer set search_path = public as $$
  select exists (
    select 1 from public.organization_members
    where organization_id = _org and user_id = _user and role = _role
  );
$$;

create or replace function public.has_org_permission(_org uuid, _user uuid, _permission text)
returns boolean language sql stable security definer set search_path = public as $$
  select exists (
    select 1
    from public.organization_members m
    join public.role_permissions rp on rp.role = m.role
    where m.organization_id = _org
      and m.user_id = _user
      and rp.permission_key = _permission
  );
$$;

grant execute on function public.is_org_member(uuid, uuid) to authenticated;
grant execute on function public.get_org_role(uuid, uuid) to authenticated;
grant execute on function public.has_org_role(uuid, uuid, public.org_role) to authenticated;
grant execute on function public.has_org_permission(uuid, uuid, text) to authenticated;

-- =========================================================================
-- RLS policies
-- =========================================================================

-- organizations
create policy "members read own organizations" on public.organizations
  for select to authenticated using (public.is_org_member(id, auth.uid()));
create policy "authenticated create organizations" on public.organizations
  for insert to authenticated with check (auth.uid() = created_by);
create policy "admins update organization" on public.organizations
  for update to authenticated
  using (public.has_org_permission(id, auth.uid(), 'organization.update'))
  with check (public.has_org_permission(id, auth.uid(), 'organization.update'));
create policy "owners delete organization" on public.organizations
  for delete to authenticated
  using (public.has_org_permission(id, auth.uid(), 'organization.delete'));

-- organization_members
create policy "members read organization members" on public.organization_members
  for select to authenticated using (public.is_org_member(organization_id, auth.uid()));
create policy "member insert self on org create" on public.organization_members
  for insert to authenticated with check (user_id = auth.uid());
create policy "admins update members" on public.organization_members
  for update to authenticated
  using (public.has_org_permission(organization_id, auth.uid(), 'member.update'))
  with check (public.has_org_permission(organization_id, auth.uid(), 'member.update'));
create policy "admins remove members" on public.organization_members
  for delete to authenticated
  using (
    public.has_org_permission(organization_id, auth.uid(), 'member.remove')
    or user_id = auth.uid()
  );

-- invitations
create policy "members read invitations" on public.invitations
  for select to authenticated
  using (public.has_org_permission(organization_id, auth.uid(), 'invitation.view'));
create policy "admins create invitations" on public.invitations
  for insert to authenticated
  with check (
    public.has_org_permission(organization_id, auth.uid(), 'member.invite')
    and invited_by = auth.uid()
  );
create policy "admins revoke invitations" on public.invitations
  for update to authenticated
  using (public.has_org_permission(organization_id, auth.uid(), 'invitation.revoke'))
  with check (public.has_org_permission(organization_id, auth.uid(), 'invitation.revoke'));
create policy "admins delete invitations" on public.invitations
  for delete to authenticated
  using (public.has_org_permission(organization_id, auth.uid(), 'invitation.revoke'));

-- audit_logs
create policy "members read audit logs" on public.audit_logs
  for select to authenticated
  using (
    organization_id is not null
    and public.has_org_permission(organization_id, auth.uid(), 'audit.view')
  );
create policy "members insert own audit logs" on public.audit_logs
  for insert to authenticated with check (actor_id = auth.uid());

-- organization_settings
create policy "members read settings" on public.organization_settings
  for select to authenticated
  using (public.has_org_permission(organization_id, auth.uid(), 'settings.view'));
create policy "admins upsert settings" on public.organization_settings
  for insert to authenticated
  with check (public.has_org_permission(organization_id, auth.uid(), 'settings.update'));
create policy "admins update settings" on public.organization_settings
  for update to authenticated
  using (public.has_org_permission(organization_id, auth.uid(), 'settings.update'))
  with check (public.has_org_permission(organization_id, auth.uid(), 'settings.update'));

-- =========================================================================
-- updated_at trigger
-- =========================================================================
create or replace function public.tg_set_updated_at()
returns trigger language plpgsql as $$
begin
  new.updated_at = now();
  return new;
end;
$$;

create trigger set_updated_at before update on public.user_profiles
  for each row execute function public.tg_set_updated_at();
create trigger set_updated_at before update on public.organizations
  for each row execute function public.tg_set_updated_at();
create trigger set_updated_at before update on public.organization_members
  for each row execute function public.tg_set_updated_at();
create trigger set_updated_at before update on public.invitations
  for each row execute function public.tg_set_updated_at();
create trigger set_updated_at before update on public.organization_settings
  for each row execute function public.tg_set_updated_at();

-- =========================================================================
-- Slug generation helper
-- =========================================================================
create or replace function public.generate_org_slug(_base text)
returns text language plpgsql security definer set search_path = public as $$
declare
  base text := lower(regexp_replace(coalesce(nullif(_base, ''), 'workspace'), '[^a-zA-Z0-9]+', '-', 'g'));
  candidate text;
  n int := 0;
begin
  base := trim(both '-' from base);
  if base = '' then base := 'workspace'; end if;
  candidate := base;
  while exists (select 1 from public.organizations where slug = candidate) loop
    n := n + 1;
    candidate := base || '-' || n::text;
  end loop;
  return candidate;
end;
$$;
grant execute on function public.generate_org_slug(text) to authenticated;

-- =========================================================================
-- Signup handler: create profile + default organization
-- =========================================================================
create or replace function public.handle_new_user()
returns trigger language plpgsql security definer set search_path = public as $$
declare
  new_org_id uuid;
  display_name text;
  slug text;
begin
  display_name := coalesce(
    nullif(new.raw_user_meta_data ->> 'full_name', ''),
    nullif(new.raw_user_meta_data ->> 'name', ''),
    split_part(new.email, '@', 1)
  );

  insert into public.user_profiles(id, email, full_name, avatar_url)
    values (new.id, new.email, display_name, new.raw_user_meta_data ->> 'avatar_url')
    on conflict (id) do nothing;

  slug := public.generate_org_slug(display_name || ' workspace');

  insert into public.organizations(name, slug, created_by)
    values (display_name || '''s Workspace', slug, new.id)
    returning id into new_org_id;

  insert into public.organization_members(organization_id, user_id, role)
    values (new_org_id, new.id, 'owner');

  insert into public.organization_settings(organization_id) values (new_org_id);

  return new;
end;
$$;

drop trigger if exists on_auth_user_created on auth.users;
create trigger on_auth_user_created
  after insert on auth.users
  for each row execute function public.handle_new_user();
