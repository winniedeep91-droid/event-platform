/**
 * Audit trail screen: activity entries that carry before/after values, with a
 * side-by-side comparison panel.
 */
import { useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { platformApi, type ActivityEntryRecord, type ActivityListParams } from "../../api";
import {
  Alert,
  Badge,
  Button,
  Card,
  DataTable,
  Drawer,
  FilterBar,
  Grid,
  Input,
  PageLayout,
  Pagination,
  Section,
  Stack,
  StatCard,
  type DataTableColumn,
} from "../../ui";
import {
  diffRows,
  formatDateTime,
  formatValue,
  humanise,
  severityTone,
  slugOptions,
} from "./shared";

const PER_PAGE = 20;

export function AuditView() {
  const [search, setSearch] = useState("");
  const [module, setModule] = useState("");
  const [severity, setSeverity] = useState("");
  const [since, setSince] = useState("");
  const [until, setUntil] = useState("");
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<ActivityEntryRecord | null>(null);

  const params = useMemo<ActivityListParams>(
    () => ({ search, module, severity, since, until, page, per_page: PER_PAGE }),
    [search, module, severity, since, until, page],
  );

  const filters = useQuery({
    queryKey: ["eventos", "platform", "activity-filters"],
    queryFn: platformApi.activityFilters,
  });

  const entries = useQuery({
    queryKey: ["eventos", "platform", "audit", params],
    queryFn: () => platformApi.audit(params),
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
      header: "Change",
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
    { key: "user", header: "Changed by", cell: (row) => row.user.name || "System" },
    {
      key: "fields",
      header: "Fields changed",
      width: "140px",
      cell: (row) => String(diffRows(row.before, row.after).filter((diff) => diff.changed).length),
    },
    {
      key: "severity",
      header: "Severity",
      width: "120px",
      cell: (row) => <Badge tone={severityTone(row.severity)}>{humanise(row.severity)}</Badge>,
    },
    {
      key: "actions",
      header: "",
      align: "right",
      width: "120px",
      cell: (row) => (
        <Button size="sm" variant="secondary" onClick={() => setSelected(row)}>
          Compare
        </Button>
      ),
    },
  ];

  const changes = selected ? diffRows(selected.before, selected.after) : [];

  return (
    <PageLayout
      title="Audit Trail"
      description="Recorded changes with their previous and new values."
    >
      <Stack>
        {entries.error ? <Alert tone="danger">{(entries.error as Error).message}</Alert> : null}

        <Grid minColumnWidth={220}>
          <StatCard
            label="Audited changes"
            value={entries.data?.total ?? 0}
            loading={entries.isLoading}
          />
          <StatCard label="Total log entries" value={filters.data?.total ?? 0} />
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
                placeholder: "Search audited changes",
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
              onReset={() => {
                setSearch("");
                setModule("");
                setSeverity("");
                setSince("");
                setUntil("");
                setPage(1);
              }}
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
            caption="Audit trail"
            columns={columns}
            rows={entries.data?.items ?? []}
            getRowId={(row) => String(row.id)}
            loading={entries.isLoading}
            emptyTitle="No audited changes"
            emptyDescription="Changes that record previous and new values will appear here."
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

      <Drawer
        open={Boolean(selected)}
        onClose={() => setSelected(null)}
        title={selected ? humanise(selected.action) : "Change"}
        description={
          selected
            ? `${selected.user.name || "System"} · ${formatDateTime(selected.created_at)}`
            : undefined
        }
      >
        {selected ? (
          <div className="eos-table-wrap">
            <table className="eos-table">
              <caption className="eos-visually-hidden">Before and after values</caption>
              <thead>
                <tr>
                  <th scope="col">Field</th>
                  <th scope="col">Before</th>
                  <th scope="col">After</th>
                </tr>
              </thead>
              <tbody>
                {changes.map((row) => (
                  <tr key={row.key}>
                    <td data-label="Field">
                      <strong>{row.key}</strong>
                      {row.changed ? (
                        <>
                          {" "}
                          <Badge tone="warning">Changed</Badge>
                        </>
                      ) : null}
                    </td>
                    <td data-label="Before">
                      <pre className="eos-code">{formatValue(row.before)}</pre>
                    </td>
                    <td data-label="After">
                      <pre className="eos-code">{formatValue(row.after)}</pre>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}
      </Drawer>
    </PageLayout>
  );
}
