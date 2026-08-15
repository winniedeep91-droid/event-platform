/**
 * Shared helpers for the Events administration screens.
 *
 * Navigation, date formatting and status presentation live here so every
 * events screen behaves identically. No UI primitives are declared in this
 * file — the shared EventOS component library remains the only source of
 * components.
 */
import { config, type EventRecord } from "../../api";
import { Badge, type StatusKind } from "../../ui";

export const EVENTS_PAGES = {
  dashboard: "eventos-events",
  list: "eventos-events-list",
  calendar: "eventos-events-calendar",
  venues: "eventos-venues",
  artists: "eventos-artists",
  terms: "eventos-event-terms",
} as const;

export type EventsPage = (typeof EVENTS_PAGES)[keyof typeof EVENTS_PAGES];

/** Builds an absolute wp-admin URL for an EventOS screen. */
export function pageUrl(
  page: EventsPage,
  params: Record<string, string | number | undefined> = {},
): string {
  const { adminUrl } = config();
  // adminUrl is already the full admin.php URL (built by admin_url('admin.php')
  // on the PHP side) — do not append another /admin.php segment here.
  const base = adminUrl.replace(/\/+$/, "");
  const search = new URLSearchParams({ page });

  Object.entries(params).forEach(([key, value]) => {
    if (value === undefined || value === "") return;
    search.set(key, String(value));
  });

  return `${base}?${search.toString()}`;
}

/** Navigates the browser to another EventOS screen. */
export function goTo(
  page: EventsPage,
  params: Record<string, string | number | undefined> = {},
): void {
  window.location.href = pageUrl(page, params);
}

/** Reads a query string parameter from the current wp-admin URL. */
export function queryParam(key: string): string {
  return new URLSearchParams(window.location.search).get(key) ?? "";
}

const STATUS_KIND: Record<string, StatusKind> = {
  draft: "draft",
  scheduled: "scheduled",
  published: "active",
  live: "active",
  completed: "inactive",
  cancelled: "failed",
  archived: "inactive",
  postponed: "pending",
  pending: "pending",
  sold_out: "pending",
};

/** Maps an event lifecycle status onto the shared status chip vocabulary. */
export function statusKind(status: string): StatusKind {
  return STATUS_KIND[status] ?? "inactive";
}

/** Human readable label for a status slug. */
export function statusLabel(status: string, labels?: Record<string, string>): string {
  if (labels && labels[status]) return labels[status];

  return status
    .split("_")
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(" ");
}

/** Formats a MySQL UTC datetime for display in the site locale. */
export function formatDateTime(value: string | null | undefined): string {
  if (!value) return "—";

  const parsed = new Date(value.replace(" ", "T") + (value.includes("Z") ? "" : "Z"));

  if (Number.isNaN(parsed.getTime())) return value;

  return parsed.toLocaleString(config().locale.replace("_", "-"), {
    dateStyle: "medium",
    timeStyle: "short",
  });
}

/** Formats a MySQL UTC datetime as a date only. */
export function formatDate(value: string | null | undefined): string {
  if (!value) return "—";

  const parsed = new Date(value.replace(" ", "T") + (value.includes("Z") ? "" : "Z"));

  if (Number.isNaN(parsed.getTime())) return value;

  return parsed.toLocaleDateString(config().locale.replace("_", "-"), { dateStyle: "medium" });
}

/** Formats an amount in the given ISO currency code for display. */
export function formatMoney(amount: number, currency: string): string {
  return new Intl.NumberFormat(config().locale.replace("_", "-"), {
    style: "currency",
    currency: currency || "USD",
    maximumFractionDigits: 0,
  }).format(amount);
}

/** Formats a Y-m-d date string as a short "15 Aug" label for chart axes/tooltips. */
export function formatShortDate(value: string): string {
  const parsed = new Date(`${value}T00:00:00Z`);

  if (Number.isNaN(parsed.getTime())) return value;

  return parsed.toLocaleDateString(config().locale.replace("_", "-"), {
    day: "numeric",
    month: "short",
  });
}

/** Converts a MySQL datetime into a value the datetime-local control accepts. */
export function toLocalInput(value: string | null | undefined): string {
  if (!value) return "";

  return value.replace(" ", "T").slice(0, 16);
}

/** Converts a datetime-local value back into the MySQL format the API expects. */
export function fromLocalInput(value: string): string {
  if (!value) return "";

  return `${value.replace("T", " ")}:00`.slice(0, 19);
}

/** Renders the venue of an event, or a neutral placeholder. */
export function venueLabel(event: EventRecord): string {
  if (!event.venue_name) return "No venue";

  return event.venue_city ? `${event.venue_name} · ${event.venue_city}` : event.venue_name;
}

/** Visibility badge shared by the list and detail screens. */
export function VisibilityBadge({ visibility }: { visibility: string }) {
  const tone =
    visibility === "public" ? "success" : visibility === "private" ? "warning" : "neutral";

  return <Badge tone={tone}>{statusLabel(visibility)}</Badge>;
}

/** Turns an unknown thrown value into a message. */
export function errorMessage(error: unknown): string {
  return error instanceof Error ? error.message : "Something went wrong.";
}
