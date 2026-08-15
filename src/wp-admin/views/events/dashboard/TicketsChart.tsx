/** Tickets Sold Over Time — brand-wide, period-scoped chart for the dashboard. */
import { Card, MiniBarChart } from "../../../ui";
import type { BrandPerformanceSeries } from "../../../api";
import { formatShortDate } from "../shared";

interface Props {
  series: BrandPerformanceSeries | undefined;
  loading?: boolean;
}

export function TicketsChart({ series, loading }: Props) {
  const rows = series?.tickets_by_day ?? [];

  return (
    <Card title="Tickets sold over time" description="Tickets issued across every event, by day.">
      {loading ? (
        <p className="eos-page__description">Loading…</p>
      ) : (
        <MiniBarChart
          data={rows}
          valueKey="tickets"
          labelKey="date"
          tone="info"
          maxBars={rows.length}
          formatLabel={formatShortDate}
        />
      )}
    </Card>
  );
}
