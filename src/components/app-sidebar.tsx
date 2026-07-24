import { Link, useRouterState } from "@tanstack/react-router";
import { LayoutDashboard, Settings, Users, Mail, Building2 } from "lucide-react";
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarGroup,
  SidebarGroupContent,
  SidebarGroupLabel,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
} from "@/components/ui/sidebar";
import { OrgSwitcher } from "./org-switcher";
import { useCurrentOrg } from "@/hooks/use-current-org";
import { PERMISSIONS, type PermissionKey } from "@/lib/permissions";

type NavItem = { title: string; to: string; icon: typeof LayoutDashboard; permission?: PermissionKey };

const mainItems: NavItem[] = [
  { title: "Dashboard", to: "/dashboard", icon: LayoutDashboard },
];

const settingsItems: NavItem[] = [
  { title: "Profile", to: "/settings/profile", icon: Building2 },
  { title: "Organization", to: "/settings/organization", icon: Settings, permission: PERMISSIONS.settingsView },
  { title: "Members", to: "/settings/members", icon: Users, permission: PERMISSIONS.memberView },
  { title: "Invitations", to: "/settings/invitations", icon: Mail, permission: PERMISSIONS.invitationView },
];

export function AppSidebar() {
  const pathname = useRouterState({ select: (s) => s.location.pathname });
  const { can } = useCurrentOrg();
  const isActive = (to: string) => pathname === to || pathname.startsWith(to + "/");

  const renderItems = (items: NavItem[]) =>
    items
      .filter((item) => !item.permission || can(item.permission))
      .map((item) => (
        <SidebarMenuItem key={item.to}>
          <SidebarMenuButton asChild isActive={isActive(item.to)}>
            <Link to={item.to} className="flex items-center gap-2">
              <item.icon className="h-4 w-4" />
              <span>{item.title}</span>
            </Link>
          </SidebarMenuButton>
        </SidebarMenuItem>
      ));

  return (
    <Sidebar collapsible="icon">
      <SidebarHeader>
        <OrgSwitcher />
      </SidebarHeader>
      <SidebarContent>
        <SidebarGroup>
          <SidebarGroupLabel>Workspace</SidebarGroupLabel>
          <SidebarGroupContent>
            <SidebarMenu>{renderItems(mainItems)}</SidebarMenu>
          </SidebarGroupContent>
        </SidebarGroup>
        <SidebarGroup>
          <SidebarGroupLabel>Settings</SidebarGroupLabel>
          <SidebarGroupContent>
            <SidebarMenu>{renderItems(settingsItems)}</SidebarMenu>
          </SidebarGroupContent>
        </SidebarGroup>
      </SidebarContent>
      <SidebarFooter>
        <p className="px-2 py-1 text-xs text-muted-foreground">EventOS</p>
      </SidebarFooter>
    </Sidebar>
  );
}
