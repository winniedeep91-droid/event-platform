import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from "react";
import { Button } from "./buttons";
import { cx, type StyleProps, type Tone } from "./utils";

export interface AlertProps extends StyleProps {
  tone?: Tone;
  title?: ReactNode;
  children?: ReactNode;
  onDismiss?: () => void;
  actions?: ReactNode;
}

const TONE_GLYPH: Record<Tone, string> = {
  neutral: "•",
  primary: "•",
  info: "i",
  success: "✓",
  warning: "!",
  danger: "!",
};

/** Inline message block. */
export function Alert({ tone = "info", title, children, onDismiss, actions, className, style }: AlertProps) {
  return (
    <div
      className={cx("eos-alert", `eos-alert--${tone}`, className)}
      style={style}
      role={tone === "danger" ? "alert" : "status"}
    >
      <span className="eos-alert__icon" aria-hidden="true">
        {TONE_GLYPH[tone]}
      </span>
      <div>
        {title ? <p className="eos-alert__title">{title}</p> : null}
        {children ? <div className="eos-alert__body">{children}</div> : null}
        {actions ? <div className="eos-inline">{actions}</div> : null}
      </div>
      {onDismiss ? (
        <Button variant="ghost" size="sm" iconOnly aria-label="Dismiss message" onClick={onDismiss}>
          ×
        </Button>
      ) : (
        <span />
      )}
    </div>
  );
}

export interface NoticeItem {
  id: string;
  tone: Tone;
  title?: string;
  message: string;
  dismissible?: boolean;
}

/** Stack of persistent admin notices, typically loaded from the REST notices endpoint. */
export function NoticeList({
  notices,
  onDismiss,
  className,
  style,
}: StyleProps & { notices: NoticeItem[]; onDismiss?: (id: string) => void }) {
  if (!notices.length) return null;

  return (
    <div className={cx("eos-stack", className)} style={style} aria-live="polite">
      {notices.map((notice) => (
        <Alert
          key={notice.id}
          tone={notice.tone}
          title={notice.title}
          onDismiss={notice.dismissible !== false && onDismiss ? () => onDismiss(notice.id) : undefined}
        >
          {notice.message}
        </Alert>
      ))}
    </div>
  );
}

export interface Toast {
  id: string;
  tone: Tone;
  title?: string;
  message: string;
  duration?: number;
}

interface ToastContextValue {
  toasts: Toast[];
  push: (toast: Omit<Toast, "id"> & { id?: string }) => string;
  dismiss: (id: string) => void;
  success: (message: string, title?: string) => string;
  error: (message: string, title?: string) => string;
  info: (message: string, title?: string) => string;
}

const ToastContext = createContext<ToastContextValue | null>(null);

/** Provides the toast queue. Mount once at the app root. */
export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([]);
  const timers = useRef(new Map<string, ReturnType<typeof setTimeout>>());

  const dismiss = useCallback((id: string) => {
    setToasts((current) => current.filter((toast) => toast.id !== id));
    const timer = timers.current.get(id);
    if (timer) {
      clearTimeout(timer);
      timers.current.delete(id);
    }
  }, []);

  const push = useCallback<ToastContextValue["push"]>(
    (toast) => {
      const id = toast.id ?? `toast-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
      const duration = toast.duration ?? 6000;
      setToasts((current) => [...current, { ...toast, id }]);
      if (duration > 0) {
        timers.current.set(
          id,
          setTimeout(() => dismiss(id), duration),
        );
      }
      return id;
    },
    [dismiss],
  );

  useEffect(() => {
    const pending = timers.current;
    return () => {
      pending.forEach((timer) => clearTimeout(timer));
      pending.clear();
    };
  }, []);

  const value = useMemo<ToastContextValue>(
    () => ({
      toasts,
      push,
      dismiss,
      success: (message, title) => push({ tone: "success", message, title }),
      error: (message, title) => push({ tone: "danger", message, title, duration: 9000 }),
      info: (message, title) => push({ tone: "info", message, title }),
    }),
    [toasts, push, dismiss],
  );

  return (
    <ToastContext.Provider value={value}>
      {children}
      <ToastViewport />
    </ToastContext.Provider>
  );
}

/** Access the toast queue. Throws when used outside `ToastProvider`. */
export function useToast(): ToastContextValue {
  const context = useContext(ToastContext);
  if (!context) throw new Error("useToast must be used inside a ToastProvider");
  return context;
}

function ToastViewport() {
  const { toasts, dismiss } = useToast();

  return (
    <div className="eos-toast-viewport" role="region" aria-label="Notifications">
      {toasts.map((toast) => (
        <div key={toast.id} className={cx("eos-toast", `eos-toast--${toast.tone}`)} role="status" aria-live="polite">
          <div>
            {toast.title ? <p className="eos-toast__title">{toast.title}</p> : null}
            <p className="eos-toast__body">{toast.message}</p>
          </div>
          <Button variant="ghost" size="sm" iconOnly aria-label="Dismiss notification" onClick={() => dismiss(toast.id)}>
            ×
          </Button>
        </div>
      ))}
    </div>
  );
}

/** Rectangular placeholder for content that is loading. */
export function Skeleton({
  width = "100%",
  height = 14,
  radius,
  className,
  style,
}: StyleProps & { width?: number | string; height?: number | string; radius?: number }) {
  return (
    <span
      className={cx("eos-skeleton", className)}
      style={{ width, height, borderRadius: radius, ...style }}
      aria-hidden="true"
    />
  );
}

/** Repeated skeleton lines for text blocks and lists. */
export function SkeletonText({ lines = 3, className, style }: StyleProps & { lines?: number }) {
  return (
    <div className={cx("eos-stack", className)} style={style} aria-hidden="true">
      {Array.from({ length: lines }).map((_, index) => (
        <Skeleton key={index} width={index === lines - 1 ? "60%" : "100%"} />
      ))}
    </div>
  );
}

/** Centered spinner with accessible status text. */
export function LoadingState({ label = "Loading…", className, style }: StyleProps & { label?: string }) {
  return (
    <div className={cx("eos-loading", className)} style={style} role="status" aria-live="polite">
      <span className="eos-spinner" aria-hidden="true" />
      <span>{label}</span>
    </div>
  );
}

export interface EmptyStateProps extends StyleProps {
  title: ReactNode;
  description?: ReactNode;
  icon?: ReactNode;
  action?: ReactNode;
}

/** Placeholder shown when a collection has no records. */
export function EmptyState({ title, description, icon, action, className, style }: EmptyStateProps) {
  return (
    <div className={cx("eos-empty", className)} style={style}>
      {icon ? (
        <div className="eos-empty__icon" aria-hidden="true">
          {icon}
        </div>
      ) : null}
      <h3 className="eos-empty__title">{title}</h3>
      {description ? <p className="eos-empty__description">{description}</p> : null}
      {action ? <div className="eos-inline" style={{ justifyContent: "center" }}>{action}</div> : null}
    </div>
  );
}
