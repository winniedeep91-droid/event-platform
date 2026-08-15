import { useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Alert,
  Badge,
  Button,
  Card,
  DataTable,
  DefinitionList,
  Grid,
  Input,
  LoadingState,
  PageLayout,
  Select,
  Stack,
  Tabs,
  Textarea,
  Timeline,
  useToast,
  type DataTableColumn,
  type SelectOption,
  type TabItem,
  type TimelineItem,
  type Tone,
} from "../../ui";
import {
  crmApi,
  type PersonEventHistoryEntry,
  type PersonProfile,
  type SegmentRecord,
  type TimelineEntry,
} from "../../api";
import { crmErrorMessage, fmtDate, fmtMoney, peopleListUrl } from "./shared";

function timelineItem(entry: TimelineEntry, index: number): TimelineItem {
  const p = entry.payload ?? {};
  let title = entry.type;
  let description: string | undefined;
  let tone: Tone = "neutral";

  switch (entry.type) {
    case "person_created":
      title = "Permanent Person record created";
      description = p.source ? `via ${String(p.source)}` : undefined;
      break;
    case "identity_attached":
      title = `Identity attached — ${String(p.type ?? "")}`;
      description = p.source ? `via ${String(p.source)}` : undefined;
      break;
    case "purchase":
      title = `Purchased ${String(p.quantity ?? "")} × ${String(p.ticket_type_name ?? "")}`;
      description = [
        p.event_title,
        typeof p.order_total === "number" ? fmtMoney(p.order_total) : undefined,
      ]
        .filter(Boolean)
        .join(" · ");
      tone = "success";
      break;
    case "ticket_issued":
      title = `Complimentary ticket issued — ${String(p.ticket_type_name ?? "")}`;
      description = p.event_title ? String(p.event_title) : undefined;
      tone = "info";
      break;
    case "ticket_cancelled":
      title = `Ticket cancelled or refunded — ${String(p.ticket_type_name ?? "")}`;
      description = p.event_title ? String(p.event_title) : undefined;
      tone = "danger";
      break;
    case "attendance":
      title = `Checked in — ${String(p.event_title ?? "")}`;
      description = p.ticket_type_name ? String(p.ticket_type_name) : undefined;
      tone = "success";
      break;
    case "tag_added":
      title = `Tag added — ${String(p.tag ?? "")}`;
      break;
    case "note_added":
      title = `Internal note added${p.author_name ? ` by ${String(p.author_name)}` : ""}`;
      break;
    case "consent_granted":
      title = `Consent granted — ${String(p.channel ?? "")}`;
      tone = "info";
      break;
    case "consent_revoked":
      title = `Consent revoked — ${String(p.channel ?? "")}`;
      tone = "warning";
      break;
    default:
      break;
  }

  return {
    id: `${entry.type}-${entry.occurred_at}-${index}`,
    title,
    description,
    timestamp: entry.occurred_at,
    tone,
  };
}

function OverviewTab({ profile }: { profile: PersonProfile }) {
  const { identity, relationship_metrics: metrics, identity_signals } = profile;

  return (
    <Stack>
      <Card title="Identity">
        <DefinitionList
          items={[
            { term: "Name", value: identity.display_name || "—" },
            { term: "Email", value: identity.primary_email || "—" },
            { term: "Phone", value: identity.primary_phone || "—" },
            { term: "Location", value: identity.location || "—" },
            {
              term: "Date of birth",
              value: identity.date_of_birth ? fmtDate(identity.date_of_birth) : "—",
            },
            { term: "Known since", value: fmtDate(metrics.first_interaction) },
          ]}
        />
      </Card>

      <Card title="Relationship summary">
        <Grid minColumnWidth={160}>
          <div>
            <p className="eos-field__label">Lifetime spend</p>
            <strong>{fmtMoney(metrics.total_spend)}</strong>
          </div>
          <div>
            <p className="eos-field__label">Tickets purchased</p>
            <span>{metrics.total_tickets_purchased}</span>
          </div>
          <div>
            <p className="eos-field__label">Events attended</p>
            <span>{metrics.total_events_attended}</span>
          </div>
          <div>
            <p className="eos-field__label">Avg. order value</p>
            <span>{fmtMoney(metrics.avg_order_value)}</span>
          </div>
          <div>
            <p className="eos-field__label">Avg. ticket value (approx.)</p>
            <span>{fmtMoney(metrics.avg_ticket_value)}</span>
          </div>
          <div>
            <p className="eos-field__label">Attendance rate</p>
            <span>
              {metrics.attendance_rate === null
                ? "—"
                : `${Math.round(metrics.attendance_rate * 100)}%`}
            </span>
          </div>
          <div>
            <p className="eos-field__label">VIP purchases</p>
            <span>{metrics.vip_purchase_count}</span>
          </div>
          <div>
            <p className="eos-field__label">Complimentary tickets</p>
            <span>{metrics.complimentary_count}</span>
          </div>
          <div>
            <p className="eos-field__label">Last attendance</p>
            <span>{fmtDate(metrics.last_attendance_at)}</span>
          </div>
          <div>
            <p className="eos-field__label">Last purchase</p>
            <span>{fmtDate(metrics.last_purchase_at)}</span>
          </div>
        </Grid>
        <p className="eos-page__description" style={{ marginTop: "var(--eos-space-3)" }}>
          Refund and cancellation counts aren&rsquo;t shown: the current ticket lifecycle
          can&rsquo;t yet distinguish a refund from a plain cancellation, so those figures
          aren&rsquo;t reliable enough to report.
        </p>
      </Card>

      <Card title="Identity signals">
        {identity_signals.length > 0 ? (
          <div className="eos-inline" style={{ flexWrap: "wrap" }}>
            {identity_signals.map((signal) => (
              <Badge key={signal.id} tone="neutral">
                {signal.type}: {signal.value}
              </Badge>
            ))}
          </div>
        ) : (
          <p className="eos-page__description">No identity signals recorded yet.</p>
        )}
      </Card>
    </Stack>
  );
}

function TimelineTab({ profile }: { profile: PersonProfile }) {
  const items = profile.relationship_timeline.map(timelineItem);

  return (
    <Card title="Relationship timeline">
      {items.length > 0 ? (
        <Timeline items={items} />
      ) : (
        <p className="eos-page__description">
          No relationship activity yet. Purchases, attendance, tags, notes and consent changes will
          appear here as this person interacts with the brand.
        </p>
      )}
    </Card>
  );
}

function EventsTab({ profile }: { profile: PersonProfile }) {
  const columns: DataTableColumn<PersonEventHistoryEntry>[] = [
    {
      key: "event_title",
      header: "Event",
      cell: (row) => row.event_title || `Event #${row.event_id}`,
    },
    { key: "starts_at", header: "Date", cell: (row) => fmtDate(row.starts_at) },
    { key: "tickets", header: "Tickets", cell: (row) => row.tickets },
    {
      key: "attended",
      header: "Attended",
      cell: (row) => (
        <Badge tone={row.attended ? "success" : "neutral"}>{row.attended ? "Yes" : "No"}</Badge>
      ),
    },
  ];

  return (
    <Card title="Event history">
      <DataTable
        caption="Events this person has purchased tickets to or attended"
        columns={columns}
        rows={profile.event_history}
        getRowId={(row) => String(row.event_id)}
        emptyTitle="No event history yet"
        emptyDescription="Tickets purchased under this person's identities will appear here."
      />
    </Card>
  );
}

function TagsAndNotesTab({ personId, profile }: { personId: number; profile: PersonProfile }) {
  const toast = useToast();
  const qc = useQueryClient();
  const [newTag, setNewTag] = useState("");
  const [newNote, setNewNote] = useState("");

  const attachTag = useMutation({
    mutationFn: (tag: string) => crmApi.attachTag(personId, tag),
    onSuccess: () => {
      setNewTag("");
      void qc.invalidateQueries({ queryKey: ["crm", "person", personId] });
    },
    onError: (err: unknown) => toast.error(crmErrorMessage(err), "Could not add tag"),
  });

  const detachTag = useMutation({
    mutationFn: (tag: string) => crmApi.detachTag(personId, tag),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ["crm", "person", personId] }),
    onError: (err: unknown) => toast.error(crmErrorMessage(err), "Could not remove tag"),
  });

  const createNote = useMutation({
    mutationFn: (body: string) => crmApi.createNote(personId, body),
    onSuccess: () => {
      setNewNote("");
      void qc.invalidateQueries({ queryKey: ["crm", "person", personId] });
    },
    onError: (err: unknown) => toast.error(crmErrorMessage(err), "Could not add note"),
  });

  return (
    <Stack>
      <Card title="Tags">
        <Stack>
          <div className="eos-inline" style={{ flexWrap: "wrap" }}>
            {profile.tags.map((t) => (
              <Badge key={t.id} tone="neutral">
                {t.tag}{" "}
                <button
                  onClick={() => detachTag.mutate(t.tag)}
                  style={{
                    background: "none",
                    border: "none",
                    cursor: "pointer",
                    padding: "0 0 0 4px",
                    color: "inherit",
                  }}
                  aria-label={`Remove tag ${t.tag}`}
                >
                  ×
                </button>
              </Badge>
            ))}
            {profile.tags.length === 0 && (
              <span className="eos-page__description">No tags yet.</span>
            )}
          </div>
          <div className="eos-inline">
            <Input
              value={newTag}
              onChange={(e) => setNewTag(e.target.value)}
              placeholder="Add tag…"
              onKeyDown={(e) =>
                e.key === "Enter" && newTag.trim() && attachTag.mutate(newTag.trim())
              }
            />
            <Button
              size="sm"
              loading={attachTag.isPending}
              disabled={!newTag.trim()}
              onClick={() => attachTag.mutate(newTag.trim())}
            >
              Add
            </Button>
          </div>
        </Stack>
      </Card>

      <Card title="Internal notes">
        <Stack>
          <p className="eos-page__description">
            Internal staff notes — never shown to the customer or any future customer-facing
            surface.
          </p>
          {profile.notes.length > 0 ? (
            <Stack>
              {profile.notes.map((note) => (
                <div key={note.id} className="eos-card" style={{ padding: "var(--eos-space-3)" }}>
                  <p style={{ margin: 0 }}>{note.body}</p>
                  <p className="eos-page__description" style={{ margin: 0 }}>
                    {note.author_name || "Unknown"} · {fmtDate(note.created_at)}
                  </p>
                </div>
              ))}
            </Stack>
          ) : (
            <p className="eos-page__description">No notes yet.</p>
          )}
          <Textarea
            label="Add note"
            rows={2}
            value={newNote}
            onChange={(e) => setNewNote(e.target.value)}
            placeholder="Internal note visible to EventOS admins only…"
          />
          <Button
            size="sm"
            loading={createNote.isPending}
            disabled={!newNote.trim()}
            onClick={() => createNote.mutate(newNote.trim())}
          >
            Save note
          </Button>
        </Stack>
      </Card>
    </Stack>
  );
}

function ConsentTab({ personId, profile }: { personId: number; profile: PersonProfile }) {
  const toast = useToast();
  const qc = useQueryClient();
  const [channel, setChannel] = useState("email");

  const grant = useMutation({
    mutationFn: (ch: string) => crmApi.grantConsent(personId, ch),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ["crm", "person", personId] }),
    onError: (err: unknown) => toast.error(crmErrorMessage(err), "Could not record consent"),
  });

  const revoke = useMutation({
    mutationFn: (ch: string) => crmApi.revokeConsent(personId, ch),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ["crm", "person", personId] }),
    onError: (err: unknown) => toast.error(crmErrorMessage(err), "Could not revoke consent"),
  });

  return (
    <Card title="Marketing consent">
      <Stack>
        <p className="eos-page__description">
          Consent is never assumed from a purchase or an email address — it must be recorded
          explicitly here.
        </p>
        {profile.consents.length > 0 ? (
          <DataTable
            caption="Consent history"
            columns={[
              { key: "channel", header: "Channel", cell: (r) => r.channel },
              {
                key: "active",
                header: "Status",
                cell: (r) =>
                  r.active ? (
                    <Badge tone="success">Granted</Badge>
                  ) : (
                    <Badge tone="neutral">Revoked</Badge>
                  ),
              },
              { key: "granted_at", header: "Granted", cell: (r) => fmtDate(r.granted_at) },
              { key: "revoked_at", header: "Revoked", cell: (r) => fmtDate(r.revoked_at) },
              { key: "source", header: "Source", cell: (r) => r.source || "—" },
              {
                key: "id",
                header: "",
                cell: (r) =>
                  r.active ? (
                    <Button
                      size="sm"
                      loading={revoke.isPending}
                      onClick={() => revoke.mutate(r.channel)}
                    >
                      Revoke
                    </Button>
                  ) : null,
              },
            ]}
            rows={profile.consents}
            getRowId={(r) => String(r.id)}
            emptyTitle="No consent recorded"
          />
        ) : (
          <p className="eos-page__description">
            No consent recorded — unknown, not assumed absent.
          </p>
        )}

        <div className="eos-inline">
          <Input
            value={channel}
            onChange={(e) => setChannel(e.target.value)}
            placeholder="Channel, e.g. email"
          />
          <Button
            size="sm"
            loading={grant.isPending}
            disabled={!channel.trim()}
            onClick={() => grant.mutate(channel.trim())}
          >
            Grant consent
          </Button>
        </div>
      </Stack>
    </Card>
  );
}

function SegmentsTab({ personId, profile }: { personId: number; profile: PersonProfile }) {
  const toast = useToast();
  const qc = useQueryClient();
  const [selected, setSelected] = useState("");

  const allSegments = useQuery({
    queryKey: ["crm", "segments", "all"],
    queryFn: () => crmApi.segments(),
  });

  const attach = useMutation({
    mutationFn: (segmentId: number) => crmApi.attachSegmentMember(segmentId, personId),
    onSuccess: () => {
      setSelected("");
      void qc.invalidateQueries({ queryKey: ["crm", "person", personId] });
    },
    onError: (err: unknown) => toast.error(crmErrorMessage(err), "Could not add to segment"),
  });

  const detach = useMutation({
    mutationFn: (segmentId: number) => crmApi.detachSegmentMember(segmentId, personId),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ["crm", "person", personId] }),
    onError: (err: unknown) => toast.error(crmErrorMessage(err), "Could not remove from segment"),
  });

  const memberIds = new Set(profile.segments.map((s) => s.id));
  const options: SelectOption[] = (allSegments.data?.segments ?? [])
    .filter((s: SegmentRecord) => !s.archived && !memberIds.has(s.id))
    .map((s: SegmentRecord) => ({ value: String(s.id), label: s.name }));

  return (
    <Card title="Segments">
      <Stack>
        <p className="eos-page__description">
          Manually managed membership only — segments are not evaluated automatically in this phase.
        </p>
        {profile.segments.length > 0 ? (
          <div className="eos-inline" style={{ flexWrap: "wrap" }}>
            {profile.segments.map((s) => (
              <Badge key={s.id} tone="info">
                {s.name}{" "}
                <button
                  onClick={() => detach.mutate(s.id)}
                  style={{
                    background: "none",
                    border: "none",
                    cursor: "pointer",
                    padding: "0 0 0 4px",
                    color: "inherit",
                  }}
                  aria-label={`Remove from ${s.name}`}
                >
                  ×
                </button>
              </Badge>
            ))}
          </div>
        ) : (
          <p className="eos-page__description">Not a member of any segment yet.</p>
        )}

        <div className="eos-inline">
          <Select
            aria-label="Add to segment"
            value={selected}
            placeholder="Choose a segment…"
            options={options}
            onChange={(e) => setSelected(e.target.value)}
          />
          <Button
            size="sm"
            loading={attach.isPending}
            disabled={!selected}
            onClick={() => attach.mutate(parseInt(selected, 10))}
          >
            Add
          </Button>
        </div>
      </Stack>
    </Card>
  );
}

export function PersonProfileView({ personId }: { personId: number }) {
  const [tab, setTab] = useState("overview");

  const {
    data: profile,
    isLoading,
    error,
    refetch,
  } = useQuery({
    queryKey: ["crm", "person", personId],
    queryFn: () => crmApi.person(personId),
  });

  const tabs = useMemo<TabItem[]>(() => {
    if (!profile) return [];

    return [
      { id: "overview", label: "Overview", content: <OverviewTab profile={profile} /> },
      { id: "timeline", label: "Timeline", content: <TimelineTab profile={profile} /> },
      { id: "events", label: "Events", content: <EventsTab profile={profile} /> },
      {
        id: "tags-notes",
        label: "Tags & Notes",
        content: <TagsAndNotesTab personId={personId} profile={profile} />,
      },
      {
        id: "consent",
        label: "Consent",
        content: <ConsentTab personId={personId} profile={profile} />,
      },
      {
        id: "segments",
        label: "Segments",
        content: <SegmentsTab personId={personId} profile={profile} />,
      },
    ];
  }, [profile, personId]);

  if (isLoading) {
    return (
      <PageLayout title="Customer" description="Loading this person's relationship profile…">
        <LoadingState label="Loading profile…" />
      </PageLayout>
    );
  }

  if (error || !profile) {
    return (
      <PageLayout
        title="Customer"
        description="This person could not be loaded."
        actions={
          <a className="eos-btn eos-btn--secondary" href={peopleListUrl()}>
            Back to customers
          </a>
        }
      >
        <Alert
          tone="danger"
          title="Profile unavailable"
          actions={
            <Button size="sm" onClick={() => void refetch()}>
              Retry
            </Button>
          }
        >
          {error ? crmErrorMessage(error) : "That person no longer exists."}
        </Alert>
      </PageLayout>
    );
  }

  return (
    <PageLayout
      title={
        profile.identity.display_name || profile.identity.primary_email || `Person #${personId}`
      }
      description={profile.identity.primary_email}
      actions={
        <a className="eos-btn eos-btn--secondary" href={peopleListUrl()}>
          All customers
        </a>
      }
    >
      <Tabs label="Customer profile" items={tabs} value={tab} onChange={setTab} />
    </PageLayout>
  );
}
