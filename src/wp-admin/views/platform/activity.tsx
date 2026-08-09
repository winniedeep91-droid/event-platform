/**
 * Activity log screen: paginated, filterable view of the EventOS audit trail.
 */
import { useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { platformApi, type ActivityEntryRecord, type ActivityListParams } from "../../api";
import {
  Alert,
  Badge,
  Button,
  Card,
  ConfirmDialog,
  DataTable,
  FilterBar,
  Grid,
  Input,
  PageLayout,
  Pagination,
  Section,
  Stack,
  StatCard,
  useToast,
  type DataTableColumn,
} from "../../ui";
import { formatDateTime, humanise, severityTone, slugOptions } from "./shared";

const PER_PAGE = 20;

export function ActivityView() {
  const queryClient = useQueryClient();
  const { push } = useToast();

  const [search, setSearch] = useState("");
  const [module, setModule] = useState("");
  const [severity, setSeverity] = useState("");
  const [since, setSince] = useState("");
  const [until, setUntil] = useState("");
  const [page, setPage] = useState(1);
  const [purgeOpen, setPurgeOpen] = useState(false);
  const [purgeDays, setPurgeDays] = useState(90);

  const params = useMemo<ActivityListParams>(
    () => ({ search, module, severity, since, until, page, per_page: PER_PAGE }),
    [search, module, severity, since, until, page],
  );

  const filters = useQuery({
    queryKey: ["eventos", "platform", "activity-filters"],
    queryFn: platformApi.activityFilters,
  });

  const entries = useQuery({
    queryKey: ["eventos", "platform", "activity", params],
    queryFn: () => platformApi.activity(params),
  });

  const purge = useMutation({
    mutationFn: () => platformApi.purgeActivity(purgeDays),
    onSuccess: (result) => {
      setPurgeOpen(false);
      push({ tone: "success", title: `${result.deleted} entries removed.` });
      void queryClient.invalidateQueries({ queryKey: ["eventos", "platform"] });
    },
    onError: (error: Error) => push({ tone: "danger", title: error.message }),
  });

  const columns: DataTableColumn<ActivityEntryRecord>[] = [
    {
      key: "created_at",
      header: "When",
      width: "170px",
      cell: (row) => formatDateTime(row.created_at),
    },
    {
      key: "action",
      header: "Action",
      cell: (row) => (
        <Stack>
          <strong>{humanise(row.action)}</strong>
          <span className="eos-field__hint">{humanise(row.module)}</span>
        </Stack>
      ),
    },
    {
      key: "entity",
      header: "Entity",
      cell: (row) =>
        row.entity.type ? `${humanise(row.entity.type)} #${row.entity.id || "—"}` : "—",
    },
    { key: "user", header: "User", cell: (row) => row.user.name || "System" },
    {
      key: "severity",
      header: "Severity",
      width: "120px",
      cell: (row) => <Badge tone={severityTone(row.severity)}>{humanise(row.severity)}</Badge>,
    },
  ];

  const reset = () => {
    setSearch("");
    setModule("");
    setSeverity("");
    setSince("");
    setUntil("");
    setPage(1);
  };

  return (
    <PageLayout
      title="Activity Log"
      description="Every action recorded across the EventOS platform."
      actions={
        <Button variant="danger" onClick={() => setPurgeOpen(true)}>
          Purge old entries
        </Button>
      }
    >
      <Stack>
        {entries.error ? <Alert tone="danger">{(entries.error as Error).message}</Alert> : null}

        <Grid minColumnWidth={220}>
          <StatCard
            label="Entries retained"
            value={filters.data?.total ?? 0}
            loading={filters.isLoading}
          />
          <StatCard
            label="Matching this filter"
            value={entries.data?.total ?? 0}
            loading={entries.isLoading}
          />
          <StatCard label="Modules reporting" value={filters.data?.modules.length ?? 0} />
        </Grid>

        <Section title="Filters">
          <Stack>
            <FilterBar
              search={{
                value: search,
                onChange: (value) => {
                  setSearch(value);
                  setPage(1);
                },
                placeholder: "Search actions, entities and context",
              }}
              filters={[
                {
                  key: "module",
                  label: "Module",
                  placeholder: "All modules",
                  options: slugOptions(filters.data?.modules ?? []),
                },
                {
                  key: "severity",
                  label: "Severity",
                  placeholder: "All severities",
                  options: slugOptions(filters.data?.severities ?? []),
                },
              ]}
              values={{ module, severity }}
              onFilterChange={(key, value) => {
                setPage(1);
                if (key === "module") setModule(value);
                if (key === "severity") setSeverity(value);
              }}
              onReset={reset}
            />
            <Grid minColumnWidth={220}>
              <Input
                type="date"
                label="From"
                value={since}
                onChange={(event) => {
                  setSince(event.target.value);
                  setPage(1);
                }}
              />
              <Input
                type="date"
                label="Until"
                value={until}
                onChange={(event) => {
                  setUntil(event.target.value);
                  setPage(1);
                }}
              />
            </Grid>
          </Stack>
        </Section>

        <Card flush>
          <DataTable
            caption="Activity log"
            columns={columns}
            rows={entries.data?.items ?? []}
            getRowId={(row) => String(row.id)}
            loading={entries.isLoading}
            emptyTitle="No activity recorded"
            emptyDescription="Actions performed in EventOS will appear here."
            footer={
              <Pagination
                page={entries.data?.page ?? 1}
                totalPages={entries.data?.totalPages ?? 1}
                total={entries.data?.total ?? 0}
                onPageChange={setPage}
              />
            }
          />
        </Card>
      </Stack>

      <ConfirmDialog
        open={purgeOpen}
        onCancel={() => setPurgeOpen(false)}
        onConfirm={() => purge.mutate()}
        busy={purge.isPending}
        destructive
        confirmLabel="Purge entries"
        title="Purge activity entries"
        description={
          <Input
            type="number"
            min={1}
            label="Delete entries older than (days)"
            value={String(purgeDays)}
            onChange={(event) => setPurgeDays(Math.max(1, Number(event.target.value)))}
          />
        }
      />
    </PageLayout>
  );
}
