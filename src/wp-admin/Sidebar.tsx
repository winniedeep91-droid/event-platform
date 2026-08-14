/**
 * Primary grouped navigation for the EventOS admin shell — the single real
 * navigation surface in the app (see navigation.ts for the grouping rules).
 * Rendered twice by AdminApp: once as a persistent column on wide viewports,
 * once inside a Drawer for narrow ones. Content is identical either way.
 */
import type { MenuItem } from "./navigation";
import { DASHBOARD_SLUG, NAV_GROUPS } from "./navigation";
import type { EventOSConfig } from "./api";

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
              <p className="eos-sidebar__group-label">{group.label}</p>
              <ul>
                {items.map(({ leaf, item }) => (
                  <li key={leaf.slug}>
                    {leaf.section ? (
                      <p className="eos-sidebar__section-label">{leaf.section}</p>
                    ) : null}
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
            </div>
          );
        })}
      </div>
    </nav>
  );
}
