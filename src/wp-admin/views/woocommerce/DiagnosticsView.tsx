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
import { wcApi, type WcConnectionStatus, type WcSyncStatus } from "../../api";
import { fmtDate, syncStatusTone, wcErrorMessage } from "./shared";

function ConnectionCard({
  status,
  onRecheck,
  rechecking,
}: {
  status: WcConnectionStatus;
  onRecheck: () => void;
  rechecking: boolean;
}) {
  const checks: Array<{ label: string; ok: boolean; detail?: string }> = [
    { label: "WooCommerce installed & active", ok: status.connected },
    { label: "REST API accessible", ok: status.api_accessible },
    {
      label: "Webhooks registered",
      ok: status.webhooks_registered,
      detail: status.webhooks_registered ? undefined : "Register webhooks in the Webhooks view.",
    },
  ];

  const allOk = checks.every((c) => c.ok);

  return (
    <Card
      title="WooCommerce connection"
      actions={
        <Button size="sm" loading={rechecking} onClick={onRecheck}>
          Re-check
        </Button>
      }
    >
      <Stack>
        <Grid minColumnWidth={160}>
          <div>
            <p className="eos-field__label">Status</p>
            <Badge tone={status.connected ? "success" : "danger"}>
              {status.connected ? "Connected" : "Not connected"}
            </Badge>
          </div>
          <div>
            <p className="eos-field__label">WooCommerce version</p>
            <span>{status.woocommerce_version || "—"}</span>
          </div>
          <div>
            <p className="eos-field__label">Store currency</p>
            <span>{status.store_currency || "—"}</span>
          </div>
          <div>
            <p className="eos-field__label">Store URL</p>
            <span className="eos-page__description" style={{ wordBreak: "break-all" }}>
              {status.store_url || "—"}
            </span>
          </div>
          <div>
            <p className="eos-field__label">Last checked</p>
            <span className="eos-page__description">{fmtDate(status.last_checked)}</span>
          </div>
        </Grid>

        {!allOk && (
          <Alert tone="warning" title="Issues detected">
            One or more health checks failed. Review the details below.
          </Alert>
        )}

        <DataTable
          caption="Connection health checks"
          columns={[
            {
              key: "label",
              header: "Check",
              cell: (row) => row.label,
            },
            {
              key: "ok",
              header: "Status",
              cell: (row) => (
                <Badge tone={row.ok ? "success" : "danger"}>{row.ok ? "Pass" : "Fail"}</Badge>
              ),
            },
            {
              key: "detail",
              header: "Detail",
              cell: (row) =>
                row.detail ? <span className="eos-page__description">{row.detail}</span> : null,
            },
          ]}
          rows={checks}
          getRowId={(row) => row.label}
          emptyTitle="No checks"
        />
      </Stack>
    </Card>
  );
}

function SyncStatusCard({ syncStatus }: { syncStatus: WcSyncStatus }) {
  type ModuleKey = keyof WcSyncStatus;
  const modules: Array<{ key: ModuleKey; label: string }> = [
    { key: "products", label: "Products" },
    { key: "orders", label: "Orders" },
    { key: "customers", label: "Customers" },
    { key: "coupons", label: "Coupons" },
  ];

  return (
    <Card title="Synchronisation status">
      <DataTable
        caption="WooCommerce sync module status"
        columns={[
          { key: "label", header: "Module", cell: (row) => <strong>{row.label}</strong> },
          {
            key: "status",
            header: "Status",
            cell: (row) => {
              const mod = syncStatus[row.key];
              return <Badge tone={syncStatusTone(mod.status)}>{mod.status}</Badge>;
            },
          },
          {
            key: "synced",
            header: "Synced",
            cell: (row) => {
              const mod = syncStatus[row.key];
              return (
                <span>
                  {mod.synced.toLocaleString()}
                  {mod.total > 0 && (
                    <span className="eos-page__description"> / {mod.total.toLocaleString()}</span>
                  )}
                </span>
              );
            },
          },
          {
            key: "errors",
            header: "Errors",
            cell: (row) => {
              const mod = syncStatus[row.key];
              return mod.errors > 0 ? (
                <Badge tone="danger">{mod.errors}</Badge>
              ) : (
                <span className="eos-page__description">—</span>
              );
            },
          },
          {
            key: "last_run",
            header: "Last run",
            cell: (row) => {
              const mod = syncStatus[row.key];
              return <span className="eos-page__description">{fmtDate(mod.last_run)}</span>;
            },
          },
        ]}
        rows={modules}
        getRowId={(row) => row.key}
        emptyTitle="No sync data"
      />
    </Card>
  );
}

export function WcDiagnosticsView() {
  const toast = useToast();
  const qc = useQueryClient();

  const connectionQuery = useQuery({
    queryKey: ["wc", "connection"],
    queryFn: () => wcApi.connectionStatus(),
    retry: false,
  });

  const syncQuery = useQuery({
    queryKey: ["wc", "sync", "status"],
    queryFn: () => wcApi.syncStatus(),
    retry: false,
  });

  const recheckMutation = useMutation({
    mutationFn: () => wcApi.recheckConnection(),
    onSuccess: () => {
      toast.success("Connection re-checked.", "Done");
      void qc.invalidateQueries({ queryKey: ["wc", "connection"] });
    },
    onError: (err: unknown) => toast.error(wcErrorMessage(err), "Re-check failed"),
  });

  const connected = connectionQuery.data?.connected ?? false;
  const apiOk = connectionQuery.data?.api_accessible ?? false;
  const webhooksOk = connectionQuery.data?.webhooks_registered ?? false;

  return (
    <Stack>
      <Grid minColumnWidth={160}>
        <StatCard
          label="Connection"
          value={connected ? "Connected" : "Disconnected"}
          trend={{ direction: connected ? "up" : "flat", label: "" }}
        />
        <StatCard label="REST API" value={apiOk ? "Accessible" : "Inaccessible"} />
        <StatCard label="Webhooks" value={webhooksOk ? "Registered" : "Not registered"} />
        {connectionQuery.data && (
          <StatCard label="Last checked" value={fmtDate(connectionQuery.data.last_checked)} />
        )}
      </Grid>

      {!connected && (
        <Alert tone="danger" title="WooCommerce not connected">
          EventOS cannot communicate with WooCommerce. Ensure WooCommerce is installed, active, and
          that the REST API is accessible.
        </Alert>
      )}

      {connectionQuery.isLoading ? (
        <LoadingState label="Checking connection…" />
      ) : connectionQuery.error ? (
        <Alert tone="danger" title="Could not check connection">
          {wcErrorMessage(connectionQuery.error)}
        </Alert>
      ) : connectionQuery.data ? (
        <ConnectionCard
          status={connectionQuery.data}
          onRecheck={() => recheckMutation.mutate()}
          rechecking={recheckMutation.isPending}
        />
      ) : null}

      {syncQuery.isLoading ? (
        <LoadingState label="Loading sync status…" />
      ) : syncQuery.error ? (
        <Alert tone="warning" title="Sync status unavailable">
          {wcErrorMessage(syncQuery.error)}
        </Alert>
      ) : syncQuery.data ? (
        <SyncStatusCard syncStatus={syncQuery.data} />
      ) : null}
    </Stack>
  );
}
