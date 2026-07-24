import { useState } from "react";
import { createFileRoute } from "@tanstack/react-router";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useServerFn } from "@tanstack/react-start";
import { Users } from "lucide-react";
import { toast } from "sonner";
import { PageHeader } from "@/components/page-header";
import { DataTable, type DataTableColumn } from "@/components/data-table";
import { EmptyState } from "@/components/empty-state";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/confirm-dialog";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { useCurrentOrg } from "@/hooks/use-current-org";
import { useAuth } from "@/hooks/use-auth";
import {
  listOrganizationMembers,
  removeMember,
  updateMemberRole,
} from "@/lib/organizations.functions";
import { ORG_ROLES, PERMISSIONS, ROLE_LABEL, type OrgRole } from "@/lib/permissions";

export const Route = createFileRoute("/_authenticated/settings/members")({
  head: () => ({ meta: [{ title: "Members — EventOS" }] }),
  component: MembersPage,
});

type MemberRow = {
  id: string;
  user_id: string;
  role: OrgRole;
  email: string;
  full_name: string | null;
  avatar_url: string | null;
  created_at: string;
};

function MembersPage() {
  const { currentOrg, can } = useCurrentOrg();
  const { user } = useAuth();
  const listFn = useServerFn(listOrganizationMembers);
  const updateFn = useServerFn(updateMemberRole);
  const removeFn = useServerFn(removeMember);
  const qc = useQueryClient();
  const [pendingRemove, setPendingRemove] = useState<MemberRow | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ["members", currentOrg?.id],
    queryFn: () => listFn({ data: { organizationId: currentOrg!.id } }),
    enabled: !!currentOrg,
  });

  const updateMutation = useMutation({
    mutationFn: (input: { memberId: string; role: OrgRole }) => updateFn({ data: input }),
    onSuccess: () => {
      toast.success("Role updated");
      qc.invalidateQueries({ queryKey: ["members", currentOrg?.id] });
    },
    onError: (err: Error) => toast.error(err.message),
  });

  const removeMutation = useMutation({
    mutationFn: (memberId: string) => removeFn({ data: { memberId } }),
    onSuccess: () => {
      toast.success("Member removed");
      setPendingRemove(null);
      qc.invalidateQueries({ queryKey: ["members", currentOrg?.id] });
    },
    onError: (err: Error) => toast.error(err.message),
  });

  if (!currentOrg) {
    return <EmptyState icon={Users} title="No organization selected" />;
  }

  const canManage = can(PERMISSIONS.memberUpdate);
  const canRemove = can(PERMISSIONS.memberRemove);

  const columns: DataTableColumn<MemberRow>[] = [
    {
      key: "name",
      header: "Name",
      cell: (row) => (
        <div className="flex flex-col">
          <span className="font-medium">{row.full_name ?? row.email}</span>
          <span className="text-xs text-muted-foreground">{row.email}</span>
        </div>
      ),
    },
    {
      key: "role",
      header: "Role",
      cell: (row) =>
        canManage && row.user_id !== user?.id ? (
          <Select
            value={row.role}
            onValueChange={(value) =>
              updateMutation.mutate({ memberId: row.id, role: value as OrgRole })
            }
          >
            <SelectTrigger className="h-8 w-32">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {ORG_ROLES.map((r) => (
                <SelectItem key={r} value={r}>
                  {ROLE_LABEL[r]}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        ) : (
          <span>{ROLE_LABEL[row.role]}</span>
        ),
    },
    {
      key: "actions",
      header: "",
      className: "text-right",
      cell: (row) =>
        canRemove && row.user_id !== user?.id ? (
          <Button variant="ghost" size="sm" onClick={() => setPendingRemove(row)}>
            Remove
          </Button>
        ) : null,
    },
  ];

  return (
    <div className="mx-auto max-w-4xl space-y-6">
      <PageHeader title="Members" description="Everyone with access to this organization." />
      <DataTable
        columns={columns}
        data={data ?? []}
        loading={isLoading}
        getRowId={(r) => r.id}
        emptyTitle="No members yet"
      />
      <ConfirmDialog
        open={!!pendingRemove}
        onOpenChange={(v) => !v && setPendingRemove(null)}
        title="Remove member"
        description={`Remove ${pendingRemove?.full_name ?? pendingRemove?.email} from this organization?`}
        confirmLabel="Remove"
        destructive
        loading={removeMutation.isPending}
        onConfirm={() => pendingRemove && removeMutation.mutate(pendingRemove.id)}
      />
    </div>
  );
}
