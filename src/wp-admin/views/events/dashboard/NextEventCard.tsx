/** Next Event — prominent operational summary for the soonest upcoming event. */
import {
  Card,
  DefinitionList,
  LinkButton,
  ProgressBar,
  StatCard,
  StatusChip,
  Grid,
} from "../../../ui";
import type { NextEventReport } from "../../../api";
import {
  EVENTS_PAGES,
  formatDateTime,
  formatMoney,
  pageUrl,
  statusKind,
  statusLabel,
} from "../shared";

interface Props {
  report: NextEventReport | null | undefined;
  loading?: boolean;
}

export function NextEventCard({ report, loading }: Props) {
  if (loading) {
    return (
      <Card title="Next event">
        <p className="eos-page__description">Loading…</p>
      </Card>
    );
  }

  if (!report) {
    return (
      <Card title="Next event">
        <p className="eos-page__description">
          No upcoming events scheduled. Once an event is published with a future date, it will
          appear here.
        </p>
      </Card>
    );
  }

  const { summary } = report;
  const workspaceUrl = pageUrl(EVENTS_PAGES.list, { event: report.event_id });

  return (
    <Card
      title={report.title}
      description="The soonest upcoming event on the calendar."
      actions={
        <LinkButton href={workspaceUrl} variant="primary" size="sm">
          Open workspace
        </LinkButton>
      }
    >
      <DefinitionList
        items={[
          { term: "Date", value: formatDateTime(report.starts_at) },
          { term: "Venue", value: report.venue_name || "No venue" },
          {
            term: "Status",
            value: (
              <StatusChip status={statusKind(report.status)} label={statusLabel(report.status)} />
            ),
          },
        ]}
      />

      {summary.capacity != null && summary.capacity > 0 ? (
        <ProgressBar
          label="Tickets sold"
          value={summary.tickets_sold}
          max={summary.capacity}
          showValue
        />
      ) : (
        <p className="eos-page__description">
          {summary.tickets_sold.toLocaleString()} tickets sold — no capacity limit set.
        </p>
      )}

      <Grid minColumnWidth={140}>
        <StatCard label="Revenue" value={formatMoney(summary.gross_revenue, report.currency)} />
        <StatCard
          label="Checked in"
          value={summary.checked_in.toLocaleString()}
          hint={
            summary.attendance_rate != null
              ? `${Math.round(summary.attendance_rate)}% of sold`
              : undefined
          }
        />
        <StatCard label="Orders" value={summary.orders.toLocaleString()} />
      </Grid>
    </Card>
  );
}
