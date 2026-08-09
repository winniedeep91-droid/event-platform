/**
 * Schema-driven settings form shared by the organisation settings and
 * branding screens. Fields are rendered from the REST settings schema using
 * the shared EventOS component library.
 */
import { useEffect, useState } from "react";
import {
  selectAttachment,
  type SettingsField,
  type SettingsGroup,
} from "../../api";
import {
  Button,
  Grid,
  Input,
  MediaUploader,
  Select,
  Stack,
  Switch,
  type MediaValue,
} from "../../ui";

export type SettingsValues = Record<string, unknown>;

function toNumber(value: string): number {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : 0;
}

function SettingsControl({
  field,
  value,
  media,
  onChange,
  onMedia,
}: {
  field: SettingsField;
  value: unknown;
  media: MediaValue | null;
  onChange: (value: unknown) => void;
  onMedia: (value: MediaValue | null) => void;
}) {
  if (field.type === "boolean") {
    return (
      <Switch
        label={field.label}
        checked={Boolean(value)}
        onChange={(checked) => onChange(checked)}
      />
    );
  }

  if (field.type === "choice") {
    return (
      <Select
        label={field.label}
        value={String(value ?? "")}
        options={field.choices.map((choice) => ({ value: choice, label: choice }))}
        onChange={(event) => onChange(event.target.value)}
      />
    );
  }

  if (field.type === "attachment") {
    const id = Number(value ?? 0);

    return (
      <MediaUploader
        label={field.label}
        value={media ?? (id ? { id, url: "" } : null)}
        hint={id ? `Attachment #${id}` : "No image selected"}
        onChange={(next) => {
          onMedia(next);
          onChange(next?.id ?? 0);
        }}
        onBrowse={async () => {
          const picked = await selectAttachment(field.label);
          return picked ? { id: picked.id, url: picked.url } : null;
        }}
      />
    );
  }

  if (field.type === "color") {
    return (
      <Input
        type="color"
        label={field.label}
        value={String(value ?? "#000000")}
        onChange={(event) => onChange(event.target.value)}
      />
    );
  }

  if (field.type === "list") {
    return (
      <Input
        type="text"
        label={field.label}
        placeholder="example.com, partner.org"
        hint="Comma separated values."
        value={Array.isArray(value) ? (value as string[]).join(", ") : ""}
        onChange={(event) =>
          onChange(
            event.target.value
              .split(",")
              .map((item) => item.trim())
              .filter(Boolean),
          )
        }
      />
    );
  }

  const isNumber = field.type === "number" || field.type === "integer";
  const inputType =
    field.type === "email" ? "email" : field.type === "url" ? "url" : isNumber ? "number" : "text";

  return (
    <Input
      type={inputType}
      label={field.label}
      value={String(value ?? "")}
      onChange={(event) => onChange(isNumber ? toNumber(event.target.value) : event.target.value)}
    />
  );
}

export function SettingsForm({
  group,
  values,
  saving,
  onSubmit,
  minColumnWidth = 320,
}: {
  group: SettingsGroup;
  values: SettingsValues;
  saving: boolean;
  onSubmit: (values: SettingsValues) => void;
  minColumnWidth?: number;
}) {
  const [draft, setDraft] = useState<SettingsValues>(values);
  const [media, setMedia] = useState<Record<string, MediaValue | null>>({});

  useEffect(() => {
    setDraft(values);
  }, [values]);

  return (
    <form
      onSubmit={(event) => {
        event.preventDefault();
        onSubmit(draft);
      }}
    >
      <Stack>
        <Grid minColumnWidth={minColumnWidth}>
          {group.fields.map((field) => (
            <SettingsControl
              key={field.key}
              field={field}
              value={draft[field.key] ?? field.default}
              media={media[field.key] ?? null}
              onChange={(next) => setDraft((current) => ({ ...current, [field.key]: next }))}
              onMedia={(next) => setMedia((current) => ({ ...current, [field.key]: next }))}
            />
          ))}
        </Grid>
        <div className="eos-inline">
          <Button type="submit" variant="primary" loading={saving}>
            Save changes
          </Button>
          <Button type="button" variant="ghost" onClick={() => setDraft(values)} disabled={saving}>
            Reset
          </Button>
        </div>
      </Stack>
    </form>
  );
}
