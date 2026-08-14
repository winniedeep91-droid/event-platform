/** Venue management: list, create, edit and delete venues. */
import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { eventsApi, type VenueRecord } from "../../api";
import {
  Alert,
  Button,
  Card,
  ConfirmDialog,
  DataTable,
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
import { errorMessage } from "./shared";

interface VenueForm {
  name: string;
  address_line1: string;
  address_line2: string;
  city: string;
  province: string;
  postal_code: string;
  country: string;
  maps_url: string;
  parking_info: string;
  capacity: string;
  notes: string;
}

const EMPTY: VenueForm = {
  name: "",
  address_line1: "",
  address_line2: "",
  city: "",
  province: "",
  postal_code: "",
  country: "",
  maps_url: "",
  parking_info: "",
  capacity: "",
  notes: "",
};

function toForm(venue: VenueRecord): VenueForm {
  return {
    name: venue.name,
    address_line1: venue.address_line1,
    address_line2: venue.address_line2,
    city: venue.city,
    province: venue.province,
    postal_code: venue.postal_code,
    country: venue.country,
    maps_url: venue.maps_url,
    parking_info: venue.parking_info,
    capacity: String(venue.capacity || ""),
    notes: venue.notes,
  };
}

export function VenuesView() {
  const toast = useToast();
  const queryClient = useQueryClient();

  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(20);
  const [editing, setEditing] = useState<VenueRecord | null>(null);
  const [creating, setCreating] = useState(false);
  const [form, setForm] = useState<VenueForm>(EMPTY);
  const [pendingDelete, setPendingDelete] = useState<VenueRecord | null>(null);

  const params = { search, page, per_page: perPage, orderby: "name", order: "asc" };
  const list = useQuery({
    queryKey: ["eventos", "events", "venues", params],
    queryFn: () => eventsApi.venues(params),
  });

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ["eventos", "events"] });
  };

  const set = <K extends keyof VenueForm>(key: K, value: VenueForm[K]) =>
    setForm((current) => ({ ...current, [key]: value }));

  const payload = () => ({
    ...form,
    capacity: Number(form.capacity) || 0,
  });

  const save = useMutation({
    mutationFn: () =>
      editing ? eventsApi.updateVenue(editing.id, payload()) : eventsApi.createVenue(payload()),
    onSuccess: () => {
      toast.success(editing ? "Venue updated." : "Venue created.", "Saved");
      setEditing(null);
      setCreating(false);
      setForm(EMPTY);
      invalidate();
    },
    onError: (error: unknown) => toast.error(errorMessage(error), "Save failed"),
  });

  const remove = useMutation({
    mutationFn: (id: number) => eventsApi.removeVenue(id),
    onSuccess: () => {
      toast.success("Venue deleted and detached from its events.", "Deleted");
      setPendingDelete(null);
      invalidate();
    },
    onError: (error: unknown) => toast.error(errorMessage(error), "Delete failed"),
  });

  const columns: DataTableColumn<VenueRecord>[] = [
    { key: "name", header: "Venue", cell: (row) => <strong>{row.name}</strong> },
    {
      key: "location",
      header: "Location",
      cell: (row) => [row.city, row.province, row.country].filter(Boolean).join(", ") || "—",
    },
    { key: "capacity", header: "Capacity", align: "right", cell: (row) => row.capacity || "—" },
    {
      key: "actions",
      header: "Actions",
      align: "right",
      cell: (row) => (
        <div className="eos-inline" style={{ justifyContent: "flex-end" }}>
          <Button
            size="sm"
            variant="ghost"
            onClick={() => {
              setEditing(row);
              setForm(toForm(row));
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

  const open = creating || Boolean(editing);

  return (
    <PageLayout
      title="Venues"
      description="Every location events can be scheduled against."
      actions={
        <Button
          variant="primary"
          onClick={() => {
            setForm(EMPTY);
            setEditing(null);
            setCreating(true);
          }}
        >
          New venue
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
              placeholder: "Search venues…",
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
            title="Could not load venues"
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
            caption="Venues"
            columns={columns}
            rows={list.data?.items ?? []}
            getRowId={(row) => String(row.id)}
            loading={list.isLoading}
            emptyTitle="No venues yet"
            emptyDescription="Create the first venue to schedule events against it."
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
        open={open}
        onClose={() => {
          setCreating(false);
          setEditing(null);
        }}
        title={editing ? `Edit ${editing.name}` : "New venue"}
        size="lg"
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
              Save venue
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
              label="Address line 1"
              value={form.address_line1}
              onChange={(e) => set("address_line1", e.target.value)}
            />
            <Input
              label="Address line 2"
              value={form.address_line2}
              onChange={(e) => set("address_line2", e.target.value)}
            />
            <Input label="City" value={form.city} onChange={(e) => set("city", e.target.value)} />
            <Input
              label="Province"
              value={form.province}
              onChange={(e) => set("province", e.target.value)}
            />
            <Input
              label="Postal code"
              value={form.postal_code}
              onChange={(e) => set("postal_code", e.target.value)}
            />
            <Input
              label="Country"
              value={form.country}
              onChange={(e) => set("country", e.target.value)}
            />
            <Input
              label="Capacity"
              type="number"
              min={0}
              value={form.capacity}
              onChange={(e) => set("capacity", e.target.value)}
            />
            <Input
              label="Maps URL"
              value={form.maps_url}
              onChange={(e) => set("maps_url", e.target.value)}
            />
          </Grid>
          <Textarea
            label="Parking information"
            rows={2}
            value={form.parking_info}
            onChange={(e) => set("parking_info", e.target.value)}
          />
          <Textarea
            label="Notes"
            rows={3}
            value={form.notes}
            onChange={(e) => set("notes", e.target.value)}
          />
        </Stack>
      </Modal>

      <ConfirmDialog
        open={Boolean(pendingDelete)}
        title={`Delete “${pendingDelete?.name ?? ""}”?`}
        description="Events using this venue keep their schedule but lose the venue link."
        confirmLabel="Delete venue"
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
