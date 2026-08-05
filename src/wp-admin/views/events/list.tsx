/** Events list: search, filters, sorting, pagination and lifecycle actions. */
import { useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { eventsApi, type EventListParams, type EventRecord } from "../../api";
import {
  Alert,
  Button,
  Card,
  ConfirmDialog,
  DataTable,
  FilterBar,
  Pagination,
  PageLayout,
  Select,
  StatusChip,
  Stack,
  useToast,
  type DataTableColumn,
  type FilterDefinition,
  type SelectOption,
  type SortDirection,
} from "../../ui";
import {
  EVENTS_PAGES,
  errorMessage,
  formatDateTime,
  goTo,
  pageUrl,
  statusKind,
  statusLabel,
  venueLabel,
} from "./shared";

export function EventsListView() {
  const toast = useToast();
  const queryClient = useQueryClient();

  const [search, setSearch] = useState("");
  const [filters, setFilters] = useState<Record<string, string>>({});
  const [sort, setSort] = useState<{ key: string; direction: SortDirection }>({
    key: "starts_at",
    direction: "desc",
  });
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(20);
  const [pendingDelete, setPendingDelete] = useState<EventRecord | null>(null);

  const options = useQuery({ queryKey: ["eventos", "events", "options"], queryFn: eventsApi.options });

  const params: EventListParams = {
    search,
    status: filters.status,
    visibility: filters.visibility,
    venue_id: filters.venue_id ? Number(filters.venue_id) : undefined,
    category_id: filters.category_id ? Number(filters.category_id) : undefined,
    orderby: sort.key,
    order: sort.direction,
    page,
    per_page: perPage,
  };

  const list = useQuery({
    queryKey: ["eventos", "events", "list", params],
    queryFn: () => eventsApi.list(params),
  });

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ["eventos", "events"] });
  };

  const duplicate = useMutation({
    mutationFn: (id: number) => eventsApi.duplicate(id),
    onSuccess: (event) => {
      toast.success(`“${event.title}” created as a draft.`, "Event duplicated");
      invalidate();
    },
    onError: (error: unknown) => toast.error(errorMessage(error), "Duplicate failed"),
  });

  const remove = useMutation({
    mutationFn: (id: number) => eventsApi.remove(id),
    onSuccess: () => {
      toast.success("The event and everything attached to it were deleted.", "Event deleted");
      setPendingDelete(null);
      invalidate();
    },
    onError: (error: unknown) => toast.error(errorMessage(error), "Delete failed"),
  });

  const transition = useMutation({
    mutationFn: (input: { id: number; status: string }) => eventsApi.transition(input.id, input.status),
    onSuccess: (event) => {
      toast.success(`“${event.title}” is now ${statusLabel(event.status, options.data?.statuses)}.`, "Status updated");
      invalidate();
    },
    onError: (error: unknown) => toast.error(errorMessage(error), "Transition failed"),
  });

  const statusOptions = useMemo<SelectOption[]>(
    () =>
      Object.entries(options.data?.statuses ?? {}).map(([value, label]) => ({
        value,
        label: String(label),
      })),
    [options.data],
  );

  const filterDefinitions: FilterDefinition[] = [
    { key: "status", label: "Status", options: statusOptions, placeholder: "All statuses" },
    {
      key: "visibility",
      label: "Visibility",
      options: Object.entries(options.data?.visibilities ?? {}).map(([value, label]) => ({
        value,
        label: String(label),
      })),
      placeholder: "All visibilities",
    },
    {
      key: "venue_id",
      label: "Venue",
      options: (options.data?.venues ?? []).map((venue) => ({ value: String(venue.id), label: venue.name })),
      placeholder: "All venues",
    },
    {
      key: "category_id",
      label: "Category",
      options: (options.data?.categories ?? []).map((term) => ({ value: String(term.id), label: term.name })),
      placeholder: "All categories",
    },
  ];

  const columns: DataTableColumn<EventRecord>[] = [
    {
      key: "title",
      header: "Event",
      sortable: true,
      cell: (row) => (
        <a href={pageUrl(EVENTS_PAGES.list, { event: row.id })}>
          <strong>{row.title}</strong>
          {row.subtitle ? <div className="eos-page__description">{row.subtitle}</div> : null}
        </a>
      ),
    },
    { key: "starts_at", header: "Starts", sortable: true, cell: (row) => formatDateTime(row.starts_at) },
    { key: "venue_id", header: "Venue", cell: (row) => venueLabel(row) },
    { key: "capacity", header: "Capacity", align: "right", sortable: true, cell: (row) => row.capacity || "—" },
    {
      key: "status",
      header: "Status",
      sortable: true,
      cell: (row) => (
        <StatusChip status={statusKind(row.status)} label={statusLabel(row.status, options.data?.statuses)} />
      ),
    },
    {
      key: "transition",
      header: "Move to",
      cell: (row) => {
        const targets = options.data?.transitions?.[row.status] ?? [];

        if (!targets.length) return <span className="eos-empty__description">Final</span>;

        return (
          <Select
            aria-label={`Change status of ${row.title}`}
            value=""
            placeholder="Change…"
            disabled={transition.isPending}
            options={targets.map<SelectOption>((status) => ({
              value: status,
              label: statusLabel(status, options.data?.statuses),
            }))}
            onChange={(event) => {
              if (!event.target.value) return;
              transition.mutate({ id: row.id, status: event.target.value });
            }}
          />
        );
      },
    },
    {
      key: "actions",
      header: "Actions",
      align: "right",
      cell: (row) => (
        <div className="eos-inline" style={{ justifyContent: "flex-end" }}>
          <Button size="sm" variant="ghost" onClick={() => goTo(EVENTS_PAGES.list, { event: row.id })}>
            Open
          </Button>
          <Button
            size="sm"
            variant="ghost"
            loading={duplicate.isPending && duplicate.variables === row.id}
            onClick={() => duplicate.mutate(row.id)}
          >
            Duplicate
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
      title="All events"
      description="Every event on this installation, across all lifecycle states."
      actions={
        <Button variant="primary" onClick={() => goTo(EVENTS_PAGES.list, { action: "new" })}>
          New event
        </Button>
      }
    >
      <Stack>
        {list.error ? <Alert tone="danger" title="Could not load events">{errorMessage(list.error)}</Alert> : null}

        <Card flush>
          <FilterBar
            search={{
              value: search,
              onChange: (value) => {
                setPage(1);
                setSearch(value);
              },
              placeholder: "Search events…",
            }}
            filters={filterDefinitions}
            values={filters}
            onFilterChange={(key, value) => {
              setPage(1);
              setFilters((current) => ({ ...current, [key]: value }));
            }}
            onReset={() => {
              setSearch("");
              setFilters({});
              setPage(1);
            }}
          />
        </Card>

        <DataTable
          caption="Events"
          columns={columns}
          rows={list.data?.items ?? []}
          getRowId={(row) => String(row.id)}
          loading={list.isLoading}
          sort={sort}
          onSortChange={(next) => {
            setPage(1);
            setSort(next);
          }}
          emptyTitle="No events found"
          emptyDescription="Adjust the filters, or create the first event."
          emptyAction={
            <Button variant="primary" onClick={() => goTo(EVENTS_PAGES.list, { action: "new" })}>
              New event
            </Button>
          }
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
      </Stack>

      <ConfirmDialog
        open={Boolean(pendingDelete)}
        title={`Delete “${pendingDelete?.title ?? ""}”?`}
        description="Artists, media, schedules and taxonomy links attached to this event are removed too. This cannot be undone."
        confirmLabel="Delete event"
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
