import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Alert,
  Avatar,
  Badge,
  Button,
  Card,
  ConfirmDialog,
  DataTable,
  Drawer,
  FilterBar,
  Grid,
  Input,
  LinkButton,
  LoadingState,
  Pagination,
  SearchInput,
  Stack,
  StatCard,
  Textarea,
  Timeline,
  useToast,
  type DataTableColumn,
  type FilterDefinition,
  type TimelineItem,
} from "../../../ui";
import { eventsApi, type GuestRecord, type GuestStatus } from "../../../api";
import { errorMessage, formatDateTime } from "../shared";

interface Props {
  eventId: number;
}

const STATUS_FILTERS: FilterDefinition = {
  key: "status",
  label: "Status",
  options: [
    { value: "confirmed", label: "Confirmed" },
    { value: "waitlisted", label: "Waitlisted" },
    { value: "cancelled", label: "Cancelled" },
    { value: "no_show", label: "No show" },
  ],
};

const CHECKIN_FILTER: FilterDefinition = {
  key: "checked_in",
  label: "Check-in",
  options: [
    { value: "true", label: "Checked in" },
    { value: "false", label: "Not checked in" },
  ],
};

function statusTone(status: GuestStatus): "success" | "warning" | "neutral" | "danger" {
  const map: Record<GuestStatus, "success" | "warning" | "neutral" | "danger"> = {
    confirmed: "success",
    waitlisted: "warning",
    cancelled: "neutral",
    no_show: "danger",
  };
  return map[status] ?? "neutral";
}

function GuestDrawer({
  eventId,
  guest,
  onClose,
  onCheckin,
  onUndoCheckin,
  checkingIn,
}: {
  eventId: number;
  guest: GuestRecord;
  onClose: () => void;
  onCheckin: () => void;
  onUndoCheckin: () => void;
  checkingIn: boolean;
}) {
  const toast = useToast();
  const qc = useQueryClient();
  const [newNote, setNewNote] = useState("");
  const [newTag, setNewTag] = useState("");
  const [tags, setTags] = useState<string[]>(guest.tags);

  const addNote = useMutation({
    mutationFn: () => eventsApi.addGuestNote(eventId, guest.id, newNote),
    onSuccess: () => {
      toast.success("Note added.", "Saved");
      setNewNote("");
      void qc.invalidateQueries({ queryKey: ["eventos", "guests", eventId] });
    },
    onError: (err: unknown) => toast.error(errorMessage(err), "Failed"),
  });

  const updateTags = useMutation({
    mutationFn: (updated: string[]) => eventsApi.updateGuestTags(eventId, guest.id, updated),
    onSuccess: () => {
      toast.success("Tags updated.", "Saved");
      void qc.invalidateQueries({ queryKey: ["eventos", "guests", eventId] });
    },
    onError: (err: unknown) => toast.error(errorMessage(err), "Failed"),
  });

  const handleAddTag = () => {
    const trimmed = newTag.trim();
    if (!trimmed || tags.includes(trimmed)) return;
    const updated = [...tags, trimmed];
    setTags(updated);
    setNewTag("");
    updateTags.mutate(updated);
  };

  const handleRemoveTag = (tag: string) => {
    const updated = tags.filter((t) => t !== tag);
    setTags(updated);
    updateTags.mutate(updated);
  };

  const timelineItems: TimelineItem[] = [
    ...guest.notes.map((n) => ({
      id: `note-${n.id}`,
      title: n.note,
      timestamp: n.created_at,
      description: n.author,
      tone: "neutral" as const,
    })),
    ...(guest.checked_in && guest.checked_in_at
      ? [
          {
            id: "checkin",
            title: `Checked in${guest.checked_in_by ? ` by ${guest.checked_in_by}` : ""}`,
            timestamp: guest.checked_in_at,
            tone: "success" as const,
          },
        ]
      : []),
  ].sort((a, b) => a.timestamp.localeCompare(b.timestamp));

  return (
    <Drawer
      open
      onClose={onClose}
      title={guest.name}
      description={`${guest.email}${guest.phone ? ` · ${guest.phone}` : ""}`}
      footer={
        <div className="eos-inline">
          <Button onClick={onClose}>Close</Button>
          {guest.checked_in ? (
            <Button loading={checkingIn} onClick={onUndoCheckin}>
              Undo check-in
            </Button>
          ) : (
            <Button variant="primary" loading={checkingIn} onClick={onCheckin}>
              Check in
            </Button>
          )}
        </div>
      }
    >
      <Stack>
        <Grid minColumnWidth={140}>
          <div>
            <p className="eos-field__label">Ticket</p>
            <strong>{guest.ticket_type_name}</strong>
            <p className="eos-page__description">{guest.ticket_number}</p>
          </div>
          <div>
            <p className="eos-field__label">Status</p>
            <Badge tone={statusTone(guest.status)}>{guest.status}</Badge>
          </div>
          <div>
            <p className="eos-field__label">Check-in</p>
            {guest.checked_in ? (
              <Badge tone="success">
                {guest.checked_in_at ? formatDateTime(guest.checked_in_at) : "Yes"}
              </Badge>
            ) : (
              <Badge tone="neutral">Not admitted</Badge>
            )}
          </div>
          {guest.is_complimentary && (
            <div>
              <p className="eos-field__label">Type</p>
              <Badge tone="info">Complimentary</Badge>
            </div>
          )}
        </Grid>

        <Card title="Tags">
          <Stack>
            <div className="eos-inline" style={{ flexWrap: "wrap" }}>
              {tags.map((tag) => (
                <Badge key={tag} tone="neutral">
                  {tag}{" "}
                  <button
                    onClick={() => handleRemoveTag(tag)}
                    style={{
                      background: "none",
                      border: "none",
                      cursor: "pointer",
                      padding: "0 0 0 4px",
                      color: "inherit",
                    }}
                    aria-label={`Remove tag ${tag}`}
                  >
                    ×
                  </button>
                </Badge>
              ))}
              {tags.length === 0 && <span className="eos-page__description">No tags yet</span>}
            </div>
            <div className="eos-inline">
              <Input
                value={newTag}
                onChange={(e) => setNewTag(e.target.value)}
                placeholder="Add tag…"
                onKeyDown={(e) => e.key === "Enter" && handleAddTag()}
              />
              <Button size="sm" onClick={handleAddTag} disabled={!newTag.trim()}>
                Add
              </Button>
            </div>
          </Stack>
        </Card>

        {guest.attendance_history.length > 0 && (
          <Card title="Attendance history">
            <DataTable
              caption="Events this guest has attended"
              columns={[
                { key: "event_title", header: "Event", cell: (row) => row.event_title },
                {
                  key: "event_starts_at",
                  header: "Date",
                  cell: (row) => formatDateTime(row.event_starts_at),
                },
                {
                  key: "checked_in",
                  header: "Attended",
                  cell: (row) => (
                    <Badge tone={row.checked_in ? "success" : "neutral"}>
                      {row.checked_in ? "Yes" : "No"}
                    </Badge>
                  ),
                },
              ]}
              rows={guest.attendance_history}
              getRowId={(row) => String(row.event_id)}
              emptyTitle="No history"
            />
          </Card>
        )}

        <Card title="Notes &amp; activity">
          <Stack>
            {timelineItems.length > 0 ? (
              <Timeline items={timelineItems} />
            ) : (
              <p className="eos-page__description">No notes yet.</p>
            )}
            <Textarea
              label="Add note"
              rows={2}
              value={newNote}
              onChange={(e) => setNewNote(e.target.value)}
              placeholder="Internal note visible to organisers only…"
            />
            <Button
              size="sm"
              loading={addNote.isPending}
              disabled={!newNote.trim()}
              onClick={() => addNote.mutate()}
            >
              Save note
            </Button>
          </Stack>
        </Card>
      </Stack>
    </Drawer>
  );
}

export function GuestsTab({ eventId }: Props) {
  const toast = useToast();
  const qc = useQueryClient();
  const [search, setSearch] = useState("");
  const [filterValues, setFilterValues] = useState<Record<string, string>>({});
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<GuestRecord | null>(null);

  const PER_PAGE = 20;
  const status = filterValues["status"] ?? "";
  const checkedIn = filterValues["checked_in"] ?? "";

  const { data, isLoading, error } = useQuery({
    queryKey: ["eventos", "guests", eventId, { search, status, checkedIn, page }],
    queryFn: () =>
      eventsApi.eventGuests(eventId, {
        search,
        status,
        checked_in: checkedIn === "true" ? true : checkedIn === "false" ? false : undefined,
        page,
        per_page: PER_PAGE,
      }),
    placeholderData: (prev) => prev,
  });

  const checkin = useMutation({
    mutationFn: (guestId: number) => eventsApi.checkinGuest(eventId, guestId),
    onSuccess: (_, guestId) => {
      toast.success("Guest checked in.", "Checked in");
      void qc.invalidateQueries({ queryKey: ["eventos", "guests", eventId] });
      if (selected?.id === guestId) {
        setSelected((g) =>
          g ? { ...g, checked_in: true, checked_in_at: new Date().toISOString() } : g,
        );
      }
    },
    onError: (err: unknown) => toast.error(errorMessage(err), "Check-in failed"),
  });

  const undoCheckin = useMutation({
    mutationFn: (guestId: number) => eventsApi.undoCheckin(eventId, guestId),
    onSuccess: (_, guestId) => {
      toast.success("Check-in reversed.", "Reversed");
      void qc.invalidateQueries({ queryKey: ["eventos", "guests", eventId] });
      if (selected?.id === guestId) {
        setSelected((g) => (g ? { ...g, checked_in: false, checked_in_at: null } : g));
      }
    },
    onError: (err: unknown) => toast.error(errorMessage(err), "Undo failed"),
  });

  const guests = data?.items ?? [];
  const total = data?.total ?? 0;
  const totalPages = data?.totalPages ?? 1;
  const checkedInCount = guests.filter((g) => g.checked_in).length;

  const columns: DataTableColumn<GuestRecord>[] = [
    {
      key: "name",
      header: "Guest",
      cell: (row) => (
        <div className="eos-inline">
          <Avatar name={row.name} size={28} />
          <Stack>
            <button className="eos-btn eos-btn--link" onClick={() => setSelected(row)}>
              <strong>{row.name}</strong>
            </button>
            <span className="eos-page__description">{row.email}</span>
          </Stack>
        </div>
      ),
    },
    {
      key: "ticket_type_name",
      header: "Ticket",
      cell: (row) => (
        <Stack>
          <span>{row.ticket_type_name}</span>
          <span className="eos-page__description">{row.ticket_number}</span>
        </Stack>
      ),
    },
    {
      key: "status",
      header: "Status",
      cell: (row) => <Badge tone={statusTone(row.status)}>{row.status}</Badge>,
    },
    {
      key: "checked_in",
      header: "Check-in",
      cell: (row) =>
        row.checked_in ? (
          <Badge tone="success">
            {row.checked_in_at ? formatDateTime(row.checked_in_at) : "Admitted"}
          </Badge>
        ) : (
          <Badge tone="neutral">Pending</Badge>
        ),
    },
    {
      key: "tags",
      header: "Tags",
      cell: (row) => (
        <div className="eos-inline" style={{ flexWrap: "wrap", gap: 4 }}>
          {row.tags.length > 0 ? (
            row.tags.map((t) => (
              <Badge key={t} tone="neutral">
                {t}
              </Badge>
            ))
          ) : (
            <span className="eos-page__description">—</span>
          )}
        </div>
      ),
    },
    {
      key: "is_complimentary",
      header: "Comp",
      cell: (row) => (row.is_complimentary ? <Badge tone="info">Comp</Badge> : null),
    },
    {
      key: "id",
      header: "",
      cell: (row) => (
        <div className="eos-inline">
          <Button size="sm" onClick={() => setSelected(row)}>
            View
          </Button>
          {row.checked_in ? (
            <Button
              size="sm"
              loading={undoCheckin.isPending && undoCheckin.variables === row.id}
              onClick={() => undoCheckin.mutate(row.id)}
            >
              Undo
            </Button>
          ) : (
            <Button
              size="sm"
              variant="primary"
              loading={checkin.isPending && checkin.variables === row.id}
              onClick={() => checkin.mutate(row.id)}
            >
              Check in
            </Button>
          )}
        </div>
      ),
    },
  ];

  return (
    <Stack>
      <Grid minColumnWidth={160}>
        <StatCard label="Total guests" value={total.toLocaleString()} />
        <StatCard
          label="Checked in"
          value={checkedInCount.toLocaleString()}
          hint={total > 0 ? `${Math.round((checkedInCount / total) * 100)}%` : undefined}
          trend={{ direction: checkedInCount > 0 ? "up" : "flat", label: "admitted" }}
        />
        <StatCard label="Not admitted" value={(total - checkedInCount).toLocaleString()} />
      </Grid>

      <Card title={`Guest list${total > 0 ? ` (${total.toLocaleString()})` : ""}`}>
        <Stack>
          <FilterBar
            search={{
              value: search,
              onChange: setSearch,
              placeholder: "Search by name, email, ticket #…",
            }}
            filters={[STATUS_FILTERS, CHECKIN_FILTER]}
            values={filterValues}
            onFilterChange={(key, value) => {
              setFilterValues((prev) => ({ ...prev, [key]: value }));
              setPage(1);
            }}
            onReset={() => {
              setFilterValues({});
              setSearch("");
              setPage(1);
            }}
          />

          {isLoading ? (
            <LoadingState label="Loading guests…" />
          ) : error ? (
            <Alert tone="danger" title="Could not load guests">
              {errorMessage(error)}
            </Alert>
          ) : (
            <>
              <DataTable
                caption="Guest list for this event"
                columns={columns}
                rows={guests}
                getRowId={(row) => String(row.id)}
                emptyTitle="No guests found"
                emptyDescription={
                  search || status || checkedIn
                    ? "Try adjusting your filters."
                    : "Guests appear here once tickets are sold or issued."
                }
              />
              {totalPages > 1 && (
                <Pagination
                  page={page}
                  totalPages={totalPages}
                  total={total}
                  onPageChange={setPage}
                />
              )}
            </>
          )}
        </Stack>
      </Card>

      {selected && (
        <GuestDrawer
          eventId={eventId}
          guest={selected}
          onClose={() => setSelected(null)}
          onCheckin={() => checkin.mutate(selected.id)}
          onUndoCheckin={() => undoCheckin.mutate(selected.id)}
          checkingIn={checkin.isPending || undoCheckin.isPending}
        />
      )}
    </Stack>
  );
}
