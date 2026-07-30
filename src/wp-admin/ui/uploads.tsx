import { useRef, useState, type ChangeEvent, type ReactNode } from "react";
import { Button } from "./buttons";
import { Field } from "./forms";
import { cx, formatBytes, type StyleProps } from "./utils";

export interface MediaValue {
  id: number;
  url: string;
  filename?: string;
  filesize?: number;
}

export interface MediaUploaderProps extends StyleProps {
  label: ReactNode;
  value: MediaValue | null;
  onChange: (value: MediaValue | null) => void;
  /** Opens the host media library and resolves with the chosen attachment. */
  onBrowse: () => Promise<MediaValue | null>;
  hint?: ReactNode;
  error?: ReactNode;
  previewHeight?: number;
  buttonLabel?: string;
}

/** Generic media picker backed by the host media library. */
export function MediaUploader({
  label,
  value,
  onChange,
  onBrowse,
  hint,
  error,
  previewHeight = 56,
  buttonLabel = "Select image",
  className,
  style,
}: MediaUploaderProps) {
  const [busy, setBusy] = useState(false);

  const browse = async () => {
    setBusy(true);
    try {
      const selected = await onBrowse();
      if (selected) onChange(selected);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Field label={label} hint={hint} error={error} labelAs="span" className={className} style={style}>
      <div className="eos-uploader">
        {value?.url ? (
          <img src={value.url} alt="" className="eos-uploader__preview" style={{ maxHeight: previewHeight }} />
        ) : (
          <span className="eos-uploader__placeholder" style={{ height: previewHeight }}>
            No image
          </span>
        )}
        <div className="eos-inline">
          <Button variant="secondary" loading={busy} onClick={() => void browse()}>
            {buttonLabel}
          </Button>
          {value ? (
            <Button variant="ghost" onClick={() => onChange(null)}>
              Remove
            </Button>
          ) : null}
        </div>
      </div>
    </Field>
  );
}

/** Preset for brand logos. */
export function LogoUploader(props: Omit<MediaUploaderProps, "buttonLabel" | "previewHeight">) {
  return <MediaUploader {...props} buttonLabel="Select logo" previewHeight={48} />;
}

/** Preset for general imagery. */
export function ImageUploader(props: Omit<MediaUploaderProps, "buttonLabel">) {
  return <MediaUploader buttonLabel="Select image" {...props} />;
}

export interface FileUploaderProps extends StyleProps {
  label: ReactNode;
  accept?: string;
  multiple?: boolean;
  maxSizeBytes?: number;
  hint?: ReactNode;
  error?: ReactNode;
  files: File[];
  onChange: (files: File[]) => void;
  disabled?: boolean;
}

/** Drag-and-drop file input used by importers and attachments. */
export function FileUploader({
  label,
  accept,
  multiple = false,
  maxSizeBytes,
  hint,
  error,
  files,
  onChange,
  disabled = false,
  className,
  style,
}: FileUploaderProps) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [dragging, setDragging] = useState(false);
  const [localError, setLocalError] = useState<string | null>(null);

  const acceptFiles = (incoming: FileList | null) => {
    if (!incoming?.length) return;
    const list = Array.from(incoming);
    const tooLarge = maxSizeBytes ? list.find((file) => file.size > maxSizeBytes) : undefined;
    if (tooLarge && maxSizeBytes) {
      setLocalError(`${tooLarge.name} exceeds the ${formatBytes(maxSizeBytes)} limit.`);
      return;
    }
    setLocalError(null);
    onChange(multiple ? [...files, ...list] : list.slice(0, 1));
  };

  return (
    <Field label={label} hint={hint} error={error ?? localError} labelAs="span" className={className} style={style}>
      <div
        className={cx("eos-dropzone", dragging && "is-dragging", disabled && "is-disabled")}
        onDragOver={(event) => {
          event.preventDefault();
          if (!disabled) setDragging(true);
        }}
        onDragLeave={() => setDragging(false)}
        onDrop={(event) => {
          event.preventDefault();
          setDragging(false);
          if (!disabled) acceptFiles(event.dataTransfer.files);
        }}
      >
        <p className="eos-dropzone__text">Drag files here or</p>
        <Button variant="secondary" size="sm" disabled={disabled} onClick={() => inputRef.current?.click()}>
          Browse files
        </Button>
        <input
          ref={inputRef}
          type="file"
          className="eos-visually-hidden"
          accept={accept}
          multiple={multiple}
          disabled={disabled}
          onChange={(event: ChangeEvent<HTMLInputElement>) => acceptFiles(event.target.files)}
        />
      </div>
      {files.length ? (
        <ul className="eos-file-list">
          {files.map((file, index) => (
            <li key={`${file.name}-${index}`}>
              <span>{file.name}</span>
              <span className="eos-field__hint">{formatBytes(file.size)}</span>
              <Button
                variant="ghost"
                size="sm"
                iconOnly
                aria-label={`Remove ${file.name}`}
                onClick={() => onChange(files.filter((_, position) => position !== index))}
              >
                ×
              </Button>
            </li>
          ))}
        </ul>
      ) : null}
    </Field>
  );
}
