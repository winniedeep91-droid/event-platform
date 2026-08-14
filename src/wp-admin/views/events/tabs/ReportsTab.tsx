import { useQuery } from "@tanstack/react-query";
import {
  Alert,
  Badge,
  Card,
  DataTable,
  Grid,
  LoadingState,
  Stack,
  StatCard,
  type DataTableColumn,
} from "../../../ui";
import { eventsApi, type EventReportPayload, type TicketTier } from "../../../api";
import { errorMessage, formatDateTime } from "../shared";

interface Props {
  eventId: number;
}

function fmt(amount: number, cur: string) {
  return new Intl.NumberFormat(undefined, {
    style: "currency",
    currency: cur || "USD",
    maximumFractionDigits: 0,
  }).format(amount);
}

function MiniBarChart({
  data,
  valueKey,
  labelKey,
}: {
  data: Record<string, unknown>[];
  valueKey: string;
  labelKey: string;
}) {
  if (!data.length) {
    return <p className="eos-page__description">No data available.</p>;
  }
  const values = data.map((d) => Number(d[valueKey]) || 0);
  const peak = Math.max(...values, 1);

  return (
    <div style={{ display: "flex", alignItems: "flex-end", gap: 4, height: 80 }}>
      {data.slice(-30).map((d, i) => {
        const val = Number(d[valueKey]) || 0;
        const heightPct = Math.max(4, (val / peak) * 100);
        return (
          <div
            key={i}
            title={`${String(d[labelKey])}: ${String(d[valueKey])}`}
            style={{
              flex: 1,
              height: `${heightPct}%`,
              background: "var(--eos-primary)",
              borderRadius: "2px 2px 0 0",
              minWidth: 4,
              opacity: 0.5 + (i / data.length) * 0.5,
            }}
          />
        );
      })}
    </div>
  );
}

const TIER_TONE: Record<TicketTier, "neutral" | "primary" | "success" | "warning" | "info"> = {
  standard: "neutral",
  early_bird: "info",
  vip: "warning",
  table: "primary",
  backstage: "warning",
  complimentary: "success",
  custom: "neutral",
};

export function ReportsTab({ eventId }: Props) {
  const { data, isLoading, error } = useQuery({
    queryKey: ["eventos", "events", "report", eventId],
    queryFn: () => eventsApi.eventReport(eventId),
    retry: false,
  });

  if (isLoading) return <LoadingState label="Loading report…" />;

  if (error) {
    return (
      <Alert tone="warning" title="Report unavailable">
        Reports will populate once ticket sales begin. {errorMessage(error)}
      </Alert>
    );
  }

  if (!data) return null;

  const {
    currency: cur,
    summary,
    revenue_by_day,
    revenue_by_ticket_type,
    sales_velocity,
    top_customers,
    refund_breakdown,
  } = data;

  const capacityPct =
    summary.capacity && summary.capacity > 0
      ? Math.round((summary.tickets_sold / summary.capacity) * 100)
      : null;

  const checkinPct =
    summary.tickets_sold > 0 ? Math.round((summary.checked_in / summary.tickets_sold) * 100) : null;

  type TicketTypeRow = EventReportPayload["revenue_by_ticket_type"][0];
  type CustomerRow = EventReportPayload["top_customers"][0];
  type RefundRow = EventReportPayload["refund_breakdown"][0];
  type DayRow = EventReportPayload["revenue_by_day"][0];

  const ticketTypeCols: DataTableColumn<TicketTypeRow>[] = [
    {
      key: "name",
      header: "Ticket type",
      cell: (row) => (
        <div className="eos-inline">
          <strong>{row.name}</strong>
          <Badge tone={TIER_TONE[row.tier]}>{row.tier.replace("_", " ")}</Badge>
        </div>
      ),
    },
    { key: "sold", header: "Sold", cell: (row) => row.sold.toLocaleString() },
    {
      key: "capacity",
      header: "Capacity",
      cell: (row) =>
        row.capacity != null ? (
          <span>
            {row.capacity.toLocaleString()}
            {row.capacity > 0 && (
              <span className="eos-page__description">
                {" "}
                ({Math.round((row.sold / row.capacity) * 100)}%)
              </span>
            )}
          </span>
        ) : (
          "Unlimited"
        ),
    },
    { key: "gross", header: "Gross revenue", cell: (row) => fmt(row.gross, cur) },
    { key: "net", header: "Net revenue", cell: (row) => fmt(row.net, cur) },
  ];

  const customerCols: DataTableColumn<CustomerRow>[] = [
    {
      key: "name",
      header: "Customer",
      cell: (row) => (
        <Stack>
          <strong>{row.name}</strong>
          <span className="eos-page__description">{row.email}</span>
        </Stack>
      ),
    },
    { key: "orders", header: "Orders", cell: (row) => row.orders },
    { key: "spend", header: "Total spend", cell: (row) => fmt(row.spend, cur) },
  ];

  const refundCols: DataTableColumn<RefundRow>[] = [
    { key: "date", header: "Date", cell: (row) => formatDateTime(row.date) },
    { key: "count", header: "Refunds", cell: (row) => row.count },
    { key: "amount", header: "Amount", cell: (row) => fmt(row.amount, cur) },
  ];

  const dayCols: DataTableColumn<DayRow>[] = [
    { key: "date", header: "Date", cell: (row) => formatDateTime(row.date) },
    { key: "orders", header: "Orders", cell: (row) => row.orders },
    { key: "gross", header: "Gross", cell: (row) => fmt(row.gross, cur) },
    { key: "net", header: "Net", cell: (row) => fmt(row.net, cur) },
  ];

  return (
    <Stack>
      {/* Export */}
      <div className="eos-inline" style={{ justifyContent: "flex-end" }}>
        <a
          href={eventsApi.exportReport(eventId, "csv")}
          download
          className="eos-btn eos-btn--secondary eos-btn--md"
        >
          Export CSV
        </a>
        <a
          href={eventsApi.exportReport(eventId, "pdf")}
          download
          className="eos-btn eos-btn--secondary eos-btn--md"
        >
          Export PDF
        </a>
      </div>

      {/* KPI summary */}
      <Grid minColumnWidth={160}>
        <StatCard
          label="Gross revenue"
          value={fmt(summary.gross_revenue, cur)}
          hint={`${summary.orders} order${summary.orders !== 1 ? "s" : ""}`}
        />
        <StatCard
          label="Net revenue"
          value={fmt(summary.net_revenue, cur)}
          hint={summary.refunds > 0 ? `${fmt(summary.refunds, cur)} refunded` : "No refunds"}
        />
        <StatCard label="Avg. order value" value={fmt(summary.average_order_value, cur)} />
        <StatCard
          label="Tickets sold"
          value={summary.tickets_sold.toLocaleString()}
          hint={
            summary.tickets_available != null
              ? `${summary.tickets_available} remaining`
              : "No limit set"
          }
        />
        {capacityPct != null && (
          <StatCard
            label="Capacity"
            value={`${capacityPct}%`}
            hint={`${summary.tickets_sold} / ${summary.capacity ?? 0}`}
          />
        )}
        {summary.attendance_rate != null && (
          <StatCard
            label="Attendance rate"
            value={`${Math.round(summary.attendance_rate)}%`}
            hint="Sold → checked in"
          />
        )}
        <StatCard
          label="Checked in"
          value={summary.checked_in.toLocaleString()}
          hint={checkinPct != null ? `${checkinPct}% of sold` : undefined}
        />
        <StatCard
          label="Complimentary"
          value={summary.complimentary.toLocaleString()}
          hint="Issued free"
        />
      </Grid>

      {/* Revenue over time */}
      {revenue_by_day.length > 0 && (
        <Card title="Revenue over time">
          <Stack>
            <MiniBarChart
              data={revenue_by_day as Record<string, unknown>[]}
              valueKey="gross"
              labelKey="date"
            />
            <DataTable
              caption="Revenue by day"
              columns={dayCols}
              rows={revenue_by_day}
              getRowId={(row) => row.date}
              emptyTitle="No revenue data"
            />
          </Stack>
        </Card>
      )}

      {/* Sales velocity */}
      {sales_velocity.length > 0 && (
        <Card title="Ticket sales velocity">
          <Stack>
            <MiniBarChart
              data={sales_velocity as Record<string, unknown>[]}
              valueKey="tickets"
              labelKey="date"
            />
            <p className="eos-page__description">
              Daily ticket sales volume over the on-sale period.
            </p>
          </Stack>
        </Card>
      )}

      {/* Revenue by ticket type */}
      {revenue_by_ticket_type.length > 0 && (
        <Card title="Revenue by ticket type">
          <DataTable
            caption="Revenue breakdown by ticket type"
            columns={ticketTypeCols}
            rows={revenue_by_ticket_type}
            getRowId={(row) => String(row.ticket_type_id)}
            emptyTitle="No ticket types"
          />
        </Card>
      )}

      {/* Top customers */}
      {top_customers.length > 0 && (
        <Card title="Top customers">
          <DataTable
            caption="Highest spending customers for this event"
            columns={customerCols}
            rows={top_customers}
            getRowId={(row) => String(row.customer_id)}
            emptyTitle="No customers yet"
          />
        </Card>
      )}

      {/* Refund breakdown */}
      {refund_breakdown.length > 0 && (
        <Card title="Refund breakdown">
          <DataTable
            caption="Refunds issued for this event"
            columns={refundCols}
            rows={refund_breakdown}
            getRowId={(row) => row.date}
            emptyTitle="No refunds"
          />
        </Card>
      )}

      {revenue_by_day.length === 0 &&
        revenue_by_ticket_type.length === 0 &&
        top_customers.length === 0 && (
          <Alert tone="info" title="No report data yet">
            Revenue charts, ticket breakdowns and customer reports will appear here once ticket
            sales begin.
          </Alert>
        )}
    </Stack>
  );
}
