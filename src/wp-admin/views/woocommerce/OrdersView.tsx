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
  LoadingState,
  Pagination,
  Stack,
  StatCard,
  type DataTableColumn,
  type FilterDefinition,
} from "../../ui";
import { wcApi, type WcOrderRecord, type WcOrderStatus } from "../../api";
import { fmtDate, fmtMoney, wcErrorMessage, wcOrderStatusTone } from "./shared";

const STATUS_FILTER: FilterDefinition = {
  key: "status",
  label: "Payment status",
  options: [
    { value: "completed", label: "Completed" },
    { value: "processing", label: "Processing" },
    { value: "on-hold", label: "On hold" },
    { value: "pending", label: "Pending" },
    { value: "cancelled", label: "Cancelled" },
    { value: "refunded", label: "Refunded" },
    { value: "failed", label: "Failed" },
  ],
};

const REFUND_FILTER: FilterDefinition = {
  key: "has_refund",
  label: "Refunds",
  options: [
    { value: "true", label: "Has refunds" },
    { value: "false", label: "No refunds" },
  ],
};

const MAPPED_FILTER: FilterDefinition = {
  key: "mapped",
  label: "Mapping",
  options: [
    { value: "true", label: "Linked to event" },
    { value: "false", label: "Unlinked" },
  ],
};

function OrderDrawer({ order, onClose }: { order: WcOrderRecord; onClose: () => void }) {
  return (
    <Drawer
      open
      onClose={onClose}
      title={`Order #${order.wc_order_id}`}
      description={`${order.customer_name} · ${fmtDate(order.created_at)}`}
      footer={
        <a
          href={`/wp-admin/post.php?post=${order.wc_order_id}&action=edit`}
          target="_blank"
          rel="noreferrer"
          className="eos-btn eos-btn--secondary eos-btn--md"
        >
          Edit in WooCommerce ↗
        </a>
      }
    >
      <Stack>
        <Grid minColumnWidth={140}>
          <div>
            <p className="eos-field__label">Status</p>
            <Badge tone={wcOrderStatusTone(order.status as WcOrderStatus)}>{order.status}</Badge>
          </div>
          <div>
            <p className="eos-field__label">Total</p>
            <strong>{fmtMoney(order.total, order.currency)}</strong>
          </div>
          <div>
            <p className="eos-field__label">Payment method</p>
            <span>{order.payment_method_title || order.payment_method || "—"}</span>
          </div>
          <div>
            <p className="eos-field__label">Transaction ID</p>
            <span>{order.transaction_id || "—"}</span>
          </div>
          <div>
            <p className="eos-field__label">EventOS event</p>
            <span>{order.eos_event_id ? `#${order.eos_event_id}` : "Unlinked"}</span>
          </div>
          <div>
            <p className="eos-field__label">Last synced</p>
            <span>{fmtDate(order.eos_synced_at)}</span>
          </div>
        </Grid>

        <Card title="Customer">
          <Grid minColumnWidth={180}>
            <div>
              <p className="eos-field__label">Name</p>
              <strong>{order.customer_name}</strong>
            </div>
            <div>
              <p className="eos-field__label">Email</p>
              <span>{order.customer_email}</span>
            </div>
            <div>
              <p className="eos-field__label">Phone</p>
              <span>{order.customer_phone || "—"}</span>
            </div>
          </Grid>
        </Card>

        <Card title="Billing address">
          <p>
            {[
              order.billing.address_1,
              order.billing.address_2,
              order.billing.city,
              order.billing.state,
              order.billing.postcode,
              order.billing.country,
            ]
              .filter(Boolean)
              .join(", ")}
          </p>
        </Card>

        <Card title="Line items">
          <DataTable
            caption="Products in this order"
            columns={[
              { key: "name", header: "Product", cell: (r) => r.name },
              { key: "quantity", header: "Qty", cell: (r) => r.quantity },
              { key: "price", header: "Unit", cell: (r) => fmtMoney(r.price, order.currency) },
              { key: "total", header: "Total", cell: (r) => fmtMoney(r.total, order.currency) },
              {
                key: "eos_ticket_type_id",
                header: "Ticket tier",
                cell: (r) =>
                  r.eos_ticket_type_id ? (
                    <Badge tone="info">#{r.eos_ticket_type_id}</Badge>
                  ) : (
                    <span className="eos-page__description">—</span>
                  ),
              },
            ]}
            rows={order.line_items}
            getRowId={(r) => String(r.id)}
            emptyTitle="No line items"
          />
        </Card>

        {order.refunds.length > 0 && (
          <Card title="Refunds">
            <DataTable
              caption="Refunds on this order"
              columns={[
                { key: "created_at", header: "Date", cell: (r) => fmtDate(r.created_at) },
                {
                  key: "amount",
                  header: "Amount",
                  cell: (r) => fmtMoney(r.amount, order.currency),
                },
                { key: "reason", header: "Reason", cell: (r) => r.reason || "—" },
                { key: "refunded_by", header: "By", cell: (r) => r.refunded_by || "—" },
              ]}
              rows={order.refunds}
              getRowId={(r) => String(r.id)}
              emptyTitle="No refunds"
            />
          </Card>
        )}

        {order.coupon_lines.length > 0 && (
          <Card title="Coupons applied">
            <DataTable
              caption="Coupons used on this order"
              columns={[
                { key: "code", header: "Code", cell: (r) => <code>{r.code}</code> },
                {
                  key: "discount",
                  header: "Discount",
                  cell: (r) => fmtMoney(r.discount, order.currency),
                },
              ]}
              rows={order.coupon_lines}
              getRowId={(r) => String(r.id)}
              emptyTitle="No coupons"
            />
          </Card>
        )}

        {order.notes.length > 0 && (
          <Card title="Order notes">
            <Stack>
              {order.notes.map((n) => (
                <div key={n.id}>
                  <p className="eos-field__label">
                    {fmtDate(n.created_at)} · {n.added_by}
                    {n.customer_note && " (customer)"}
                  </p>
                  <p>{n.note}</p>
                </div>
              ))}
            </Stack>
          </Card>
        )}
      </Stack>
    </Drawer>
  );
}

export function OrdersView() {
  const [filterValues, setFilterValues] = useState<Record<string, string>>({});
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<WcOrderRecord | null>(null);

  const PER_PAGE = 20;
  const status = filterValues["status"] ?? "";

  const { data, isLoading, error } = useQuery({
    queryKey: ["wc", "orders", { search, status, page }],
    queryFn: () =>
      wcApi.orders({ search, status, page, per_page: PER_PAGE, orderby: "date", order: "desc" }),
    placeholderData: (prev) => prev,
  });

  const orders = data?.items ?? [];
  const total = data?.total ?? 0;
  const totalPages = data?.totalPages ?? 1;

  const completedRevenue = orders
    .filter((o) => o.status === "completed")
    .reduce((acc, o) => acc + o.total, 0);
  const refundedTotal = orders
    .filter((o) => o.status === "refunded")
    .reduce((acc, o) => acc + o.refunds.reduce((s, r) => s + r.amount, 0), 0);
  const withRefunds = orders.filter((o) => o.refunds.length > 0).length;

  const columns: DataTableColumn<WcOrderRecord>[] = [
    {
      key: "wc_order_id",
      header: "Order",
      cell: (row) => (
        <button className="eos-btn eos-btn--link" onClick={() => setSelected(row)}>
          #{row.wc_order_id}
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
    {
      key: "status",
      header: "Status",
      cell: (row) => (
        <Badge tone={wcOrderStatusTone(row.status as WcOrderStatus)}>{row.status}</Badge>
      ),
    },
    {
      key: "total",
      header: "Total",
      cell: (row) => <strong>{fmtMoney(row.total, row.currency)}</strong>,
    },
    {
      key: "refunds",
      header: "Refunds",
      cell: (row) =>
        row.refunds.length > 0 ? (
          <Badge tone="danger">
            {fmtMoney(
              row.refunds.reduce((s, r) => s + r.amount, 0),
              row.currency,
            )}
          </Badge>
        ) : (
          <span className="eos-page__description">—</span>
        ),
    },
    {
      key: "payment_method_title",
      header: "Payment",
      cell: (row) => row.payment_method_title || row.payment_method || "—",
    },
    {
      key: "eos_event_id",
      header: "Event",
      cell: (row) =>
        row.eos_event_id ? (
          <Badge tone="success">#{row.eos_event_id}</Badge>
        ) : (
          <Badge tone="neutral">Unlinked</Badge>
        ),
    },
    { key: "created_at", header: "Date", cell: (row) => fmtDate(row.created_at) },
    {
      key: "id",
      header: "",
      cell: (row) => (
        <div className="eos-inline">
          <Button size="sm" onClick={() => setSelected(row)}>
            View
          </Button>
          <a
            href={`/wp-admin/post.php?post=${row.wc_order_id}&action=edit`}
            target="_blank"
            rel="noreferrer"
            className="eos-btn eos-btn--secondary eos-btn--sm"
          >
            WC ↗
          </a>
        </div>
      ),
    },
  ];

  return (
    <Stack>
      <Grid minColumnWidth={160}>
        <StatCard label="Total orders" value={total.toLocaleString()} />
        <StatCard
          label="Completed revenue"
          value={fmtMoney(completedRevenue)}
          hint="Completed orders only"
        />
        {withRefunds > 0 && (
          <StatCard
            label="Refunded"
            value={fmtMoney(refundedTotal)}
            hint={`${withRefunds} order${withRefunds !== 1 ? "s" : ""}`}
          />
        )}
      </Grid>

      <Alert tone="info" title="WooCommerce orders">
        Orders are stored and processed in WooCommerce. EventOS reads order data to link tickets and
        guests. Refunds and payment changes must be made in WooCommerce.
      </Alert>

      <Card
        title={`Orders${total > 0 ? ` (${total.toLocaleString()})` : ""}`}
        actions={
          <a
            href={wcApi.exportOrders({ status, search })}
            download
            className="eos-btn eos-btn--secondary eos-btn--md"
          >
            Export CSV
          </a>
        }
      >
        <Stack>
          <FilterBar
            search={{
              value: search,
              onChange: setSearch,
              placeholder: "Search by name, email, order #…",
            }}
            filters={[STATUS_FILTER, REFUND_FILTER, MAPPED_FILTER]}
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
              {wcErrorMessage(error)}
            </Alert>
          ) : (
            <>
              <DataTable
                caption="WooCommerce orders"
                columns={columns}
                rows={orders}
                getRowId={(row) => String(row.id)}
                emptyTitle="No orders found"
                emptyDescription={
                  search || status
                    ? "Try adjusting your filters."
                    : "Orders will appear here after the first sync."
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

      {selected && <OrderDrawer order={selected} onClose={() => setSelected(null)} />}
    </Stack>
  );
}
