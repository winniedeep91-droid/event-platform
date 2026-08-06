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
  eventsApi,
  type AudienceSegment,
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
  };
}

function CampaignDrawer({
  eventId,
  editing,
  onClose,
}: {
  eventId: number;
  editing: DiscountCampaign | null;
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

export function MarketingTab({ eventId }: Props) {
  const toast = useToast();
  const qc = useQueryClient();
  const [campaignDrawer, setCampaignDrawer] = useState<DiscountCampaign | null | "new">(null);
  const [linkDrawer, setLinkDrawer] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState<{
    type: "campaign" | "link";
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

  const removeItem = useMutation({
    mutationFn: ({ type, id }: { type: "campaign" | "link"; id: number }) =>
      type === "campaign"
        ? eventsApi.removeDiscountCampaign(eventId, id)
        : eventsApi.removePromoLink(eventId, id),
    onSuccess: (_, { type }) => {
      toast.success(`${type === "campaign" ? "Campaign" : "Link"} deleted.`, "Deleted");
      void qc.invalidateQueries({
        queryKey: ["eventos", "marketing", type === "campaign" ? "campaigns" : "links", eventId],
      });
      setDeleteTarget(null);
    },
    onError: (err: unknown) => toast.error(errorMessage(err), "Delete failed"),
  });

  const campaignList = campaigns.data?.campaigns ?? [];
  const linkList = links.data?.links ?? [];
  const audienceList = audiences.data?.audiences ?? [];

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
        {audienceList.length > 0 && <StatCard label="Audiences" value={audienceList.length} />}
      </Grid>

      <Alert tone="info" title="WooCommerce coupons">
        Discount campaigns automatically create and sync WooCommerce coupons. All checkout
        validation and redemption is handled by WooCommerce. EventOS tracks campaign usage per
        event.
      </Alert>

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

      {/* Audience segments */}
      {audienceList.length > 0 && (
        <Card title="Audience segments">
          <DataTable
            caption="Audience segments for this event"
            columns={[
              { key: "name", header: "Segment", cell: (row) => <strong>{row.name}</strong> },
              { key: "description", header: "Description", cell: (row) => row.description || "—" },
              { key: "count", header: "Guests", cell: (row) => row.count.toLocaleString() },
            ]}
            rows={audienceList}
            getRowId={(row) => String(row.id)}
            emptyTitle="No segments"
          />
        </Card>
      )}

      {/* Drawers */}
      {campaignDrawer !== null && (
        <CampaignDrawer
          eventId={eventId}
          editing={campaignDrawer === "new" ? null : campaignDrawer}
          onClose={() => setCampaignDrawer(null)}
        />
      )}
      {linkDrawer && <PromoLinkDrawer eventId={eventId} onClose={() => setLinkDrawer(false)} />}

      {/* Delete confirm */}
      <ConfirmDialog
        open={deleteTarget !== null}
        title={`Delete "${deleteTarget?.name}"?`}
        description={
          deleteTarget?.type === "campaign"
            ? "This will also archive the linked WooCommerce coupon."
            : "This cannot be undone."
        }
        confirmLabel="Delete"
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
