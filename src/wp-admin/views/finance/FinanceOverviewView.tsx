import { useQuery } from "@tanstack/react-query";
import {
  Alert,
  Badge,
  Button,
  Card,
  Grid,
  LoadingState,
  PageLayout,
  Stack,
  StatCard,
} from "../../ui";
import { financeApi } from "../../api";
import { errorMessage } from "../events/shared";

function fmt(amount: number, cur: string) {
  return new Intl.NumberFormat(undefined, {
    style: "currency",
    currency: cur || "USD",
    maximumFractionDigits: 2,
  }).format(amount);
}

/**
 * Organisation-wide Profit & Loss: the roll-up across every event's
 * Finance tab (see FinanceTab for the per-event breakdown and expense
 * management this summarises). Kept intentionally light — a promoter
 * managing a handful of events today, and the future multi-event
 * dashboard a SaaS org needs, both start from the same
 * Finance_Report_Builder::org_summary() totals; this screen just renders
 * them.
 */
export function FinanceOverviewView() {
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ["eventos", "finance", "org-summary"],
    queryFn: () => financeApi.orgSummary(),
    retry: false,
  });

  return (
    <PageLayout
      title="Finance"
      description="Profit & loss across every event. Open an event's Finance tab to manage its expenses."
    >
      <Stack>
        {isLoading ? (
          <LoadingState label="Loading organisation P&amp;L…" />
        ) : error ? (
          <Alert
            tone="danger"
            title="Could not load finance summary"
            actions={
              <Button size="sm" onClick={() => void refetch()}>
                Retry
              </Button>
            }
          >
            {errorMessage(error)}
          </Alert>
        ) : data ? (
          <>
            <Grid minColumnWidth={170}>
              <StatCard
                label="Gross revenue"
                value={fmt(data.result.gross_revenue, data.currency)}
              />
              <StatCard label="Net revenue" value={fmt(data.result.net_revenue, data.currency)} />
              <StatCard label="Total fees" value={fmt(data.result.total_fees, data.currency)} />
              <StatCard
                label="Total expenses"
                value={fmt(data.result.total_expenses, data.currency)}
              />
              <StatCard
                label={data.result.net_profit >= 0 ? "Net profit" : "Net loss"}
                value={fmt(data.result.net_profit, data.currency)}
                trend={{
                  direction: data.result.net_profit >= 0 ? "up" : "down",
                  label: data.result.net_profit >= 0 ? "Profitable" : "Loss",
                }}
              />
              <StatCard
                label="Profit margin"
                value={
                  data.result.profit_margin != null
                    ? `${data.result.profit_margin.toFixed(1)}%`
                    : "—"
                }
              />
            </Grid>

            <Card
              title="Fees"
              actions={
                <Badge tone={data.fees.fee_status === "recorded" ? "neutral" : "warning"}>
                  {data.fees.fee_status === "recorded" ? "Recorded" : "No fee data"}
                </Badge>
              }
            >
              <Stack>
                <div className="eos-inline" style={{ justifyContent: "space-between" }}>
                  <span>Payment / processing fees</span>
                  <span>{fmt(data.fees.payment_fees, data.currency)}</span>
                </div>
                <div className="eos-inline" style={{ justifyContent: "space-between" }}>
                  <span>Discounts</span>
                  <span>{fmt(data.adjustments.discounts, data.currency)}</span>
                </div>
                <div className="eos-inline" style={{ justifyContent: "space-between" }}>
                  <span>Refunds</span>
                  <span>{fmt(data.adjustments.refunds, data.currency)}</span>
                </div>
              </Stack>
            </Card>

            <Alert tone="info" title="Per-event detail">
              Expense management, category breakdowns and the full P&amp;L statement for a single
              event live on that event's Finance tab in the Event Workspace.
            </Alert>
          </>
        ) : null}
      </Stack>
    </PageLayout>
  );
}
