/** Month calendar for the events programme. */
import { useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { eventsApi, type EventRecord } from "../../api";
import {
  Alert,
  Button,
  Card,
  DataTable,
  LoadingState,
  PageLayout,
  Stack,
  StatusChip,
  type DataTableColumn,
} from "../../ui";
import {
  EVENTS_PAGES,
  errorMessage,
  formatDateTime,
  pageUrl,
  statusKind,
  statusLabel,
  venueLabel,
} from "./shared";

function pad(value: number): string {
  return String(value).padStart(2, "0");
}

function monthRange(year: number, month: number): { from: string; to: string } {
  const last = new Date(Date.UTC(year, month + 1, 0)).getUTCDate();

  return {
    from: `${year}-${pad(month + 1)}-01 00:00:00`,
    to: `${year}-${pad(month + 1)}-${pad(last)} 23:59:59`,
  };
}

export function EventsCalendarView() {
  const today = new Date();
  const [cursor, setCursor] = useState({
    year: today.getUTCFullYear(),
    month: today.getUTCMonth(),
  });

  const range = monthRange(cursor.year, cursor.month);

  const calendar = useQuery({
    queryKey: ["eventos", "events", "calendar", range.from, range.to],
    queryFn: () => eventsApi.calendar(range.from, range.to),
  });
  const options = useQuery({
    queryKey: ["eventos", "events", "options"],
    queryFn: eventsApi.options,
  });

  const monthLabel = new Date(Date.UTC(cursor.year, cursor.month, 1)).toLocaleDateString(
    undefined,
    {
      month: "long",
      year: "numeric",
      timeZone: "UTC",
    },
  );

  const days = useMemo(() => {
    const first = new Date(Date.UTC(cursor.year, cursor.month, 1));
    const total = new Date(Date.UTC(cursor.year, cursor.month + 1, 0)).getUTCDate();
    const leading = (first.getUTCDay() + 6) % 7; // Monday-first grid.
    const cells: Array<{ key: string; date: string | null }> = [];

    for (let index = 0; index < leading; index += 1) {
      cells.push({ key: `blank-${index}`, date: null });
    }

    for (let day = 1; day <= total; day += 1) {
      const date = `${cursor.year}-${pad(cursor.month + 1)}-${pad(day)}`;
      cells.push({ key: date, date });
    }

    return cells;
  }, [cursor]);

  const byDate = useMemo(() => {
    const map = new Map<string, typeof events>();
    const events = calendar.data?.events ?? [];

    events.forEach((event) => {
      if (!event.starts_at) return;
      const key = event.starts_at.slice(0, 10);
      map.set(key, [...(map.get(key) ?? []), event]);
    });

    return map;
  }, [calendar.data]);

  const shift = (delta: number) => {
    setCursor((current) => {
      const next = new Date(Date.UTC(current.year, current.month + delta, 1));
      return { year: next.getUTCFullYear(), month: next.getUTCMonth() };
    });
  };

  return (
    <PageLayout
      title="Calendar"
      description="Every event scheduled inside the selected month."
      actions={
        <>
          <Button onClick={() => shift(-1)}>Previous</Button>
          <Button
            onClick={() => setCursor({ year: today.getUTCFullYear(), month: today.getUTCMonth() })}
          >
            Today
          </Button>
          <Button onClick={() => shift(1)}>Next</Button>
        </>
      }
    >
      <Stack>
        {calendar.error ? (
          <Alert
            tone="danger"
            title="Could not load the calendar"
            actions={
              <Button size="sm" onClick={() => void calendar.refetch()}>
                Retry
              </Button>
            }
          >
            {errorMessage(calendar.error)}
          </Alert>
        ) : (
          <>
            <Card title={monthLabel} flush>
              {calendar.isLoading ? (
                <LoadingState label="Loading calendar…" />
              ) : (
                <div
                  style={{
                    display: "grid",
                    gridTemplateColumns: "repeat(7, minmax(0, 1fr))",
                    gap: 1,
                    padding: 1,
                    background: "var(--eos-border)",
                    borderRadius: "var(--eos-radius-lg)",
                    overflow: "hidden",
                  }}
                >
                  {["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"].map((label) => (
                    <div
                      key={label}
                      className="eos-page__description"
                      style={{
                        padding: "var(--eos-space-2) var(--eos-space-3)",
                        fontWeight: 600,
                        background: "var(--eos-surface-muted)",
                      }}
                    >
                      {label}
                    </div>
                  ))}
                  {days.map((cell) => (
                    <div
                      key={cell.key}
                      style={{
                        minHeight: 108,
                        padding: "var(--eos-space-2) var(--eos-space-3)",
                        background: "var(--eos-surface)",
                      }}
                    >
                      {cell.date ? (
                        <Stack style={{ gap: "var(--eos-space-1)" }}>
                          <span className="eos-page__description">
                            {Number(cell.date.slice(8, 10))}
                          </span>
                          <Stack style={{ gap: "var(--eos-space-1)" }}>
                            {(byDate.get(cell.date) ?? []).map((event) => (
                              <a
                                key={event.id}
                                href={pageUrl(EVENTS_PAGES.list, { event: event.id })}
                                title={`${event.title} — ${formatDateTime(event.starts_at)}`}
                              >
                                <StatusChip
                                  status={statusKind(event.status)}
                                  label={`${event.title.slice(0, 22)}${event.title.length > 22 ? "…" : ""}`}
                                />
                              </a>
                            ))}
                          </Stack>
                        </Stack>
                      ) : null}
                    </div>
                  ))}
                </div>
              )}
            </Card>

            <Card title="Scheduled this month" flush>
              <ScheduledThisMonthTable
                events={calendar.data?.events ?? []}
                statuses={options.data?.statuses}
                monthLabel={monthLabel}
              />
            </Card>
          </>
        )}
      </Stack>
    </PageLayout>
  );
}

function ScheduledThisMonthTable({
  events,
  statuses,
  monthLabel,
}: {
  events: EventRecord[];
  statuses?: Record<string, string>;
  monthLabel: string;
}) {
  const columns: DataTableColumn<EventRecord>[] = [
    {
      key: "title",
      header: "Event",
      cell: (row) => <a href={pageUrl(EVENTS_PAGES.list, { event: row.id })}>{row.title}</a>,
    },
    { key: "starts_at", header: "Starts", cell: (row) => formatDateTime(row.starts_at) },
    { key: "venue", header: "Venue", cell: (row) => venueLabel(row) },
    {
      key: "status",
      header: "Status",
      cell: (row) => (
        <StatusChip status={statusKind(row.status)} label={statusLabel(row.status, statuses)} />
      ),
    },
  ];

  return (
    <DataTable
      caption="Events scheduled this month"
      columns={columns}
      rows={events}
      getRowId={(row) => String(row.id)}
      emptyTitle="Nothing scheduled"
      emptyDescription={`No events are scheduled in ${monthLabel}.`}
    />
  );
}
