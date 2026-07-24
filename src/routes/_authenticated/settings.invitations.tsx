import { useState } from "react";
import { createFileRoute } from "@tanstack/react-router";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useServerFn } from "@tanstack/react-start";
import { Loader2, Mail, Plus } from "lucide-react";
import { toast } from "sonner";
import { PageHeader } from "@/components/page-header";
import { DataTable, type DataTableColumn } from "@/components/data-table";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Badge } from "@/components/ui/badge";
import { EmptyState } from "@/components/empty-state";
import { useCurrentOrg } from "@/hooks/use-current-org";
import {
  createInvitation,
  listInvitations,
  revokeInvitation,
} from "@/lib/invitations.functions";
import { PERMISSIONS, ROLE_LABEL, type OrgRole } from "@/lib/permissions";

export const Route = createFileRoute("/_authenticated/settings/invitations")({
  head: () => ({ meta: [{ title: "Invitations — EventOS" }] }),
  component: InvitationsPage,
});

type InviteRow = {
  id: string;
  email: string;
  role: OrgRole;
  status: "pending" | "accepted" | "revoked" | "expired";
  expires_at: string;
  created_at: string;
};

const INVITE_ROLES: OrgRole[] = ["admin", "member", "viewer"];

function InvitationsPage() {
  const { currentOrg, can } = useCurrentOrg();
  const listFn = useServerFn(listInvitations);
  const createFn = useServerFn(createInvitation);
  const revokeFn = useServerFn(revokeInvitation);
  const qc = useQueryClient();
  const [open, setOpen] = useState(false);
  const [email, setEmail] = useState("");
  const [role, setRole] = useState<OrgRole>("member");

  const { data, isLoading } = useQuery({
    queryKey: ["invitations", currentOrg?.id],
    queryFn: () => listFn({ data: { organizationId: currentOrg!.id } }),
    enabled: !!currentOrg,
  });

  const createMutation = useMutation({
    mutationFn: () =>
      createFn({
        data: {
          organizationId: currentOrg!.id,
          email: email.trim().toLowerCase(),
          role: role as "admin" | "member" | "viewer",
        },
      }),
    onSuccess: () => {
      toast.success("Invitation sent");
      setOpen(false);
      setEmail("");
      setRole("member");
      qc.invalidateQueries({ queryKey: ["invitations", currentOrg?.id] });
    },
    onError: (err: Error) => toast.error(err.message),
  });

  const revokeMutation = useMutation({
    mutationFn: (invitationId: string) => revokeFn({ data: { invitationId } }),
    onSuccess: () => {
      toast.success("Invitation revoked");
      qc.invalidateQueries({ queryKey: ["invitations", currentOrg?.id] });
    },
    onError: (err: Error) => toast.error(err.message),
  });

  if (!currentOrg) {
    return <EmptyState icon={Mail} title="No organization selected" />;
  }

  const canInvite = can(PERMISSIONS.memberInvite);
  const canRevoke = can(PERMISSIONS.invitationRevoke);

  const columns: DataTableColumn<InviteRow>[] = [
    { key: "email", header: "Email", cell: (r) => r.email },
    { key: "role", header: "Role", cell: (r) => ROLE_LABEL[r.role] },
    {
      key: "status",
      header: "Status",
      cell: (r) => (
        <Badge variant={r.status === "pending" ? "default" : "secondary"} className="capitalize">
          {r.status}
        </Badge>
      ),
    },
    {
      key: "expires",
      header: "Expires",
      cell: (r) => new Date(r.expires_at).toLocaleDateString(),
    },
    {
      key: "actions",
      header: "",
      className: "text-right",
      cell: (r) =>
        canRevoke && r.status === "pending" ? (
          <Button
            variant="ghost"
            size="sm"
            onClick={() => revokeMutation.mutate(r.id)}
            disabled={revokeMutation.isPending}
          >
            Revoke
          </Button>
        ) : null,
    },
  ];

  return (
    <div className="mx-auto max-w-4xl space-y-6">
      <PageHeader
        title="Invitations"
        description="Invite people by email to join this organization."
        actions={
          canInvite ? (
            <Button onClick={() => setOpen(true)}>
              <Plus className="mr-2 h-4 w-4" /> Invite member
            </Button>
          ) : null
        }
      />
      <DataTable
        columns={columns}
        data={data ?? []}
        loading={isLoading}
        getRowId={(r) => r.id}
        emptyTitle="No invitations"
        emptyDescription="Invite people to collaborate."
      />
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Invite a member</DialogTitle>
            <DialogDescription>
              They'll receive a link at their email once you send it from your mail system.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-3">
            <div className="space-y-2">
              <Label htmlFor="invite-email">Email</Label>
              <Input
                id="invite-email"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
              />
            </div>
            <div className="space-y-2">
              <Label>Role</Label>
              <Select value={role} onValueChange={(v) => setRole(v as OrgRole)}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {INVITE_ROLES.map((r) => (
                    <SelectItem key={r} value={r}>
                      {ROLE_LABEL[r]}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)}>
              Cancel
            </Button>
            <Button
              disabled={createMutation.isPending || !email.trim()}
              onClick={() => createMutation.mutate()}
            >
              {createMutation.isPending ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}
              Send invitation
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
