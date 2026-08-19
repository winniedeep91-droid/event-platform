/**
 * Slim application-level bar above every page: mobile nav toggle, a short
 * breadcrumb trail, global search, and the current user. Deliberately does
 * not repeat the sidebar's links — see navigation.ts for why each page does
 * or doesn't get a breadcrumb.
 */
import { useEffect, useState } from "react";
import { Avatar, Breadcrumbs, Button } from "./ui";
import { GlobalSearch } from "./GlobalSearch";
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
  const [searchOpen, setSearchOpen] = useState(false);

  useEffect(() => {
    // "/" rather than Cmd/Ctrl+K — WordPress 7.1's own admin bar already
    // owns that combination for its command palette, and hijacking it would
    // fight a system-level shortcut rather than extend the UI.
    const onKeyDown = (event: KeyboardEvent) => {
      if ("/" !== event.key || event.metaKey || event.ctrlKey || event.altKey) return;

      const target = event.target as HTMLElement | null;
      const tag = target?.tagName;
      if ("INPUT" === tag || "TEXTAREA" === tag || target?.isContentEditable) return;

      event.preventDefault();
      setSearchOpen(true);
    };

    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, []);

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

      <button
        type="button"
        className="eos-topbar__search-trigger"
        onClick={() => setSearchOpen(true)}
        aria-label="Search EventOS"
      >
        <span aria-hidden="true">🔍</span>
        <span className="eos-topbar__search-trigger-label">Search…</span>
        <kbd className="eos-topbar__search-trigger-kbd">/</kbd>
      </button>

      <div className="eos-topbar__user">
        <Avatar name={currentUser.name} src={currentUser.avatar} size={28} />
        <span className="eos-topbar__user-name">{currentUser.name}</span>
      </div>

      <GlobalSearch open={searchOpen} onClose={() => setSearchOpen(false)} />
    </header>
  );
}
