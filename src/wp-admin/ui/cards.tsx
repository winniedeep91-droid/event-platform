import type { ReactNode } from "react";
import { cx, type StyleProps, type Tone } from "./utils";

export interface CardProps extends StyleProps {
  title?: ReactNode;
  description?: ReactNode;
  actions?: ReactNode;
  footer?: ReactNode;
  flush?: boolean;
  children?: ReactNode;
}

/** Base surface for every panel in the admin. */
export function Card({
  title,
  description,
  actions,
  footer,
  flush = false,
  children,
  className,
  style,
}: CardProps) {
  return (
    <section className={cx("eos-card", flush && "eos-card--flush", className)} style={style}>
      {title || actions ? (
        <header className="eos-card__header">
          <div style={{ minWidth: 0 }}>
            {title ? <h3 className="eos-card__title">{title}</h3> : null}
            {description ? <p className="eos-card__description">{description}</p> : null}
          </div>
          {actions ? <div className="eos-inline">{actions}</div> : null}
        </header>
      ) : null}
      {children ? <div className="eos-card__body">{children}</div> : null}
      {footer ? <div className="eos-card__footer">{footer}</div> : null}
    </section>
  );
}

export type TrendDirection = "up" | "down" | "flat";

export interface StatCardProps extends StyleProps {
  label: ReactNode;
  value: ReactNode;
  hint?: ReactNode;
  icon?: ReactNode;
  trend?: { direction: TrendDirection; label: string };
  loading?: boolean;
}

const TREND_GLYPH: Record<TrendDirection, string> = { up: "▲", down: "▼", flat: "→" };

/** Compact metric tile. */
export function StatCard({ label, value, hint, icon, trend, loading = false, className, style }: StatCardProps) {
  return (
    <section className={cx("eos-card", className)} style={style}>
      <div className="eos-card__body eos-stat">
        <div className="eos-stat__row" style={{ justifyContent: "space-between", width: "100%" }}>
          <p className="eos-stat__label">{label}</p>
          {icon ? (
            <span className="eos-stat__icon" aria-hidden="true">
              {icon}
            </span>
          ) : null}
        </div>
        <div className="eos-stat__row">
          {loading ? (
            <span className="eos-skeleton" style={{ width: 96, height: 28 }} aria-hidden="true" />
          ) : (
            <span className="eos-stat__value">{value}</span>
          )}
          {trend && !loading ? (
            <span className={cx("eos-trend", `eos-trend--${trend.direction}`)}>
              <span aria-hidden="true">{TREND_GLYPH[trend.direction]}</span>
              {trend.label}
            </span>
          ) : null}
        </div>
        {hint ? <p className="eos-stat__hint">{hint}</p> : null}
      </div>
    </section>
  );
}

export interface KpiWidgetProps extends StyleProps {
  label: ReactNode;
  value: ReactNode;
  target?: number;
  current?: number;
  tone?: Tone;
  hint?: ReactNode;
}

/** KPI tile with an optional progress-to-target indicator. */
export function KpiWidget({ label, value, target, current, tone = "primary", hint, className, style }: KpiWidgetProps) {
  const percent =
    typeof target === "number" && target > 0 && typeof current === "number"
      ? Math.max(0, Math.min(100, Math.round((current / target) * 100)))
      : null;

  return (
    <section className={cx("eos-card", className)} style={style}>
      <div className="eos-card__body eos-stat">
        <p className="eos-stat__label">{label}</p>
        <span className="eos-stat__value">{value}</span>
        {percent !== null ? (
          <div className={cx("eos-progress", tone !== "primary" && `eos-progress--${tone}`)}>
            <div className="eos-progress__meta">
              <span>{percent}% of target</span>
              <span>{target}</span>
            </div>
            <div
              className="eos-progress__track"
              role="progressbar"
              aria-valuenow={percent}
              aria-valuemin={0}
              aria-valuemax={100}
              aria-label={typeof label === "string" ? `${label} progress` : "Progress"}
            >
              <div className="eos-progress__bar" style={{ width: `${percent}%` }} />
            </div>
          </div>
        ) : null}
        {hint ? <p className="eos-stat__hint">{hint}</p> : null}
      </div>
    </section>
  );
}

export interface DashboardWidgetProps extends StyleProps {
  title: ReactNode;
  description?: ReactNode;
  actions?: ReactNode;
  footer?: ReactNode;
  loading?: boolean;
  error?: string | null;
  isEmpty?: boolean;
  emptyMessage?: string;
  children: ReactNode;
}

/**
 * Dashboard tile that owns the loading / error / empty lifecycle so modules
 * never re-implement it.
 */
export function DashboardWidget({
  title,
  description,
  actions,
  footer,
  loading = false,
  error = null,
  isEmpty = false,
  emptyMessage = "Nothing to show yet.",
  children,
  className,
  style,
}: DashboardWidgetProps) {
  let body: ReactNode = children;

  if (loading) {
    body = (
      <div className="eos-stack" aria-busy="true">
        <span className="eos-skeleton" style={{ height: 14, width: "80%" }} />
        <span className="eos-skeleton" style={{ height: 14, width: "60%" }} />
        <span className="eos-skeleton" style={{ height: 14, width: "70%" }} />
      </div>
    );
  } else if (error) {
    body = (
      <div className="eos-alert eos-alert--danger" role="alert">
        <span aria-hidden="true">!</span>
        <div>
          <p className="eos-alert__body">{error}</p>
        </div>
        <span />
      </div>
    );
  } else if (isEmpty) {
    body = <p className="eos-empty__description">{emptyMessage}</p>;
  }

  return (
    <Card title={title} description={description} actions={actions} footer={footer} className={className} style={style}>
      {body}
    </Card>
  );
}
