
create or replace function public.tg_set_updated_at()
returns trigger language plpgsql set search_path = public as $$
begin new.updated_at = now(); return new; end;
$$;

revoke execute on function public.is_org_member(uuid, uuid) from public;
revoke execute on function public.get_org_role(uuid, uuid) from public;
revoke execute on function public.has_org_role(uuid, uuid, public.org_role) from public;
revoke execute on function public.has_org_permission(uuid, uuid, text) from public;
revoke execute on function public.generate_org_slug(text) from public;
revoke execute on function public.handle_new_user() from public;
