/** Artist profiles: list, create, edit, delete and performance history. */
import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { eventsApi, type ArtistRecord } from "../../api";
import {
  Alert,
  Badge,
  Button,
  Card,
  ConfirmDialog,
  DataTable,
  Drawer,
  FilterBar,
  Grid,
  Input,
  Modal,
  Pagination,
  PageLayout,
  Stack,
  Textarea,
  useToast,
  type DataTableColumn,
} from "../../ui";
import { errorMessage, formatDateTime, statusLabel } from "./shared";

interface ArtistForm {
  name: string;
  biography: string;
  genres: string;
  website: string;
  country: string;
}

const EMPTY: ArtistForm = { name: "", biography: "", genres: "", website: "", country: "" };

export function ArtistsView() {
  const toast = useToast();
  const queryClient = useQueryClient();

  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(20);
  const [form, setForm] = useState<ArtistForm>(EMPTY);
  const [editing, setEditing] = useState<ArtistRecord | null>(null);
  const [creating, setCreating] = useState(false);
  const [inspecting, setInspecting] = useState<number | null>(null);
  const [pendingDelete, setPendingDelete] = useState<ArtistRecord | null>(null);

  const params = { search, page, per_page: perPage, orderby: "name", order: "asc" };
  const list = useQuery({
    queryKey: ["eventos", "events", "artists", params],
    queryFn: () => eventsApi.artists(params),
  });

  const detail = useQuery({
    queryKey: ["eventos", "events", "artist", inspecting],
    queryFn: () => eventsApi.artist(inspecting as number),
    enabled: inspecting !== null,
  });

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ["eventos", "events"] });
  };

  const set = <K extends keyof ArtistForm>(key: K, value: ArtistForm[K]) =>
    setForm((current) => ({ ...current, [key]: value }));

  const save = useMutation({
    mutationFn: () => {
      const payload = {
        name: form.name,
        biography: form.biography,
        website: form.website,
        country: form.country,
        genres: form.genres
          .split(",")
          .map((genre) => genre.trim())
          .filter(Boolean),
      };

      return editing
        ? eventsApi.updateArtist(editing.id, payload)
        : eventsApi.createArtist(payload);
    },
    onSuccess: () => {
      toast.success(editing ? "Artist updated." : "Artist created.", "Saved");
      setEditing(null);
      setCreating(false);
      setForm(EMPTY);
      invalidate();
    },
    onError: (error: unknown) => toast.error(errorMessage(error), "Save failed"),
  });

  const remove = useMutation({
    mutationFn: (id: number) => eventsApi.removeArtist(id),
    onSuccess: () => {
      toast.success("Artist deleted.", "Deleted");
      setPendingDelete(null);
      invalidate();
    },
    onError: (error: unknown) => toast.error(errorMessage(error), "Delete failed"),
  });

  const columns: DataTableColumn<ArtistRecord>[] = [
    { key: "name", header: "Artist", cell: (row) => <strong>{row.name}</strong> },
    {
      key: "genres",
      header: "Genres",
      cell: (row) =>
        row.genres.length ? (
          <div className="eos-inline">
            {row.genres.map((genre) => (
              <Badge key={genre}>{genre}</Badge>
            ))}
          </div>
        ) : (
          "—"
        ),
    },
    { key: "country", header: "Country", cell: (row) => row.country || "—" },
    {
      key: "actions",
      header: "Actions",
      align: "right",
      cell: (row) => (
        <div className="eos-inline" style={{ justifyContent: "flex-end" }}>
          <Button size="sm" variant="ghost" onClick={() => setInspecting(row.id)}>
            Performances
          </Button>
          <Button
            size="sm"
            variant="ghost"
            onClick={() => {
              setEditing(row);
              setForm({
                name: row.name,
                biography: row.biography,
                genres: row.genres.join(", "),
                website: row.website,
                country: row.country,
              });
            }}
          >
            Edit
          </Button>
          <Button size="sm" variant="danger" onClick={() => setPendingDelete(row)}>
            Delete
          </Button>
        </div>
      ),
    },
  ];

  return (
    <PageLayout
      title="Artists"
      description="Performer profiles available to every event."
      actions={
        <Button
          variant="primary"
          onClick={() => {
            setForm(EMPTY);
            setEditing(null);
            setCreating(true);
          }}
        >
          New artist
        </Button>
      }
    >
      <Stack>
        <Card flush>
          <FilterBar
            search={{
              value: search,
              onChange: (value) => {
                setPage(1);
                setSearch(value);
              },
              placeholder: "Search artists…",
            }}
            onReset={() => {
              setSearch("");
              setPage(1);
            }}
          />
        </Card>

        {list.error ? (
          <Alert
            tone="danger"
            title="Could not load artists"
            actions={
              <Button size="sm" onClick={() => void list.refetch()}>
                Retry
              </Button>
            }
          >
            {errorMessage(list.error)}
          </Alert>
        ) : (
          <DataTable
            caption="Artists"
            columns={columns}
            rows={list.data?.items ?? []}
            getRowId={(row) => String(row.id)}
            loading={list.isLoading}
            emptyTitle="No artists yet"
            emptyDescription="Create the first artist profile to build line-ups."
            footer={
              <Pagination
                page={list.data?.page ?? page}
                totalPages={list.data?.totalPages ?? 1}
                total={list.data?.total}
                perPage={perPage}
                onPageChange={setPage}
                onPerPageChange={(value) => {
                  setPerPage(value);
                  setPage(1);
                }}
              />
            }
          />
        )}
      </Stack>

      <Modal
        open={creating || Boolean(editing)}
        onClose={() => {
          setCreating(false);
          setEditing(null);
        }}
        title={editing ? `Edit ${editing.name}` : "New artist"}
        footer={
          <>
            <Button
              variant="ghost"
              onClick={() => {
                setCreating(false);
                setEditing(null);
              }}
            >
              Cancel
            </Button>
            <Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>
              Save artist
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
          />
          <Grid minColumnWidth={240}>
            <Input
              label="Genres"
              hint="Comma separated"
              value={form.genres}
              onChange={(e) => set("genres", e.target.value)}
            />
            <Input
              label="Country"
              value={form.country}
              onChange={(e) => set("country", e.target.value)}
            />
            <Input
              label="Website"
              value={form.website}
              onChange={(e) => set("website", e.target.value)}
            />
          </Grid>
          <Textarea
            label="Biography"
            rows={5}
            value={form.biography}
            onChange={(e) => set("biography", e.target.value)}
          />
        </Stack>
      </Modal>

      <Drawer
        open={inspecting !== null}
        onClose={() => setInspecting(null)}
        title={detail.data?.name ?? "Artist"}
      >
        <DataTable
          caption="Performance history"
          columns={[
            { key: "event_title", header: "Event", cell: (row) => row.event_title },
            {
              key: "event_starts_at",
              header: "Date",
              cell: (row) => formatDateTime(row.event_starts_at),
            },
            { key: "event_status", header: "Status", cell: (row) => statusLabel(row.event_status) },
            { key: "billing", header: "Billing", cell: (row) => row.billing || "—" },
          ]}
          rows={detail.data?.performances ?? []}
          getRowId={(row) => `${row.event_id}-${row.starts_at ?? ""}`}
          loading={detail.isLoading}
          emptyTitle="No performances"
          emptyDescription="This artist has not been booked yet."
        />
      </Drawer>

      <ConfirmDialog
        open={Boolean(pendingDelete)}
        title={`Delete “${pendingDelete?.name ?? ""}”?`}
        description="The artist is removed from every line-up they appear in."
        confirmLabel="Delete artist"
        destructive
        busy={remove.isPending}
        onCancel={() => setPendingDelete(null)}
        onConfirm={() => {
          if (pendingDelete) remove.mutate(pendingDelete.id);
        }}
      />
    </PageLayout>
  );
}
