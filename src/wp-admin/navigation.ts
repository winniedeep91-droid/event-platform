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
  /**
   * Starts a new labelled cluster within the group, rendered as a small
   * sub-heading right above this item. Used in "system" to separate
   * platform-wide screens from their WooCommerce-specific counterparts —
   * "Diagnostics" and "WooCommerce Diagnostics" otherwise read as one
   * confusable flat list.
   */
  section?: string;
}

export interface NavGroup {
  id: string;
  label: string;
  items: NavLeaf[];
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
      { slug: "eventos-events-list", label: "All Events" },
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
    id: "settings",
    label: "Settings",
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
    items: [
      { slug: "eventos-diagnostics", label: "Diagnostics", section: "Platform" },
      { slug: "eventos-sync", label: "Synchronisation" },
      { slug: "eventos-activity", label: "Activity Log" },
      { slug: "eventos-audit", label: "Audit Trail" },
      { slug: "eventos-notifications", label: "Notifications" },
      { slug: "wc-diagnostics", label: "Diagnostics", section: "WooCommerce" },
      { slug: "wc-sync", label: "Synchronisation" },
      { slug: "wc-webhooks", label: "Webhooks" },
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

  const leafIndex = group.items.findIndex((entry) => entry.slug === item.slug);
  const leaf = group.items[leafIndex];
  // The nearest preceding item (including this one) that opens a section.
  const section = group.items
    .slice(0, leafIndex + 1)
    .reverse()
    .find((entry) => entry.section)?.section;

  return [
    { label: group.label, href: undefined },
    ...(section ? [{ label: section, href: undefined }] : []),
    { label: leaf?.label ?? item.title },
  ];
}
