import { useEffect, useState } from "react";
import { createFileRoute } from "@tanstack/react-router";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useServerFn } from "@tanstack/react-start";
import { Loader2 } from "lucide-react";
import { toast } from "sonner";
import { PageHeader } from "@/components/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { EmptyState } from "@/components/empty-state";
import { useCurrentOrg } from "@/hooks/use-current-org";
import { PERMISSIONS } from "@/lib/permissions";
import { updateOrganization } from "@/lib/organizations.functions";

export const Route = createFileRoute("/_authenticated/settings/organization")({
  head: () => ({ meta: [{ title: "Organization settings — EventOS" }] }),
  component: OrgSettingsPage,
});

function OrgSettingsPage() {
  const { currentOrg, can, refresh } = useCurrentOrg();
  const updateFn = useServerFn(updateOrganization);
  const qc = useQueryClient();
  const [name, setName] = useState(currentOrg?.name ?? "");

  useEffect(() => {
    if (currentOrg) setName(currentOrg.name);
  }, [currentOrg]);

  const mutation = useMutation({
    mutationFn: (input: { name: string }) =>
      updateFn({ data: { organizationId: currentOrg!.id, name: input.name } }),
    onSuccess: async () => {
      toast.success("Organization updated");
      await qc.invalidateQueries({ queryKey: ["organizations"] });
      await refresh();
    },
    onError: (err: Error) => toast.error(err.message),
  });

  if (!currentOrg) {
    return <EmptyState title="No organization selected" description="Pick or create one." />;
  }

  const canEdit = can(PERMISSIONS.organizationUpdate);

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <PageHeader
        title="Organization"
        description="Manage the current organization's identity."
      />
      <Card>
        <CardContent className="space-y-4 pt-6">
          <div className="space-y-2">
            <Label>Slug</Label>
            <Input value={currentOrg.slug} disabled readOnly />
          </div>
          <div className="space-y-2">
            <Label htmlFor="name">Name</Label>
            <Input
              id="name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              disabled={!canEdit}
            />
          </div>
          <div>
            <Button
              onClick={() => mutation.mutate({ name: name.trim() })}
              disabled={!canEdit || mutation.isPending || name.trim().length < 2}
            >
              {mutation.isPending ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}
              Save changes
            </Button>
            {!canEdit ? (
              <p className="mt-2 text-xs text-muted-foreground">
                Only admins and owners can edit these details.
              </p>
            ) : null}
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
