/**
 * Team screen: invite members, manage pending invitations, and assign
 * EventOS roles to WordPress users. Same api.* calls as before this was
 * migrated off the legacy hand-rolled markup — only the rendering layer
 * changed, not the data flow.
 */
import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api, type Invitation, type TeamMember } from "../../api";
import {
  Button,
  Card,
  CheckboxGroup,
  ConfirmDialog,
  DataTable,
  Input,
  NoticeList,
  PageLayout,
  Stack,
  useToast,
  type DataTableColumn,
  type NoticeItem,
} from "../../ui";

export function TeamView() {
  const queryClient = useQueryClient();
  const toast = useToast();
  const [search, setSearch] = useState("");
  const [email, setEmail] = useState("");
  const [inviteRoles, setInviteRoles] = useState<string[]>([]);
  const [revokeTarget, setRevokeTarget] = useState<{ id: number; email: string } | null>(null);

  const roles = useQuery({ queryKey: ["eventos", "roles"], queryFn: api.roles });
  const members = useQuery({
    queryKey: ["eventos", "members", search],
    queryFn: () => api.members(search),
  });
  const invitations = useQuery({ queryKey: ["eventos", "invitations"], queryFn: api.invitations });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ["eventos"] });

  const updateMember = useMutation({
    mutationFn: ({ id, next }: { id: number; next: string[] }) => api.updateMember(id, next),
    onSuccess: invalidate,
    onError: (error: Error) => toast.error(error.message),
  });

  const invite = useMutation({
    mutationFn: () => api.createInvitation(email, inviteRoles),
    onSuccess: () => {
      toast.success("Invitation sent.");
      setEmail("");
      setInviteRoles([]);
      invalidate();
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const revoke = useMutation({
    mutationFn: (id: number) => api.revokeInvitation(id),
    onSuccess: () => {
      toast.success("Invitation revoked.");
      setRevokeTarget(null);
      invalidate();
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const roleList = roles.data?.roles ?? [];
  const roleOptions = roleList.map((role) => ({ value: role.slug, label: role.label }));

  const notices: NoticeItem[] = [roles.error, members.error, invitations.error]
    .filter((error): error is Error => Boolean(error))
    .map((error, index) => ({
      id: `team-error-${index}`,
      tone: "danger",
      message: error.message,
    }));

  const invitationColumns: DataTableColumn<Invitation>[] = [
    { key: "email", header: "Email", cell: (row) => row.email },
    { key: "roles", header: "Roles", cell: (row) => row.roles.join(", ") || "—" },
    { key: "status", header: "Status", cell: (row) => row.status },
    { key: "expires_at", header: "Expires", cell: (row) => row.expires_at },
    {
      key: "actions",
      header: "",
      align: "right",
      cell: (row) =>
        row.status === "pending" ? (
          <Button
            variant="link"
            size="sm"
            onClick={() => setRevokeTarget({ id: row.id, email: row.email })}
          >
            Revoke
          </Button>
        ) : null,
    },
  ];

  const memberColumns: DataTableColumn<TeamMember>[] = [
    {
      key: "name",
      header: "User",
      cell: (row) => (
        <Stack>
          <strong>{row.name}</strong>
          <span className="eos-page__description">{row.email}</span>
        </Stack>
      ),
    },
    { key: "wp_roles", header: "WordPress roles", cell: (row) => row.wp_roles.join(", ") || "—" },
    {
      key: "eventos_roles",
      header: "EventOS roles",
      cell: (row) => (
        <CheckboxGroup
          legend={`EventOS roles for ${row.name}`}
          options={roleOptions}
          value={row.eventos_roles}
          disabled={updateMember.isPending}
          onChange={(next) => updateMember.mutate({ id: row.id, next })}
        />
      ),
    },
  ];

  return (
    <PageLayout
      title="Team"
      description="Assign EventOS roles to WordPress users and invite new team members."
    >
      <Stack>
        <NoticeList notices={notices} />

        <Card title="Invite a team member">
          <form
            onSubmit={(event) => {
              event.preventDefault();
              invite.mutate();
            }}
          >
            <Stack>
              <Input
                type="email"
                label="Email address"
                required
                value={email}
                onChange={(event) => setEmail(event.target.value)}
              />
              <CheckboxGroup
                legend="Roles"
                options={roleOptions}
                value={inviteRoles}
                onChange={setInviteRoles}
              />
              <div className="eos-inline">
                <Button
                  type="submit"
                  variant="primary"
                  loading={invite.isPending}
                  disabled={!inviteRoles.length}
                >
                  Send invitation
                </Button>
              </div>
            </Stack>
          </form>
        </Card>

        <Card title="Pending invitations" flush>
          <DataTable
            caption="Pending invitations"
            columns={invitationColumns}
            rows={invitations.data?.invitations ?? []}
            getRowId={(row) => String(row.id)}
            loading={invitations.isLoading}
            emptyTitle="No invitations yet"
          />
        </Card>

        <Card title="WordPress users" flush>
          <Stack style={{ padding: "var(--eos-space-4) var(--eos-space-4) 0" }}>
            <Input
              label="Search users"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
            />
          </Stack>
          <DataTable
            caption="WordPress users"
            columns={memberColumns}
            rows={members.data?.members ?? []}
            getRowId={(row) => String(row.id)}
            loading={members.isLoading}
            emptyTitle="No users found"
            emptyDescription={search ? "Try a different search." : undefined}
          />
        </Card>
      </Stack>

      <ConfirmDialog
        open={Boolean(revokeTarget)}
        onCancel={() => setRevokeTarget(null)}
        onConfirm={() => {
          if (revokeTarget) revoke.mutate(revokeTarget.id);
        }}
        title="Revoke invitation?"
        description={
          revokeTarget
            ? `${revokeTarget.email} will no longer be able to accept this invitation.`
            : ""
        }
        confirmLabel="Revoke invitation"
        destructive
        busy={revoke.isPending}
      />
    </PageLayout>
  );
}
