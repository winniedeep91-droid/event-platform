import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from "react";
import { cx, type Size, type StyleProps } from "./utils";

export type ButtonVariant = "primary" | "secondary" | "outline" | "ghost" | "link" | "danger";

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant;
  size?: Size;
  loading?: boolean;
  block?: boolean;
  iconOnly?: boolean;
  leadingIcon?: ReactNode;
  trailingIcon?: ReactNode;
}

/** Spinner used by buttons and loading states. */
export function Spinner({ size = 16, label }: { size?: number; label?: string }) {
  return (
    <>
      <span className="eos-spinner" style={{ width: size, height: size }} aria-hidden="true" />
      {label ? <span className="eos-visually-hidden">{label}</span> : null}
    </>
  );
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  {
    variant = "secondary",
    size = "md",
    loading = false,
    block = false,
    iconOnly = false,
    leadingIcon,
    trailingIcon,
    className,
    children,
    disabled,
    type = "button",
    ...rest
  },
  ref,
) {
  return (
    <button
      ref={ref}
      type={type}
      className={cx(
        "eos-btn",
        `eos-btn--${variant}`,
        size !== "md" && `eos-btn--${size}`,
        iconOnly && "eos-btn--icon",
        block && "eos-btn--block",
        className,
      )}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      {...rest}
    >
      {loading ? <Spinner size={size === "lg" ? 18 : 14} /> : leadingIcon}
      {children}
      {!loading ? trailingIcon : null}
    </button>
  );
});

export interface LinkButtonProps extends StyleProps {
  href: string;
  variant?: ButtonVariant;
  size?: Size;
  target?: string;
  rel?: string;
  children: ReactNode;
}

/** Anchor styled as a button — keeps real navigation semantics. */
export function LinkButton({ href, variant = "secondary", size = "md", className, ...rest }: LinkButtonProps) {
  return (
    <a
      href={href}
      className={cx("eos-btn", `eos-btn--${variant}`, size !== "md" && `eos-btn--${size}`, className)}
      {...rest}
    />
  );
}

/**
 * Groups related actions. `attached` renders a segmented control; pass
 * `role="group"` semantics automatically via the accessible label.
 */
export function ButtonGroup({
  children,
  attached = false,
  label,
  className,
  style,
}: StyleProps & { children: ReactNode; attached?: boolean; label: string }) {
  return (
    <div
      role="group"
      aria-label={label}
      className={cx("eos-btn-group", attached ? "eos-btn-group--attached" : "eos-btn-group--spaced", className)}
      style={style}
    >
      {children}
    </div>
  );
}

export interface SegmentedOption<T extends string> {
  value: T;
  label: ReactNode;
  disabled?: boolean;
}

/** Segmented single-choice control built on ButtonGroup. */
export function SegmentedControl<T extends string>({
  label,
  value,
  options,
  onChange,
  size = "sm",
  className,
  style,
}: StyleProps & {
  label: string;
  value: T;
  options: SegmentedOption<T>[];
  onChange: (value: T) => void;
  size?: Size;
}) {
  return (
    <ButtonGroup attached label={label} className={className} style={style}>
      {options.map((option) => (
        <Button
          key={option.value}
          size={size}
          variant="outline"
          aria-pressed={option.value === value}
          disabled={option.disabled}
          onClick={() => onChange(option.value)}
        >
          {option.label}
        </Button>
      ))}
    </ButtonGroup>
  );
}
