/**
 * Synchronisation screen: manage sync targets and inspect run history.
 */
import { useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { platformApi, type SyncHistoryParams, type SyncRun, type SyncTarget } from "../../api";
import {
  Alert,
  Badge,
  Button,
  Card,
  ConfirmDialog,
  DataTable,
  FilterBar,
  Grid,
  PageLayout,
  Pagination,
  Section,
  Stack,
  StatCard,
  Switch,
  useToast,
  type DataTableColumn,
} from "../../ui";
import { formatDateTime, formatDuration, humanise, slugOptions, statusTone } from "./shared";

const PER_PAGE = 20;

const STATUS_OPTIONS = [
  { value: "success", label: "Success" },
  { value: "failed", label: "Failed" },
  { value: "running", label: "Running" },
  { value: "skipped", label: "Skipped" },
];

const TRIGGER_OPTIONS = [
  { value: "manual", label: "Manual" },
  { value: "cron", label: "Scheduled" },
  { value: "queue", label: "Queued" },
];

export function SyncView() {
  const queryClient = useQueryClient();
  const toast = useToast();

  const [search, setSearch] = useState("");
  const [target, setTarget] = useState("");
  const [status, setStatus] = useState("");
  const [trigger, setTrigger] = useState("");
  const [page, setPage] = useState(1);
  const [clearOpen, setClearOpen] = useState(false);

  const params = useMemo<SyncHistoryParams>(
    () => ({ search, target, status, trigger, page, per_page: PER_PAGE }),
    [search, target, status, trigger, page],
  );

  const targets = useQuery({
    queryKey: ["eventos", "platform", "sync-targets"],
    queryFn: platformApi.syncTargets,
  });

  const history = useQuery({
    queryKey: ["eventos", "platform", "sync-history", params],
    queryFn: () => platformApi.syncHistory(params),
  });

  const invalidate = () =>
    queryClient.invalidateQueries({ queryKey: ["eventos", "platform"] });

  const run = useMutation({
    mutationFn: (slug: string) => platformApi.runSync(slug),
    onSuccess: (result) => {
      toast.success(result.message || `${result.label} finished with status ${result.status}.`);
      void invalidate();
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const queue = useMutation({
    mutationFn: (slug: string) => platformApi.queueSync(slug),
    onSuccess: (result) => {
      toast.success(`Queued as background job #${result.job_id}.`);
      void invalidate();
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const toggle = useMutation({
    mutationFn: ({ slug, enabled }: { slug: string; enabled: boolean }) =>
      platformApi.toggleSync(slug, enabled),
    onSuccess: () => {
      void invalidate();
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const clearHistory = useMutation({
    mutationFn: () => platformApi.clearSyncHistory(),
    onSuccess: () => {
      setClearOpen(false);
      toast.success("Synchronisation history cleared.");
      void invalidate();
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const targetList = targets.data?.targets ?? [];
  const stats = targets.data?.stats ?? {};

  const targetColumns: DataTableColumn<SyncTarget>[] = [
    {
      key: "label",
      header: "Target",
      cell: (row) => (
        <Stack>
          <strong>{row.label}</strong>
          {row.description ? <span className="eos-field__hint">{row.description}</span> : null}
        </Stack>
      ),
    },
    { key: "module", header: "Module", width: "140px", cell: (row) => humanise(row.module) },
    {
      key: "interval",
      header: "Interval",
      width: "130px",
      cell: (row) => (row.interval ? formatDuration(row.interval) : "Manual"),
    },
    {
      key: "last_run",
      header: "Last run",
      cell: (row) => (
        <Stack>
          <span>{formatDateTime(row.last_run_at)}</span>
          {row.last_message ? <span className="eos-field__hint">{row.last_message}</span> : null}
        </Stack>
      ),
    },
    {
      key: "status",
      header: "Status",
      width: "130px",
      cell: (row) => (
        <Badge tone={row.running ? "info" : statusTone(row.last_status)}>
          {row.running ? "Running" : humanise(row.last_status || "idle")}
        </Badge>
      ),
    },
    {
      key: "enabled",
      header: "Enabled",
      width: "150px",
      cell: (row) => (
        <Switch
          label="Enabled"
          checked={row.enabled}
          disabled={toggle.isPending}
          onChange={(checked) => toggle.mutate({ slug: row.slug, enabled: checked })}
        />
      ),
    },
    {
      key: "actions",
      header: "",
      align: "right",
      width: "190px",
      cell: (row) => (
        <div className="eos-inline">
          <Button
            size="sm"
            variant="secondary"
            loading={run.isPending}
            disabled={row.running}
            onClick={() => run.mutate(row.slug)}
          >
            Run now
          </Button>
          <Button
            size="sm"
            variant="ghost"
            loading={queue.isPending}
            onClick={() => queue.mutate(row.slug)}
          >
            Queue
          </Button>
        </div>
      ),
    },
  ];

  const historyColumns: DataTableColumn<SyncRun>[] = [
    {
      key: "started_at",
      header: "Started",
      width: "170px",
      cell: (row) => formatDateTime(row.started_at),
    },
    {
      key: "label",
      header: "Target",
      cell: (row) => (
        <Stack>
          <strong>{row.label || humanise(row.target)}</strong>
          {row.message ? <span className="eos-field__hint">{row.message}</span> : null}
        </Stack>
      ),
    },
    { key: "trigger", header: "Trigger", width: "120px", cell: (row) => humanise(row.trigger) },
    { key: "processed", header: "Processed", width: "110px", cell: (row) => row.processed },
    { key: "failed", header: "Failed", width: "100px", cell: (row) => row.failed },
    {
      key: "duration",
      header: "Duration",
      width: "120px",
      cell: (row) => formatDuration(row.duration),
    },
    {
      key: "status",
      header: "Status",
      width: "120px",
      cell: (row) => <Badge tone={statusTone(row.status)}>{humanise(row.status)}</Badge>,
    },
  ];

  return (
    <PageLayout
      title="Synchronisation"
      description="Scheduled and manual synchronisation targets registered by EventOS modules."
      actions={
        <Button variant="danger" onClick={() => setClearOpen(true)}>
          Clear history
        </Button>
      }
    >
      <Stack>
        {targets.error ? <Alert tone="danger">{(targets.error as Error).message}</Alert> : null}
        {history.error ? <Alert tone="danger">{(history.error as Error).message}</Alert> : null}

        <Grid minColumnWidth={220}>
          <StatCard label="Targets" value={targetList.length} loading={targets.isLoading} />
          <StatCard label="Successful runs" value={stats.success ?? 0} />
          <StatCard label="Failed runs" value={stats.failed ?? 0} />
          <StatCard label="Runs recorded" value={history.data?.total ?? 0} />
        </Grid>

        <Section title="Targets" description="Enable, disable, run or queue each target.">
          <Card flush>
            <DataTable
              caption="Synchronisation targets"
              columns={targetColumns}
              rows={targetList}
              getRowId={(row) => row.slug}
              loading={targets.isLoading}
              emptyTitle="No synchronisation targets"
              emptyDescription="Modules register their targets with the synchronisation registry."
            />
          </Card>
        </Section>

        <Section title="Run history">
          <Stack>
            <FilterBar
              search={{
                value: search,
                onChange: (value) => {
                  setSearch(value);
                  setPage(1);
                },
                placeholder: "Search run history",
              }}
              filters={[
                {
                  key: "target",
                  label: "Target",
                  placeholder: "All targets",
                  options: slugOptions(targetList.map((item) => item.slug)),
                },
                {
                  key: "status",
                  label: "Status",
                  placeholder: "All statuses",
                  options: STATUS_OPTIONS,
                },
                {
                  key: "trigger",
                  label: "Trigger",
                  placeholder: "All triggers",
                  options: TRIGGER_OPTIONS,
                },
              ]}
              values={{ target, status, trigger }}
              onFilterChange={(key, value) => {
                setPage(1);
                if (key === "target") setTarget(value);
                if (key === "status") setStatus(value);
                if (key === "trigger") setTrigger(value);
              }}
              onReset={() => {
                setSearch("");
                setTarget("");
                setStatus("");
                setTrigger("");
                setPage(1);
              }}
            />
            <Card flush>
              <DataTable
                caption="Synchronisation history"
                columns={historyColumns}
                rows={history.data?.items ?? []}
                getRowId={(row) => row.id}
                loading={history.isLoading}
                emptyTitle="No runs recorded"
                emptyDescription="Runs appear here once a target has been executed."
                footer={
                  <Pagination
                    page={history.data?.page ?? 1}
                    totalPages={history.data?.totalPages ?? 1}
                    total={history.data?.total ?? 0}
                    onPageChange={setPage}
                  />
                }
              />
            </Card>
          </Stack>
        </Section>
      </Stack>

      <ConfirmDialog
        open={clearOpen}
        onCancel={() => setClearOpen(false)}
        onConfirm={() => clearHistory.mutate()}
        busy={clearHistory.isPending}
        destructive
        confirmLabel="Clear history"
        title="Clear synchronisation history"
        description="Every recorded run will be deleted. Target configuration is preserved."
      />
    </PageLayout>
  );
}
