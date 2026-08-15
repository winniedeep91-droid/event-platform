/** Revenue Over Time — brand-wide, period-scoped chart for the dashboard. */
import { Card, MiniBarChart } from "../../../ui";
import type { BrandPerformanceSeries } from "../../../api";
import { formatMoney, formatShortDate } from "../shared";

interface Props {
  series: BrandPerformanceSeries | undefined;
  loading?: boolean;
}

export function RevenueChart({ series, loading }: Props) {
  const rows = series?.revenue_by_day ?? [];
  const currency = series?.currency ?? "USD";

  return (
    <Card title="Revenue over time" description="Gross revenue from paid orders, by day.">
      {loading ? (
        <p className="eos-page__description">Loading…</p>
      ) : (
        <MiniBarChart
          data={rows}
          valueKey="revenue"
          labelKey="date"
          tone="primary"
          maxBars={rows.length}
          formatValue={(value) => formatMoney(value, currency)}
          formatLabel={formatShortDate}
        />
      )}
    </Card>
  );
}
