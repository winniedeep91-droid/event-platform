/**
 * Primary navigation grouping for the EventOS admin shell.
 *
 * The PHP side (Admin_Menu + each module's menu_items()) still owns the
 * canonical list of pages — slug, view string, title and capability-filtered
 * visibility all come from `config().menu`. This file only decides how those
 * already-registered pages are grouped and labelled in the app's own sidebar,
 * so promoter-facing operations read as primary and technical/config screens
 * read as secondary. No page is invented here: every slug referenced below
 * must already exist in `config().menu`, and any item not present (because
 * the user lacks the capability, or a module isn't active) is simply skipped.
 */
import type { EventOSConfig } from "./api";

export type MenuItem = EventOSConfig["menu"][number];

export interface NavLeaf {
  /** PHP menu slug — the source of truth for the URL and capability gate. */
  slug: string;
  /** Overrides the PHP-provided title when a clearer promoter-facing label exists. */
  label?: string;
}

export interface NavGroup {
  id: string;
  label: string;
  items: NavLeaf[];
  /**
   * Renders the group label as an expand/collapse toggle instead of a static
   * heading. The sidebar auto-expands a collapsible group whenever the
   * current page is one of its own items.
   */
  collapsible?: boolean;
}

/**
 * "Regional" and "Security" point at the same modern Organisation Settings
 * page as "Organisation" (see OrganisationSettingsView's `initialGroup`
 * prop) — they're separate PHP pages with separate slugs, but the same
 * settings schema, so they open the matching tab rather than a duplicate
 * legacy form. The old "General" page (eventos-general) renders the
 * identical "general" tab, so it's intentionally not listed twice here; it
 * remains reachable at its own URL, it's just not a separate sidebar entry.
 */
export const NAV_GROUPS: NavGroup[] = [
  {
    id: "events",
    label: "Events",
    items: [
      { slug: "eventos-events-list", label: "My Events" },
      { slug: "eventos-events-calendar", label: "Calendar" },
      { slug: "eventos-venues", label: "Venues" },
      { slug: "eventos-artists", label: "Artists" },
      { slug: "eventos-event-terms", label: "Terms" },
    ],
  },
  {
    id: "sales",
    label: "Sales",
    items: [
      { slug: "eventos-orders", label: "Orders" },
      { slug: "wc-products", label: "Products" },
      { slug: "wc-customers", label: "Customers" },
      { slug: "wc-coupons", label: "Coupons" },
    ],
  },
  {
    id: "crm",
    label: "CRM",
    items: [
      { slug: "eventos-crm-people", label: "Customers" },
      { slug: "eventos-crm-segments", label: "Segments" },
      { slug: "eventos-crm-insights", label: "Relationship Insights" },
    ],
  },
  {
    id: "finance",
    label: "Finance",
    items: [{ slug: "eventos-finance", label: "Finance" }],
  },
  {
    id: "analytics",
    label: "Analytics",
    items: [{ slug: "eventos-analytics", label: "Analytics" }],
  },
  {
    id: "settings",
    label: "Settings",
    collapsible: true,
    items: [
      { slug: "eventos-settings", label: "Organisation" },
      { slug: "eventos-regional", label: "Regional" },
      { slug: "eventos-security", label: "Security" },
      { slug: "eventos-branding", label: "Branding" },
      { slug: "eventos-team", label: "Team" },
    ],
  },
  {
    id: "system",
    label: "System",
    collapsible: true,
    items: [
      { slug: "eventos-diagnostics", label: "Diagnostics" },
      { slug: "wc-diagnostics", label: "WooCommerce Diagnostics" },
      { slug: "eventos-import-export", label: "Import / Export" },
      { slug: "eventos-sync", label: "Synchronisation" },
      { slug: "wc-sync", label: "WooCommerce Synchronisation" },
      { slug: "wc-webhooks", label: "Webhooks" },
      { slug: "eventos-activity", label: "Activity Log" },
      { slug: "eventos-audit", label: "Audit Trail" },
      { slug: "eventos-notifications", label: "Notifications" },
    ],
  },
];

export const DASHBOARD_SLUG = "eventos";

/** The group a given menu slug belongs to, if any. */
export function groupForSlug(slug: string): NavGroup | undefined {
  return NAV_GROUPS.find((group) => group.items.some((item) => item.slug === slug));
}

export interface Crumb {
  label: string;
  href?: string;
}

/**
 * A short "Group / Page" trail for the top bar. Returns null for the
 * dashboard (it's the root, nothing to trail from) and for the Event
 * Workspace (its own page title already names the event; a generic
 * "Events / All Events" crumb above it would add noise, not orientation).
 */
export function breadcrumbFor(view: string, menu: MenuItem[]): Crumb[] | null {
  if (view === "dashboard") return null;
  if (view === "events/list" && new URLSearchParams(window.location.search).get("event"))
    return null;

  const item = menu.find((entry) => entry.view === view);
  if (!item) return null;

  const group = groupForSlug(item.slug);
  if (!group) return [{ label: item.title }];

  const leaf = group.items.find((entry) => entry.slug === item.slug);

  return [{ label: group.label, href: undefined }, { label: leaf?.label ?? item.title }];
}
