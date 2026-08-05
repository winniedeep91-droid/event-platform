/** Event workspace: the eight operational tabs for a single event. */
import { useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { eventsApi, type EventRecord } from "../../api";
import {
  Alert,
  Badge,
  Button,
  Card,
  ConfirmDialog,
  DataTable,
  DateTimePicker,
  DefinitionList,
  Grid,
  Input,
  LoadingState,
  MultiSelect,
  PageLayout,
  Select,
  Stack,
  StatCard,
  StatusChip,
  Tabs,
  Textarea,
  useToast,
  type DataTableColumn,
  type SelectOption,
  type TabItem,
} from "../../ui";
import {
  EVENTS_PAGES,
  errorMessage,
  formatDateTime,
  fromLocalInput,
  goTo,
  pageUrl,
  statusKind,
  statusLabel,
  toLocalInput,
  venueLabel,
} from "./shared";

/** Placeholder for tabs whose module ships in a later build. */
function ModuleTab({ title, description }: { title: string; description: string }) {
  return (
    <Card title={title}>
      <Alert tone="info" title={`${title} is not installed`}>
        {description}
      </Alert>
    </Card>
  );
}

function OverviewTab({ event, statuses }: { event: EventRecord; statuses?: Record<string, string> }) {
  const artistColumns: DataTableColumn<NonNullable<EventRecord["artists"]>[number]> = {
    key: "artist_name",
    header: "Artist",
    cell: (row) => <strong>{row.artist_name}</strong>,
  };

  return (
    <Stack>
      <Grid>
        <StatCard label="Capacity" value={event.capacity || "—"} />
        <StatCard label="Line-up" value={event.artists?.length ?? 0} hint="Artists booked" />
        <StatCard label="Schedule items" value={event.schedules?.length ?? 0} />
        <StatCard label="Media" value={event.media?.length ?? 0} />
      </Grid>

      <Card title="Event details">
        <DefinitionList
          items={[
            { term: "Status", value: statusLabel(event.status, statuses) },
            { term: "Visibility", value: statusLabel(event.visibility) },
            { term: "Ticket visibility", value: statusLabel(event.ticket_visibility) },
            { term: "Starts", value: formatDateTime(event.starts_at) },
            { term: "Ends", value: formatDateTime(event.ends_at) },
            { term: "Doors open", value: formatDateTime(event.doors_open_at) },
            { term: "Venue", value: venueLabel(event) },
            { term: "Timezone", value: event.timezone || "—" },
            { term: "Age restriction", value: event.age_restriction || "—" },
            { term: "Accessibility", value: event.accessibility || "—" },
            { term: "Slug", value: event.slug },
            { term: "Last updated", value: formatDateTime(event.updated_at) },
          ]}
        />
      </Card>

      {event.short_description || event.description ? (
        <Card title="Description">
          <Stack>
            {event.short_description ? <p>{event.short_description}</p> : null}
            {event.description ? <p className="eos-page__description">{event.description}</p> : null}
          </Stack>
        </Card>
      ) : null}

      <Card title="Line-up">
        <DataTable
          caption="Artists booked for this event"
          columns={[
            artistColumns,
            { key: "billing", header: "Billing", cell: (row) => row.billing || "—" },
            { key: "stage", header: "Stage", cell: (row) => row.stage || "—" },
            { key: "starts_at", header: "Set time", cell: (row) => formatDateTime(row.starts_at) },
          ]}
          rows={event.artists ?? []}
          getRowId={(row) => String(row.id)}
          emptyTitle="No artists booked"
          emptyDescription="Attach artists from the event settings tab."
        />
      </Card>

      <Card title="Running order">
        <DataTable
          caption="Schedule for this event"
          columns={[
            { key: "label", header: "Item", cell: (row) => <strong>{row.label}</strong> },
            { key: "type", header: "Type", cell: (row) => statusLabel(row.type) },
            { key: "stage", header: "Stage", cell: (row) => row.stage || "—" },
            { key: "artist_name", header: "Artist", cell: (row) => row.artist_name || "—" },
            { key: "starts_at", header: "Starts", cell: (row) => formatDateTime(row.starts_at) },
            { key: "ends_at", header: "Ends", cell: (row) => formatDateTime(row.ends_at) },
          ]}
          rows={event.schedules ?? []}
          getRowId={(row) => String(row.id)}
          emptyTitle="No schedule yet"
        />
      </Card>
    </Stack>
  );
}

function SettingsTab({ event }: { event: EventRecord }) {
  const toast = useToast();
  const queryClient = useQueryClient();
  const options = useQuery({ queryKey: ["eventos", "events", "options"], queryFn: eventsApi.options });

  const [form, setForm] = useState({
    title: event.title,
    subtitle: event.subtitle,
    short_description: event.short_description,
    description: event.description,
    venue_id: event.venue_id ? String(event.venue_id) : "",
    timezone: event.timezone,
    starts_at: toLocalInput(event.starts_at),
    ends_at: toLocalInput(event.ends_at),
    doors_open_at: toLocalInput(event.doors_open_at),
    capacity: String(event.capacity || ""),
    age_restriction: event.age_restriction,
    accessibility: event.accessibility,
    visibility: event.visibility,
    ticket_visibility: event.ticket_visibility,
    artists: (event.artists ?? []).map((artist) => String(artist.artist_id)),
    categories: (event.categories ?? []).map(String),
    tags: (event.tags ?? []).map(String),
  });

  const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) =>
    setForm((current) => ({ ...current, [key]: value }));

  const save = useMutation({
    mutationFn: () =>
      eventsApi.update(event.id, {
        title: form.title,
        subtitle: form.subtitle,
        short_description: form.short_description,
        description: form.description,
        venue_id: form.venue_id ? Number(form.venue_id) : 0,
        timezone: form.timezone,
        starts_at: fromLocalInput(form.starts_at),
        ends_at: fromLocalInput(form.ends_at),
        doors_open_at: fromLocalInput(form.doors_open_at),
        capacity: Number(form.capacity) || 0,
        age_restriction: form.age_restriction,
        accessibility: form.accessibility,
        visibility: form.visibility,
        ticket_visibility: form.ticket_visibility,
        artists: form.artists.map((id) => ({ artist_id: Number(id) })),
        categories: form.categories.map(Number),
        tags: form.tags.map(Number),
      }),
    onSuccess: () => {
      toast.success("Event settings saved.", "Saved");
      void queryClient.invalidateQueries({ queryKey: ["eventos", "events"] });
    },
    onError: (error: unknown) => toast.error(errorMessage(error), "Save failed"),
  });

  return (
    <Stack>
      <Card
        title="Event settings"
        actions={
          <Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>
            Save changes
          </Button>
        }
      >
        <Stack>
          <Input label="Title" value={form.title} onChange={(e) => set("title", e.target.value)} required />
          <Input label="Subtitle" value={form.subtitle} onChange={(e) => set("subtitle", e.target.value)} />
          <Textarea
            label="Short description"
            rows={2}
            value={form.short_description}
            onChange={(e) => set("short_description", e.target.value)}
          />
          <Textarea
            label="Full description"
            rows={6}
            value={form.description}
            onChange={(e) => set("description", e.target.value)}
          />

          <Grid minColumnWidth={240}>
            <DateTimePicker label="Starts at" value={form.starts_at} onChange={(v) => set("starts_at", v)} />
            <DateTimePicker label="Ends at" value={form.ends_at} onChange={(v) => set("ends_at", v)} />
            <DateTimePicker label="Doors open" value={form.doors_open_at} onChange={(v) => set("doors_open_at", v)} />
          </Grid>

          <Grid minColumnWidth={240}>
            <Select
              label="Venue"
              placeholder="No venue"
              value={form.venue_id}
              options={(options.data?.venues ?? []).map<SelectOption>((venue) => ({
                value: String(venue.id),
                label: venue.name,
              }))}
              onChange={(e) => set("venue_id", e.target.value)}
            />
            <Select
              label="Timezone"
              value={form.timezone || options.data?.default_timezone || "UTC"}
              options={(options.data?.timezones ?? []).map<SelectOption>((zone) => ({ value: zone, label: zone }))}
              onChange={(e) => set("timezone", e.target.value)}
            />
            <Input
              label="Capacity"
              type="number"
              min={0}
              value={form.capacity}
              onChange={(e) => set("capacity", e.target.value)}
            />
          </Grid>

          <Grid minColumnWidth={240}>
            <Input
              label="Age restriction"
              value={form.age_restriction}
              onChange={(e) => set("age_restriction", e.target.value)}
            />
            <Input
              label="Accessibility"
              value={form.accessibility}
              onChange={(e) => set("accessibility", e.target.value)}
            />
            <Select
              label="Visibility"
              value={form.visibility}
              options={Object.entries(options.data?.visibilities ?? { public: "Public" }).map<SelectOption>(
                ([value, label]) => ({ value, label: String(label) }),
              )}
              onChange={(e) => set("visibility", e.target.value)}
            />
            <Select
              label="Ticket visibility"
              value={form.ticket_visibility}
              options={Object.entries(options.data?.ticket_visibilities ?? { public: "Public" }).map<SelectOption>(
                ([value, label]) => ({ value, label: String(label) }),
              )}
              onChange={(e) => set("ticket_visibility", e.target.value)}
            />
          </Grid>

          <MultiSelect
            label="Artists"
            value={form.artists}
            options={(options.data?.artists ?? []).map<SelectOption>((artist) => ({
              value: String(artist.id),
              label: artist.name,
            }))}
            onChange={(value) => set("artists", value)}
          />
          <MultiSelect
            label="Categories"
            value={form.categories}
            options={(options.data?.categories ?? []).map<SelectOption>((term) => ({
              value: String(term.id),
              label: term.name,
            }))}
            onChange={(value) => set("categories", value)}
          />
          <MultiSelect
            label="Tags"
            value={form.tags}
            options={(options.data?.tags ?? []).map<SelectOption>((term) => ({
              value: String(term.id),
              label: term.name,
            }))}
            onChange={(value) => set("tags", value)}
          />
        </Stack>
      </Card>
    </Stack>
  );
}

export function EventWorkspaceView({ eventId }: { eventId: number }) {
  const toast = useToast();
  const queryClient = useQueryClient();
  const [tab, setTab] = useState("overview");
  const [confirmDelete, setConfirmDelete] = useState(false);

  const detail = useQuery({
    queryKey: ["eventos", "events", "detail", eventId],
    queryFn: () => eventsApi.get(eventId),
  });
  const options = useQuery({ queryKey: ["eventos", "events", "options"], queryFn: eventsApi.options });

  const event = detail.data;

  const transition = useMutation({
    mutationFn: (status: string) => eventsApi.transition(eventId, status),
    onSuccess: (updated) => {
      toast.success(`Status changed to ${statusLabel(updated.status, options.data?.statuses)}.`, "Status updated");
      void queryClient.invalidateQueries({ queryKey: ["eventos", "events"] });
    },
    onError: (error: unknown) => toast.error(errorMessage(error), "Transition failed"),
  });

  const duplicate = useMutation({
    mutationFn: () => eventsApi.duplicate(eventId),
    onSuccess: (created) => goTo(EVENTS_PAGES.list, { event: created.id }),
    onError: (error: unknown) => toast.error(errorMessage(error), "Duplicate failed"),
  });

  const remove = useMutation({
    mutationFn: () => eventsApi.remove(eventId),
    onSuccess: () => goTo(EVENTS_PAGES.list),
    onError: (error: unknown) => toast.error(errorMessage(error), "Delete failed"),
  });

  const tabs = useMemo<TabItem[]>(() => {
    if (!event) return [];

    return [
      { id: "overview", label: "Overview", content: <OverviewTab event={event} statuses={options.data?.statuses} /> },
      {
        id: "ticketing",
        label: "Ticketing",
        content: (
          <ModuleTab
            title="Ticketing"
            description="Ticket types, pricing tiers and allocations are delivered by the Ticketing module. Once it is installed, this tab manages the inventory for this event."
          />
        ),
      },
      {
        id: "orders",
        label: "Orders",
        content: (
          <ModuleTab
            title="Orders"
            description="Order history, refunds and payment reconciliation for this event appear here once the Ticketing module is installed."
          />
        ),
      },
      {
        id: "guests",
        label: "Guests",
        content: (
          <ModuleTab
            title="Guest list"
            description="Guest lists, comps and door notes for this event appear here once the Ticketing module is installed."
          />
        ),
      },
      {
        id: "scanner",
        label: "Scanner",
        content: (
          <ModuleTab
            title="Scanner"
            description="Door scanning sessions, device pairing and live admission counts appear here once the Scanning module is installed."
          />
        ),
      },
      {
        id: "marketing",
        label: "Marketing",
        content: (
          <ModuleTab
            title="Marketing"
            description="Campaigns, audiences and promotional links for this event appear here once the Marketing module is installed."
          />
        ),
      },
      {
        id: "reports",
        label: "Reports",
        content: (
          <ModuleTab
            title="Reports"
            description="Sales, attendance and settlement reporting for this event appear here once the Finance module is installed."
          />
        ),
      },
      { id: "settings", label: "Settings", content: <SettingsTab event={event} /> },
    ];
  }, [event, options.data]);

  if (detail.isLoading) {
    return (
      <PageLayout title="Event" description="Loading the event workspace…">
        <LoadingState label="Loading event…" />
      </PageLayout>
    );
  }

  if (detail.error || !event) {
    return (
      <PageLayout
        title="Event"
        description="This event could not be loaded."
        actions={<a className="eos-btn eos-btn--secondary" href={pageUrl(EVENTS_PAGES.list)}>Back to events</a>}
      >
        <Alert tone="danger" title="Event unavailable">
          {detail.error ? errorMessage(detail.error) : "That record no longer exists."}
        </Alert>
      </PageLayout>
    );
  }

  const transitions = options.data?.transitions?.[event.status] ?? [];

  return (
    <PageLayout
      title={event.title}
      description={`${formatDateTime(event.starts_at)} · ${venueLabel(event)}`}
      aside={<StatusChip status={statusKind(event.status)} label={statusLabel(event.status, options.data?.statuses)} />}
      actions={
        <>
          <a className="eos-btn eos-btn--secondary" href={pageUrl(EVENTS_PAGES.list)}>
            All events
          </a>
          {transitions.length ? (
            <Select
              aria-label="Change event status"
              value=""
              placeholder="Change status…"
              disabled={transition.isPending}
              options={transitions.map<SelectOption>((status) => ({
                value: status,
                label: statusLabel(status, options.data?.statuses),
              }))}
              onChange={(changeEvent) => {
                if (changeEvent.target.value) transition.mutate(changeEvent.target.value);
              }}
            />
          ) : null}
          <Button loading={duplicate.isPending} onClick={() => duplicate.mutate()}>
            Duplicate
          </Button>
          <Button variant="danger" onClick={() => setConfirmDelete(true)}>
            Delete
          </Button>
        </>
      }
    >
      <Stack>
        <div className="eos-inline">
          <Badge tone="neutral">Slug: {event.slug}</Badge>
          {event.password_protected ? <Badge tone="warning">Password protected</Badge> : null}
          {event.recurrence && Object.keys(event.recurrence).length ? <Badge tone="info">Recurring</Badge> : null}
        </div>

        <Tabs label="Event workspace" items={tabs} value={tab} onChange={setTab} />
      </Stack>

      <ConfirmDialog
        open={confirmDelete}
        title={`Delete “${event.title}”?`}
        description="Artists, media, schedules and taxonomy links attached to this event are removed too. This cannot be undone."
        confirmLabel="Delete event"
        destructive
        busy={remove.isPending}
        onCancel={() => setConfirmDelete(false)}
        onConfirm={() => remove.mutate()}
      />
    </PageLayout>
  );
}
