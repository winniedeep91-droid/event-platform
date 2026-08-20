import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Alert,
  Badge,
  Button,
  Card,
  Checkbox,
  ConfirmDialog,
  DataTable,
  DateTimePicker,
  Drawer,
  Grid,
  Input,
  LoadingState,
  Modal,
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
  type ComplimentaryPayload,
  type TicketTier,
  type TicketTypeRecord,
  type TicketVisibility,
  type WaitlistEntryRecord,
  type WaitlistStatus,
} from "../../../api";
import { errorMessage, formatDateTime, toLocalInput, fromLocalInput } from "../shared";

interface Props {
  eventId: number;
}

const TIER_OPTIONS: SelectOption[] = [
  { value: "standard", label: "General Admission" },
  { value: "early_bird", label: "Early Bird" },
  { value: "vip", label: "VIP" },
  { value: "table", label: "Table" },
  { value: "backstage", label: "Backstage" },
  { value: "complimentary", label: "Complimentary" },
  { value: "custom", label: "Custom" },
];

const VISIBILITY_OPTIONS: SelectOption[] = [
  { value: "public", label: "Public" },
  { value: "private", label: "Private (link only)" },
  { value: "hidden", label: "Hidden" },
];

const TIER_TONE: Record<TicketTier, "neutral" | "primary" | "success" | "warning" | "info"> = {
  standard: "neutral",
  early_bird: "info",
  vip: "warning",
  table: "primary",
  backstage: "warning",
  complimentary: "success",
  custom: "neutral",
};

interface TicketTypeFormState {
  name: string;
  description: string;
  tier: TicketTier;
  price: string;
  capacity: string;
  visibility: TicketVisibility;
  sale_start: string;
  sale_end: string;
  min_per_order: string;
  max_per_order: string;
  waitlist_enabled: boolean;
}

const defaultForm = (): TicketTypeFormState => ({
  name: "",
  description: "",
  tier: "standard",
  price: "",
  capacity: "",
  visibility: "public",
  sale_start: "",
  sale_end: "",
  min_per_order: "1",
  max_per_order: "",
  waitlist_enabled: false,
});

function fromRecord(r: TicketTypeRecord): TicketTypeFormState {
  return {
    name: r.name,
    description: r.description,
    tier: r.tier,
    price: String(r.price),
    capacity: r.capacity != null ? String(r.capacity) : "",
    visibility: r.visibility,
    sale_start: toLocalInput(r.sale_start),
    sale_end: toLocalInput(r.sale_end),
    min_per_order: String(r.min_per_order),
    max_per_order: r.max_per_order != null ? String(r.max_per_order) : "",
    waitlist_enabled: r.waitlist_enabled,
  };
}

function TicketTypeDrawer({
  eventId,
  editing,
  onClose,
}: {
  eventId: number;
  editing: TicketTypeRecord | null;
  onClose: () => void;
}) {
  const toast = useToast();
  const qc = useQueryClient();
  const [form, setForm] = useState<TicketTypeFormState>(
    editing ? fromRecord(editing) : defaultForm(),
  );

  const set = <K extends keyof TicketTypeFormState>(k: K, v: TicketTypeFormState[K]) =>
    setForm((f) => ({ ...f, [k]: v }));

  const payload = () => ({
    name: form.name,
    description: form.description,
    tier: form.tier,
    price: Number(form.price) || 0,
    capacity: form.capacity ? Number(form.capacity) : null,
    visibility: form.visibility,
    sale_start: fromLocalInput(form.sale_start),
    sale_end: fromLocalInput(form.sale_end),
    min_per_order: Number(form.min_per_order) || 1,
    max_per_order: form.max_per_order ? Number(form.max_per_order) : null,
    waitlist_enabled: form.waitlist_enabled,
  });

  const save = useMutation({
    mutationFn: () =>
      editing
        ? eventsApi.updateTicketType(eventId, editing.id, payload())
        : eventsApi.createTicketType(eventId, payload()),
    onSuccess: () => {
      toast.success(editing ? "Ticket type updated." : "Ticket type created.", "Saved");
      void qc.invalidateQueries({ queryKey: ["eventos", "ticket-types", eventId] });
      onClose();
    },
    onError: (err: unknown) => toast.error(errorMessage(err), "Save failed"),
  });

  return (
    <Drawer
      open
      onClose={onClose}
      title={editing ? `Edit: ${editing.name}` : "New ticket type"}
      description="Ticket types map to WooCommerce products automatically."
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>
            {editing ? "Save changes" : "Create ticket type"}
          </Button>
        </>
      }
    >
      <Stack>
        <Input
          label="Name"
          required
          value={form.name}
          onChange={(e) => set("name", e.target.value)}
          placeholder="e.g. General Admission"
        />
        <Textarea
          label="Description"
          rows={2}
          value={form.description}
          onChange={(e) => set("description", e.target.value)}
        />
        <Grid minColumnWidth={160}>
          <Select
            label="Tier"
            value={form.tier}
            options={TIER_OPTIONS}
            onChange={(e) => set("tier", e.target.value as TicketTier)}
          />
          <Select
            label="Visibility"
            value={form.visibility}
            options={VISIBILITY_OPTIONS}
            onChange={(e) => set("visibility", e.target.value as TicketVisibility)}
          />
        </Grid>
        <Grid minColumnWidth={160}>
          <Input
            label="Price (R)"
            type="number"
            min={0}
            step="0.01"
            value={form.price}
            onChange={(e) => set("price", e.target.value)}
            placeholder="0.00"
          />
          <Input
            label="Capacity"
            type="number"
            min={0}
            value={form.capacity}
            onChange={(e) => set("capacity", e.target.value)}
            placeholder="Unlimited"
          />
        </Grid>
        <Grid minColumnWidth={200}>
          <DateTimePicker
            label="Sale starts"
            value={form.sale_start}
            onChange={(v) => set("sale_start", v)}
          />
          <DateTimePicker
            label="Sale ends"
            value={form.sale_end}
            onChange={(v) => set("sale_end", v)}
          />
        </Grid>
        <Grid minColumnWidth={160}>
          <Input
            label="Min per order"
            type="number"
            min={1}
            value={form.min_per_order}
            onChange={(e) => set("min_per_order", e.target.value)}
          />
          <Input
            label="Max per order"
            type="number"
            min={1}
            value={form.max_per_order}
            onChange={(e) => set("max_per_order", e.target.value)}
            placeholder="No limit"
          />
        </Grid>
        <Switch
          label="Enable waiting list"
          description="Guests can join a waiting list when this ticket type sells out."
          checked={form.waitlist_enabled}
          onChange={(v) => set("waitlist_enabled", v)}
        />
      </Stack>
    </Drawer>
  );
}

function ComplimentaryModal({
  eventId,
  ticketTypes,
  onClose,
}: {
  eventId: number;
  ticketTypes: TicketTypeRecord[];
  onClose: () => void;
}) {
  const toast = useToast();
  const qc = useQueryClient();
  const [form, setForm] = useState<ComplimentaryPayload>({
    ticket_type_id: ticketTypes[0]?.id ?? 0,
    quantity: 1,
    recipient_name: "",
    recipient_email: "",
    label: "",
    note: "",
  });

  const set = <K extends keyof ComplimentaryPayload>(k: K, v: ComplimentaryPayload[K]) =>
    setForm((f) => ({ ...f, [k]: v }));

  const issue = useMutation({
    mutationFn: () => eventsApi.issueComplimentary(eventId, form),
    onSuccess: (res) => {
      toast.success(
        `${res.issued} complimentary ticket${res.issued !== 1 ? "s" : ""} issued.`,
        "Issued",
      );
      void qc.invalidateQueries({ queryKey: ["eventos", "ticket-types", eventId] });
      onClose();
    },
    onError: (err: unknown) => toast.error(errorMessage(err), "Issue failed"),
  });

  return (
    <Modal
      open
      onClose={onClose}
      title="Issue complimentary tickets"
      description="Complimentary tickets bypass WooCommerce checkout and are issued directly."
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button variant="primary" loading={issue.isPending} onClick={() => issue.mutate()}>
            Issue tickets
          </Button>
        </>
      }
    >
      <Stack>
        <Select
          label="Ticket type"
          value={String(form.ticket_type_id)}
          options={ticketTypes.map<SelectOption>((t) => ({ value: String(t.id), label: t.name }))}
          onChange={(e) => set("ticket_type_id", Number(e.target.value))}
        />
        <Input
          label="Quantity"
          type="number"
          min={1}
          max={100}
          value={form.quantity}
          onChange={(e) => set("quantity", Number(e.target.value))}
        />
        <Input
          label="Recipient name"
          required
          value={form.recipient_name}
          onChange={(e) => set("recipient_name", e.target.value)}
        />
        <Input
          label="Recipient email"
          type="email"
          required
          value={form.recipient_email}
          onChange={(e) => set("recipient_email", e.target.value)}
        />
        <Input
          label="Label (optional)"
          value={form.label ?? ""}
          onChange={(e) => set("label", e.target.value)}
          placeholder="e.g. Press, Sponsor, Artist"
        />
        <Textarea
          label="Internal note"
          rows={2}
          value={form.note ?? ""}
          onChange={(e) => set("note", e.target.value)}
        />
      </Stack>
    </Modal>
  );
}

const WAITLIST_STATUS_TONE: Record<
  WaitlistStatus,
  "success" | "warning" | "neutral" | "danger" | "info"
> = {
  waiting: "info",
  promoted: "warning",
  converted: "success",
  expired: "neutral",
  cancelled: "neutral",
};

function WaitlistDrawer({
  eventId,
  ticketType,
  onClose,
}: {
  eventId: number;
  ticketType: TicketTypeRecord;
  onClose: () => void;
}) {
  const toast = useToast();
  const qc = useQueryClient();

  const invalidate = () => {
    void qc.invalidateQueries({ queryKey: ["eventos", "waitlist", eventId, ticketType.id] });
    void qc.invalidateQueries({ queryKey: ["eventos", "ticket-types", eventId] });
  };

  const { data, isLoading, error } = useQuery({
    queryKey: ["eventos", "waitlist", eventId, ticketType.id],
    queryFn: () => eventsApi.waitlist(eventId, { ticket_type_id: ticketType.id, per_page: 100 }),
  });

  const process = useMutation({
    mutationFn: () => eventsApi.processWaitlist(eventId, ticketType.id),
    onSuccess: (res) => {
      toast.success(
        res.promoted.length > 0
          ? `${res.promoted.length} ${res.promoted.length === 1 ? "person" : "people"} promoted.`
          : "No one was eligible for promotion right now.",
        "Checked",
      );
      invalidate();
    },
    onError: (err: unknown) => toast.error(errorMessage(err), "Check failed"),
  });

  const cancelEntry = useMutation({
    mutationFn: (entryId: number) => eventsApi.cancelWaitlistEntry(eventId, entryId),
    onSuccess: () => {
      toast.success("Waitlist entry cancelled.", "Cancelled");
      invalidate();
    },
    onError: (err: unknown) => toast.error(errorMessage(err), "Cancel failed"),
  });

  const promoteEntry = useMutation({
    mutationFn: (entryId: number) => eventsApi.promoteWaitlistEntry(eventId, entryId),
    onSuccess: () => {
      toast.success("Entry promoted — a purchase notification was sent.", "Promoted");
      invalidate();
    },
    onError: (err: unknown) => toast.error(errorMessage(err), "Promotion failed"),
  });

  const entries = data?.items ?? [];

  const columns: DataTableColumn<WaitlistEntryRecord>[] = [
    {
      key: "queue_position",
      header: "#",
      cell: (row) => (row.queue_position != null ? `#${row.queue_position}` : "—"),
    },
    {
      key: "name",
      header: "Person",
      cell: (row) => (
        <Stack>
          <strong>{row.name}</strong>
          <span className="eos-page__description">{row.email}</span>
        </Stack>
      ),
    },
    {
      key: "status",
      header: "Status",
      cell: (row) => <Badge tone={WAITLIST_STATUS_TONE[row.status]}>{row.status}</Badge>,
    },
    {
      key: "created_at",
      header: "Joined",
      cell: (row) => formatDateTime(row.created_at),
    },
    {
      key: "expires_at",
      header: "Promotion window",
      cell: (row) =>
        row.expires_at ? (
          <span className="eos-page__description">Until {formatDateTime(row.expires_at)}</span>
        ) : (
          "—"
        ),
    },
    {
      key: "id",
      header: "",
      cell: (row) => (
        <div className="eos-inline">
          {"waiting" === row.status && (
            <Button
              size="sm"
              loading={promoteEntry.isPending}
              onClick={() => promoteEntry.mutate(row.id)}
            >
              Promote now
            </Button>
          )}
          {("waiting" === row.status || "promoted" === row.status) && (
            <Button
              size="sm"
              variant="danger"
              loading={cancelEntry.isPending}
              onClick={() => cancelEntry.mutate(row.id)}
            >
              Cancel
            </Button>
          )}
        </div>
      ),
    },
  ];

  return (
    <Drawer
      open
      onClose={onClose}
      title={`Waitlist: ${ticketType.name}`}
      description="People who joined the waiting list for this ticket type, first joined first eligible."
      footer={
        <>
          <Button onClick={onClose}>Close</Button>
          <Button variant="primary" loading={process.isPending} onClick={() => process.mutate()}>
            Re-check availability
          </Button>
        </>
      }
    >
      <Stack>
        {isLoading ? (
          <LoadingState label="Loading waitlist…" />
        ) : error ? (
          <Alert tone="danger" title="Could not load the waitlist">
            {errorMessage(error)}
          </Alert>
        ) : (
          <DataTable
            caption={`Waitlist for ${ticketType.name}`}
            columns={columns}
            rows={entries}
            getRowId={(row) => String(row.id)}
            emptyTitle="No one is waiting"
            emptyDescription="Entries appear here once this ticket type sells out and people join the waiting list."
          />
        )}
      </Stack>
    </Drawer>
  );
}

export function TicketingTab({ eventId }: Props) {
  const toast = useToast();
  const qc = useQueryClient();
  const [drawerTarget, setDrawerTarget] = useState<TicketTypeRecord | null | "new">(null);
  const [deleteTarget, setDeleteTarget] = useState<TicketTypeRecord | null>(null);
  const [showComp, setShowComp] = useState(false);
  const [waitlistTarget, setWaitlistTarget] = useState<TicketTypeRecord | null>(null);

  const { data, isLoading, error } = useQuery({
    queryKey: ["eventos", "ticket-types", eventId],
    queryFn: () => eventsApi.ticketTypes(eventId),
  });

  const remove = useMutation({
    mutationFn: (id: number) => eventsApi.removeTicketType(eventId, id),
    onSuccess: () => {
      toast.success("Ticket type deleted.", "Deleted");
      void qc.invalidateQueries({ queryKey: ["eventos", "ticket-types", eventId] });
      setDeleteTarget(null);
    },
    onError: (err: unknown) => toast.error(errorMessage(err), "Delete failed"),
  });

  const ticketTypes = data?.ticket_types ?? [];
  const totalSold = ticketTypes.reduce((acc, t) => acc + t.sold, 0);
  const totalCapacity = ticketTypes.reduce(
    (acc, t) => (t.capacity != null ? acc + t.capacity : acc),
    0,
  );
  const totalComp = ticketTypes
    .filter((t) => t.tier === "complimentary")
    .reduce((acc, t) => acc + t.sold, 0);
  const totalWaitlist = ticketTypes.reduce((acc, t) => acc + t.waitlist_count, 0);

  const columns: DataTableColumn<TicketTypeRecord>[] = [
    {
      key: "name",
      header: "Ticket type",
      cell: (row) => (
        <Stack>
          <div className="eos-inline">
            <strong>{row.name}</strong>
            <Badge tone={TIER_TONE[row.tier]}>{row.tier.replace("_", " ")}</Badge>
          </div>
          {row.description && <span className="eos-page__description">{row.description}</span>}
        </Stack>
      ),
    },
    {
      key: "price",
      header: "Price",
      cell: (row) =>
        row.tier === "complimentary"
          ? "Free"
          : new Intl.NumberFormat("en-ZA", { style: "currency", currency: "ZAR" }).format(
              row.price,
            ),
    },
    {
      key: "sold",
      header: "Sold",
      cell: (row) => (
        <span>
          {row.sold.toLocaleString()}
          {row.capacity != null && (
            <span className="eos-page__description"> / {row.capacity.toLocaleString()}</span>
          )}
        </span>
      ),
    },
    {
      key: "visibility",
      header: "Visibility",
      cell: (row) => (
        <Badge tone={row.visibility === "public" ? "success" : "neutral"}>{row.visibility}</Badge>
      ),
    },
    {
      key: "status",
      header: "Status",
      cell: (row) => (
        <Badge
          tone={
            row.status === "active" ? "success" : row.status === "sold_out" ? "danger" : "neutral"
          }
        >
          {row.status.replace("_", " ")}
        </Badge>
      ),
    },
    {
      key: "sale_start",
      header: "Sale window",
      cell: (row) =>
        row.sale_start || row.sale_end ? (
          <span className="eos-page__description">
            {row.sale_start ? formatDateTime(row.sale_start) : "Now"}
            {" → "}
            {row.sale_end ? formatDateTime(row.sale_end) : "Open"}
          </span>
        ) : (
          <span className="eos-page__description">Always on sale</span>
        ),
    },
    {
      key: "waitlist_count",
      header: "Waitlist",
      cell: (row) =>
        row.waitlist_enabled ? (
          <Button
            size="sm"
            variant="ghost"
            onClick={() => setWaitlistTarget(row)}
            aria-label={`View waitlist for ${row.name}`}
          >
            <Badge tone={row.waitlist_count > 0 ? "warning" : "neutral"}>
              {row.waitlist_count}
            </Badge>
          </Button>
        ) : (
          <span className="eos-page__description">Off</span>
        ),
    },
    {
      key: "wc_product_id",
      header: "WC product",
      cell: (row) =>
        row.wc_product_id ? (
          <a
            href={`/wp-admin/post.php?post=${row.wc_product_id}&action=edit`}
            target="_blank"
            rel="noreferrer"
            className="eos-btn eos-btn--link"
          >
            #{row.wc_product_id}
          </a>
        ) : (
          <span className="eos-page__description">Pending sync</span>
        ),
    },
    {
      key: "id",
      header: "",
      cell: (row) => (
        <div className="eos-inline">
          <Button size="sm" onClick={() => setDrawerTarget(row)}>
            Edit
          </Button>
          <Button size="sm" variant="danger" onClick={() => setDeleteTarget(row)}>
            Delete
          </Button>
        </div>
      ),
    },
  ];

  if (isLoading) return <LoadingState label="Loading ticket types…" />;

  if (error)
    return (
      <Alert tone="danger" title="Could not load ticket types">
        {errorMessage(error)}
      </Alert>
    );

  return (
    <Stack>
      {/* Stats row */}
      <Grid minColumnWidth={160}>
        <StatCard label="Ticket types" value={ticketTypes.length} />
        <StatCard
          label="Total sold"
          value={totalSold.toLocaleString()}
          hint={totalCapacity > 0 ? `of ${totalCapacity.toLocaleString()}` : undefined}
        />
        <StatCard label="Complimentary" value={totalComp.toLocaleString()} hint="Issued" />
        {totalWaitlist > 0 && (
          <StatCard label="On waitlist" value={totalWaitlist.toLocaleString()} />
        )}
      </Grid>

      {/* WooCommerce notice */}
      <Alert tone="info" title="WooCommerce integration">
        Each ticket type creates and syncs a WooCommerce product. Checkout, payment processing,
        order management and emails are handled by WooCommerce. EventOS manages event-specific
        metadata, capacity, and scanning.
      </Alert>

      {/* Ticket types table */}
      <Card
        title="Ticket types"
        actions={
          <div className="eos-inline">
            <Button onClick={() => setShowComp(true)}>Issue comp tickets</Button>
            <Button variant="primary" onClick={() => setDrawerTarget("new")}>
              Add ticket type
            </Button>
          </div>
        }
      >
        <DataTable
          caption="Ticket types for this event"
          columns={columns}
          rows={ticketTypes}
          getRowId={(row) => String(row.id)}
          emptyTitle="No ticket types yet"
          emptyDescription='Add your first ticket type with "Add ticket type" above.'
        />
      </Card>

      {/* Create / edit drawer */}
      {drawerTarget !== null && (
        <TicketTypeDrawer
          eventId={eventId}
          editing={drawerTarget === "new" ? null : drawerTarget}
          onClose={() => setDrawerTarget(null)}
        />
      )}

      {/* Complimentary modal */}
      {showComp && ticketTypes.length > 0 && (
        <ComplimentaryModal
          eventId={eventId}
          ticketTypes={ticketTypes}
          onClose={() => setShowComp(false)}
        />
      )}

      {/* Waitlist drawer */}
      {waitlistTarget && (
        <WaitlistDrawer
          eventId={eventId}
          ticketType={waitlistTarget}
          onClose={() => setWaitlistTarget(null)}
        />
      )}

      {/* Delete confirm */}
      <ConfirmDialog
        open={deleteTarget !== null}
        title={`Delete "${deleteTarget?.name}"?`}
        description="This will remove the ticket type and archive the linked WooCommerce product. Existing orders are not affected."
        confirmLabel="Delete ticket type"
        destructive
        busy={remove.isPending}
        onCancel={() => setDeleteTarget(null)}
        onConfirm={() => {
          if (deleteTarget) remove.mutate(deleteTarget.id);
        }}
      />
    </Stack>
  );
}
