import { createServerFn } from "@tanstack/react-start";
import { z } from "zod";
import { requireSupabaseAuth } from "@/integrations/supabase/auth-middleware";

export const getMyProfile = createServerFn({ method: "GET" })
  .middleware([requireSupabaseAuth])
  .handler(async ({ context }) => {
    const { data, error } = await context.supabase
      .from("user_profiles")
      .select("id, email, full_name, avatar_url")
      .eq("id", context.userId)
      .maybeSingle();
    if (error) throw new Error(error.message);
    return data;
  });

const updateSchema = z.object({
  full_name: z.string().trim().min(1).max(120),
  avatar_url: z.string().url().max(500).nullable().optional(),
});

export const updateMyProfile = createServerFn({ method: "POST" })
  .middleware([requireSupabaseAuth])
  .inputValidator((raw: unknown) => updateSchema.parse(raw))
  .handler(async ({ data, context }) => {
    const { error } = await context.supabase
      .from("user_profiles")
      .update({ full_name: data.full_name, avatar_url: data.avatar_url ?? null })
      .eq("id", context.userId);
    if (error) throw new Error(error.message);
    return { ok: true };
  });
