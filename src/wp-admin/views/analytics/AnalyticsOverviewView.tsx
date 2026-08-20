/**
 * Organisation-wide event comparison — the one view neither the Dashboard's
 * My Events table (no profit/margin, not sortable) nor Finance's
 * organisation summary (a single aggregate, not broken out per event)
 * already provides. See Event_Comparison_Builder's docblock for exactly
 * what's reused (Brand_Report_Builder, Finance_Report_Builder) versus new.
 */
import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import {
  Alert,
  Badge,
  Button,
  Card,
  DataTable,
  FilterBar,
  Grid,
  LinkButton,
  LoadingState,
  MiniBarChart,
  PageLayout,
  Pagination,
  Stack,
  StatCard,
  type DataTableColumn,
  type FilterDefinition,
  type SortDirection,
} from "../../ui";
import { analyticsApi, crmApi, type EventComparisonRow } from "../../api";
import { EVENTS_PAGES, errorMessage, formatDate, formatMoney, pageUrl } from "../events/shared";

const STATUS_FILTER: FilterDefinition = {
  key: "status",
  label: "Status",
  options: [
    { value: "draft", label: "Draft" },
    { value: "published", label: "Published" },
    { value: "postponed", label: "Postponed" },
    { value: "cancelled", label: "Cancelled" },
    { value: "archived", label: "Archived" },
  ],
};

const PER_PAGE = 25;

export function AnalyticsOverviewView() {
  const [search, setSearch] = useState("");
  const [filterValues, setFilterValues] = useState<Record<string, string>>({});
  const [sort, setSort] = useState<{ key: string; direction: SortDirection }>({
    key: "starts_at",
    direction: "desc",
  });
  const [page, setPage] = useState(1);

  const status = filterValues["status"] ?? "";

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ["eventos", "analytics", "comparison", { search, status, sort, page }],
    queryFn: () =>
      analyticsApi.eventComparison({
        search,
        status,
        orderby: sort.key,
        order: sort.direction,
        page,
        per_page: PER_PAGE,
      }),
    placeholderData: (prev) => prev,
  });

  const crmInsights = useQuery({
    queryKey: ["crm", "insights"],
    queryFn: () => crmApi.insights(),
    retry: false,
  });

  const rows = data?.items ?? [];
  const financialsIncluded = data?.financialsIncluded ?? false;
  const totalPages = data?.totalPages ?? 1;

  const totals = rows.reduce(
    (acc, row) => ({
      revenue: acc.revenue + row.revenue,
      ticketsSold: acc.ticketsSold + row.tickets_sold,
      netProfit: acc.netProfit + (row.net_profit ?? 0),
      newCustomers: acc.newCustomers + row.new_customers,
      returningCustomers: acc.returningCustomers + row.returning_customers,
    }),
    { revenue: 0, ticketsSold: 0, netProfit: 0, newCustomers: 0, returningCustomers: 0 },
  );

  const currency = rows[0]?.currency ?? "";

  const columns: DataTableColumn<EventComparisonRow>[] = [
    {
      key: "title",
      header: "Event",
      cell: (row) => (
        <Stack>
          <LinkButton href={pageUrl(EVENTS_PAGES.list, { event: row.event_id })} variant="link">
            <strong>{row.title}</strong>
          </LinkButton>
          <span className="eos-page__description">{formatDate(row.starts_at)}</span>
        </Stack>
      ),
    },
    {
      key: "status",
      header: "Status",
      cell: (row) => <Badge tone="neutral">{row.status}</Badge>,
    },
    {
      key: "tickets_sold",
      header: "Tickets sold",
      sortable: true,
      cell: (row) => row.tickets_sold.toLocaleString(),
    },
    {
      key: "sell_through",
      header: "Sell-through",
      cell: (row) =>
        row.sell_through != null ? `${Math.round(row.sell_through)}%` : "No capacity limit",
    },
    {
      key: "checked_in",
      header: "Checked in",
      cell: (row) => row.checked_in.toLocaleString(),
    },
    { key: "orders", header: "Orders", sortable: true, cell: (row) => row.orders.toLocaleString() },
    {
      key: "average_order_value",
      header: "Avg. order",
      cell: (row) => formatMoney(row.average_order_value, row.currency ?? currency),
    },
    {
      key: "revenue",
      header: "Revenue",
      sortable: true,
      cell: (row) => formatMoney(row.revenue, row.currency ?? currency),
    },
    ...(financialsIncluded
      ? ([
          {
            key: "total_expenses",
            header: "Expenses",
            cell: (row) => formatMoney(row.total_expenses ?? 0, row.currency ?? currency),
          },
          {
            key: "net_profit",
            header: "Net profit",
            sortable: true,
            cell: (row) => formatMoney(row.net_profit ?? 0, row.currency ?? currency),
          },
          {
            key: "profit_margin",
            header: "Margin",
            cell: (row) => (row.profit_margin != null ? `${row.profit_margin.toFixed(1)}%` : "—"),
          },
        ] as DataTableColumn<EventComparisonRow>[])
      : []),
    {
      key: "new_customers",
      header: "New customers",
      cell: (row) => row.new_customers.toLocaleString(),
    },
    {
      key: "returning_customers",
      header: "Returning",
      cell: (row) => row.returning_customers.toLocaleString(),
    },
  ];

  const chartRows = [...rows]
    .sort((a, b) => b.revenue - a.revenue)
    .slice(0, 12)
    .map((row) => ({ label: row.title, revenue: row.revenue }));

  return (
    <PageLayout
      title="Analytics"
      description="Compare performance across events — ticket sales, attendance, audience and (where you have finance access) profit, side by side."
    >
      <Stack>
        {!financialsIncluded && !isLoading && (
          <Alert tone="info" title="Financial columns hidden">
            Expenses, net profit and margin require finance access and aren't shown for your
            account. Everything else on this page is unaffected.
          </Alert>
        )}

        <Grid minColumnWidth={170}>
          <StatCard
            label="Tickets sold"
            value={totals.ticketsSold.toLocaleString()}
            hint="across events shown"
          />
          <StatCard
            label="Revenue"
            value={formatMoney(totals.revenue, currency)}
            hint="across events shown"
          />
          {financialsIncluded && (
            <StatCard
              label="Net profit"
              value={formatMoney(totals.netProfit, currency)}
              hint="across events shown"
              trend={{
                direction: totals.netProfit >= 0 ? "up" : "down",
                label: totals.netProfit >= 0 ? "Profitable" : "Loss",
              }}
            />
          )}
          <StatCard
            label="New customers"
            value={totals.newCustomers.toLocaleString()}
            hint="across events shown"
          />
          <StatCard
            label="Returning customers"
            value={totals.returningCustomers.toLocaleString()}
            hint={
              crmInsights.data ? crmInsights.data.repeat_customer_definition : "across events shown"
            }
          />
        </Grid>

        {chartRows.length > 0 && (
          <Card title="Revenue by event" description="Top events shown, highest revenue first.">
            <MiniBarChart
              data={chartRows as unknown as Record<string, unknown>[]}
              valueKey="revenue"
              labelKey="label"
              formatValue={(value) => formatMoney(value, currency)}
            />
          </Card>
        )}

        <Card
          title={`Events${data ? ` (${data.total.toLocaleString()})` : ""}`}
          actions={
            financialsIncluded ? (
              <a
                href={analyticsApi.exportComparison("csv")}
                download
                className="eos-btn eos-btn--secondary eos-btn--sm"
              >
                Export CSV
              </a>
            ) : undefined
          }
        >
          <Stack>
            <FilterBar
              search={{ value: search, onChange: setSearch, placeholder: "Search events…" }}
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
              <LoadingState label="Loading event comparison…" />
            ) : error ? (
              <Alert
                tone="danger"
                title="Could not load event comparison"
                actions={
                  <Button size="sm" onClick={() => void refetch()}>
                    Retry
                  </Button>
                }
              >
                {errorMessage(error)}
              </Alert>
            ) : (
              <>
                <DataTable
                  caption="Event performance comparison"
                  columns={columns}
                  rows={rows}
                  getRowId={(row) => String(row.event_id)}
                  emptyTitle="No events found"
                  emptyDescription={
                    search || status
                      ? "Try adjusting your filters."
                      : "Events you create will appear here once scheduled."
                  }
                  sort={sort}
                  onSortChange={(next) => {
                    setSort(next);
                    setPage(1);
                  }}
                />
                {totalPages > 1 && (
                  <Pagination
                    page={page}
                    totalPages={totalPages}
                    total={data?.total}
                    onPageChange={setPage}
                  />
                )}
              </>
            )}
          </Stack>
        </Card>
      </Stack>
    </PageLayout>
  );
}
