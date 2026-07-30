import type { ElementType, ReactNode } from "react";
import { cx, type StyleProps } from "./utils";

/** Top level admin screen wrapper: title, description, actions and body. */
export function PageLayout({
  title,
  description,
  actions,
  breadcrumbs,
  aside,
  children,
  className,
  style,
}: StyleProps & {
  title: ReactNode;
  description?: ReactNode;
  actions?: ReactNode;
  breadcrumbs?: ReactNode;
  aside?: ReactNode;
  children: ReactNode;
}) {
  return (
    <div className={cx("eos-page", className)} style={style}>
      {breadcrumbs}
      <header className="eos-page__header">
        <div className="eos-page__heading">
          {aside}
          <div style={{ minWidth: 0 }}>
            <h1 className="eos-page__title">{title}</h1>
            {description ? <p className="eos-page__description">{description}</p> : null}
          </div>
        </div>
        {actions ? <div className="eos-page__actions">{actions}</div> : null}
      </header>
      <div className="eos-page__body">{children}</div>
    </div>
  );
}

/** Titled region inside a page. */
export function Section({
  title,
  description,
  actions,
  children,
  as: Tag = "section",
  className,
  style,
}: StyleProps & {
  title?: ReactNode;
  description?: ReactNode;
  actions?: ReactNode;
  children: ReactNode;
  as?: ElementType;
}) {
  return (
    <Tag className={cx("eos-section", className)} style={style}>
      {title || actions ? (
        <div className="eos-section__header">
          <div style={{ minWidth: 0 }}>
            {title ? <h2 className="eos-section__title">{title}</h2> : null}
            {description ? <p className="eos-section__description">{description}</p> : null}
          </div>
          {actions ? <div className="eos-page__actions">{actions}</div> : null}
        </div>
      ) : null}
      {children}
    </Tag>
  );
}

/** Responsive auto-fit grid used for card and widget collections. */
export function Grid({
  minColumnWidth = 260,
  children,
  className,
  style,
}: StyleProps & { minColumnWidth?: number; children: ReactNode }) {
  return (
    <div
      className={cx("eos-grid", className)}
      style={{ ["--eos-grid-min" as string]: `${minColumnWidth}px`, ...style }}
    >
      {children}
    </div>
  );
}

/** Vertical spacing stack. */
export function Stack({ children, className, style }: StyleProps & { children: ReactNode }) {
  return (
    <div className={cx("eos-stack", className)} style={style}>
      {children}
    </div>
  );
}

/** Horizontal wrapping row. */
export function Inline({ children, className, style }: StyleProps & { children: ReactNode }) {
  return (
    <div className={cx("eos-inline", className)} style={style}>
      {children}
    </div>
  );
}

/** Key/value list used by summary cards. */
export function DefinitionList({
  items,
  className,
  style,
}: StyleProps & { items: Array<{ term: ReactNode; value: ReactNode; key?: string }> }) {
  return (
    <dl className={cx("eos-definition", className)} style={style}>
      {items.map((item, index) => (
        <div key={item.key ?? index} style={{ display: "contents" }}>
          <dt>{item.term}</dt>
          <dd>{item.value}</dd>
        </div>
      ))}
    </dl>
  );
}
