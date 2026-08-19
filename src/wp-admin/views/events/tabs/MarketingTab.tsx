import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Alert,
  Badge,
  Button,
  Card,
  ConfirmDialog,
  DataTable,
  DateTimePicker,
  Drawer,
  Grid,
  Input,
  LoadingState,
  Select,
  Stack,
  StatCard,
  Switch,
  Textarea,
  useToast,
  type DataTableColumn,
  type SelectOption,
} from "../../../ui";
import {
  crmApi,
  eventsApi,
  type AudiencePreviewPerson,
  type AudienceSegment,
  type AudienceType,
  type CampaignStatus,
  type DiscountCampaign,
  type DiscountType,
  type PromoLink,
} from "../../../api";
import { errorMessage, formatDateTime, fromLocalInput, toLocalInput } from "../shared";

interface Props {
  eventId: number;
}

const DISCOUNT_TYPE_OPTIONS: SelectOption[] = [
  { value: "percent", label: "Percentage discount" },
  { value: "fixed", label: "Fixed amount discount" },
];

const CAMPAIGN_STATUS_OPTIONS: SelectOption[] = [
  { value: "draft", label: "Draft" },
  { value: "active", label: "Active" },
  { value: "paused", label: "Paused" },
  { value: "archived", label: "Archived" },
];

// Event-scoped types use the current event implicitly; the rest describe a
// person's brand-wide relationship/behaviour and are therefore created as
// global audiences (reusable from every event's Marketing tab) even when
// the operator starts from this one — see Audience_Repository's own event_id
// handling for the backend half of this.
const AUDIENCE_TYPE_OPTIONS: SelectOption[] = [
  { value: "all", label: "All known people" },
  { value: "event_purchasers", label: "This event — purchasers" },
  { value: "event_ticket_type", label: "This event — ticket-type purchasers" },
  { value: "event_attendees", label: "This event — attendees (checked in)" },
  { value: "event_non_attendees", label: "This event — non-attendees" },
  { value: "repeat_customers", label: "Repeat customers (2+ tickets)" },
  { value: "high_value", label: "High-value customers (spend threshold)" },
  { value: "recent_purchasers", label: "Recent purchasers" },
  { value: "lapsed_customers", label: "Lapsed customers" },
  { value: "segment", label: "Existing CRM segment" },
];

const EVENT_SCOPED_TYPES: AudienceType[] = [
  "event_purchasers",
  "event_ticket_type",
  "event_attendees",
  "event_non_attendees",
];

function statusTone(status: CampaignStatus): "success" | "warning" | "neutral" | "danger" {
  const map: Record<CampaignStatus, "success" | "warning" | "neutral" | "danger"> = {
    active: "success",
    draft: "neutral",
    paused: "warning",
    expired: "neutral",
    archived: "neutral",
  };
  return map[status] ?? "neutral";
}

interface CampaignFormState {
  name: string;
  code: string;
  type: DiscountType;
  value: string;
  min_spend: string;
  max_uses: string;
  expires_at: string;
  applies_to: "all" | "specific_types";
  status: CampaignStatus;
  audience_id: string;
}

const defaultCampaignForm = (): CampaignFormState => ({
  name: "",
  code: "",
  type: "percent",
  value: "",
  min_spend: "",
  max_uses: "",
  expires_at: "",
  applies_to: "all",
  status: "draft",
  audience_id: "",
});

function fromCampaign(c: DiscountCampaign): CampaignFormState {
  return {
    name: c.name,
    code: c.code,
    type: c.type,
    value: String(c.value),
    min_spend: c.min_spend != null ? String(c.min_spend) : "",
    max_uses: c.max_uses != null ? String(c.max_uses) : "",
    expires_at: toLocalInput(c.expires_at),
    applies_to: c.applies_to,
    // draft/active/paused/archived only — "expired" is a computed display
    // status (see Campaign_Status::effective()), never a value this form
    // should try to set directly.
    status: c.status === "expired" ? "active" : c.status,
    audience_id: c.audience_id != null ? String(c.audience_id) : "",
  };
}

function CampaignDrawer({
  eventId,
  editing,
  audienceOptions,
  onClose,
}: {
  eventId: number;
  editing: DiscountCampaign | null;
  audienceOptions: SelectOption[];
  onClose: () => void;
}) {
  const toast = useToast();
  const qc = useQueryClient();
  const [form, setForm] = useState<CampaignFormState>(
    editing ? fromCampaign(editing) : defaultCampaignForm(),
  );

  const set = <K extends keyof CampaignFormState>(k: K, v: CampaignFormState[K]) =>
    setForm((f) => ({ ...f, [k]: v }));

  const payload = () => ({
    name: form.name,
    code: form.code.toUpperCase().trim(),
    type: form.type,
    value: Number(form.value) || 0,
    min_spend: form.min_spend ? Number(form.min_spend) : null,
    max_uses: form.max_uses ? Number(form.max_uses) : null,
    expires_at: fromLocalInput(form.expires_at),
    applies_to: form.applies_to,
    // Sent explicitly on every save (create AND edit) so editing a campaign
    // without touching status can never silently reset it — see
    // Campaign_Repository::sanitize()'s $default_status parameter for the
    // matching backend half of this fix.
    status: form.status,
    audience_id: form.audience_id ? Number(form.audience_id) : null,
  });

  const save = useMutation({
    mutationFn: () =>
      editing
        ? eventsApi.updateDiscountCampaign(eventId, editing.id, payload())
        : eventsApi.createDiscountCampaign(eventId, payload()),
    onSuccess: () => {
      toast.success(editing ? "Campaign updated." : "Campaign created.", "Saved");
      void qc.invalidateQueries({ queryKey: ["eventos", "marketing", "campaigns", eventId] });
      onClose();
    },
    onError: (err: unknown) => toast.error(errorMessage(err), "Save failed"),
  });

  return (
    <Drawer
      open
      onClose={onClose}
      title={editing ? `Edit: ${editing.name}` : "New discount campaign"}
      description="Discount codes sync to WooCommerce coupons automatically."
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>
            {editing ? "Save changes" : "Create campaign"}
          </Button>
        </>
      }
    >
      <Stack>
        <Input
          label="Campaign name"
          required
          value={form.name}
          onChange={(e) => set("name", e.target.value)}
          placeholder="e.g. Early Bird Discount"
        />
        <Grid minColumnWidth={160}>
          <Input
            label="Discount code"
            required
            value={form.code}
            onChange={(e) => set("code", e.target.value.toUpperCase())}
            placeholder="EARLYBIRD20"
          />
          <Select
            label="Discount type"
            value={form.type}
            options={DISCOUNT_TYPE_OPTIONS}
            onChange={(e) => set("type", e.target.value as DiscountType)}
          />
        </Grid>
        <Grid minColumnWidth={160}>
          <Input
            label={form.type === "percent" ? "Discount (%)" : "Discount amount (R)"}
            type="number"
            min={0}
            step={form.type === "percent" ? "1" : "0.01"}
            max={form.type === "percent" ? 100 : undefined}
            required
            value={form.value}
            onChange={(e) => set("value", e.target.value)}
            placeholder={form.type === "percent" ? "20" : "50.00"}
          />
          <Input
            label="Min order spend (R)"
            type="number"
            min={0}
            step="0.01"
            value={form.min_spend}
            onChange={(e) => set("min_spend", e.target.value)}
            placeholder="No minimum"
          />
        </Grid>
        <Grid minColumnWidth={160}>
          <Input
            label="Usage limit"
            type="number"
            min={0}
            value={form.max_uses}
            onChange={(e) => set("max_uses", e.target.value)}
            placeholder="Unlimited"
          />
          <DateTimePicker
            label="Expires"
            value={form.expires_at}
            onChange={(v) => set("expires_at", v)}
          />
        </Grid>
        <Select
          label="Applies to"
          value={form.applies_to}
          options={[
            { value: "all", label: "All ticket types" },
            { value: "specific_types", label: "Specific ticket types" },
          ]}
          onChange={(e) => set("applies_to", e.target.value as "all" | "specific_types")}
        />
        {form.applies_to === "specific_types" && (
          <Alert tone="info" title="Ticket type selection">
            After creating the campaign, edit it to select specific ticket types.
          </Alert>
        )}
        <Select
          label="Status"
          value={form.status}
          options={CAMPAIGN_STATUS_OPTIONS}
          onChange={(e) => set("status", e.target.value as CampaignStatus)}
          hint="Active publishes the linked WooCommerce coupon so the code works at checkout."
        />
        <Select
          label="Audience"
          value={form.audience_id}
          options={[{ value: "", label: "No audience — coupon only" }, ...audienceOptions]}
          onChange={(e) => set("audience_id", e.target.value)}
          hint="Who this campaign is for. Sending isn't built yet — this just records the intended audience."
        />
      </Stack>
    </Drawer>
  );
}

interface PromoLinkFormState {
  label: string;
  url: string;
  utm_source: string;
  utm_medium: string;
  utm_campaign: string;
}

function PromoLinkDrawer({ eventId, onClose }: { eventId: number; onClose: () => void }) {
  const toast = useToast();
  const qc = useQueryClient();
  const [form, setForm] = useState<PromoLinkFormState>({
    label: "",
    url: "",
    utm_source: "",
    utm_medium: "promo-link",
    utm_campaign: "",
  });

  const set = <K extends keyof PromoLinkFormState>(k: K, v: PromoLinkFormState[K]) =>
    setForm((f) => ({ ...f, [k]: v }));

  const save = useMutation({
    mutationFn: () =>
      eventsApi.createPromoLink(eventId, form as unknown as Record<string, unknown>),
    onSuccess: () => {
      toast.success("Promotional link created.", "Created");
      void qc.invalidateQueries({ queryKey: ["eventos", "marketing", "links", eventId] });
      onClose();
    },
    onError: (err: unknown) => toast.error(errorMessage(err), "Failed"),
  });

  return (
    <Drawer
      open
      onClose={onClose}
      title="New promotional link"
      description="Track clicks from different sources using UTM parameters."
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>
            Create link
          </Button>
        </>
      }
    >
      <Stack>
        <Input
          label="Label"
          required
          value={form.label}
          onChange={(e) => set("label", e.target.value)}
          placeholder="e.g. Instagram bio link"
        />
        <Input
          label="Destination URL"
          type="url"
          required
          value={form.url}
          onChange={(e) => set("url", e.target.value)}
          placeholder="https://…"
        />
        <Grid minColumnWidth={160}>
          <Input
            label="UTM source"
            value={form.utm_source}
            onChange={(e) => set("utm_source", e.target.value)}
            placeholder="instagram"
          />
          <Input
            label="UTM medium"
            value={form.utm_medium}
            onChange={(e) => set("utm_medium", e.target.value)}
            placeholder="social"
          />
        </Grid>
        <Input
          label="UTM campaign"
          value={form.utm_campaign}
          onChange={(e) => set("utm_campaign", e.target.value)}
          placeholder="event-2025"
        />
      </Stack>
    </Drawer>
  );
}

interface AudienceFormState {
  name: string;
  description: string;
  type: AudienceType;
  ticket_type_id: string;
  min_spend: string;
  days: string;
  segment_id: string;
}

const defaultAudienceForm = (): AudienceFormState => ({
  name: "",
  description: "",
  type: "event_purchasers",
  ticket_type_id: "",
  min_spend: "",
  days: "30",
  segment_id: "",
});

function AudienceDrawer({
  eventId,
  ticketTypeOptions,
  segmentOptions,
  onClose,
}: {
  eventId: number;
  ticketTypeOptions: SelectOption[];
  segmentOptions: SelectOption[];
  onClose: () => void;
}) {
  const toast = useToast();
  const qc = useQueryClient();
  const [form, setForm] = useState<AudienceFormState>(defaultAudienceForm());
  const [previewedId, setPreviewedId] = useState<number | null>(null);

  const set = <K extends keyof AudienceFormState>(k: K, v: AudienceFormState[K]) =>
    setForm((f) => ({ ...f, [k]: v }));

  const isEventScoped = EVENT_SCOPED_TYPES.includes(form.type);

  const criteria = (): Record<string, unknown> => {
    switch (form.type) {
      case "event_ticket_type":
        return { ticket_type_id: Number(form.ticket_type_id) || 0 };
      case "high_value":
        return { min_spend: Number(form.min_spend) || 0 };
      case "recent_purchasers":
      case "lapsed_customers":
        return { days: Number(form.days) || 0 };
      case "segment":
        return { segment_id: Number(form.segment_id) || 0 };
      default:
        return {};
    }
  };

  const save = useMutation({
    mutationFn: () =>
      eventsApi.createAudience({
        name: form.name,
        description: form.description,
        type: form.type,
        event_id: isEventScoped ? eventId : null,
        criteria: criteria(),
      }),
    onSuccess: (created) => {
      toast.success("Audience created.", "Saved");
      void qc.invalidateQueries({ queryKey: ["eventos", "marketing", "audiences", eventId] });
      setPreviewedId(created.id);
    },
    onError: (err: unknown) => toast.error(errorMessage(err), "Save failed"),
  });

  const preview = useQuery({
    queryKey: ["eventos", "marketing", "audience-preview", previewedId],
    queryFn: () => eventsApi.audiencePreview(previewedId as number),
    enabled: previewedId !== null,
  });

  return (
    <Drawer
      open
      onClose={onClose}
      title="New audience"
      description="An audience is a live rule against Audience CRM — it always reflects who currently matches, not a fixed list."
      footer={
        previewedId === null ? (
          <>
            <Button onClick={onClose}>Cancel</Button>
            <Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>
              Create audience
            </Button>
          </>
        ) : (
          <Button variant="primary" onClick={onClose}>
            Done
          </Button>
        )
      }
    >
      {previewedId === null ? (
        <Stack>
          <Input
            label="Audience name"
            required
            value={form.name}
            onChange={(e) => set("name", e.target.value)}
            placeholder="e.g. Repeat customers"
          />
          <Textarea
            label="Description"
            value={form.description}
            onChange={(e) => set("description", e.target.value)}
            placeholder="Optional — what this audience is for"
          />
          <Select
            label="Audience type"
            value={form.type}
            options={AUDIENCE_TYPE_OPTIONS}
            onChange={(e) => set("type", e.target.value as AudienceType)}
          />
          {isEventScoped && (
            <Alert tone="info" title="Scoped to this event">
              This audience will always resolve against <strong>this event only</strong>.
            </Alert>
          )}
          {form.type === "event_ticket_type" && (
            <Select
              label="Ticket type"
              required
              value={form.ticket_type_id}
              options={ticketTypeOptions}
              onChange={(e) => set("ticket_type_id", e.target.value)}
            />
          )}
          {form.type === "high_value" && (
            <Input
              label="Minimum lifetime spend (R)"
              type="number"
              min={0}
              step="0.01"
              required
              value={form.min_spend}
              onChange={(e) => set("min_spend", e.target.value)}
              placeholder="1000"
            />
          )}
          {(form.type === "recent_purchasers" || form.type === "lapsed_customers") && (
            <Input
              label={
                form.type === "recent_purchasers"
                  ? "Purchased within the last (days)"
                  : "No purchase in the last (days)"
              }
              type="number"
              min={1}
              required
              value={form.days}
              onChange={(e) => set("days", e.target.value)}
              placeholder="30"
            />
          )}
          {form.type === "segment" && (
            <Select
              label="CRM segment"
              required
              value={form.segment_id}
              options={segmentOptions}
              onChange={(e) => set("segment_id", e.target.value)}
            />
          )}
        </Stack>
      ) : (
        <Stack>
          <Alert tone="success" title="Audience created">
            {form.name}
          </Alert>
          {preview.isLoading ? (
            <LoadingState label="Resolving audience…" />
          ) : preview.data ? (
            <Stack>
              <StatCard label="Estimated audience" value={preview.data.count.toLocaleString()} />
              <div>
                <strong>Preview</strong>
                {preview.data.preview.length === 0 ? (
                  <p className="eos-page__description">No people currently match this audience.</p>
                ) : (
                  <ul>
                    {preview.data.preview.map((person: AudiencePreviewPerson) => (
                      <li key={person.person_id}>
                        {person.display_name ||
                          person.primary_email ||
                          `Person #${person.person_id}`}
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            </Stack>
          ) : null}
        </Stack>
      )}
    </Drawer>
  );
}

export function MarketingTab({ eventId }: Props) {
  const toast = useToast();
  const qc = useQueryClient();
  const [campaignDrawer, setCampaignDrawer] = useState<DiscountCampaign | null | "new">(null);
  const [linkDrawer, setLinkDrawer] = useState(false);
  const [audienceDrawer, setAudienceDrawer] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState<{
    type: "campaign" | "link" | "audience";
    id: number;
    name: string;
  } | null>(null);

  const campaigns = useQuery({
    queryKey: ["eventos", "marketing", "campaigns", eventId],
    queryFn: () => eventsApi.discountCampaigns(eventId),
  });

  const links = useQuery({
    queryKey: ["eventos", "marketing", "links", eventId],
    queryFn: () => eventsApi.promoLinks(eventId),
  });

  const audiences = useQuery({
    queryKey: ["eventos", "marketing", "audiences", eventId],
    queryFn: () => eventsApi.audiences(eventId),
    retry: false,
  });

  const ticketTypes = useQuery({
    queryKey: ["eventos", "ticketing", "ticket-types", eventId],
    queryFn: () => eventsApi.ticketTypes(eventId),
  });

  const segments = useQuery({
    queryKey: ["eventos", "crm", "segments-for-audience"],
    queryFn: () => crmApi.segments(),
  });

  const removeItem = useMutation({
    mutationFn: ({ type, id }: { type: "campaign" | "link" | "audience"; id: number }) => {
      if (type === "campaign") return eventsApi.removeDiscountCampaign(eventId, id);
      if (type === "link") return eventsApi.removePromoLink(eventId, id);
      return eventsApi.archiveAudience(id).then(() => ({ deleted: true }));
    },
    onSuccess: (_, { type }) => {
      const label = type === "campaign" ? "Campaign" : type === "link" ? "Link" : "Audience";
      toast.success(`${label} deleted.`, "Deleted");
      void qc.invalidateQueries({
        queryKey: [
          "eventos",
          "marketing",
          type === "campaign" ? "campaigns" : type === "link" ? "links" : "audiences",
          eventId,
        ],
      });
      setDeleteTarget(null);
    },
    onError: (err: unknown) => toast.error(errorMessage(err), "Delete failed"),
  });

  const campaignList = campaigns.data?.campaigns ?? [];
  const linkList = links.data?.links ?? [];
  const audienceList = audiences.data?.audiences ?? [];
  const ticketTypeOptions: SelectOption[] = (ticketTypes.data?.ticket_types ?? []).map((t) => ({
    value: String(t.id),
    label: t.name,
  }));
  const segmentOptions: SelectOption[] = (segments.data?.segments ?? []).map((s) => ({
    value: String(s.id),
    label: s.name,
  }));
  const audienceOptions: SelectOption[] = audienceList.map((a) => ({
    value: String(a.id),
    label: a.name,
  }));

  const activeCampaigns = campaignList.filter((c) => c.status === "active").length;
  const totalUses = campaignList.reduce((acc, c) => acc + c.uses, 0);
  const totalClicks = linkList.reduce((acc, l) => acc + l.clicks, 0);

  const campaignColumns: DataTableColumn<DiscountCampaign>[] = [
    {
      key: "name",
      header: "Campaign",
      cell: (row) => (
        <Stack>
          <strong>{row.name}</strong>
          <code
            style={{
              fontSize: "0.8em",
              background: "var(--eos-surface-muted)",
              padding: "1px 4px",
              borderRadius: 3,
            }}
          >
            {row.code}
          </code>
        </Stack>
      ),
    },
    {
      key: "type",
      header: "Discount",
      cell: (row) => <span>{row.type === "percent" ? `${row.value}%` : `R${row.value}`}</span>,
    },
    {
      key: "status",
      header: "Status",
      cell: (row) => <Badge tone={statusTone(row.status)}>{row.status}</Badge>,
    },
    {
      key: "audience_id",
      header: "Audience",
      cell: (row) => {
        const audience = audienceList.find((a) => a.id === row.audience_id);
        return audience ? audience.name : <span className="eos-page__description">—</span>;
      },
    },
    {
      key: "uses",
      header: "Uses",
      cell: (row) => (
        <span>
          {row.uses.toLocaleString()}
          {row.max_uses != null && (
            <span className="eos-page__description"> / {row.max_uses.toLocaleString()}</span>
          )}
        </span>
      ),
    },
    {
      key: "expires_at",
      header: "Expires",
      cell: (row) => (row.expires_at ? formatDateTime(row.expires_at) : "No expiry"),
    },
    {
      key: "wc_coupon_id",
      header: "WC coupon",
      cell: (row) =>
        row.wc_coupon_id ? (
          <a
            href={`/wp-admin/post.php?post=${row.wc_coupon_id}&action=edit`}
            target="_blank"
            rel="noreferrer"
            className="eos-btn eos-btn--link"
          >
            #{row.wc_coupon_id}
          </a>
        ) : (
          <span className="eos-page__description">Pending</span>
        ),
    },
    {
      key: "id",
      header: "",
      cell: (row) => (
        <div className="eos-inline">
          <Button size="sm" onClick={() => setCampaignDrawer(row)}>
            Edit
          </Button>
          <Button
            size="sm"
            variant="danger"
            onClick={() => setDeleteTarget({ type: "campaign", id: row.id, name: row.name })}
          >
            Delete
          </Button>
        </div>
      ),
    },
  ];

  const linkColumns: DataTableColumn<PromoLink>[] = [
    {
      key: "label",
      header: "Label",
      cell: (row) => <strong>{row.label}</strong>,
    },
    {
      key: "utm_source",
      header: "Source",
      cell: (row) => row.utm_source || "—",
    },
    {
      key: "utm_medium",
      header: "Medium",
      cell: (row) => row.utm_medium || "—",
    },
    {
      key: "clicks",
      header: "Clicks",
      cell: (row) => row.clicks.toLocaleString(),
    },
    {
      key: "url",
      header: "URL",
      cell: (row) => (
        <a href={row.url} target="_blank" rel="noreferrer" className="eos-btn eos-btn--link">
          Open ↗
        </a>
      ),
    },
    {
      key: "created_at",
      header: "Created",
      cell: (row) => formatDateTime(row.created_at),
    },
    {
      key: "id",
      header: "",
      cell: (row) => (
        <Button
          size="sm"
          variant="danger"
          onClick={() => setDeleteTarget({ type: "link", id: row.id, name: row.label })}
        >
          Delete
        </Button>
      ),
    },
  ];

  const audienceColumns: DataTableColumn<AudienceSegment>[] = [
    { key: "name", header: "Audience", cell: (row) => <strong>{row.name}</strong> },
    {
      key: "type",
      header: "Type",
      cell: (row) => AUDIENCE_TYPE_OPTIONS.find((o) => o.value === row.type)?.label ?? row.type,
    },
    {
      key: "scope",
      header: "Scope",
      cell: (row) => (row.event_id ? "This event" : "Global"),
    },
    {
      key: "count",
      header: "Estimated size",
      cell: (row) => (row.count ?? 0).toLocaleString(),
    },
    {
      key: "id",
      header: "",
      cell: (row) => (
        <Button
          size="sm"
          variant="danger"
          onClick={() => setDeleteTarget({ type: "audience", id: row.id, name: row.name })}
        >
          Archive
        </Button>
      ),
    },
  ];

  return (
    <Stack>
      {/* Stats */}
      <Grid minColumnWidth={160}>
        <StatCard
          label="Campaigns"
          value={campaignList.length}
          hint={`${activeCampaigns} active`}
        />
        <StatCard label="Discount uses" value={totalUses.toLocaleString()} />
        <StatCard label="Promo links" value={linkList.length} hint={`${totalClicks} clicks`} />
        <StatCard label="Audiences" value={audienceList.length} />
      </Grid>

      <Alert tone="info" title="WooCommerce coupons">
        Discount campaigns automatically create and sync WooCommerce coupons. All checkout
        validation and redemption is handled by WooCommerce. EventOS tracks campaign usage per
        event.
      </Alert>

      {/* Audiences */}
      <Card
        title="Audiences"
        actions={
          <Button variant="primary" onClick={() => setAudienceDrawer(true)}>
            New audience
          </Button>
        }
      >
        {audiences.isLoading ? (
          <LoadingState label="Loading audiences…" />
        ) : audiences.error ? (
          <Alert tone="danger" title="Could not load audiences">
            {errorMessage(audiences.error)}
          </Alert>
        ) : (
          <DataTable
            caption="Marketing audiences available to this event"
            columns={audienceColumns}
            rows={audienceList}
            getRowId={(row) => String(row.id)}
            emptyTitle="No audiences yet"
            emptyDescription="Define who a campaign is for — resolved live from Audience CRM, never a fixed list."
          />
        )}
      </Card>

      {/* Discount campaigns */}
      <Card
        title="Discount campaigns"
        actions={
          <Button variant="primary" onClick={() => setCampaignDrawer("new")}>
            New campaign
          </Button>
        }
      >
        {campaigns.isLoading ? (
          <LoadingState label="Loading campaigns…" />
        ) : campaigns.error ? (
          <Alert tone="danger" title="Could not load campaigns">
            {errorMessage(campaigns.error)}
          </Alert>
        ) : (
          <DataTable
            caption="Discount campaigns for this event"
            columns={campaignColumns}
            rows={campaignList}
            getRowId={(row) => String(row.id)}
            emptyTitle="No campaigns yet"
            emptyDescription="Create a discount campaign to generate a WooCommerce coupon linked to this event."
          />
        )}
      </Card>

      {/* Promo links */}
      <Card
        title="Promotional links"
        actions={
          <Button variant="primary" onClick={() => setLinkDrawer(true)}>
            New link
          </Button>
        }
      >
        {links.isLoading ? (
          <LoadingState label="Loading links…" />
        ) : links.error ? (
          <Alert tone="danger" title="Could not load links">
            {errorMessage(links.error)}
          </Alert>
        ) : (
          <DataTable
            caption="Promotional links for this event"
            columns={linkColumns}
            rows={linkList}
            getRowId={(row) => String(row.id)}
            emptyTitle="No links yet"
            emptyDescription="Create trackable promotional links to measure traffic from different channels."
          />
        )}
      </Card>

      {/* Drawers */}
      {campaignDrawer !== null && (
        <CampaignDrawer
          eventId={eventId}
          editing={campaignDrawer === "new" ? null : campaignDrawer}
          audienceOptions={audienceOptions}
          onClose={() => setCampaignDrawer(null)}
        />
      )}
      {linkDrawer && <PromoLinkDrawer eventId={eventId} onClose={() => setLinkDrawer(false)} />}
      {audienceDrawer && (
        <AudienceDrawer
          eventId={eventId}
          ticketTypeOptions={ticketTypeOptions}
          segmentOptions={segmentOptions}
          onClose={() => setAudienceDrawer(false)}
        />
      )}

      {/* Delete confirm */}
      <ConfirmDialog
        open={deleteTarget !== null}
        title={`${deleteTarget?.type === "audience" ? "Archive" : "Delete"} "${deleteTarget?.name}"?`}
        description={
          deleteTarget?.type === "campaign"
            ? "This will also archive the linked WooCommerce coupon."
            : deleteTarget?.type === "audience"
              ? "Archived audiences stop appearing in pickers, but any campaign already referencing this one keeps working."
              : "This cannot be undone."
        }
        confirmLabel={deleteTarget?.type === "audience" ? "Archive" : "Delete"}
        destructive
        busy={removeItem.isPending}
        onCancel={() => setDeleteTarget(null)}
        onConfirm={() => {
          if (deleteTarget) removeItem.mutate({ type: deleteTarget.type, id: deleteTarget.id });
        }}
      />
    </Stack>
  );
}
