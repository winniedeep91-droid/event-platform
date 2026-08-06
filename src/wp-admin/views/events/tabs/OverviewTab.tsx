import { useQuery } from "@tanstack/react-query";
import {
  ActivityFeed,
  Alert,
  Badge,
  Card,
  DataTable,
  DefinitionList,
  Grid,
  LoadingState,
  ProgressBar,
  Stack,
  StatCard,
  type DataTableColumn,
} from "../../../ui";
import { eventsApi, type EventRecord, type OrderRecord } from "../../../api";
import { formatDateTime, venueLabel, statusLabel } from "../shared";

interface Props {
  event: EventRecord;
  statuses?: Record<string, string>;
}

function currency(amount: number, cur: string) {
  return new Intl.NumberFormat("en-ZA", {
    style: "currency",
    currency: cur || "ZAR",
    maximumFractionDigits: 0,
  }).format(amount);
}

export function OverviewTab({ event, statuses }: Props) {
  const report = useQuery({
    queryKey: ["eventos", "events", "report", event.id],
    queryFn: () => eventsApi.eventReport(event.id),
    retry: false,
  });

  const ordersQuery = useQuery({
    queryKey: ["eventos", "events", "orders", event.id, { page: 1, per_page: 5 }],
    queryFn: () => eventsApi.eventOrders(event.id, { page: 1, per_page: 5 }),
    retry: false,
  });

  const s = report.data?.summary;
  const cur = "ZAR";

  const capacityPct =
    s && s.capacity && s.capacity > 0
      ? Math.min(100, Math.round((s.tickets_sold / s.capacity) * 100))
      : null;

  const checkinPct =
    s && s.tickets_sold > 0
      ? Math.min(100, Math.round((s.checked_in / s.tickets_sold) * 100))
      : null;

  const orderColumns: DataTableColumn<OrderRecord>[] = [
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
      key: "ticket_count",
      header: "Tickets",
      cell: (row) => row.ticket_count,
    },
    {
      key: "total",
      header: "Total",
      cell: (row) => currency(row.total, row.currency),
    },
    {
      key: "status",
      header: "Status",
      cell: (row) => (
        <Badge
          tone={
            row.status === "completed"
              ? "success"
              : row.status === "refunded"
                ? "danger"
                : "neutral"
          }
        >
          {row.status}
        </Badge>
      ),
    },
    {
      key: "created_at",
      header: "Date",
      cell: (row) => formatDateTime(row.created_at),
    },
  ];

  return (
    <Stack>
      {/* KPI cards */}
      {report.isLoading ? (
        <LoadingState label="Loading stats…" />
      ) : report.error ? (
        <Alert tone="warning" title="Stats unavailable">
          Revenue and sales data will appear once orders are placed for this event.
        </Alert>
      ) : s ? (
        <Grid minColumnWidth={180}>
          <StatCard
            label="Gross revenue"
            value={currency(s.gross_revenue, cur)}
            hint={`${s.orders} order${s.orders !== 1 ? "s" : ""}`}
            trend={{ direction: s.gross_revenue > 0 ? "up" : "flat", label: "all time" }}
          />
          <StatCard
            label="Net revenue"
            value={currency(s.net_revenue, cur)}
            hint={s.refunds > 0 ? `${currency(s.refunds, cur)} refunded` : "No refunds"}
          />
          <StatCard
            label="Tickets sold"
            value={s.tickets_sold.toLocaleString()}
            hint={s.tickets_available != null ? `${s.tickets_available} remaining` : "No limit"}
          />
          <StatCard
            label="Checked in"
            value={s.checked_in.toLocaleString()}
            hint={checkinPct != null ? `${checkinPct}% of sold` : undefined}
            trend={{ direction: s.checked_in > 0 ? "up" : "flat", label: "admitted" }}
          />
          {s.complimentary > 0 && (
            <StatCard
              label="Complimentary"
              value={s.complimentary.toLocaleString()}
              hint="Issued"
            />
          )}
          <StatCard label="Avg. order value" value={currency(s.average_order_value, cur)} />
        </Grid>
      ) : null}

      {/* Capacity bars */}
      {(capacityPct != null || checkinPct != null) && s && (
        <Grid minColumnWidth={280}>
          {capacityPct != null && s.capacity != null && (
            <Card title="Capacity">
              <Stack>
                <div className="eos-inline" style={{ justifyContent: "space-between" }}>
                  <span>{s.tickets_sold.toLocaleString()} sold</span>
                  <span className="eos-page__description">
                    {capacityPct}% of {s.capacity.toLocaleString()}
                  </span>
                </div>
                <ProgressBar
                  value={capacityPct}
                  max={100}
                  tone={capacityPct > 85 ? "danger" : capacityPct > 60 ? "warning" : "primary"}
                  label={`${capacityPct}% capacity`}
                />
              </Stack>
            </Card>
          )}
          {checkinPct != null && (
            <Card title="Check-in progress">
              <Stack>
                <div className="eos-inline" style={{ justifyContent: "space-between" }}>
                  <span>{s.checked_in.toLocaleString()} admitted</span>
                  <span className="eos-page__description">{checkinPct}% checked in</span>
                </div>
                <ProgressBar
                  value={checkinPct}
                  max={100}
                  tone="success"
                  label={`${checkinPct}% checked in`}
                />
              </Stack>
            </Card>
          )}
        </Grid>
      )}

      {/* Event details */}
      <Card title="Event details">
        <DefinitionList
          items={[
            { term: "Status", value: statusLabel(event.status, statuses) },
            { term: "Visibility", value: statusLabel(event.visibility) },
            { term: "Ticket visibility", value: statusLabel(event.ticket_visibility) },
            { term: "Starts", value: formatDateTime(event.starts_at) },
            { term: "Ends", value: formatDateTime(event.ends_at) },
            { term: "Doors open", value: formatDateTime(event.doors_open_at) },
            { term: "Venue", value: venueLabel(event) },
            { term: "Timezone", value: event.timezone || "—" },
            {
              term: "Capacity",
              value: event.capacity ? event.capacity.toLocaleString() : "Unlimited",
            },
            { term: "Age restriction", value: event.age_restriction || "—" },
            { term: "Accessibility", value: event.accessibility || "—" },
            { term: "Slug", value: event.slug },
            { term: "Last updated", value: formatDateTime(event.updated_at) },
          ]}
        />
      </Card>

      {/* Revenue by ticket type */}
      {report.data && report.data.revenue_by_ticket_type.length > 0 && (
        <Card title="Revenue by ticket type">
          <DataTable
            caption="Sales breakdown by ticket type"
            columns={[
              { key: "name", header: "Ticket type", cell: (row) => <strong>{row.name}</strong> },
              {
                key: "tier",
                header: "Tier",
                cell: (row) => <Badge tone="neutral">{row.tier}</Badge>,
              },
              { key: "sold", header: "Sold", cell: (row) => row.sold.toLocaleString() },
              {
                key: "capacity",
                header: "Capacity",
                cell: (row) => (row.capacity != null ? row.capacity.toLocaleString() : "Unlimited"),
              },
              { key: "gross", header: "Gross", cell: (row) => currency(row.gross, cur) },
              { key: "net", header: "Net", cell: (row) => currency(row.net, cur) },
            ]}
            rows={report.data.revenue_by_ticket_type}
            getRowId={(row) => String(row.ticket_type_id)}
            emptyTitle="No ticket types"
          />
        </Card>
      )}

      {/* Recent orders */}
      <Card title="Recent orders">
        {ordersQuery.isLoading ? (
          <LoadingState label="Loading orders…" />
        ) : ordersQuery.error ? (
          <Alert tone="warning" title="Orders unavailable">
            Order data will appear here once the Ticketing module is active.
          </Alert>
        ) : (
          <DataTable
            caption="Recent orders for this event"
            columns={orderColumns}
            rows={ordersQuery.data?.items ?? []}
            getRowId={(row) => String(row.id)}
            emptyTitle="No orders yet"
            emptyDescription="Orders placed through WooCommerce for this event will appear here."
          />
        )}
      </Card>

      {/* Line-up */}
      {(event.artists ?? []).length > 0 && (
        <Card title="Line-up">
          <DataTable
            caption="Artists booked for this event"
            columns={[
              {
                key: "artist_name",
                header: "Artist",
                cell: (row) => <strong>{row.artist_name}</strong>,
              },
              { key: "billing", header: "Billing", cell: (row) => row.billing || "—" },
              { key: "stage", header: "Stage", cell: (row) => row.stage || "—" },
              {
                key: "starts_at",
                header: "Set time",
                cell: (row) => formatDateTime(row.starts_at),
              },
            ]}
            rows={event.artists ?? []}
            getRowId={(row) => String(row.id)}
            emptyTitle="No artists booked"
          />
        </Card>
      )}

      {/* Running order */}
      {(event.schedules ?? []).length > 0 && (
        <Card title="Running order">
          <DataTable
            caption="Schedule for this event"
            columns={[
              { key: "label", header: "Item", cell: (row) => <strong>{row.label}</strong> },
              { key: "type", header: "Type", cell: (row) => statusLabel(row.type) },
              { key: "stage", header: "Stage", cell: (row) => row.stage || "—" },
              { key: "artist_name", header: "Artist", cell: (row) => row.artist_name || "—" },
              { key: "starts_at", header: "Starts", cell: (row) => formatDateTime(row.starts_at) },
              { key: "ends_at", header: "Ends", cell: (row) => formatDateTime(row.ends_at) },
            ]}
            rows={event.schedules ?? []}
            getRowId={(row) => String(row.id)}
            emptyTitle="No schedule yet"
          />
        </Card>
      )}
    </Stack>
  );
}
