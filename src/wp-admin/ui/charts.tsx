/**
 * Dependency-free chart primitives. EventOS deliberately does not ship a
 * charting library — see ReportsTab.tsx's original MiniBarChart, which this
 * module promotes out of that file so both the Event Workspace's Reports
 * tab and the dashboard can share one implementation.
 */
import type { CSSProperties } from "react";

export type ChartTone = "primary" | "secondary" | "info";

const TONE_VAR: Record<ChartTone, string> = {
  primary: "var(--eos-primary)",
  secondary: "var(--eos-secondary)",
  info: "var(--eos-info)",
};

export interface MiniBarChartProps {
  data: Record<string, unknown>[];
  valueKey: string;
  labelKey: string;
  /** Bar colour, drawn from the shared design tokens — never a literal hex. */
  tone?: ChartTone;
  /** Formats the tooltip value; defaults to the raw stored value. */
  formatValue?: (value: number) => string;
  /** Formats the tooltip label; defaults to the raw stored label. */
  formatLabel?: (label: string) => string;
  height?: number;
  /**
   * Most recent N points to render. Defaults to 30, matching the original
   * Reports tab behaviour exactly. Dashboard charts pass the full series
   * length so a selected period is never silently truncated.
   */
  maxBars?: number;
  style?: CSSProperties;
}

export function MiniBarChart({
  data,
  valueKey,
  labelKey,
  tone = "primary",
  formatValue,
  formatLabel,
  height = 80,
  maxBars = 30,
  style,
}: MiniBarChartProps) {
  if (!data.length) {
    return <p className="eos-page__description">No data available.</p>;
  }

  const visible = maxBars > 0 ? data.slice(-maxBars) : data;
  const values = visible.map((d) => Number(d[valueKey]) || 0);
  const peak = Math.max(...values, 1);
  const color = TONE_VAR[tone];

  return (
    <div style={{ display: "flex", alignItems: "flex-end", gap: 4, height, ...style }}>
      {visible.map((d, i) => {
        const val = Number(d[valueKey]) || 0;
        const heightPct = Math.max(4, (val / peak) * 100);
        const rawLabel = String(d[labelKey]);
        const rawValue = String(d[valueKey]);
        const label = formatLabel ? formatLabel(rawLabel) : rawLabel;
        const value = formatValue ? formatValue(val) : rawValue;

        return (
          <div
            key={i}
            title={`${label}: ${value}`}
            style={{
              flex: 1,
              height: `${heightPct}%`,
              background: color,
              borderRadius: "2px 2px 0 0",
              minWidth: 3,
              opacity: 0.5 + (i / visible.length) * 0.5,
            }}
          />
        );
      })}
    </div>
  );
}
