/**
 * Slim application-level bar above every page: mobile nav toggle, a short
 * breadcrumb trail, and the current user. Deliberately does not repeat the
 * sidebar's links — see navigation.ts for why each page does or doesn't get
 * a breadcrumb.
 */
import { Avatar, Breadcrumbs, Button } from "./ui";
import { breadcrumbFor, type MenuItem } from "./navigation";
import type { EventOSConfig } from "./api";

export function TopBar({
  view,
  menu,
  currentUser,
  onOpenSidebar,
}: {
  view: string;
  menu: MenuItem[];
  currentUser: EventOSConfig["currentUser"];
  onOpenSidebar: () => void;
}) {
  const crumbs = breadcrumbFor(view, menu);

  return (
    <header className="eos-topbar">
      <Button
        variant="ghost"
        size="sm"
        iconOnly
        aria-label="Open navigation"
        className="eos-topbar__menu-toggle"
        onClick={onOpenSidebar}
      >
        ☰
      </Button>

      <div className="eos-topbar__trail">{crumbs ? <Breadcrumbs items={crumbs} /> : null}</div>

      <div className="eos-topbar__user">
        <Avatar name={currentUser.name} src={currentUser.avatar} size={28} />
        <span className="eos-topbar__user-name">{currentUser.name}</span>
      </div>
    </header>
  );
}
