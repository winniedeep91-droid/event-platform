/** Event creation wizard: essentials, scheduling, programme and publishing. */
import { useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { eventsApi, type EventPayload } from "../../api";
import {
  Alert,
  Card,
  DateTimePicker,
  Field,
  Grid,
  Input,
  MultiSelect,
  PageLayout,
  Select,
  Stack,
  Switch,
  Textarea,
  Wizard,
  useToast,
  type SelectOption,
  type StepDefinition,
} from "../../ui";
import { EVENTS_PAGES, errorMessage, fromLocalInput, goTo } from "./shared";

interface WizardState {
  title: string;
  subtitle: string;
  short_description: string;
  description: string;
  venue_id: string;
  timezone: string;
  starts_at: string;
  ends_at: string;
  doors_open_at: string;
  capacity: string;
  age_restriction: string;
  accessibility: string;
  artists: string[];
  categories: string[];
  tags: string[];
  status: string;
  visibility: string;
  ticket_visibility: string;
  generate_occurrences: boolean;
  recurrence_frequency: string;
  recurrence_interval: string;
  recurrence_count: string;
}

const INITIAL: WizardState = {
  title: "",
  subtitle: "",
  short_description: "",
  description: "",
  venue_id: "",
  timezone: "",
  starts_at: "",
  ends_at: "",
  doors_open_at: "",
  capacity: "",
  age_restriction: "",
  accessibility: "",
  artists: [],
  categories: [],
  tags: [],
  status: "draft",
  visibility: "public",
  ticket_visibility: "public",
  generate_occurrences: false,
  recurrence_frequency: "weekly",
  recurrence_interval: "1",
  recurrence_count: "4",
};

export function EventWizardView() {
  const toast = useToast();
  const queryClient = useQueryClient();
  const [step, setStep] = useState(0);
  const [state, setState] = useState<WizardState>(INITIAL);

  const options = useQuery({ queryKey: ["eventos", "events", "options"], queryFn: eventsApi.options });

  const set = <K extends keyof WizardState>(key: K, value: WizardState[K]) =>
    setState((current) => ({ ...current, [key]: value }));

  const timezone = state.timezone || options.data?.default_timezone || "UTC";

  const venueOptions = useMemo<SelectOption[]>(
    () => (options.data?.venues ?? []).map((venue) => ({ value: String(venue.id), label: venue.name })),
    [options.data],
  );

  const create = useMutation({
    mutationFn: async (payload: EventPayload) => {
      const event = await eventsApi.create(payload);

      if (state.generate_occurrences) {
        await eventsApi.generateOccurrences(event.id, {
          frequency: state.recurrence_frequency,
          interval: Number(state.recurrence_interval) || 1,
          count: Number(state.recurrence_count) || 1,
        });
      }

      return event;
    },
    onSuccess: (event) => {
      toast.success(`“${event.title}” was created.`, "Event created");
      void queryClient.invalidateQueries({ queryKey: ["eventos", "events"] });
      goTo(EVENTS_PAGES.list, { event: event.id });
    },
    onError: (error: unknown) => toast.error(errorMessage(error), "Could not create the event"),
  });

  const steps: StepDefinition[] = [
    {
      id: "essentials",
      title: "Essentials",
      description: "Name and describe the event",
      content: (
        <Card title="Event details">
          <Stack>
            <Input
              label="Title"
              required
              value={state.title}
              onChange={(event) => set("title", event.target.value)}
            />
            <Input
              label="Subtitle"
              value={state.subtitle}
              onChange={(event) => set("subtitle", event.target.value)}
            />
            <Textarea
              label="Short description"
              rows={2}
              hint="Used in listings and search results."
              value={state.short_description}
              onChange={(event) => set("short_description", event.target.value)}
            />
            <Textarea
              label="Full description"
              rows={6}
              value={state.description}
              onChange={(event) => set("description", event.target.value)}
            />
          </Stack>
        </Card>
      ),
    },
    {
      id: "schedule",
      title: "Schedule & venue",
      description: "When and where it happens",
      content: (
        <Card title="Scheduling">
          <Stack>
            <Grid minColumnWidth={240}>
              <DateTimePicker
                label="Starts at"
                required
                value={state.starts_at}
                onChange={(value) => set("starts_at", value)}
              />
              <DateTimePicker label="Ends at" value={state.ends_at} onChange={(value) => set("ends_at", value)} />
              <DateTimePicker
                label="Doors open"
                value={state.doors_open_at}
                onChange={(value) => set("doors_open_at", value)}
              />
            </Grid>
            <Grid minColumnWidth={240}>
              <Select
                label="Venue"
                placeholder="No venue"
                value={state.venue_id}
                options={venueOptions}
                onChange={(event) => set("venue_id", event.target.value)}
              />
              <Select
                label="Timezone"
                value={timezone}
                options={(options.data?.timezones ?? []).map<SelectOption>((zone) => ({ value: zone, label: zone }))}
                onChange={(event) => set("timezone", event.target.value)}
              />
              <Input
                label="Capacity"
                type="number"
                min={0}
                value={state.capacity}
                onChange={(event) => set("capacity", event.target.value)}
              />
            </Grid>
            <Grid minColumnWidth={240}>
              <Input
                label="Age restriction"
                value={state.age_restriction}
                onChange={(event) => set("age_restriction", event.target.value)}
              />
              <Input
                label="Accessibility"
                value={state.accessibility}
                onChange={(event) => set("accessibility", event.target.value)}
              />
            </Grid>
          </Stack>
        </Card>
      ),
    },
    {
      id: "programme",
      title: "Programme",
      description: "Line-up and taxonomy",
      content: (
        <Card title="Line-up and classification">
          <Stack>
            <MultiSelect
              label="Artists"
              value={state.artists}
              options={(options.data?.artists ?? []).map<SelectOption>((artist) => ({
                value: String(artist.id),
                label: artist.name,
              }))}
              onChange={(value) => set("artists", value)}
            />
            <MultiSelect
              label="Categories"
              value={state.categories}
              options={(options.data?.categories ?? []).map<SelectOption>((term) => ({
                value: String(term.id),
                label: term.name,
              }))}
              onChange={(value) => set("categories", value)}
            />
            <MultiSelect
              label="Tags"
              value={state.tags}
              options={(options.data?.tags ?? []).map<SelectOption>((term) => ({
                value: String(term.id),
                label: term.name,
              }))}
              onChange={(value) => set("tags", value)}
            />
          </Stack>
        </Card>
      ),
    },
    {
      id: "publish",
      title: "Publishing",
      description: "Visibility and recurrence",
      content: (
        <Card title="Publishing">
          <Stack>
            <Grid minColumnWidth={240}>
              <Select
                label="Status"
                value={state.status}
                options={Object.entries(options.data?.statuses ?? { draft: "Draft" }).map<SelectOption>(
                  ([value, label]) => ({ value, label: String(label) }),
                )}
                onChange={(event) => set("status", event.target.value)}
              />
              <Select
                label="Visibility"
                value={state.visibility}
                options={Object.entries(options.data?.visibilities ?? { public: "Public" }).map<SelectOption>(
                  ([value, label]) => ({ value, label: String(label) }),
                )}
                onChange={(event) => set("visibility", event.target.value)}
              />
              <Select
                label="Ticket visibility"
                value={state.ticket_visibility}
                options={Object.entries(options.data?.ticket_visibilities ?? { public: "Public" }).map<SelectOption>(
                  ([value, label]) => ({ value, label: String(label) }),
                )}
                onChange={(event) => set("ticket_visibility", event.target.value)}
              />
            </Grid>

            <Switch
              label="Generate recurring occurrences"
              description="Creates additional draft events from this one after it is saved."
              checked={state.generate_occurrences}
              onChange={(checked) => set("generate_occurrences", checked)}
            />

            {state.generate_occurrences ? (
              <Grid minColumnWidth={220}>
                <Select
                  label="Frequency"
                  value={state.recurrence_frequency}
                  options={[
                    { value: "daily", label: "Daily" },
                    { value: "weekly", label: "Weekly" },
                    { value: "monthly", label: "Monthly" },
                    { value: "yearly", label: "Yearly" },
                  ]}
                  onChange={(event) => set("recurrence_frequency", event.target.value)}
                />
                <Input
                  label="Interval"
                  type="number"
                  min={1}
                  value={state.recurrence_interval}
                  onChange={(event) => set("recurrence_interval", event.target.value)}
                />
                <Input
                  label="Occurrences"
                  type="number"
                  min={1}
                  value={state.recurrence_count}
                  onChange={(event) => set("recurrence_count", event.target.value)}
                />
              </Grid>
            ) : null}

            <Field label="Summary" labelAs="span">
              <p className="eos-page__description">
                {state.title || "Untitled event"} · {state.starts_at || "no start date"} ·{" "}
                {venueOptions.find((option) => option.value === state.venue_id)?.label ?? "no venue"}
              </p>
            </Field>
          </Stack>
        </Card>
      ),
    },
  ];

  const canContinue =
    step === 0 ? state.title.trim().length > 0 : step === 1 ? state.starts_at.length > 0 : true;

  const submit = () => {
    create.mutate({
      title: state.title,
      subtitle: state.subtitle,
      short_description: state.short_description,
      description: state.description,
      venue_id: state.venue_id ? Number(state.venue_id) : 0,
      timezone,
      starts_at: fromLocalInput(state.starts_at),
      ends_at: fromLocalInput(state.ends_at),
      doors_open_at: fromLocalInput(state.doors_open_at),
      capacity: Number(state.capacity) || 0,
      age_restriction: state.age_restriction,
      accessibility: state.accessibility,
      status: state.status,
      visibility: state.visibility,
      ticket_visibility: state.ticket_visibility,
      artists: state.artists.map((id) => ({ artist_id: Number(id) })),
      categories: state.categories.map(Number),
      tags: state.tags.map(Number),
    });
  };

  return (
    <PageLayout
      title="Create an event"
      description="Four steps from a title to a publishable event."
      actions={
        <a className="eos-btn eos-btn--secondary" href="#" onClick={(event) => { event.preventDefault(); goTo(EVENTS_PAGES.list); }}>
          Cancel
        </a>
      }
    >
      <Stack>
        {options.error ? (
          <Alert tone="danger" title="Could not load reference data">{errorMessage(options.error)}</Alert>
        ) : null}

        <Wizard
          steps={steps}
          current={step}
          onStepChange={setStep}
          onFinish={submit}
          finishLabel="Create event"
          busy={create.isPending}
          canContinue={canContinue}
        />
      </Stack>
    </PageLayout>
  );
}
