/** Categories and tags management for the events taxonomy. */
import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { eventsApi, type EventTaxonomy, type EventTerm } from "../../api";
import {
  Alert,
  Button,
  Card,
  ConfirmDialog,
  DataTable,
  FilterBar,
  Input,
  Modal,
  PageLayout,
  Stack,
  Textarea,
  useToast,
  type DataTableColumn,
} from "../../ui";
import { errorMessage } from "./shared";

const COPY: Record<EventTaxonomy, { title: string; description: string; singular: string }> = {
  category: {
    title: "Categories",
    description: "Hierarchical classification applied to events.",
    singular: "category",
  },
  tag: {
    title: "Tags",
    description: "Free-form labels applied to events.",
    singular: "tag",
  },
};

export function EventTermsView({ taxonomy }: { taxonomy: EventTaxonomy }) {
  const toast = useToast();
  const queryClient = useQueryClient();
  const copy = COPY[taxonomy];

  const [search, setSearch] = useState("");
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [editing, setEditing] = useState<EventTerm | null>(null);
  const [creating, setCreating] = useState(false);
  const [pendingDelete, setPendingDelete] = useState<EventTerm | null>(null);

  const list = useQuery({
    queryKey: ["eventos", "events", "terms", taxonomy, search],
    queryFn: () => eventsApi.terms(taxonomy, search),
  });

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ["eventos", "events"] });
  };

  const reset = () => {
    setCreating(false);
    setEditing(null);
    setName("");
    setDescription("");
  };

  const save = useMutation({
    mutationFn: () => {
      const payload = { name, description };
      return editing
        ? eventsApi.updateTerm(taxonomy, editing.id, payload)
        : eventsApi.createTerm(taxonomy, payload);
    },
    onSuccess: () => {
      toast.success(editing ? `The ${copy.singular} was updated.` : `The ${copy.singular} was created.`, "Saved");
      reset();
      invalidate();
    },
    onError: (error: unknown) => toast.error(errorMessage(error), "Save failed"),
  });

  const remove = useMutation({
    mutationFn: (id: number) => eventsApi.removeTerm(taxonomy, id),
    onSuccess: () => {
      toast.success(`The ${copy.singular} was deleted.`, "Deleted");
      setPendingDelete(null);
      invalidate();
    },
    onError: (error: unknown) => toast.error(errorMessage(error), "Delete failed"),
  });

  const columns: DataTableColumn<EventTerm>[] = [
    { key: "name", header: "Name", cell: (row) => <strong>{row.name}</strong> },
    { key: "slug", header: "Slug", cell: (row) => row.slug },
    { key: "description", header: "Description", cell: (row) => row.description || "—" },
    { key: "usage_count", header: "Events", align: "right", cell: (row) => row.usage_count },
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
              setName(row.name);
              setDescription(row.description);
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
      title={copy.title}
      description={copy.description}
      actions={
        <Button
          variant="primary"
          onClick={() => {
            reset();
            setCreating(true);
          }}
        >
          New {copy.singular}
        </Button>
      }
    >
      <Stack>
        {list.error ? (
          <Alert tone="danger" title={`Could not load ${copy.title.toLowerCase()}`}>{errorMessage(list.error)}</Alert>
        ) : null}

        <Card flush>
          <FilterBar
            search={{ value: search, onChange: setSearch, placeholder: `Search ${copy.title.toLowerCase()}…` }}
            onReset={() => setSearch("")}
          />
        </Card>

        <DataTable
          caption={copy.title}
          columns={columns}
          rows={list.data?.items ?? []}
          getRowId={(row) => String(row.id)}
          loading={list.isLoading}
          emptyTitle={`No ${copy.title.toLowerCase()} yet`}
          emptyDescription={`Create the first ${copy.singular} to classify events.`}
        />
      </Stack>

      <Modal
        open={creating || Boolean(editing)}
        onClose={reset}
        title={editing ? `Edit ${editing.name}` : `New ${copy.singular}`}
        footer={
          <>
            <Button variant="ghost" onClick={reset}>
              Cancel
            </Button>
            <Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>
              Save
            </Button>
          </>
        }
      >
        <Stack>
          <Input label="Name" required value={name} onChange={(event) => setName(event.target.value)} />
          <Textarea
            label="Description"
            rows={3}
            value={description}
            onChange={(event) => setDescription(event.target.value)}
          />
        </Stack>
      </Modal>

      <ConfirmDialog
        open={Boolean(pendingDelete)}
        title={`Delete “${pendingDelete?.name ?? ""}”?`}
        description={`Events keep their data but lose this ${copy.singular}.`}
        confirmLabel="Delete"
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
