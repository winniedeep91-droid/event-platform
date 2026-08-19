import { useState, type ReactNode } from "react";
import { Button, type ButtonVariant } from "./buttons";
import { useOverlayBehaviour } from "./navigation";
import { cx, type StyleProps } from "./utils";

export interface ModalProps extends StyleProps {
  open: boolean;
  onClose: () => void;
  title: ReactNode;
  description?: ReactNode;
  footer?: ReactNode;
  size?: "sm" | "md" | "lg";
  children: ReactNode;
}

/** Focus-trapped modal dialog. */
export function Modal({
  open,
  onClose,
  title,
  description,
  footer,
  size = "md",
  children,
  className,
  style,
}: ModalProps) {
  const ref = useOverlayBehaviour(open, onClose);
  if (!open) return null;

  return (
    <div
      className="eos-overlay eos-overlay--center"
      onMouseDown={(event) => event.target === event.currentTarget && onClose()}
    >
      <div
        ref={ref}
        role="dialog"
        aria-modal="true"
        aria-label={typeof title === "string" ? title : undefined}
        className={cx("eos-modal", `eos-modal--${size}`, className)}
        style={style}
      >
        <header className="eos-modal__header">
          <div style={{ minWidth: 0 }}>
            <h2 className="eos-modal__title">{title}</h2>
            {description ? <p className="eos-modal__description">{description}</p> : null}
          </div>
          <Button variant="ghost" size="sm" iconOnly aria-label="Close dialog" onClick={onClose}>
            ×
          </Button>
        </header>
        <div className="eos-modal__body">{children}</div>
        {footer ? <footer className="eos-modal__footer">{footer}</footer> : null}
      </div>
    </div>
  );
}

export interface DrawerProps extends StyleProps {
  open: boolean;
  onClose: () => void;
  title: ReactNode;
  description?: ReactNode;
  footer?: ReactNode;
  side?: "right" | "left";
  children: ReactNode;
}

/** Slide-over drawer. */
export function Drawer({
  open,
  onClose,
  title,
  description,
  footer,
  side = "right",
  children,
  className,
  style,
}: DrawerProps) {
  const ref = useOverlayBehaviour(open, onClose);
  if (!open) return null;

  return (
    <div
      className={cx("eos-overlay", "left" === side ? "eos-overlay--left" : "eos-overlay--right")}
      onMouseDown={(event) => event.target === event.currentTarget && onClose()}
    >
      <div
        ref={ref}
        role="dialog"
        aria-modal="true"
        aria-label={typeof title === "string" ? title : undefined}
        className={cx("eos-drawer", `eos-drawer--${side}`, className)}
        style={style}
      >
        <header className="eos-modal__header">
          <div style={{ minWidth: 0 }}>
            <h2 className="eos-modal__title">{title}</h2>
            {description ? <p className="eos-modal__description">{description}</p> : null}
          </div>
          <Button variant="ghost" size="sm" iconOnly aria-label="Close panel" onClick={onClose}>
            ×
          </Button>
        </header>
        <div className="eos-modal__body">{children}</div>
        {footer ? <footer className="eos-modal__footer">{footer}</footer> : null}
      </div>
    </div>
  );
}

/** Non-modal inline panel that docks beside page content. */
export function SidePanel({
  title,
  actions,
  children,
  className,
  style,
}: StyleProps & { title: ReactNode; actions?: ReactNode; children: ReactNode }) {
  return (
    <aside
      className={cx("eos-side-panel", className)}
      style={style}
      aria-label={typeof title === "string" ? title : undefined}
    >
      <header className="eos-side-panel__header">
        <h2 className="eos-card__title">{title}</h2>
        {actions ? <div className="eos-inline">{actions}</div> : null}
      </header>
      <div className="eos-side-panel__body">{children}</div>
    </aside>
  );
}

export interface ConfirmDialogProps {
  open: boolean;
  onCancel: () => void;
  onConfirm: () => void | Promise<void>;
  title: ReactNode;
  description?: ReactNode;
  confirmLabel?: string;
  cancelLabel?: string;
  destructive?: boolean;
  busy?: boolean;
}

/** Confirmation dialog for destructive or irreversible actions. */
export function ConfirmDialog({
  open,
  onCancel,
  onConfirm,
  title,
  description,
  confirmLabel = "Confirm",
  cancelLabel = "Cancel",
  destructive = false,
  busy = false,
}: ConfirmDialogProps) {
  const [pending, setPending] = useState(false);
  const variant: ButtonVariant = destructive ? "danger" : "primary";

  const run = async () => {
    setPending(true);
    try {
      await onConfirm();
    } finally {
      setPending(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={onCancel}
      title={title}
      description={description}
      size="sm"
      footer={
        <>
          <Button variant="ghost" onClick={onCancel} disabled={pending || busy}>
            {cancelLabel}
          </Button>
          <Button variant={variant} loading={pending || busy} onClick={() => void run()}>
            {confirmLabel}
          </Button>
        </>
      }
    >
      <p className="eos-modal__description">{description ?? "This action cannot be undone."}</p>
    </Modal>
  );
}
