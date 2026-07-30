import { useEffect, useId, useMemo, useRef, useState, type ReactNode } from "react";
import { Button } from "./buttons";
import { cx, type StyleProps } from "./utils";

export interface BreadcrumbItem {
  label: string;
  href?: string;
}

/** Hierarchical navigation trail. */
export function Breadcrumbs({ items, className, style }: StyleProps & { items: BreadcrumbItem[] }) {
  return (
    <nav className={cx("eos-breadcrumbs", className)} style={style} aria-label="Breadcrumb">
      <ol>
        {items.map((item, index) => {
          const isLast = index === items.length - 1;
          return (
            <li key={`${item.label}-${index}`}>
              {item.href && !isLast ? (
                <a href={item.href}>{item.label}</a>
              ) : (
                <span aria-current={isLast ? "page" : undefined}>{item.label}</span>
              )}
              {!isLast ? (
                <span className="eos-breadcrumbs__separator" aria-hidden="true">
                  /
                </span>
              ) : null}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}

export interface TabItem {
  id: string;
  label: ReactNode;
  content: ReactNode;
  badge?: ReactNode;
  disabled?: boolean;
}

export interface TabsProps extends StyleProps {
  items: TabItem[];
  value?: string;
  defaultValue?: string;
  onChange?: (id: string) => void;
  label: string;
}

/** Accessible tab set with roving keyboard navigation. */
export function Tabs({ items, value, defaultValue, onChange, label, className, style }: TabsProps) {
  const baseId = useId();
  const [internal, setInternal] = useState(defaultValue ?? items[0]?.id ?? "");
  const active = value ?? internal;
  const buttons = useRef(new Map<string, HTMLButtonElement>());

  const enabled = useMemo(() => items.filter((item) => !item.disabled), [items]);

  const select = (id: string) => {
    if (value === undefined) setInternal(id);
    onChange?.(id);
  };

  const onKeyDown = (event: React.KeyboardEvent<HTMLDivElement>) => {
    const index = enabled.findIndex((item) => item.id === active);
    if (index < 0) return;
    let next = index;
    if (event.key === "ArrowRight") next = (index + 1) % enabled.length;
    else if (event.key === "ArrowLeft") next = (index - 1 + enabled.length) % enabled.length;
    else if (event.key === "Home") next = 0;
    else if (event.key === "End") next = enabled.length - 1;
    else return;
    event.preventDefault();
    const target = enabled[next];
    select(target.id);
    buttons.current.get(target.id)?.focus();
  };

  const activeItem = items.find((item) => item.id === active);

  return (
    <div className={cx("eos-stack", className)} style={style}>
      <div className="eos-tabs" role="tablist" aria-label={label} onKeyDown={onKeyDown}>
        {items.map((item) => (
          <button
            key={item.id}
            ref={(node) => {
              if (node) buttons.current.set(item.id, node);
              else buttons.current.delete(item.id);
            }}
            type="button"
            role="tab"
            id={`${baseId}-tab-${item.id}`}
            aria-controls={`${baseId}-panel-${item.id}`}
            aria-selected={item.id === active}
            tabIndex={item.id === active ? 0 : -1}
            disabled={item.disabled}
            className={cx("eos-tabs__tab", item.id === active && "is-active")}
            onClick={() => select(item.id)}
          >
            {item.label}
            {item.badge}
          </button>
        ))}
      </div>
      {activeItem ? (
        <div
          role="tabpanel"
          id={`${baseId}-panel-${activeItem.id}`}
          aria-labelledby={`${baseId}-tab-${activeItem.id}`}
          tabIndex={0}
        >
          {activeItem.content}
        </div>
      ) : null}
    </div>
  );
}

export interface AccordionItem {
  id: string;
  title: ReactNode;
  content: ReactNode;
  meta?: ReactNode;
}

/** Collapsible sections; set `multiple` to allow several open at once. */
export function Accordion({
  items,
  multiple = false,
  defaultOpen = [],
  className,
  style,
}: StyleProps & { items: AccordionItem[]; multiple?: boolean; defaultOpen?: string[] }) {
  const [open, setOpen] = useState<string[]>(defaultOpen);

  const toggle = (id: string) => {
    setOpen((current) => {
      if (current.includes(id)) return current.filter((entry) => entry !== id);
      return multiple ? [...current, id] : [id];
    });
  };

  return (
    <div className={cx("eos-accordion", className)} style={style}>
      {items.map((item) => {
        const isOpen = open.includes(item.id);
        return (
          <div className="eos-accordion__item" key={item.id}>
            <h3 style={{ margin: 0 }}>
              <button
                type="button"
                className="eos-accordion__trigger"
                aria-expanded={isOpen}
                aria-controls={`accordion-panel-${item.id}`}
                id={`accordion-trigger-${item.id}`}
                onClick={() => toggle(item.id)}
              >
                <span>{item.title}</span>
                <span className="eos-inline">
                  {item.meta}
                  <span className={cx("eos-accordion__chevron", isOpen && "is-open")} aria-hidden="true">
                    ›
                  </span>
                </span>
              </button>
            </h3>
            {isOpen ? (
              <div
                className="eos-accordion__panel"
                id={`accordion-panel-${item.id}`}
                role="region"
                aria-labelledby={`accordion-trigger-${item.id}`}
              >
                {item.content}
              </div>
            ) : null}
          </div>
        );
      })}
    </div>
  );
}

export interface StepDefinition {
  id: string;
  title: string;
  description?: string;
  content?: ReactNode;
}

export interface StepperProps extends StyleProps {
  steps: StepDefinition[];
  current: number;
  onStepChange?: (index: number) => void;
  label?: string;
}

/** Progress indicator for multi-step flows. */
export function Stepper({ steps, current, onStepChange, label = "Progress", className, style }: StepperProps) {
  return (
    <ol className={cx("eos-stepper", className)} style={style} aria-label={label}>
      {steps.map((step, index) => {
        const state = index < current ? "is-complete" : index === current ? "is-current" : "";
        const interactive = Boolean(onStepChange) && index <= current;
        return (
          <li className={cx("eos-stepper__step", state)} key={step.id} aria-current={index === current ? "step" : undefined}>
            <span className="eos-stepper__marker" aria-hidden="true">
              {index < current ? "✓" : index + 1}
            </span>
            <span className="eos-stepper__text">
              {interactive ? (
                <button
                  type="button"
                  className="eos-btn eos-btn--link eos-btn--sm"
                  style={{ padding: 0, height: "auto" }}
                  onClick={() => onStepChange?.(index)}
                >
                  {step.title}
                </button>
              ) : (
                <span className="eos-stepper__title">{step.title}</span>
              )}
              {step.description ? <span className="eos-stepper__description">{step.description}</span> : null}
            </span>
          </li>
        );
      })}
    </ol>
  );
}

export interface WizardProps extends StyleProps {
  steps: StepDefinition[];
  current: number;
  onStepChange: (index: number) => void;
  onFinish?: () => void;
  finishLabel?: string;
  busy?: boolean;
  canContinue?: boolean;
}

/** Stepper plus navigation controls and the active step's content. */
export function Wizard({
  steps,
  current,
  onStepChange,
  onFinish,
  finishLabel = "Finish",
  busy = false,
  canContinue = true,
  className,
  style,
}: WizardProps) {
  const isLast = current >= steps.length - 1;

  return (
    <div className={cx("eos-stack", className)} style={style}>
      <Stepper steps={steps} current={current} onStepChange={onStepChange} />
      <div>{steps[current]?.content}</div>
      <div className="eos-inline" style={{ justifyContent: "flex-end" }}>
        <Button variant="ghost" disabled={current === 0 || busy} onClick={() => onStepChange(current - 1)}>
          Back
        </Button>
        <Button
          variant="primary"
          loading={busy}
          disabled={!canContinue}
          onClick={() => (isLast ? onFinish?.() : onStepChange(current + 1))}
        >
          {isLast ? finishLabel : "Continue"}
        </Button>
      </div>
    </div>
  );
}

/** Locks page scroll and restores focus when an overlay closes. */
export function useOverlayBehaviour(open: boolean, onClose: () => void) {
  const containerRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const previouslyFocused = document.activeElement as HTMLElement | null;
    const { overflow } = document.body.style;
    document.body.style.overflow = "hidden";

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        event.stopPropagation();
        onClose();
        return;
      }
      if (event.key !== "Tab" || !containerRef.current) return;
      const focusable = containerRef.current.querySelectorAll<HTMLElement>(
        'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])',
      );
      if (!focusable.length) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };

    document.addEventListener("keydown", onKeyDown, true);
    const timer = window.setTimeout(() => {
      const focusable = containerRef.current?.querySelector<HTMLElement>(
        'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])',
      );
      focusable?.focus();
    }, 0);

    return () => {
      window.clearTimeout(timer);
      document.removeEventListener("keydown", onKeyDown, true);
      document.body.style.overflow = overflow;
      previouslyFocused?.focus?.();
    };
  }, [open, onClose]);

  return containerRef;
}
