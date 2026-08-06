import { type WcOrderStatus, type WcProductStatus, type WcSyncStatusValue } from "../../api";

export function wcOrderStatusTone(
  status: WcOrderStatus,
): "success" | "warning" | "neutral" | "danger" {
  const map: Record<WcOrderStatus, "success" | "warning" | "neutral" | "danger"> = {
    completed: "success",
    processing: "warning",
    "on-hold": "neutral",
    pending: "neutral",
    cancelled: "neutral",
    refunded: "danger",
    failed: "danger",
  };
  return map[status] ?? "neutral";
}

export function wcProductStatusTone(
  status: WcProductStatus,
): "success" | "warning" | "neutral" | "danger" {
  const map: Record<WcProductStatus, "success" | "warning" | "neutral" | "danger"> = {
    publish: "success",
    draft: "neutral",
    private: "warning",
    pending: "warning",
    trash: "danger",
  };
  return map[status] ?? "neutral";
}

export function syncStatusTone(
  status: WcSyncStatusValue,
): "success" | "warning" | "neutral" | "danger" {
  const map: Record<WcSyncStatusValue, "success" | "warning" | "neutral" | "danger"> = {
    complete: "success",
    running: "warning",
    error: "danger",
    idle: "neutral",
  };
  return map[status] ?? "neutral";
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

export function wcErrorMessage(err: unknown): string {
  if (err instanceof Error) return err.message;
  if (typeof err === "string") return err;
  return "An unexpected error occurred.";
}
