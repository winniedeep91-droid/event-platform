import type { ReactNode } from "react";
import { cx, type StyleProps, type Tone } from "./utils";

export interface BadgeProps extends StyleProps {
  tone?: Tone;
  dot?: boolean;
  children: ReactNode;
}

/** Small label for counts, tags and categories. */
export function Badge({ tone = "neutral", dot = false, children, className, style }: BadgeProps) {
  return (
    <span className={cx("eos-badge", `eos-badge--${tone}`, className)} style={style}>
      {dot ? <span className="eos-chip__dot" aria-hidden="true" /> : null}
      {children}
    </span>
  );
}

export type StatusKind = "active" | "inactive" | "pending" | "failed" | "draft" | "scheduled";

const STATUS_TONE: Record<StatusKind, Tone> = {
  active: "success",
  inactive: "neutral",
  pending: "warning",
  failed: "danger",
  draft: "neutral",
  scheduled: "info",
};

export interface StatusChipProps extends StyleProps {
  status: StatusKind;
  label?: ReactNode;
}

/** Status indicator that pairs colour with text so colour is never the only cue. */
export function StatusChip({ status, label, className, style }: StatusChipProps) {
  return (
    <Badge tone={STATUS_TONE[status]} dot className={className} style={style}>
      {label ?? status.charAt(0).toUpperCase() + status.slice(1)}
    </Badge>
  );
}

export interface AvatarProps extends StyleProps {
  name: string;
  src?: string | null;
  size?: number;
}

function initialsOf(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return "?";
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return `${parts[0][0]}${parts[parts.length - 1][0]}`.toUpperCase();
}

/** User avatar with initials fallback. */
export function Avatar({ name, src, size = 36, className, style }: AvatarProps) {
  const dimension = { width: size, height: size, fontSize: Math.max(11, Math.round(size / 2.8)) };

  if (src) {
    return (
      <img
        src={src}
        alt=""
        className={cx("eos-avatar", className)}
        style={{ ...dimension, ...style }}
      />
    );
  }

  return (
    <span
      className={cx("eos-avatar eos-avatar--fallback", className)}
      style={{ ...dimension, ...style }}
    >
      <span aria-hidden="true">{initialsOf(name)}</span>
      <span className="eos-visually-hidden">{name}</span>
    </span>
  );
}

/** Stacked avatar list for member previews. */
export function AvatarGroup({
  people,
  max = 4,
  size = 30,
  className,
  style,
}: StyleProps & {
  people: Array<{ name: string; src?: string | null }>;
  max?: number;
  size?: number;
}) {
  const visible = people.slice(0, max);
  const overflow = people.length - visible.length;

  return (
    <div className={cx("eos-avatar-group", className)} style={style}>
      {visible.map((person, index) => (
        <Avatar key={`${person.name}-${index}`} name={person.name} src={person.src} size={size} />
      ))}
      {overflow > 0 ? (
        <span
          className="eos-avatar eos-avatar--fallback"
          style={{ width: size, height: size, fontSize: Math.max(10, Math.round(size / 3)) }}
        >
          +{overflow}
        </span>
      ) : null}
    </div>
  );
}

export interface ProgressBarProps extends StyleProps {
  label: string;
  value: number;
  max?: number;
  tone?: Tone;
  showValue?: boolean;
  indeterminate?: boolean;
}

/** Determinate or indeterminate progress bar. */
export function ProgressBar({
  label,
  value,
  max = 100,
  tone = "primary",
  showValue = true,
  indeterminate = false,
  className,
  style,
}: ProgressBarProps) {
  const percent = max > 0 ? Math.max(0, Math.min(100, Math.round((value / max) * 100))) : 0;

  return (
    <div
      className={cx("eos-progress", tone !== "primary" && `eos-progress--${tone}`, className)}
      style={style}
    >
      <div className="eos-progress__meta">
        <span>{label}</span>
        {showValue && !indeterminate ? <span>{percent}%</span> : null}
      </div>
      <div
        className="eos-progress__track"
        role="progressbar"
        aria-label={label}
        aria-valuenow={indeterminate ? undefined : percent}
        aria-valuemin={indeterminate ? undefined : 0}
        aria-valuemax={indeterminate ? undefined : 100}
      >
        <div
          className={cx("eos-progress__bar", indeterminate && "eos-progress__bar--indeterminate")}
          style={indeterminate ? undefined : { width: `${percent}%` }}
        />
      </div>
    </div>
  );
}

export interface TimelineItem {
  id: string;
  title: ReactNode;
  timestamp: string;
  description?: ReactNode;
  tone?: Tone;
}

/** Chronological timeline. */
export function Timeline({ items, className, style }: StyleProps & { items: TimelineItem[] }) {
  return (
    <ol className={cx("eos-timeline", className)} style={style}>
      {items.map((item) => (
        <li className="eos-timeline__item" key={item.id}>
          <span
            className={cx("eos-timeline__dot", item.tone && `eos-timeline__dot--${item.tone}`)}
            aria-hidden="true"
          />
          <div className="eos-timeline__content">
            <p className="eos-timeline__title">{item.title}</p>
            <time className="eos-timeline__meta" dateTime={item.timestamp}>
              {item.timestamp}
            </time>
            {item.description ? <p className="eos-timeline__meta">{item.description}</p> : null}
          </div>
        </li>
      ))}
    </ol>
  );
}

export interface ActivityEntry {
  id: string;
  actor: { name: string; avatar?: string | null };
  action: ReactNode;
  timestamp: string;
  context?: ReactNode;
  tone?: Tone;
}

/** Audit / activity feed rendered from the REST activity log. */
export function ActivityFeed({
  entries,
  emptyMessage = "No recent activity.",
  className,
  style,
}: StyleProps & { entries: ActivityEntry[]; emptyMessage?: string }) {
  if (!entries.length) {
    return <p className="eos-empty__description">{emptyMessage}</p>;
  }

  return (
    <ul className={cx("eos-feed", className)} style={style}>
      {entries.map((entry) => (
        <li className="eos-feed__item" key={entry.id}>
          <Avatar name={entry.actor.name} src={entry.actor.avatar} size={32} />
          <div className="eos-feed__content">
            <p className="eos-feed__title">
              <strong>{entry.actor.name}</strong> {entry.action}
            </p>
            {entry.context ? <p className="eos-feed__meta">{entry.context}</p> : null}
            <time className="eos-feed__meta" dateTime={entry.timestamp}>
              {entry.timestamp}
            </time>
          </div>
          {entry.tone ? <Badge tone={entry.tone}>{entry.tone}</Badge> : null}
        </li>
      ))}
    </ul>
  );
}
