export const PERMISSIONS = {
  organizationView: "organization.view",
  organizationUpdate: "organization.update",
  organizationDelete: "organization.delete",
  memberView: "member.view",
  memberInvite: "member.invite",
  memberUpdate: "member.update",
  memberRemove: "member.remove",
  invitationView: "invitation.view",
  invitationRevoke: "invitation.revoke",
  settingsView: "settings.view",
  settingsUpdate: "settings.update",
  auditView: "audit.view",
} as const;

export type PermissionKey = (typeof PERMISSIONS)[keyof typeof PERMISSIONS];

export const ORG_ROLES = ["owner", "admin", "member", "viewer"] as const;
export type OrgRole = (typeof ORG_ROLES)[number];

export const ROLE_LABEL: Record<OrgRole, string> = {
  owner: "Owner",
  admin: "Admin",
  member: "Member",
  viewer: "Viewer",
};

export const ROLE_RANK: Record<OrgRole, number> = {
  owner: 4,
  admin: 3,
  member: 2,
  viewer: 1,
};

/** Static role → permission map mirroring the DB seed. Server enforcement is via RLS. */
const ROLE_PERMS: Record<OrgRole, ReadonlySet<PermissionKey>> = {
  owner: new Set(Object.values(PERMISSIONS)),
  admin: new Set(
    Object.values(PERMISSIONS).filter((p) => p !== PERMISSIONS.organizationDelete),
  ),
  member: new Set<PermissionKey>([
    PERMISSIONS.organizationView,
    PERMISSIONS.memberView,
    PERMISSIONS.invitationView,
    PERMISSIONS.settingsView,
  ]),
  viewer: new Set<PermissionKey>([
    PERMISSIONS.organizationView,
    PERMISSIONS.memberView,
    PERMISSIONS.settingsView,
  ]),
};

export function roleHasPermission(role: OrgRole | null | undefined, perm: PermissionKey): boolean {
  if (!role) return false;
  return ROLE_PERMS[role].has(perm);
}
