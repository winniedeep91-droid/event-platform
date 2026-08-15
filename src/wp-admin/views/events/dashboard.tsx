/**
 * My Events dashboard: a commercial event-performance overview — revenue,
 * ticket sales and attendance across the brand — rather than a lifecycle
 * summary. See dashboard/ for the chart, next-event and table components
 * this composes.
 */
import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import type { DashboardPeriod } from "../../api";
import { eventsApi } from "../../api";
import {
  Alert,
  Button,
  Grid,
  LinkButton,
  PageLayout,
  SegmentedControl,
  StatCard,
  Stack,
} from "../../ui";
import { EVENTS_PAGES, errorMessage, formatMoney, goTo, pageUrl } from "./shared";
import { RevenueChart } from "./dashboard/RevenueChart";
import { TicketsChart } from "./dashboard/TicketsChart";
import { NextEventCard } from "./dashboard/NextEventCard";
import { MyEventsTable } from "./dashboard/MyEventsTable";

const PERIOD_OPTIONS: Array<{ value: DashboardPeriod; label: string }> = [
  { value: "7d", label: "7D" },
  { value: "30d", label: "30D" },
  { value: "90d", label: "90D" },
  { value: "year", label: "Year" },
];

export function EventsDashboardView() {
  const [period, setPeriod] = useState<DashboardPeriod>("30d");

  const dashboard = useQuery({
    queryKey: ["eventos", "events", "dashboard", period],
    queryFn: () => eventsApi.dashboard(period),
    placeholderData: (prev) => prev,
  });

  const data = dashboard.data;
  const brand = data?.brand;
  const currency = brand?.currency ?? "USD";

  return (
    <PageLayout
      title="My Events"
      description="Manage your events, monitor performance and jump into your next show."
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
        {dashboard.error ? (
          <Alert tone="danger" title="Could not load the dashboard">
            {errorMessage(dashboard.error)}
          </Alert>
        ) : null}

        <Grid minColumnWidth={180}>
          <StatCard
            label="Total Revenue"
            value={brand ? formatMoney(brand.total_revenue, currency) : "—"}
            loading={dashboard.isLoading}
          />
          <StatCard
            label="Tickets Sold"
            value={brand?.tickets_sold.toLocaleString() ?? "—"}
            loading={dashboard.isLoading}
          />
          <StatCard
            label="Attendance"
            value={brand?.attendance.toLocaleString() ?? "—"}
            hint="Checked in"
            loading={dashboard.isLoading}
          />
          <StatCard
            label="Orders"
            value={brand?.orders.toLocaleString() ?? "—"}
            loading={dashboard.isLoading}
          />
        </Grid>

        <SegmentedControl
          label="Chart period"
          value={period}
          onChange={setPeriod}
          options={PERIOD_OPTIONS}
        />

        <RevenueChart series={data?.brand_series} loading={dashboard.isLoading} />
        <TicketsChart series={data?.brand_series} loading={dashboard.isLoading} />

        <NextEventCard report={data?.next_event} loading={dashboard.isLoading} />

        <MyEventsTable
          events={data?.my_events ?? []}
          currency={currency}
          loading={dashboard.isLoading}
        />
      </Stack>
    </PageLayout>
  );
}
