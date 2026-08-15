/** My Events — event performance table, the dashboard's event-list surface. */
import { Card, DataTable, LinkButton, StatusChip, type DataTableColumn } from "../../../ui";
import type { DashboardEventSummary } from "../../../api";
import {
  EVENTS_PAGES,
  formatDateTime,
  formatMoney,
  pageUrl,
  statusKind,
  statusLabel,
  venueLabel,
} from "../shared";

interface Props {
  events: DashboardEventSummary[];
  currency: string;
  loading?: boolean;
}

export function MyEventsTable({ events, currency, loading }: Props) {
  const columns: DataTableColumn<DashboardEventSummary>[] = [
    {
      key: "title",
      header: "Event",
      cell: (row) => (
        <LinkButton href={pageUrl(EVENTS_PAGES.list, { event: row.id })} variant="link">
          <strong>{row.title}</strong>
        </LinkButton>
      ),
    },
    { key: "starts_at", header: "Date", cell: (row) => formatDateTime(row.starts_at) },
    { key: "venue_name", header: "Venue", cell: (row) => venueLabel(row) },
    {
      key: "tickets_sold",
      header: "Tickets sold",
      cell: (row) => row.tickets_sold.toLocaleString(),
    },
    { key: "revenue", header: "Revenue", cell: (row) => formatMoney(row.revenue, currency) },
    { key: "checked_in", header: "Attendance", cell: (row) => row.checked_in.toLocaleString() },
    {
      key: "status",
      header: "Status",
      cell: (row) => <StatusChip status={statusKind(row.status)} label={statusLabel(row.status)} />,
    },
  ];

  return (
    <Card title="My Events" description="Your events, most imminent first.">
      <DataTable
        caption="Event performance"
        columns={columns}
        rows={events}
        getRowId={(row) => String(row.id)}
        loading={loading}
        emptyTitle="No events yet"
        emptyDescription="Events you create will appear here once scheduled."
      />
    </Card>
  );
}
