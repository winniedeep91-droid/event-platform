/** Events dashboard: lifecycle metrics, upcoming schedule, drafts and audit trail. */
import { useQuery } from "@tanstack/react-query";
import { eventsApi } from "../../api";
import {
  Alert,
  Badge,
  Button,
  Card,
  DashboardWidget,
  DataTable,
  Grid,
  LinkButton,
  PageLayout,
  StatCard,
  StatusChip,
  Stack,
  Timeline,
  type DataTableColumn,
  type TimelineItem,
} from "../../ui";
import type { EventRecord } from "../../api";
import {
  EVENTS_PAGES,
  errorMessage,
  formatDateTime,
  goTo,
  pageUrl,
  statusKind,
  statusLabel,
  venueLabel,
} from "./shared";

export function EventsDashboardView() {
  const dashboard = useQuery({ queryKey: ["eventos", "events", "dashboard"], queryFn: eventsApi.dashboard });
  const options = useQuery({ queryKey: ["eventos", "events", "options"], queryFn: eventsApi.options });

  const labels = options.data?.statuses;
  const data = dashboard.data;

  const columns: DataTableColumn<EventRecord>[] = [
    {
      key: "title",
      header: "Event",
      cell: (row) => (
        <a href={pageUrl(EVENTS_PAGES.list, { event: row.id })}>
          <strong>{row.title}</strong>
        </a>
      ),
    },
    { key: "starts_at", header: "Starts", cell: (row) => formatDateTime(row.starts_at) },
    { key: "venue", header: "Venue", cell: (row) => venueLabel(row) },
    {
      key: "status",
      header: "Status",
      cell: (row) => <StatusChip status={statusKind(row.status)} label={statusLabel(row.status, labels)} />,
    },
  ];

  const timeline: TimelineItem[] = (data?.activity ?? []).map((entry) => ({
    id: String(entry.id),
    title: `${statusLabel(entry.action)}${entry.user ? ` — ${entry.user.name}` : ""}`,
    timestamp: entry.created_at,
    description: entry.entity_id ? `${entry.entity_type ?? "event"} #${entry.entity_id}` : undefined,
  }));

  return (
    <PageLayout
      title="Events"
      description="Programme health across every event on this installation."
      actions={
        <>
          <LinkButton href={pageUrl(EVENTS_PAGES.calendar)}>Calendar</LinkButton>
          <Button variant="primary" onClick={() => goTo(EVENTS_PAGES.list, { action: "new" })}>
            New event
          </Button>
        </>
      }
    >
      <Stack>
        {dashboard.error ? <Alert tone="danger" title="Could not load the dashboard">{errorMessage(dashboard.error)}</Alert> : null}

        <Grid>
          <StatCard label="Total events" value={data?.total ?? 0} loading={dashboard.isLoading} />
          <StatCard label="Next 30 days" value={data?.next_30_days ?? 0} loading={dashboard.isLoading} />
          <StatCard
            label="Upcoming capacity"
            value={data?.upcoming_capacity ?? 0}
            hint="Seats across published upcoming events"
            loading={dashboard.isLoading}
          />
          <StatCard label="Venues" value={data?.venues ?? 0} loading={dashboard.isLoading} />
          <StatCard label="Artists" value={data?.artists ?? 0} loading={dashboard.isLoading} />
        </Grid>

        <Card title="By status" description="Every lifecycle bucket currently in use.">
          <div className="eos-inline">
            {Object.entries(data?.counts ?? {}).map(([status, count]) => (
              <Badge key={status} tone="neutral">
                {statusLabel(status, labels)}: {count}
              </Badge>
            ))}
            {!dashboard.isLoading && !Object.keys(data?.counts ?? {}).length ? (
              <span className="eos-empty__description">No events created yet.</span>
            ) : null}
          </div>
        </Card>

        <DashboardWidget
          title="Upcoming events"
          description="The next five events on the calendar."
          loading={dashboard.isLoading}
          error={dashboard.error ? errorMessage(dashboard.error) : null}
          isEmpty={!dashboard.isLoading && !(data?.upcoming ?? []).length}
          emptyMessage="Nothing scheduled yet."
          actions={<LinkButton href={pageUrl(EVENTS_PAGES.list)} size="sm">All events</LinkButton>}
        >
          <DataTable
            caption="Upcoming events"
            columns={columns}
            rows={data?.upcoming ?? []}
            getRowId={(row) => String(row.id)}
            emptyTitle="Nothing scheduled"
          />
        </DashboardWidget>

        <DashboardWidget
          title="Drafts in progress"
          description="Events that still need to be published."
          loading={dashboard.isLoading}
          isEmpty={!dashboard.isLoading && !(data?.drafts ?? []).length}
          emptyMessage="No drafts waiting."
        >
          <DataTable
            caption="Draft events"
            columns={columns}
            rows={data?.drafts ?? []}
            getRowId={(row) => String(row.id)}
            emptyTitle="No drafts"
          />
        </DashboardWidget>

        <Card title="Recent activity" description="Audit trail for the Events module.">
          {timeline.length ? <Timeline items={timeline} /> : <p className="eos-empty__description">No activity logged yet.</p>}
        </Card>
      </Stack>
    </PageLayout>
  );
}
