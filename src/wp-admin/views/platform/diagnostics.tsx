/**
 * Diagnostics screen: environment, database, scheduling and configuration
 * health checks plus the background job queue.
 */
import { useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { platformApi, type DiagnosticsCheck, type JobListParams, type JobRecord } from "../../api";
import {
  Alert,
  Badge,
  Button,
  Card,
  DataTable,
  DefinitionList,
  FilterBar,
  Grid,
  LoadingState,
  PageLayout,
  Pagination,
  Section,
  Stack,
  StatCard,
  useToast,
  type DataTableColumn,
} from "../../ui";
import { formatDateTime, humanise, statusTone } from "./shared";

const PER_PAGE = 20;

const JOB_STATUS_OPTIONS = [
  { value: "pending", label: "Pending" },
  { value: "running", label: "Running" },
  { value: "completed", label: "Completed" },
  { value: "failed", label: "Failed" },
  { value: "cancelled", label: "Cancelled" },
];

export function DiagnosticsView() {
  const queryClient = useQueryClient();
  const toast = useToast();

  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);

  const params = useMemo<JobListParams>(
    () => ({ search, status, page, per_page: PER_PAGE }),
    [search, status, page],
  );

  const report = useQuery({
    queryKey: ["eventos", "platform", "diagnostics"],
    queryFn: platformApi.diagnostics,
  });

  const jobs = useQuery({
    queryKey: ["eventos", "platform", "jobs", params],
    queryFn: () => platformApi.jobs(params),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ["eventos", "platform"] });

  const retry = useMutation({
    mutationFn: (id: number) => platformApi.retryJob(id),
    onSuccess: () => {
      toast.success("Job queued for another attempt.");
      void invalidate();
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const cancel = useMutation({
    mutationFn: (id: number) => platformApi.cancelJob(id),
    onSuccess: () => {
      toast.success("Job cancelled.");
      void invalidate();
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const data = report.data;

  const checkColumns: DataTableColumn<DiagnosticsCheck>[] = [
    {
      key: "label",
      header: "Check",
      cell: (row) => (
        <Stack>
          <strong>{row.label}</strong>
          {row.description ? <span className="eos-field__hint">{row.description}</span> : null}
        </Stack>
      ),
    },
    { key: "value", header: "Value", cell: (row) => row.value || "—" },
    {
      key: "status",
      header: "Status",
      width: "120px",
      cell: (row) => <Badge tone={statusTone(row.status)}>{humanise(row.status)}</Badge>,
    },
    { key: "hint", header: "Suggested action", cell: (row) => row.hint || "—" },
  ];

  const jobColumns: DataTableColumn<JobRecord>[] = [
    {
      key: "type",
      header: "Job",
      cell: (row) => (
        <Stack>
          <strong>{humanise(row.type)}</strong>
          <span className="eos-field__hint">{humanise(row.module)}</span>
        </Stack>
      ),
    },
    {
      key: "attempts",
      header: "Attempts",
      width: "120px",
      cell: (row) => `${row.attempts} / ${row.max_attempts}`,
    },
    {
      key: "scheduled_at",
      header: "Scheduled",
      width: "170px",
      cell: (row) => formatDateTime(row.scheduled_at),
    },
    {
      key: "status",
      header: "Status",
      width: "130px",
      cell: (row) => (
        <Stack>
          <Badge tone={statusTone(row.status)}>{humanise(row.status)}</Badge>
          {row.last_error ? <span className="eos-field__hint">{row.last_error}</span> : null}
        </Stack>
      ),
    },
    {
      key: "actions",
      header: "",
      align: "right",
      width: "180px",
      cell: (row) => (
        <div className="eos-inline">
          {row.status === "failed" ? (
            <Button
              size="sm"
              variant="secondary"
              loading={retry.isPending}
              onClick={() => retry.mutate(row.id)}
            >
              Retry
            </Button>
          ) : null}
          {row.status === "pending" ? (
            <Button
              size="sm"
              variant="danger"
              loading={cancel.isPending}
              onClick={() => cancel.mutate(row.id)}
            >
              Cancel
            </Button>
          ) : null}
        </div>
      ),
    },
  ];

  return (
    <PageLayout
      title="Diagnostics"
      description="Platform health, environment details and the background job queue."
      actions={
        <Button
          variant="secondary"
          loading={report.isFetching}
          onClick={() => void report.refetch()}
        >
          Refresh report
        </Button>
      }
    >
      <Stack>
        {report.error ? <Alert tone="danger">{(report.error as Error).message}</Alert> : null}
        {jobs.error ? <Alert tone="danger">{(jobs.error as Error).message}</Alert> : null}

        {report.isLoading ? <LoadingState label="Running diagnostics…" /> : null}

        {data ? (
          <>
            <Alert
              tone={data.healthy ? "success" : "danger"}
              title={data.healthy ? "All checks passing" : "Attention required"}
            >
              Report generated {formatDateTime(data.generated_at)}.
            </Alert>

            <Grid minColumnWidth={200}>
              <StatCard label="Passing" value={data.summary.pass} />
              <StatCard label="Warnings" value={data.summary.warn} />
              <StatCard label="Failures" value={data.summary.fail} />
              <StatCard label="Failed jobs" value={data.jobs.failed ?? 0} />
              <StatCard label="Failed syncs" value={data.sync.failed ?? 0} />
            </Grid>

            {data.categories.map((category) => {
              const checks = data.checks.filter((check) => check.category === category.slug);

              if (!checks.length) return null;

              return (
                <Section key={category.slug} title={category.label}>
                  <Card flush>
                    <DataTable
                      caption={`${category.label} checks`}
                      columns={checkColumns}
                      rows={checks}
                      getRowId={(row) => row.id}
                    />
                  </Card>
                </Section>
              );
            })}

            <Section title="Environment">
              <Card>
                <DefinitionList
                  items={[
                    { term: "Plugin version", value: data.system.plugin_version },
                    { term: "Database schema", value: data.system.db_version },
                    { term: "WordPress", value: data.system.wordpress_version },
                    { term: "PHP", value: data.system.php_version },
                    { term: "MySQL", value: data.system.mysql_version },
                    {
                      term: "WooCommerce",
                      value: data.system.woocommerce.active
                        ? data.system.woocommerce.version || "Active"
                        : "Not installed",
                    },
                    { term: "Multisite", value: data.system.multisite ? "Yes" : "No" },
                    { term: "Storage used", value: data.system.storage.used_human },
                  ]}
                />
              </Card>
            </Section>
          </>
        ) : null}

        <Section title="Background jobs">
          <Stack>
            <FilterBar
              search={{
                value: search,
                onChange: (value) => {
                  setSearch(value);
                  setPage(1);
                },
                placeholder: "Search jobs",
              }}
              filters={[
                {
                  key: "status",
                  label: "Status",
                  placeholder: "All statuses",
                  options: JOB_STATUS_OPTIONS,
                },
              ]}
              values={{ status }}
              onFilterChange={(key, value) => {
                if (key === "status") setStatus(value);
                setPage(1);
              }}
              onReset={() => {
                setSearch("");
                setStatus("");
                setPage(1);
              }}
            />
            <Card flush>
              <DataTable
                caption="Background jobs"
                columns={jobColumns}
                rows={jobs.data?.items ?? []}
                getRowId={(row) => String(row.id)}
                loading={jobs.isLoading}
                emptyTitle="No jobs recorded"
                emptyDescription="Queued background work appears here."
                footer={
                  <Pagination
                    page={jobs.data?.page ?? 1}
                    totalPages={jobs.data?.totalPages ?? 1}
                    total={jobs.data?.total ?? 0}
                    onPageChange={setPage}
                  />
                }
              />
            </Card>
          </Stack>
        </Section>
      </Stack>
    </PageLayout>
  );
}
