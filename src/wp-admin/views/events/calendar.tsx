/** Month calendar for the events programme. */
import { useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { eventsApi } from "../../api";
import { Alert, Button, Card, LoadingState, PageLayout, Stack, StatusChip } from "../../ui";
import {
  EVENTS_PAGES,
  errorMessage,
  formatDateTime,
  pageUrl,
  statusKind,
  statusLabel,
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
  const [cursor, setCursor] = useState({ year: today.getUTCFullYear(), month: today.getUTCMonth() });

  const range = monthRange(cursor.year, cursor.month);

  const calendar = useQuery({
    queryKey: ["eventos", "events", "calendar", range.from, range.to],
    queryFn: () => eventsApi.calendar(range.from, range.to),
  });
  const options = useQuery({ queryKey: ["eventos", "events", "options"], queryFn: eventsApi.options });

  const monthLabel = new Date(Date.UTC(cursor.year, cursor.month, 1)).toLocaleDateString(undefined, {
    month: "long",
    year: "numeric",
    timeZone: "UTC",
  });

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
          <Button onClick={() => setCursor({ year: today.getUTCFullYear(), month: today.getUTCMonth() })}>
            Today
          </Button>
          <Button onClick={() => shift(1)}>Next</Button>
        </>
      }
    >
      <Stack>
        {calendar.error ? (
          <Alert tone="danger" title="Could not load the calendar">{errorMessage(calendar.error)}</Alert>
        ) : null}

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
              }}
            >
              {["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"].map((label) => (
                <div key={label} className="eos-page__description" style={{ padding: "8px 10px", fontWeight: 600 }}>
                  {label}
                </div>
              ))}
              {days.map((cell) => (
                <div
                  key={cell.key}
                  style={{
                    minHeight: 108,
                    padding: "8px 10px",
                    border: "1px solid var(--eos-border, rgba(0,0,0,0.08))",
                    borderRadius: 6,
                  }}
                >
                  {cell.date ? (
                    <>
                      <div className="eos-page__description">{Number(cell.date.slice(8, 10))}</div>
                      <div className="eos-stack" style={{ gap: 4, marginTop: 4 }}>
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
                      </div>
                    </>
                  ) : null}
                </div>
              ))}
            </div>
          )}
        </Card>

        <Card title="Scheduled this month">
          {(calendar.data?.events ?? []).length ? (
            <ul className="eos-stack" style={{ listStyle: "none", margin: 0, padding: 0 }}>
              {(calendar.data?.events ?? []).map((event) => (
                <li key={event.id} className="eos-inline" style={{ justifyContent: "space-between" }}>
                  <a href={pageUrl(EVENTS_PAGES.list, { event: event.id })}>{event.title}</a>
                  <span className="eos-page__description">
                    {formatDateTime(event.starts_at)} · {statusLabel(event.status, options.data?.statuses)}
                  </span>
                </li>
              ))}
            </ul>
          ) : (
            <p className="eos-empty__description">Nothing scheduled in {monthLabel}.</p>
          )}
        </Card>
      </Stack>
    </PageLayout>
  );
}
