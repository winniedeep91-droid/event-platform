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
  DateTimePicker,
  Grid,
  Input,
  LoadingState,
  MultiSelect,
  PageLayout,
  Select,
  Stack,
  StatusChip,
  Tabs,
  Textarea,
  useToast,
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
import {
  GuestsTab,
  MarketingTab,
  OrdersTab,
  OverviewTab,
  ReportsTab,
  ScannerTab,
  TicketingTab,
} from "./tabs";

/** Removed: ModuleTab placeholder replaced by real tab implementations. */

/** Removed: LocalOverviewTab - replaced by imported OverviewTab from ./tabs */

function SettingsTab({ event }: { event: EventRecord }) {
  const toast = useToast();
  const queryClient = useQueryClient();
  const options = useQuery({
    queryKey: ["eventos", "events", "options"],
    queryFn: eventsApi.options,
  });

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
          <Input
            label="Title"
            value={form.title}
            onChange={(e) => set("title", e.target.value)}
            required
          />
          <Input
            label="Subtitle"
            value={form.subtitle}
            onChange={(e) => set("subtitle", e.target.value)}
          />
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
            <DateTimePicker
              label="Starts at"
              value={form.starts_at}
              onChange={(v) => set("starts_at", v)}
            />
            <DateTimePicker
              label="Ends at"
              value={form.ends_at}
              onChange={(v) => set("ends_at", v)}
            />
            <DateTimePicker
              label="Doors open"
              value={form.doors_open_at}
              onChange={(v) => set("doors_open_at", v)}
            />
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
              options={(options.data?.timezones ?? []).map<SelectOption>((zone) => ({
                value: zone,
                label: zone,
              }))}
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
              options={Object.entries(
                options.data?.visibilities ?? { public: "Public" },
              ).map<SelectOption>(([value, label]) => ({ value, label: String(label) }))}
              onChange={(e) => set("visibility", e.target.value)}
            />
            <Select
              label="Ticket visibility"
              value={form.ticket_visibility}
              options={Object.entries(
                options.data?.ticket_visibilities ?? { public: "Public" },
              ).map<SelectOption>(([value, label]) => ({ value, label: String(label) }))}
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
  const options = useQuery({
    queryKey: ["eventos", "events", "options"],
    queryFn: eventsApi.options,
  });

  const event = detail.data;

  const transition = useMutation({
    mutationFn: (status: string) => eventsApi.transition(eventId, status),
    onSuccess: (updated) => {
      toast.success(
        `Status changed to ${statusLabel(updated.status, options.data?.statuses)}.`,
        "Status updated",
      );
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
      {
        id: "overview",
        label: "Overview",
        content: <OverviewTab event={event} statuses={options.data?.statuses} />,
      },
      {
        id: "ticketing",
        label: "Ticketing",
        content: <TicketingTab eventId={event.id} />,
      },
      {
        id: "orders",
        label: "Orders",
        content: <OrdersTab eventId={event.id} />,
      },
      {
        id: "guests",
        label: "Guests",
        content: <GuestsTab eventId={event.id} />,
      },
      {
        id: "scanner",
        label: "Scanner",
        content: <ScannerTab eventId={event.id} />,
      },
      {
        id: "marketing",
        label: "Marketing",
        content: <MarketingTab eventId={event.id} />,
      },
      {
        id: "reports",
        label: "Reports",
        content: <ReportsTab eventId={event.id} />,
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
        actions={
          <a className="eos-btn eos-btn--secondary" href={pageUrl(EVENTS_PAGES.list)}>
            Back to events
          </a>
        }
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
      aside={
        <StatusChip
          status={statusKind(event.status)}
          label={statusLabel(event.status, options.data?.statuses)}
        />
      }
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
          {event.recurrence && Object.keys(event.recurrence).length ? (
            <Badge tone="info">Recurring</Badge>
          ) : null}
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
