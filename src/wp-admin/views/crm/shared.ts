import { config } from "../../api";

/**
 * Reads a query string parameter from the current wp-admin URL. A local
 * copy rather than importing from views/events/shared — each module stays
 * self-contained (see views/woocommerce/index.ts's own docblock), the same
 * reason this module defines its own fmtMoney/fmtDate below rather than
 * reusing woocommerce/shared.ts's.
 */
export function queryParam(key: string): string {
  return new URLSearchParams(window.location.search).get(key) ?? "";
}

/** The registered admin URL for the CRM People screen, from config().menu. */
function peopleBaseUrl(): string {
  return (
    config().menu.find((item) => item.view === "crm/people")?.url ??
    "admin.php?page=eventos-crm-people"
  );
}

/** Navigates to the People list — the same URL, with any `?person=` dropped. */
export function peopleListUrl(): string {
  return peopleBaseUrl();
}

/** Navigates to one Person's profile via the existing `?person=<id>` sub-navigation pattern. */
export function personProfileUrl(personId: number): string {
  const base = peopleBaseUrl();
  return `${base}${base.includes("?") ? "&" : "?"}person=${personId}`;
}

export function fmtMoney(amount: number, currency = "ZAR"): string {
  return new Intl.NumberFormat("en-ZA", {
    style: "currency",
    currency,
    maximumFractionDigits: 2,
  }).format(amount);
}

export function fmtDate(value: string | null | undefined): string {
  if (!value) return "—";
  const d = new Date(value);
  if (isNaN(d.getTime())) return "—";
  return d.toLocaleDateString("en-ZA", {
    day: "numeric",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

export function crmErrorMessage(err: unknown): string {
  if (err instanceof Error) return err.message;
  if (typeof err === "string") return err;
  return "An unexpected error occurred.";
}
