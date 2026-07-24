import { createServerFn } from "@tanstack/react-start";
import { z } from "zod";
import { requireSupabaseAuth } from "@/integrations/supabase/auth-middleware";
import type { OrgRole } from "./permissions";

const listSchema = z.object({ organizationId: z.string().uuid() });

export const listInvitations = createServerFn({ method: "GET" })
  .middleware([requireSupabaseAuth])
  .inputValidator((raw: unknown) => listSchema.parse(raw))
  .handler(async ({ data, context }) => {
    const { data: rows, error } = await context.supabase
      .from("invitations")
      .select("id, email, role, status, expires_at, created_at")
      .eq("organization_id", data.organizationId)
      .order("created_at", { ascending: false });
    if (error) throw new Error(error.message);
    return rows ?? [];
  });

const createSchema = z.object({
  organizationId: z.string().uuid(),
  email: z.string().trim().email().toLowerCase(),
  role: z.enum(["admin", "member", "viewer"]),
});

export const createInvitation = createServerFn({ method: "POST" })
  .middleware([requireSupabaseAuth])
  .inputValidator((raw: unknown) => createSchema.parse(raw))
  .handler(async ({ data, context }) => {
    const { data: row, error } = await context.supabase
      .from("invitations")
      .insert({
        organization_id: data.organizationId,
        email: data.email,
        role: data.role,
        invited_by: context.userId,
      })
      .select("id, email, role, status, expires_at, token, created_at")
      .single();
    if (error) throw new Error(error.message);
    await context.supabase.from("audit_logs").insert({
      organization_id: data.organizationId,
      actor_id: context.userId,
      action: "invitation.created",
      target_type: "invitation",
      target_id: row.id,
      metadata: { email: data.email, role: data.role },
    });
    return row;
  });

const revokeSchema = z.object({ invitationId: z.string().uuid() });

export const revokeInvitation = createServerFn({ method: "POST" })
  .middleware([requireSupabaseAuth])
  .inputValidator((raw: unknown) => revokeSchema.parse(raw))
  .handler(async ({ data, context }) => {
    const { error } = await context.supabase
      .from("invitations")
      .update({ status: "revoked" })
      .eq("id", data.invitationId);
    if (error) throw new Error(error.message);
    return { ok: true };
  });

const acceptSchema = z.object({ token: z.string().min(10) });

export const acceptInvitation = createServerFn({ method: "POST" })
  .middleware([requireSupabaseAuth])
  .inputValidator((raw: unknown) => acceptSchema.parse(raw))
  .handler(async ({ data, context }) => {
    // Look up invite via admin (bypass RLS) so recipient can see it.
    const { supabaseAdmin } = await import("@/integrations/supabase/client.server");
    const { data: invite, error: invErr } = await supabaseAdmin
      .from("invitations")
      .select("id, organization_id, email, role, status, expires_at")
      .eq("token", data.token)
      .maybeSingle();
    if (invErr) throw new Error(invErr.message);
    if (!invite) throw new Error("Invitation not found");
    if (invite.status !== "pending") throw new Error(`Invitation is ${invite.status}`);
    if (new Date(invite.expires_at).getTime() < Date.now()) {
      await supabaseAdmin.from("invitations").update({ status: "expired" }).eq("id", invite.id);
      throw new Error("Invitation has expired");
    }

    const email = (context.claims.email as string | undefined)?.toLowerCase();
    if (!email || email !== invite.email.toLowerCase()) {
      throw new Error("This invitation was sent to a different email address");
    }

    const { error: memErr } = await supabaseAdmin
      .from("organization_members")
      .upsert(
        {
          organization_id: invite.organization_id,
          user_id: context.userId,
          role: invite.role,
        },
        { onConflict: "organization_id,user_id" },
      );
    if (memErr) throw new Error(memErr.message);

    await supabaseAdmin
      .from("invitations")
      .update({ status: "accepted", accepted_at: new Date().toISOString() })
      .eq("id", invite.id);

    await supabaseAdmin.from("audit_logs").insert({
      organization_id: invite.organization_id,
      actor_id: context.userId,
      action: "invitation.accepted",
      target_type: "invitation",
      target_id: invite.id,
    });

    return {
      organizationId: invite.organization_id,
      role: invite.role as OrgRole,
    };
  });
