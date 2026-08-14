import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Alert,
  Badge,
  Button,
  Card,
  DataTable,
  Grid,
  LoadingState,
  Stack,
  StatCard,
  useToast,
} from "../../ui";
import { wcApi, type WcSyncModuleStatus, type WcSyncStatusValue } from "../../api";
import { fmtDate, syncStatusTone, wcErrorMessage } from "./shared";

type ModuleKey = "products" | "orders" | "customers" | "coupons";

// Product/customer/coupon data is always read live from WooCommerce — these
// three targets don't import or copy anything, they only confirm each record
// is current and refresh its "last synced" timestamp. Only the orders target
// does further work (re-resolving which EventOS event each order belongs
// to), so its description is the one that says so.
const MODULES: Array<{ key: ModuleKey; label: string; description: string }> = [
  {
    key: "products",
    label: "Products",
    description: "Confirm every WooCommerce product is current and refresh its last-synced time.",
  },
  {
    key: "orders",
    label: "Orders",
    description: "Re-resolve which EventOS event each WooCommerce order belongs to.",
  },
  {
    key: "customers",
    label: "Customers",
    description: "Confirm every WooCommerce customer is current and refresh its last-synced time.",
  },
  {
    key: "coupons",
    label: "Coupons",
    description: "Confirm every WooCommerce coupon is current and refresh its last-synced time.",
  },
];

function statusIcon(status: WcSyncStatusValue): string {
  const map: Record<WcSyncStatusValue, string> = {
    complete: "✅",
    running: "⏳",
    error: "❌",
    idle: "—",
  };
  return map[status] ?? "—";
}

function ModuleCard({
  mod,
  status,
  onSync,
  syncing,
}: {
  mod: (typeof MODULES)[0];
  status: WcSyncModuleStatus;
  onSync: () => void;
  syncing: boolean;
}) {
  const pct =
    status.total > 0 ? Math.min(100, Math.round((status.synced / status.total) * 100)) : null;

  return (
    <Card
      title={
        <div className="eos-inline">
          <span>{statusIcon(status.status)}</span>
          <span>{mod.label}</span>
          <Badge tone={syncStatusTone(status.status)}>{status.status}</Badge>
        </div>
      }
      actions={
        <Button
          size="sm"
          variant={status.status === "running" ? "secondary" : "primary"}
          loading={syncing || status.status === "running"}
          onClick={onSync}
        >
          {status.status === "running" ? "Running…" : "Sync now"}
        </Button>
      }
    >
      <Stack>
        <p className="eos-page__description">{mod.description}</p>

        <Grid minColumnWidth={120}>
          <div>
            <p className="eos-field__label">Synced</p>
            <span>
              {status.synced.toLocaleString()}
              {status.total > 0 && (
                <span className="eos-page__description"> / {status.total.toLocaleString()}</span>
              )}
            </span>
          </div>
          {status.errors > 0 && (
            <div>
              <p className="eos-field__label">Errors</p>
              <Badge tone="danger">{status.errors}</Badge>
            </div>
          )}
          <div>
            <p className="eos-field__label">Last run</p>
            <span className="eos-page__description">{fmtDate(status.last_run)}</span>
          </div>
        </Grid>

        {pct != null && (
          <div>
            <div
              className="eos-inline"
              style={{ justifyContent: "space-between", marginBottom: 4 }}
            >
              <span className="eos-page__description" style={{ fontSize: "var(--eos-text-sm)" }}>
                {pct}% complete
              </span>
            </div>
            <div
              style={{
                height: 6,
                background: "var(--eos-surface-muted)",
                borderRadius: 6,
                overflow: "hidden",
              }}
            >
              <div
                style={{
                  height: "100%",
                  width: `${pct}%`,
                  background:
                    status.status === "error"
                      ? "var(--eos-danger)"
                      : status.status === "running"
                        ? "var(--eos-warning)"
                        : "var(--eos-primary)",
                  borderRadius: 6,
                  transition: "width 0.4s ease",
                }}
              />
            </div>
          </div>
        )}

        {status.status === "error" && (
          <Alert tone="danger" title="Sync error">
            The last sync attempt encountered errors. Check the webhook log for details or retry the
            sync.
          </Alert>
        )}
      </Stack>
    </Card>
  );
}

export function SynchronisationView() {
  const toast = useToast();
  const qc = useQueryClient();

  const { data, isLoading, error } = useQuery({
    queryKey: ["wc", "sync", "status"],
    queryFn: () => wcApi.syncStatus(),
    refetchInterval: (q) => {
      const d = q.state.data;
      if (!d) return false;
      const running = Object.values(d).some((m) => m.status === "running");
      return running ? 3_000 : false;
    },
  });

  const syncMutations: Record<ModuleKey, ReturnType<typeof useMutation>> = {
    products: useMutation({
      mutationFn: () => wcApi.syncProducts(),
      onSuccess: () => {
        toast.success("Product sync queued.", "Products");
        void qc.invalidateQueries({ queryKey: ["wc", "sync", "status"] });
      },
      onError: (err: unknown) => toast.error(wcErrorMessage(err), "Products sync failed"),
    }),
    orders: useMutation({
      mutationFn: () => wcApi.syncOrders(),
      onSuccess: () => {
        toast.success("Order sync queued.", "Orders");
        void qc.invalidateQueries({ queryKey: ["wc", "sync", "status"] });
      },
      onError: (err: unknown) => toast.error(wcErrorMessage(err), "Orders sync failed"),
    }),
    customers: useMutation({
      mutationFn: () => wcApi.syncCustomers(),
      onSuccess: () => {
        toast.success("Customer sync queued.", "Customers");
        void qc.invalidateQueries({ queryKey: ["wc", "sync", "status"] });
      },
      onError: (err: unknown) => toast.error(wcErrorMessage(err), "Customers sync failed"),
    }),
    coupons: useMutation({
      mutationFn: () => wcApi.syncCoupons(),
      onSuccess: () => {
        toast.success("Coupon sync queued.", "Coupons");
        void qc.invalidateQueries({ queryKey: ["wc", "sync", "status"] });
      },
      onError: (err: unknown) => toast.error(wcErrorMessage(err), "Coupons sync failed"),
    }),
  };

  const anyRunning = data ? Object.values(data).some((m) => m.status === "running") : false;

  const totalSynced = data ? Object.values(data).reduce((acc, m) => acc + m.synced, 0) : 0;

  const totalErrors = data ? Object.values(data).reduce((acc, m) => acc + m.errors, 0) : 0;

  const lastRuns = data
    ? Object.values(data)
        .map((m) => m.last_run)
        .filter((d): d is string => !!d)
        .sort()
        .reverse()
    : [];

  const mostRecent = lastRuns[0] ?? null;

  return (
    <Stack>
      <Grid minColumnWidth={160}>
        <StatCard
          label="Total synced"
          value={totalSynced.toLocaleString()}
          hint="across all modules"
        />
        <StatCard
          label="Status"
          value={anyRunning ? "Running" : "Idle"}
          trend={{ direction: anyRunning ? "up" : "flat", label: "" }}
        />
        {totalErrors > 0 && <StatCard label="Errors" value={totalErrors.toLocaleString()} />}
        <StatCard label="Last sync" value={mostRecent ? fmtDate(mostRecent) : "Never"} />
      </Grid>

      {anyRunning && (
        <Alert tone="info" title="Sync in progress">
          One or more sync jobs are currently running. This view refreshes automatically every 3
          seconds.
        </Alert>
      )}

      {isLoading ? (
        <LoadingState label="Loading sync status…" />
      ) : error ? (
        <Alert tone="danger" title="Could not load sync status">
          {wcErrorMessage(error)}
        </Alert>
      ) : data ? (
        <div
          style={{
            display: "grid",
            gridTemplateColumns: "repeat(auto-fill, minmax(320px, 1fr))",
            gap: "var(--eos-space-4)",
          }}
        >
          {MODULES.map((mod) => (
            <ModuleCard
              key={mod.key}
              mod={mod}
              status={data[mod.key]}
              onSync={() => {
                syncMutations[mod.key].mutate(undefined as never);
              }}
              syncing={syncMutations[mod.key].isPending}
            />
          ))}
        </div>
      ) : (
        <Alert tone="warning" title="No sync data available">
          Sync status will appear here once WooCommerce is connected and the first synchronisation
          has been attempted.
        </Alert>
      )}
    </Stack>
  );
}
