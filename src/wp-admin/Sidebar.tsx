/**
 * Primary grouped navigation for the EventOS admin shell — the single real
 * navigation surface in the app (see navigation.ts for the grouping rules).
 * Rendered twice by AdminApp: once as a persistent column on wide viewports,
 * once inside a Drawer for narrow ones. Content is identical either way.
 */
import { useState } from "react";
import type { MenuItem } from "./navigation";
import { DASHBOARD_SLUG, NAV_GROUPS } from "./navigation";
import { config, type EventOSConfig } from "./api";

export function Sidebar({
  menu,
  view,
  branding,
  onNavigate,
}: {
  menu: MenuItem[];
  view: string;
  branding: EventOSConfig["branding"];
  /** Called after a link is clicked — lets the mobile drawer close itself. */
  onNavigate?: () => void;
}) {
  const bySlug = new Map(menu.map((item) => [item.slug, item]));
  const dashboard = bySlug.get(DASHBOARD_SLUG);
  const logo = branding.logos.dashboard?.url || branding.logos.business?.url || "";

  // adminUrl is always exactly ".../wp-admin/admin.php" (no query string —
  // see Admin_Assets::config()), so swapping the filename is enough to reach
  // the normal WordPress Dashboard.
  const wpDashboardUrl = config().adminUrl.replace(/admin\.php$/, "index.php");

  // Collapsible groups start expanded when the current page belongs to
  // them, and collapsed otherwise. Since navigation here is full page loads
  // rather than client-side routing, this recomputes correctly on every
  // page visit without needing any persistence.
  const [expandedGroups, setExpandedGroups] = useState<Set<string>>(() => {
    const initial = new Set<string>();
    for (const group of NAV_GROUPS) {
      if (group.collapsible && group.items.some((leaf) => bySlug.get(leaf.slug)?.view === view)) {
        initial.add(group.id);
      }
    }
    return initial;
  });

  const toggleGroup = (id: string) => {
    setExpandedGroups((current) => {
      const next = new Set(current);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  return (
    <nav className="eos-sidebar" aria-label="EventOS">
      <div className="eos-sidebar__brand">
        {logo ? <img src={logo} alt="" /> : null}
        <span>EventOS</span>
      </div>

      <div className="eos-sidebar__scroll">
        {dashboard ? (
          <a
            href={dashboard.url}
            className="eos-sidebar__top-link"
            aria-current={dashboard.view === view ? "page" : undefined}
            onClick={onNavigate}
          >
            {dashboard.title}
          </a>
        ) : null}

        {NAV_GROUPS.map((group) => {
          const items = group.items
            .map((leaf) => ({ leaf, item: bySlug.get(leaf.slug) }))
            .filter((entry): entry is { leaf: (typeof group.items)[number]; item: MenuItem } =>
              Boolean(entry.item),
            );

          if (!items.length) return null;

          const isSecondary = group.id === "settings" || group.id === "system";
          const isExpanded = !group.collapsible || expandedGroups.has(group.id);

          return (
            <div
              key={group.id}
              className="eos-sidebar__group"
              data-tier={isSecondary ? "secondary" : "primary"}
            >
              {/* Marks the boundary between day-to-day operations and
                  administrative/technical screens (audit finding #1). */}
              {group.id === "settings" ? (
                <div className="eos-sidebar__divider" role="separator" />
              ) : null}
              {group.collapsible ? (
                <button
                  type="button"
                  className="eos-sidebar__group-toggle"
                  aria-expanded={isExpanded}
                  onClick={() => toggleGroup(group.id)}
                >
                  <span className="eos-sidebar__group-label">{group.label}</span>
                  <span className="eos-sidebar__group-chevron" aria-hidden="true">
                    ›
                  </span>
                </button>
              ) : (
                <p className="eos-sidebar__group-label">{group.label}</p>
              )}
              {isExpanded ? (
                <ul>
                  {items.map(({ leaf, item }) => (
                    <li key={leaf.slug}>
                      <a
                        href={item.url}
                        aria-current={item.view === view ? "page" : undefined}
                        onClick={onNavigate}
                      >
                        {leaf.label ?? item.title}
                      </a>
                    </li>
                  ))}
                </ul>
              ) : null}
            </div>
          );
        })}

        {/* Visually separated exit from EventOS back to normal wp-admin —
            the native admin sidebar is hidden while inside EventOS (see
            admin.css), so this is otherwise the only way back to it. A plain
            link to a non-EventOS admin page, not an EventOS route: it causes
            a full navigation away from the React app. */}
        <div className="eos-sidebar__exit">
          <div className="eos-sidebar__divider" role="separator" />
          <a href={wpDashboardUrl} className="eos-sidebar__exit-link">
            ← WordPress Dashboard
          </a>
        </div>
      </div>
    </nav>
  );
}
