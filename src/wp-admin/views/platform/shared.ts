/**
 * Shared helpers for the Platform administration screens.
 *
 * Presentation helpers only — every UI primitive comes from the shared
 * EventOS component library in `src/wp-admin/ui`.
 */
import { config } from "../../api";
import type { Tone } from "../../ui";

export const PLATFORM_PAGES = {
  activity: "eventos-activity",
  audit: "eventos-audit",
  notifications: "eventos-notifications",
  branding: "eventos-branding",
  sync: "eventos-sync",
  diagnostics: "eventos-diagnostics",
  settings: "eventos-settings",
} as const;

export type PlatformPage = (typeof PLATFORM_PAGES)[keyof typeof PLATFORM_PAGES];

/** Builds an absolute wp-admin URL for a platform screen. */
export function platformUrl(
  page: PlatformPage,
  params: Record<string, string | number | undefined> = {},
): string {
  const { adminUrl } = config();
  const base = `${adminUrl.replace(/\/+$/, "")}/admin.php`;
  const search = new URLSearchParams({ page });

  Object.entries(params).forEach(([key, value]) => {
    if (value === undefined || value === "") return;
    search.set(key, String(value));
  });

  return `${base}?${search.toString()}`;
}

/** Reads a query string parameter from the current wp-admin URL. */
export function platformQueryParam(key: string): string {
  return new URLSearchParams(window.location.search).get(key) ?? "";
}

/** Turns a snake_case slug into a human readable label. */
export function humanise(value: string): string {
  if (!value) return "—";

  return value
    .replace(/[_.-]+/g, " ")
    .split(" ")
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(" ");
}

/** Formats a MySQL UTC datetime for display in the site locale. */
export function formatDateTime(value: string | null | undefined): string {
  if (!value) return "—";

  const normalised = value.includes("T") ? value : `${value.replace(" ", "T")}Z`;
  const date = new Date(normalised);

  if (Number.isNaN(date.getTime())) return value;

  return new Intl.DateTimeFormat(config().locale.replace("_", "-"), {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(date);
}

/** Formats a duration expressed in seconds. */
export function formatDuration(seconds: number): string {
  if (!seconds || seconds < 0) return "—";
  if (seconds < 1) return `${Math.round(seconds * 1000)} ms`;
  if (seconds < 60) return `${seconds.toFixed(1)} s`;

  const minutes = Math.floor(seconds / 60);
  const rest = Math.round(seconds % 60);

  return `${minutes} m ${rest} s`;
}

const SEVERITY_TONE: Record<string, Tone> = {
  debug: "neutral",
  info: "info",
  notice: "info",
  success: "success",
  warning: "warning",
  error: "danger",
  critical: "danger",
};

/** Maps an activity severity onto the shared tone vocabulary. */
export function severityTone(severity: string): Tone {
  return SEVERITY_TONE[severity] ?? "neutral";
}

const STATUS_TONE: Record<string, Tone> = {
  pass: "success",
  success: "success",
  completed: "success",
  ok: "success",
  running: "info",
  pending: "warning",
  queued: "warning",
  warn: "warning",
  warning: "warning",
  skipped: "neutral",
  cancelled: "neutral",
  idle: "neutral",
  fail: "danger",
  failed: "danger",
  error: "danger",
};

/** Maps a run/job/check status onto the shared tone vocabulary. */
export function statusTone(status: string): Tone {
  return STATUS_TONE[status] ?? "neutral";
}

/** Renders an arbitrary audit value as readable text. */
export function formatValue(value: unknown): string {
  if (value === null || value === undefined || value === "") return "—";
  if (typeof value === "string") return value;
  if (typeof value === "number" || typeof value === "boolean") return String(value);

  try {
    return JSON.stringify(value, null, 2);
  } catch {
    return String(value);
  }
}

/** Flattens an object into a sorted key/value list for diffing. */
export function flatten(value: unknown, prefix = ""): Array<{ key: string; value: unknown }> {
  if (value === null || typeof value !== "object" || Array.isArray(value)) {
    return [{ key: prefix || "value", value }];
  }

  return Object.entries(value as Record<string, unknown>)
    .flatMap(([key, nested]) => flatten(nested, prefix ? `${prefix}.${key}` : key))
    .sort((a, b) => a.key.localeCompare(b.key));
}

/** Builds the union of keys present in the before/after payloads. */
export function diffRows(
  before: unknown,
  after: unknown,
): Array<{ key: string; before: unknown; after: unknown; changed: boolean }> {
  const beforeMap = new Map(flatten(before).map((row) => [row.key, row.value]));
  const afterMap = new Map(flatten(after).map((row) => [row.key, row.value]));
  const keys = Array.from(new Set([...beforeMap.keys(), ...afterMap.keys()])).sort();

  return keys.map((key) => {
    const left = beforeMap.get(key);
    const right = afterMap.get(key);

    return {
      key,
      before: left,
      after: right,
      changed: formatValue(left) !== formatValue(right),
    };
  });
}

/** Builds select options from a list of slugs. */
export function slugOptions(values: string[]): Array<{ value: string; label: string }> {
  return values.map((value) => ({ value, label: humanise(value) }));
}
