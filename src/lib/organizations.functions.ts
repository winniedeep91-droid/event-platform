import { createServerFn } from "@tanstack/react-start";
import { z } from "zod";
import { requireSupabaseAuth } from "@/integrations/supabase/auth-middleware";
import type { OrgRole } from "./permissions";

export type OrganizationSummary = {
  id: string;
  name: string;
  slug: string;
  logo_url: string | null;
  role: OrgRole;
};

export const listMyOrganizations = createServerFn({ method: "GET" })
  .middleware([requireSupabaseAuth])
  .handler(async ({ context }): Promise<OrganizationSummary[]> => {
    const { data, error } = await context.supabase
      .from("organization_members")
      .select("role, organizations:organization_id ( id, name, slug, logo_url )")
      .order("created_at", { ascending: true });
    if (error) throw new Error(error.message);
    return (data ?? [])
      .filter((row) => row.organizations !== null)
      .map((row) => {
        const org = row.organizations as {
          id: string;
          name: string;
          slug: string;
          logo_url: string | null;
        };
        return { ...org, role: row.role as OrgRole };
      });
  });

const createOrgSchema = z.object({
  name: z.string().trim().min(2).max(80),
});

export const createOrganization = createServerFn({ method: "POST" })
  .middleware([requireSupabaseAuth])
  .inputValidator((raw: unknown) => createOrgSchema.parse(raw))
  .handler(async ({ data, context }) => {
    const { data: slugData, error: slugErr } = await context.supabase.rpc(
      "generate_org_slug",
      { _base: data.name },
    );
    if (slugErr) throw new Error(slugErr.message);
    const slug = slugData as string;

    const { data: org, error: orgErr } = await context.supabase
      .from("organizations")
      .insert({ name: data.name, slug, created_by: context.userId })
      .select("id, name, slug, logo_url")
      .single();
    if (orgErr) throw new Error(orgErr.message);

    const { error: memErr } = await context.supabase
      .from("organization_members")
      .insert({ organization_id: org.id, user_id: context.userId, role: "owner" });
    if (memErr) throw new Error(memErr.message);

    await context.supabase.from("organization_settings").insert({ organization_id: org.id });
    await context.supabase.from("audit_logs").insert({
      organization_id: org.id,
      actor_id: context.userId,
      action: "organization.created",
      target_type: "organization",
      target_id: org.id,
    });

    return { ...org, role: "owner" as OrgRole };
  });

const orgIdSchema = z.object({ organizationId: z.string().uuid() });

export const listOrganizationMembers = createServerFn({ method: "GET" })
  .middleware([requireSupabaseAuth])
  .inputValidator((raw: unknown) => orgIdSchema.parse(raw))
  .handler(async ({ data, context }) => {
    const { data: members, error } = await context.supabase
      .from("organization_members")
      .select("id, user_id, role, created_at, profiles:user_id ( email, full_name, avatar_url )")
      .eq("organization_id", data.organizationId)
      .order("created_at", { ascending: true });
    if (error) throw new Error(error.message);
    // Note: profiles relation is on user_profiles via user_id manually below
    // Fetch profiles in bulk to avoid relying on FK aliases.
    const userIds = (members ?? []).map((m) => m.user_id);
    const { data: profiles, error: pErr } = await context.supabase
      .from("user_profiles")
      .select("id, email, full_name, avatar_url")
      .in("id", userIds.length ? userIds : ["00000000-0000-0000-0000-000000000000"]);
    if (pErr) throw new Error(pErr.message);
    const byId = new Map(profiles?.map((p) => [p.id, p]) ?? []);
    return (members ?? []).map((m) => ({
      id: m.id,
      user_id: m.user_id,
      role: m.role as OrgRole,
      created_at: m.created_at,
      email: byId.get(m.user_id)?.email ?? "",
      full_name: byId.get(m.user_id)?.full_name ?? null,
      avatar_url: byId.get(m.user_id)?.avatar_url ?? null,
    }));
  });

const updateMemberSchema = z.object({
  memberId: z.string().uuid(),
  role: z.enum(["owner", "admin", "member", "viewer"]),
});

export const updateMemberRole = createServerFn({ method: "POST" })
  .middleware([requireSupabaseAuth])
  .inputValidator((raw: unknown) => updateMemberSchema.parse(raw))
  .handler(async ({ data, context }) => {
    const { error } = await context.supabase
      .from("organization_members")
      .update({ role: data.role })
      .eq("id", data.memberId);
    if (error) throw new Error(error.message);
    return { ok: true };
  });

const removeMemberSchema = z.object({ memberId: z.string().uuid() });

export const removeMember = createServerFn({ method: "POST" })
  .middleware([requireSupabaseAuth])
  .inputValidator((raw: unknown) => removeMemberSchema.parse(raw))
  .handler(async ({ data, context }) => {
    const { error } = await context.supabase
      .from("organization_members")
      .delete()
      .eq("id", data.memberId);
    if (error) throw new Error(error.message);
    return { ok: true };
  });

const updateOrgSchema = z.object({
  organizationId: z.string().uuid(),
  name: z.string().trim().min(2).max(80),
});

export const updateOrganization = createServerFn({ method: "POST" })
  .middleware([requireSupabaseAuth])
  .inputValidator((raw: unknown) => updateOrgSchema.parse(raw))
  .handler(async ({ data, context }) => {
    const { error } = await context.supabase
      .from("organizations")
      .update({ name: data.name })
      .eq("id", data.organizationId);
    if (error) throw new Error(error.message);
    return { ok: true };
  });
