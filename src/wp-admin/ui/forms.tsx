import {
  forwardRef,
  useEffect,
  useId,
  useMemo,
  useRef,
  useState,
  type InputHTMLAttributes,
  type ReactNode,
  type SelectHTMLAttributes,
  type TextareaHTMLAttributes,
} from "react";
import { Button } from "./buttons";
import { cx, type StyleProps } from "./utils";

export interface FieldProps extends StyleProps {
  label: ReactNode;
  htmlFor?: string;
  hint?: ReactNode;
  error?: ReactNode;
  required?: boolean;
  children: ReactNode;
  labelAs?: "label" | "span";
}

/** Wraps any control with label, hint and error text. */
export function Field({
  label,
  htmlFor,
  hint,
  error,
  required = false,
  children,
  labelAs = "label",
  className,
  style,
}: FieldProps) {
  const Label = labelAs;
  return (
    <div className={cx("eos-field", className)} style={style}>
      <Label className="eos-field__label" {...(labelAs === "label" ? { htmlFor } : {})}>
        {label}
        {required ? (
          <span className="eos-field__required" aria-hidden="true">
            *
          </span>
        ) : null}
      </Label>
      {children}
      {hint ? (
        <p className="eos-field__hint" id={htmlFor ? `${htmlFor}-hint` : undefined}>
          {hint}
        </p>
      ) : null}
      {error ? (
        <p className="eos-field__error" id={htmlFor ? `${htmlFor}-error` : undefined} role="alert">
          {error}
        </p>
      ) : null}
    </div>
  );
}

type ControlBase = { label?: ReactNode; hint?: ReactNode; error?: ReactNode };

export type InputProps = InputHTMLAttributes<HTMLInputElement> & ControlBase;

export const Input = forwardRef<HTMLInputElement, InputProps>(function Input(
  { label, hint, error, id, className, required, ...rest },
  ref,
) {
  const generated = useId();
  const inputId = id ?? `eos-input-${generated}`;

  const control = (
    <input
      ref={ref}
      id={inputId}
      className={cx("eos-input", className)}
      aria-invalid={error ? true : undefined}
      aria-describedby={cx(hint ? `${inputId}-hint` : "", error ? `${inputId}-error` : "").trim() || undefined}
      required={required}
      {...rest}
    />
  );

  if (!label) return control;

  return (
    <Field label={label} htmlFor={inputId} hint={hint} error={error} required={required}>
      {control}
    </Field>
  );
});

export type TextareaProps = TextareaHTMLAttributes<HTMLTextAreaElement> & ControlBase;

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaProps>(function Textarea(
  { label, hint, error, id, className, required, ...rest },
  ref,
) {
  const generated = useId();
  const controlId = id ?? `eos-textarea-${generated}`;

  const control = (
    <textarea
      ref={ref}
      id={controlId}
      className={cx("eos-textarea", className)}
      aria-invalid={error ? true : undefined}
      aria-describedby={cx(hint ? `${controlId}-hint` : "", error ? `${controlId}-error` : "").trim() || undefined}
      required={required}
      {...rest}
    />
  );

  if (!label) return control;

  return (
    <Field label={label} htmlFor={controlId} hint={hint} error={error} required={required}>
      {control}
    </Field>
  );
});

export interface SelectOption {
  value: string;
  label: string;
  disabled?: boolean;
}

export type SelectProps = SelectHTMLAttributes<HTMLSelectElement> &
  ControlBase & { options: SelectOption[]; placeholder?: string };

export const Select = forwardRef<HTMLSelectElement, SelectProps>(function Select(
  { label, hint, error, id, className, options, placeholder, required, ...rest },
  ref,
) {
  const generated = useId();
  const controlId = id ?? `eos-select-${generated}`;

  const control = (
    <select
      ref={ref}
      id={controlId}
      className={cx("eos-select", className)}
      aria-invalid={error ? true : undefined}
      aria-describedby={cx(hint ? `${controlId}-hint` : "", error ? `${controlId}-error` : "").trim() || undefined}
      required={required}
      {...rest}
    >
      {placeholder ? <option value="">{placeholder}</option> : null}
      {options.map((option) => (
        <option key={option.value} value={option.value} disabled={option.disabled}>
          {option.label}
        </option>
      ))}
    </select>
  );

  if (!label) return control;

  return (
    <Field label={label} htmlFor={controlId} hint={hint} error={error} required={required}>
      {control}
    </Field>
  );
});

export interface CheckboxProps extends Omit<InputHTMLAttributes<HTMLInputElement>, "type"> {
  label: ReactNode;
  description?: ReactNode;
}

export const Checkbox = forwardRef<HTMLInputElement, CheckboxProps>(function Checkbox(
  { label, description, className, disabled, ...rest },
  ref,
) {
  return (
    <label className={cx("eos-choice", disabled && "eos-choice--disabled", className)}>
      <input ref={ref} type="checkbox" disabled={disabled} {...rest} />
      <span className="eos-choice__text">
        <span>{label}</span>
        {description ? <span className="eos-choice__description">{description}</span> : null}
      </span>
    </label>
  );
});

export interface CheckboxGroupProps extends StyleProps {
  legend: ReactNode;
  options: Array<{ value: string; label: string; description?: string; disabled?: boolean }>;
  value: string[];
  onChange: (value: string[]) => void;
  vertical?: boolean;
  disabled?: boolean;
  hint?: ReactNode;
  error?: ReactNode;
}

export function CheckboxGroup({
  legend,
  options,
  value,
  onChange,
  vertical = false,
  disabled = false,
  hint,
  error,
  className,
  style,
}: CheckboxGroupProps) {
  return (
    <fieldset className={cx("eos-field", className)} style={style} aria-invalid={error ? true : undefined}>
      <legend className="eos-field__label">{legend}</legend>
      <div className={cx("eos-choice-group", vertical && "eos-choice-group--vertical")}>
        {options.map((option) => (
          <Checkbox
            key={option.value}
            label={option.label}
            description={option.description}
            checked={value.includes(option.value)}
            disabled={disabled || option.disabled}
            onChange={(event) =>
              onChange(
                event.target.checked
                  ? [...value, option.value]
                  : value.filter((entry) => entry !== option.value),
              )
            }
          />
        ))}
      </div>
      {hint ? <p className="eos-field__hint">{hint}</p> : null}
      {error ? (
        <p className="eos-field__error" role="alert">
          {error}
        </p>
      ) : null}
    </fieldset>
  );
}

export interface RadioGroupProps extends StyleProps {
  legend: ReactNode;
  name: string;
  options: Array<{ value: string; label: string; description?: string; disabled?: boolean }>;
  value: string;
  onChange: (value: string) => void;
  vertical?: boolean;
  hint?: ReactNode;
  error?: ReactNode;
}

export function RadioGroup({
  legend,
  name,
  options,
  value,
  onChange,
  vertical = false,
  hint,
  error,
  className,
  style,
}: RadioGroupProps) {
  return (
    <fieldset className={cx("eos-field", className)} style={style}>
      <legend className="eos-field__label">{legend}</legend>
      <div className={cx("eos-choice-group", vertical && "eos-choice-group--vertical")}>
        {options.map((option) => (
          <label key={option.value} className={cx("eos-choice", option.disabled && "eos-choice--disabled")}>
            <input
              type="radio"
              name={name}
              value={option.value}
              checked={value === option.value}
              disabled={option.disabled}
              onChange={() => onChange(option.value)}
            />
            <span className="eos-choice__text">
              <span>{option.label}</span>
              {option.description ? <span className="eos-choice__description">{option.description}</span> : null}
            </span>
          </label>
        ))}
      </div>
      {hint ? <p className="eos-field__hint">{hint}</p> : null}
      {error ? (
        <p className="eos-field__error" role="alert">
          {error}
        </p>
      ) : null}
    </fieldset>
  );
}

export interface SwitchProps extends StyleProps {
  label: ReactNode;
  checked: boolean;
  onChange: (checked: boolean) => void;
  description?: ReactNode;
  disabled?: boolean;
}

/** Accessible toggle switch (`role="switch"`). */
export function Switch({ label, checked, onChange, description, disabled = false, className, style }: SwitchProps) {
  const id = useId();

  return (
    <div className={cx("eos-field", className)} style={style}>
      <div className="eos-switch">
        <button
          type="button"
          role="switch"
          id={id}
          aria-checked={checked}
          aria-describedby={description ? `${id}-description` : undefined}
          className="eos-switch__track"
          disabled={disabled}
          onClick={() => onChange(!checked)}
        >
          <span className="eos-switch__thumb" />
        </button>
        <label htmlFor={id} className="eos-choice__text" style={{ cursor: disabled ? "not-allowed" : "pointer" }}>
          <span style={{ fontWeight: 600 }}>{label}</span>
          {description ? (
            <span className="eos-choice__description" id={`${id}-description`}>
              {description}
            </span>
          ) : null}
        </label>
      </div>
    </div>
  );
}

export interface MultiSelectProps extends StyleProps {
  label: ReactNode;
  options: SelectOption[];
  value: string[];
  onChange: (value: string[]) => void;
  placeholder?: string;
  hint?: ReactNode;
  error?: ReactNode;
  disabled?: boolean;
}

/** Listbox-style multi select with keyboard and screen-reader support. */
export function MultiSelect({
  label,
  options,
  value,
  onChange,
  placeholder = "Select options",
  hint,
  error,
  disabled = false,
  className,
  style,
}: MultiSelectProps) {
  const [open, setOpen] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);
  const id = useId();

  useEffect(() => {
    if (!open) return;
    const onPointerDown = (event: MouseEvent) => {
      if (!containerRef.current?.contains(event.target as Node)) setOpen(false);
    };
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") setOpen(false);
    };
    document.addEventListener("mousedown", onPointerDown);
    document.addEventListener("keydown", onKeyDown);
    return () => {
      document.removeEventListener("mousedown", onPointerDown);
      document.removeEventListener("keydown", onKeyDown);
    };
  }, [open]);

  const selectedLabels = useMemo(
    () => options.filter((option) => value.includes(option.value)),
    [options, value],
  );

  const toggle = (optionValue: string) =>
    onChange(
      value.includes(optionValue) ? value.filter((entry) => entry !== optionValue) : [...value, optionValue],
    );

  return (
    <Field label={label} htmlFor={id} hint={hint} error={error} labelAs="span">
      <div className={cx("eos-multiselect", className)} style={style} ref={containerRef}>
        <button
          type="button"
          id={id}
          className="eos-multiselect__control"
          aria-haspopup="listbox"
          aria-expanded={open}
          disabled={disabled}
          onClick={() => setOpen((current) => !current)}
        >
          {selectedLabels.length ? (
            selectedLabels.map((option) => (
              <span className="eos-badge eos-badge--neutral" key={option.value}>
                {option.label}
              </span>
            ))
          ) : (
            <span className="eos-multiselect__placeholder">{placeholder}</span>
          )}
        </button>
        {open ? (
          <ul className="eos-multiselect__menu" role="listbox" aria-multiselectable="true" aria-labelledby={id}>
            {options.map((option) => (
              <li
                key={option.value}
                role="option"
                aria-selected={value.includes(option.value)}
                className="eos-multiselect__option"
              >
                <Checkbox
                  label={option.label}
                  checked={value.includes(option.value)}
                  disabled={option.disabled}
                  onChange={() => toggle(option.value)}
                />
              </li>
            ))}
          </ul>
        ) : null}
      </div>
    </Field>
  );
}

export interface SearchInputProps extends StyleProps {
  value: string;
  onChange: (value: string) => void;
  label?: string;
  placeholder?: string;
  disabled?: boolean;
  autoFocus?: boolean;
}

/** Search box with a visually hidden label and clear affordance. */
export function SearchInput({
  value,
  onChange,
  label = "Search",
  placeholder = "Search…",
  disabled = false,
  autoFocus = false,
  className,
  style,
}: SearchInputProps) {
  const id = useId();

  return (
    <div className={cx("eos-input-affix eos-input-affix--icon", className)} style={style}>
      <label htmlFor={id} className="eos-visually-hidden">
        {label}
      </label>
      <span className="eos-input-affix__icon" aria-hidden="true">
        ⌕
      </span>
      <input
        id={id}
        type="search"
        role="searchbox"
        className="eos-input"
        value={value}
        placeholder={placeholder}
        disabled={disabled}
        autoFocus={autoFocus}
        onChange={(event) => onChange(event.target.value)}
      />
      {value ? (
        <span className="eos-input-affix__clear">
          <Button variant="ghost" size="sm" iconOnly aria-label="Clear search" onClick={() => onChange("")}>
            ×
          </Button>
        </span>
      ) : null}
    </div>
  );
}

export interface DateTimeProps extends StyleProps {
  label: ReactNode;
  value: string;
  onChange: (value: string) => void;
  hint?: ReactNode;
  error?: ReactNode;
  min?: string;
  max?: string;
  required?: boolean;
  disabled?: boolean;
}

/** Date picker wrapper — normalises the native control across browsers. */
export function DatePicker(props: DateTimeProps) {
  return <TemporalInput type="date" {...props} />;
}

/** Time picker wrapper. */
export function TimePicker(props: DateTimeProps) {
  return <TemporalInput type="time" {...props} />;
}

/** Combined date & time picker wrapper. */
export function DateTimePicker(props: DateTimeProps) {
  return <TemporalInput type="datetime-local" {...props} />;
}

function TemporalInput({
  type,
  label,
  value,
  onChange,
  hint,
  error,
  min,
  max,
  required,
  disabled,
  className,
  style,
}: DateTimeProps & { type: "date" | "time" | "datetime-local" }) {
  const id = useId();

  return (
    <Field label={label} htmlFor={id} hint={hint} error={error} required={required} className={className} style={style}>
      <input
        id={id}
        type={type}
        className="eos-input"
        value={value}
        min={min}
        max={max}
        required={required}
        disabled={disabled}
        aria-invalid={error ? true : undefined}
        onChange={(event) => onChange(event.target.value)}
      />
    </Field>
  );
}
