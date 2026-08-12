import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Alert,
  Badge,
  Button,
  Card,
  DataTable,
  FilterBar,
  Grid,
  LoadingState,
  Pagination,
  Stack,
  StatCard,
  useToast,
  type DataTableColumn,
  type FilterDefinition,
} from "../../ui";
import { wcApi, type WebhookEvent, type WebhookLogRecord, type WebhookStatus } from "../../api";
import { fmtDate, wcErrorMessage } from "./shared";

const EVENT_FILTER: FilterDefinition = {
  key: "event",
  label: "Event type",
  options: [
    { value: "order.created", label: "Order created" },
    { value: "order.updated", label: "Order updated" },
    { value: "order.completed", label: "Order completed" },
    { value: "order.refunded", label: "Order refunded" },
  ],
};

const STATUS_FILTER: FilterDefinition = {
  key: "status",
  label: "Status",
  options: [
    { value: "processed", label: "Processed" },
    { value: "failed", label: "Failed" },
    { value: "pending", label: "Pending" },
    { value: "skipped", label: "Skipped" },
  ],
};

function statusTone(status: WebhookStatus): "success" | "danger" | "warning" | "neutral" {
  const map: Record<WebhookStatus, "success" | "danger" | "warning" | "neutral"> = {
    processed: "success",
    failed: "danger",
    pending: "warning",
    skipped: "neutral",
  };
  return map[status] ?? "neutral";
}

function eventLabel(event: WebhookEvent): string {
  const map: Record<WebhookEvent, string> = {
    "order.created": "Order created",
    "order.updated": "Order updated",
    "order.completed": "Order completed",
    "order.refunded": "Order refunded",
  };
  return map[event] ?? event;
}

function WebhookRegistration() {
  const toast = useToast();
  const qc = useQueryClient();

  const registerMutation = useMutation({
    mutationFn: () => wcApi.registerWebhooks(),
    onSuccess: (res) => {
      const count = res.registered.length;
      toast.success(
        count > 0
          ? `${count} webhook${count !== 1 ? "s" : ""} registered.`
          : "All webhooks already registered.",
        "Webhooks",
      );
      void qc.invalidateQueries({ queryKey: ["wc", "connection"] });
    },
    onError: (err: unknown) => toast.error(wcErrorMessage(err), "Registration failed"),
  });

  const deregisterMutation = useMutation({
    mutationFn: () => wcApi.deregisterWebhooks(),
    onSuccess: (res) => {
      toast.success(`${res.deregistered.length} webhook(s) removed.`, "Webhooks");
      void qc.invalidateQueries({ queryKey: ["wc", "connection"] });
    },
    onError: (err: unknown) => toast.error(wcErrorMessage(err), "Deregistration failed"),
  });

  const connectionQuery = useQuery({
    queryKey: ["wc", "connection"],
    queryFn: () => wcApi.connectionStatus(),
    retry: false,
  });

  const connected = connectionQuery.data?.connected ?? false;
  const webhooksOk = connectionQuery.data?.webhooks_registered ?? false;

  return (
    <Card title="Webhook registration">
      <Stack>
        <Grid minColumnWidth={180}>
          <div>
            <p className="eos-field__label">WooCommerce connected</p>
            <Badge tone={connected ? "success" : "danger"}>
              {connected ? "Connected" : "Not connected"}
            </Badge>
          </div>
          <div>
            <p className="eos-field__label">Webhooks registered</p>
            <Badge tone={webhooksOk ? "success" : "warning"}>
              {webhooksOk ? "Registered" : "Not registered"}
            </Badge>
          </div>
        </Grid>

        {!webhooksOk && (
          <Alert tone="warning" title="Webhooks not registered">
            EventOS cannot receive real-time order updates from WooCommerce until webhooks are
            registered.
          </Alert>
        )}

        <div className="eos-inline">
          <Button
            variant="primary"
            loading={registerMutation.isPending}
            onClick={() => registerMutation.mutate()}
          >
            Register webhooks
          </Button>
          {webhooksOk && (
            <Button
              variant="danger"
              loading={deregisterMutation.isPending}
              onClick={() => deregisterMutation.mutate()}
            >
              Deregister webhooks
            </Button>
          )}
        </div>
      </Stack>
    </Card>
  );
}

export function WebhooksView() {
  const toast = useToast();
  const qc = useQueryClient();
  const [filterValues, setFilterValues] = useState<Record<string, string>>({});
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);

  const PER_PAGE = 25;
  const event = filterValues["event"] ?? "";
  const status = filterValues["status"] ?? "";

  const { data, isLoading, error } = useQuery({
    queryKey: ["wc", "webhooks", { event, status, page }],
    queryFn: () =>
      wcApi.webhookLog({
        event: event || undefined,
        status: status || undefined,
        page,
        per_page: PER_PAGE,
      }),
    placeholderData: (prev) => prev,
  });

  const retryMutation = useMutation({
    mutationFn: (logId: number) => wcApi.retryWebhook(logId),
    onSuccess: () => {
      toast.success("Webhook reprocessed.", "Retried");
      void qc.invalidateQueries({ queryKey: ["wc", "webhooks"] });
    },
    onError: (err: unknown) => toast.error(wcErrorMessage(err), "Retry failed"),
  });

  const logs = data?.items ?? [];
  const total = data?.total ?? 0;
  const totalPages = data?.totalPages ?? 1;

  const processed = logs.filter((l) => l.status === "processed").length;
  const failed = logs.filter((l) => l.status === "failed").length;
  const pending = logs.filter((l) => l.status === "pending").length;

  const columns: DataTableColumn<WebhookLogRecord>[] = [
    {
      key: "received_at",
      header: "Received",
      cell: (row) => fmtDate(row.received_at),
    },
    {
      key: "event",
      header: "Event",
      cell: (row) => <Badge tone="neutral">{eventLabel(row.event)}</Badge>,
    },
    {
      key: "wc_order_id",
      header: "Order",
      cell: (row) =>
        row.wc_order_id ? (
          <a
            href={`/wp-admin/post.php?post=${row.wc_order_id}&action=edit`}
            target="_blank"
            rel="noreferrer"
            className="eos-btn eos-btn--link"
          >
            #{row.wc_order_id}
          </a>
        ) : (
          <span className="eos-page__description">—</span>
        ),
    },
    {
      key: "status",
      header: "Status",
      cell: (row) => <Badge tone={statusTone(row.status)}>{row.status}</Badge>,
    },
    {
      key: "payload_summary",
      header: "Summary",
      cell: (row) => (
        <span
          className="eos-page__description"
          style={{
            maxWidth: 260,
            display: "block",
            overflow: "hidden",
            textOverflow: "ellipsis",
            whiteSpace: "nowrap",
          }}
        >
          {row.payload_summary || "—"}
        </span>
      ),
    },
    {
      key: "error",
      header: "Error",
      cell: (row) =>
        row.error ? (
          <span style={{ color: "var(--eos-danger)", fontSize: "var(--eos-text-sm)" }}>
            {row.error}
          </span>
        ) : (
          <span className="eos-page__description">—</span>
        ),
    },
    {
      key: "processed_at",
      header: "Processed",
      cell: (row) => <span className="eos-page__description">{fmtDate(row.processed_at)}</span>,
    },
    {
      key: "id",
      header: "",
      cell: (row) =>
        row.status === "failed" || row.status === "pending" ? (
          <Button
            size="sm"
            loading={retryMutation.isPending && retryMutation.variables === row.id}
            onClick={() => retryMutation.mutate(row.id)}
          >
            Retry
          </Button>
        ) : null,
    },
  ];

  return (
    <Stack>
      <Grid minColumnWidth={160}>
        <StatCard label="Total log entries" value={total.toLocaleString()} />
        <StatCard label="Processed" value={processed.toLocaleString()} hint="in current page" />
        {failed > 0 && (
          <StatCard label="Failed" value={failed.toLocaleString()} hint="retry available" />
        )}
        {pending > 0 && <StatCard label="Pending" value={pending.toLocaleString()} />}
      </Grid>

      <WebhookRegistration />

      <Card
        title={`Delivery log${total > 0 ? ` (${total.toLocaleString()})` : ""}`}
        actions={
          <a href={wcApi.exportLog()} download className="eos-btn eos-btn--secondary eos-btn--md">
            Export log
          </a>
        }
      >
        <Stack>
          <FilterBar
            search={{ value: search, onChange: setSearch, placeholder: "Search order #…" }}
            filters={[EVENT_FILTER, STATUS_FILTER]}
            values={filterValues}
            onFilterChange={(key, value) => {
              setFilterValues((prev) => ({ ...prev, [key]: value }));
              setPage(1);
            }}
            onReset={() => {
              setFilterValues({});
              setSearch("");
              setPage(1);
            }}
          />

          {isLoading ? (
            <LoadingState label="Loading webhook log…" />
          ) : error ? (
            <Alert tone="danger" title="Could not load webhook log">
              {wcErrorMessage(error)}
            </Alert>
          ) : (
            <>
              <DataTable
                caption="WooCommerce webhook delivery log"
                columns={columns}
                rows={logs}
                getRowId={(row) => String(row.id)}
                emptyTitle="No webhook events"
                emptyDescription={
                  event || status
                    ? "Try adjusting your filters."
                    : "Webhook events will appear here as WooCommerce sends them."
                }
              />
              {totalPages > 1 && (
                <Pagination
                  page={page}
                  totalPages={totalPages}
                  total={total}
                  onPageChange={setPage}
                />
              )}
            </>
          )}
        </Stack>
      </Card>
    </Stack>
  );
}
