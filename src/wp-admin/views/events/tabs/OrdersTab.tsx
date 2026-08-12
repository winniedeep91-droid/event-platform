import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import {
  Alert,
  Badge,
  Button,
  Card,
  DataTable,
  Drawer,
  FilterBar,
  Grid,
  LinkButton,
  LoadingState,
  Pagination,
  Stack,
  StatCard,
  type DataTableColumn,
  type FilterDefinition,
} from "../../../ui";
import { eventsApi, type OrderRecord, type OrderStatus } from "../../../api";
import { errorMessage, formatDateTime } from "../shared";

interface Props {
  eventId: number;
}

const STATUS_FILTER: FilterDefinition = {
  key: "status",
  label: "Status",
  options: [
    { value: "completed", label: "Completed" },
    { value: "processing", label: "Processing" },
    { value: "on-hold", label: "On hold" },
    { value: "refunded", label: "Refunded" },
    { value: "cancelled", label: "Cancelled" },
    { value: "failed", label: "Failed" },
  ],
};

function statusTone(status: OrderStatus): "success" | "warning" | "neutral" | "danger" {
  const map: Record<OrderStatus, "success" | "warning" | "neutral" | "danger"> = {
    completed: "success",
    processing: "warning",
    "on-hold": "neutral",
    refunded: "danger",
    cancelled: "neutral",
    failed: "danger",
    pending: "neutral",
  };
  return map[status] ?? "neutral";
}

function fmt(amount: number, currency: string) {
  return new Intl.NumberFormat("en-ZA", { style: "currency", currency: currency || "ZAR" }).format(
    amount,
  );
}

function OrderDetailDrawer({ order, onClose }: { order: OrderRecord; onClose: () => void }) {
  return (
    <Drawer
      open
      onClose={onClose}
      title={`Order #${order.wc_order_id || order.id}`}
      description={`${order.customer_name} · ${formatDateTime(order.created_at)}`}
      footer={
        <LinkButton
          href={`/wp-admin/post.php?post=${order.wc_order_id}&action=edit`}
          target="_blank"
        >
          Edit in WooCommerce
        </LinkButton>
      }
    >
      <Stack>
        <Grid minColumnWidth={140}>
          <div>
            <p className="eos-field__label">Status</p>
            <Badge tone={statusTone(order.status)}>{order.status}</Badge>
          </div>
          <div>
            <p className="eos-field__label">Total</p>
            <strong>{fmt(order.total, order.currency)}</strong>
          </div>
          <div>
            <p className="eos-field__label">Payment</p>
            <span>{order.payment_method || "—"}</span>
          </div>
        </Grid>

        <Card title="Customer">
          <Stack>
            <strong>{order.customer_name}</strong>
            <span>{order.customer_email}</span>
            {order.billing_address && (
              <span className="eos-page__description">{order.billing_address}</span>
            )}
          </Stack>
        </Card>

        <Card title="Tickets">
          <DataTable
            caption="Tickets in this order"
            columns={[
              { key: "ticket_type_name", header: "Type", cell: (row) => row.ticket_type_name },
              { key: "quantity", header: "Qty", cell: (row) => row.quantity },
              { key: "price", header: "Unit price", cell: (row) => fmt(row.price, order.currency) },
              { key: "total", header: "Total", cell: (row) => fmt(row.total, order.currency) },
            ]}
            rows={order.tickets}
            getRowId={(row) => String(row.id)}
            emptyTitle="No ticket line items"
          />
        </Card>

        {order.refunds.length > 0 && (
          <Card title="Refunds">
            <DataTable
              caption="Refunds on this order"
              columns={[
                {
                  key: "created_at",
                  header: "Date",
                  cell: (row) => formatDateTime(row.created_at),
                },
                { key: "amount", header: "Amount", cell: (row) => fmt(row.amount, order.currency) },
                { key: "reason", header: "Reason", cell: (row) => row.reason || "—" },
              ]}
              rows={order.refunds}
              getRowId={(row) => String(row.id)}
              emptyTitle="No refunds"
            />
          </Card>
        )}

        {order.notes && (
          <Card title="Customer note">
            <p>{order.notes}</p>
          </Card>
        )}
      </Stack>
    </Drawer>
  );
}

export function OrdersTab({ eventId }: Props) {
  const [filterValues, setFilterValues] = useState<Record<string, string>>({});
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<OrderRecord | null>(null);

  const PER_PAGE = 20;
  const status = filterValues["status"] ?? "";

  const { data, isLoading, error } = useQuery({
    queryKey: ["eventos", "orders", eventId, { search, status, page }],
    queryFn: () =>
      eventsApi.eventOrders(eventId, {
        search,
        status,
        page,
        per_page: PER_PAGE,
        orderby: "created_at",
        order: "desc",
      }),
    placeholderData: (prev) => prev,
  });

  const orders = data?.items ?? [];
  const total = data?.total ?? 0;
  const totalPages = data?.totalPages ?? 1;

  const completedRevenue = orders
    .filter((o) => o.status === "completed")
    .reduce((acc, o) => acc + o.total, 0);
  const refundedAmount = orders
    .filter((o) => o.status === "refunded")
    .reduce((acc, o) => acc + o.refunds.reduce((r, ref) => r + ref.amount, 0), 0);
  const totalTickets = orders.reduce((acc, o) => acc + o.ticket_count, 0);

  const columns: DataTableColumn<OrderRecord>[] = [
    {
      key: "wc_order_id",
      header: "Order",
      cell: (row) => (
        <button className="eos-btn eos-btn--link" onClick={() => setSelected(row)}>
          #{row.wc_order_id || row.id}
        </button>
      ),
    },
    {
      key: "customer_name",
      header: "Customer",
      cell: (row) => (
        <Stack>
          <strong>{row.customer_name}</strong>
          <span className="eos-page__description">{row.customer_email}</span>
        </Stack>
      ),
    },
    { key: "ticket_count", header: "Tickets", cell: (row) => row.ticket_count },
    {
      key: "total",
      header: "Total",
      cell: (row) => <strong>{fmt(row.total, row.currency)}</strong>,
    },
    {
      key: "status",
      header: "Status",
      cell: (row) => <Badge tone={statusTone(row.status)}>{row.status}</Badge>,
    },
    { key: "payment_method", header: "Payment", cell: (row) => row.payment_method || "—" },
    { key: "created_at", header: "Date", cell: (row) => formatDateTime(row.created_at) },
    {
      key: "id",
      header: "",
      cell: (row) => (
        <div className="eos-inline">
          <Button size="sm" onClick={() => setSelected(row)}>
            View
          </Button>
          <LinkButton
            size="sm"
            href={`/wp-admin/post.php?post=${row.wc_order_id}&action=edit`}
            target="_blank"
          >
            WC ↗
          </LinkButton>
        </div>
      ),
    },
  ];

  return (
    <Stack>
      <Grid minColumnWidth={160}>
        <StatCard label="Total orders" value={total.toLocaleString()} />
        <StatCard
          label="Revenue"
          value={new Intl.NumberFormat("en-ZA", {
            style: "currency",
            currency: "ZAR",
            maximumFractionDigits: 0,
          }).format(completedRevenue)}
          hint="Completed orders"
        />
        <StatCard label="Tickets" value={totalTickets.toLocaleString()} />
        {refundedAmount > 0 && (
          <StatCard
            label="Refunded"
            value={new Intl.NumberFormat("en-ZA", {
              style: "currency",
              currency: "ZAR",
              maximumFractionDigits: 0,
            }).format(refundedAmount)}
          />
        )}
      </Grid>

      <Alert tone="info" title="Powered by WooCommerce">
        Orders are processed and stored in WooCommerce. Refunds, payment changes and order notes
        must be managed in WooCommerce.
      </Alert>

      <Card
        title={`Orders${total > 0 ? ` (${total.toLocaleString()})` : ""}`}
        actions={
          <div className="eos-inline">
            <a
              href={eventsApi.exportOrders(eventId)}
              download
              className="eos-btn eos-btn--secondary eos-btn--md"
            >
              Export CSV
            </a>
          </div>
        }
      >
        <Stack>
          <FilterBar
            search={{
              value: search,
              onChange: setSearch,
              placeholder: "Search by name, email, order #…",
            }}
            filters={[STATUS_FILTER]}
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
            <LoadingState label="Loading orders…" />
          ) : error ? (
            <Alert tone="danger" title="Could not load orders">
              {errorMessage(error)}
            </Alert>
          ) : (
            <>
              <DataTable
                caption="WooCommerce orders for this event"
                columns={columns}
                rows={orders}
                getRowId={(row) => String(row.id)}
                emptyTitle="No orders found"
                emptyDescription={
                  search || status
                    ? "Try adjusting your filters."
                    : "Orders placed through WooCommerce for this event will appear here."
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

      {selected && <OrderDetailDrawer order={selected} onClose={() => setSelected(null)} />}
    </Stack>
  );
}
