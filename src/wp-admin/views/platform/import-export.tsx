/**
 * Import / Export screen: download any registered entity, or run a CSV
 * import against any registered target — generic over whatever modules have
 * registered with Export_Registry / Import_Registry, nothing here is
 * hardcoded to a specific entity.
 */
import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { platformApi, selectAttachment, type ImportRun, type ImportSource } from "../../api";
import {
  Alert,
  Badge,
  Button,
  Card,
  ConfirmDialog,
  DataTable,
  LoadingState,
  PageLayout,
  Section,
  Select,
  Stack,
  StatCard,
  Wizard,
  useToast,
  type DataTableColumn,
  type SelectOption,
  type StepDefinition,
} from "../../ui";
import { formatDateTime } from "../events/shared";

function runStatusTone(status: ImportRun["status"]): "success" | "warning" | "neutral" | "danger" {
  if ("complete" === status) return "success";
  if ("failed" === status) return "danger";
  if ("rolled_back" === status || "cancelled" === status) return "neutral";
  return "warning"; // queued, running
}

function ExportSection() {
  const entities = useQuery({
    queryKey: ["eventos", "platform", "export-entities"],
    queryFn: platformApi.exportEntities,
  });

  const [entity, setEntity] = useState("");
  const [format, setFormat] = useState("csv");

  const list = entities.data?.entities ?? [];
  const selected = list.find((e) => e.entity === entity) ?? null;

  const entityOptions: SelectOption[] = list.map((e) => ({ value: e.entity, label: e.label }));
  const formatOptions: SelectOption[] = (selected?.formats ?? ["csv"]).map((f) => ({
    value: f,
    label: f.toUpperCase(),
  }));

  return (
    <Section title="Export" description="Download any registered entity as CSV, JSON or PDF.">
      <Card>
        {entities.isLoading ? (
          <LoadingState label="Loading exportable entities…" />
        ) : list.length === 0 ? (
          <Alert tone="info" title="Nothing to export">
            No exportable entities are registered, or you don&rsquo;t have permission to export any
            of them.
          </Alert>
        ) : (
          <Stack>
            <Select
              label="Entity"
              value={entity}
              options={[{ value: "", label: "Choose an entity…" }, ...entityOptions]}
              onChange={(e) => {
                setEntity(e.target.value);
                setFormat("csv");
              }}
            />
            {selected ? (
              <>
                <Select
                  label="Format"
                  value={format}
                  options={formatOptions}
                  onChange={(e) => setFormat(e.target.value)}
                />
                <Button
                  variant="primary"
                  onClick={() => {
                    window.location.href = platformApi.exportUrl(entity, format);
                  }}
                >
                  Download {selected.label}
                </Button>
              </>
            ) : null}
          </Stack>
        )}
      </Card>
    </Section>
  );
}

function ImportWizard({ onStarted }: { onStarted: () => void }) {
  const toast = useToast();
  const [step, setStep] = useState(0);
  const [entity, setEntity] = useState("");
  const [attachmentId, setAttachmentId] = useState<number | null>(null);
  const [fileName, setFileName] = useState("");
  const [mapping, setMapping] = useState<Record<string, string>>({});

  const targets = useQuery({
    queryKey: ["eventos", "platform", "import-targets"],
    queryFn: platformApi.importTargets,
  });

  const targetList = targets.data?.targets ?? [];
  const selectedTarget = targetList.find((t) => t.entity === entity) ?? null;

  const source: ImportSource | null =
    null !== attachmentId ? { provider: "csv", attachment_id: attachmentId } : null;

  const preview = useQuery({
    queryKey: ["eventos", "platform", "import-preview", attachmentId],
    queryFn: () => platformApi.importPreview(source as ImportSource, 5),
    enabled: null !== source && step >= 2,
  });

  const suggestedMapping = useQuery({
    queryKey: ["eventos", "platform", "import-mapping", attachmentId, entity],
    queryFn: () => platformApi.importMapping(source as ImportSource, entity),
    enabled: null !== source && "" !== entity && step >= 2,
  });

  // Seeds the editable mapping once from the server's suggestion whenever it
  // changes (a new file or a new target entity) — the user can still edit
  // every field afterward, this only supplies the starting point.
  useEffect(() => {
    if (suggestedMapping.data) {
      setMapping(suggestedMapping.data);
    }
  }, [suggestedMapping.data]);

  const dryRun = useMutation({
    mutationFn: () => platformApi.startImport(source as ImportSource, entity, mapping, true),
    onError: (err: unknown) => toast.error((err as Error).message, "Validation failed"),
  });

  // Job_Queue batches run asynchronously (via WP-Cron), so the run
  // `startImport` returns is still "queued" with every count at zero — it
  // has not actually read a single row yet. Poll the run until it reaches a
  // terminal status before trusting its counts for anything, including
  // gating "Continue" on the confirm step.
  const dryRunStatus = useQuery({
    queryKey: ["eventos", "platform", "import-dry-run-status", dryRun.data?.id],
    queryFn: () => platformApi.importRun((dryRun.data as ImportRun).id),
    enabled: undefined !== dryRun.data?.id,
    refetchInterval: (query) => {
      const status = query.state.data?.status;
      return "queued" === status || "running" === status ? 1000 : false;
    },
  });

  const dryRunResult = dryRunStatus.data ?? dryRun.data;
  const dryRunSettled =
    undefined !== dryRunResult &&
    ("complete" === dryRunResult.status || "failed" === dryRunResult.status);

  const realRun = useMutation({
    mutationFn: () => platformApi.startImport(source as ImportSource, entity, mapping, false),
    onSuccess: () => {
      toast.success("Import started — processing in the background.", "Started");
      onStarted();
    },
    onError: (err: unknown) => toast.error((err as Error).message, "Import failed to start"),
  });

  const columns = preview.data?.columns ?? [];

  const steps: StepDefinition[] = [
    {
      id: "target",
      title: "Target",
      content: (
        <Stack>
          <Alert tone="info" title="Choose what you're importing into">
            Each target defines its own required fields — the next step maps your file&rsquo;s
            columns onto them.
          </Alert>
          {targets.isLoading ? (
            <LoadingState label="Loading import targets…" />
          ) : (
            <Select
              label="Entity"
              required
              value={entity}
              options={[
                { value: "", label: "Choose an entity…" },
                ...targetList.map((t) => ({ value: t.entity, label: t.label })),
              ]}
              onChange={(e) => {
                setEntity(e.target.value);
                setMapping({});
              }}
            />
          )}
          {selectedTarget ? (
            <div>
              <strong>Fields</strong>
              <ul>
                {Object.entries(selectedTarget.fields).map(([key, field]) => (
                  <li key={key}>
                    {field.label}
                    {field.required ? " (required)" : ""}
                  </li>
                ))}
              </ul>
            </div>
          ) : null}
        </Stack>
      ),
    },
    {
      id: "file",
      title: "File",
      content: (
        <Stack>
          <Button
            onClick={async () => {
              const picked = await selectAttachment("Choose a CSV file", "text");
              if (picked) {
                setAttachmentId(picked.id);
                setFileName(picked.url.split("/").pop() ?? "file.csv");
                setMapping({});
              }
            }}
          >
            {attachmentId ? "Change file" : "Choose CSV file"}
          </Button>
          {fileName ? <p className="eos-page__description">Selected: {fileName}</p> : null}
        </Stack>
      ),
    },
    {
      id: "preview",
      title: "Preview & mapping",
      content: (
        <Stack>
          {preview.isLoading || suggestedMapping.isLoading ? (
            <LoadingState label="Reading file…" />
          ) : preview.data ? (
            <>
              <p className="eos-page__description">
                {preview.data.total >= 0 ? preview.data.total.toLocaleString() : "Unknown"} row(s)
                detected. Showing the first {preview.data.rows.length}.
              </p>
              <DataTable
                caption="File preview"
                columns={columns.map((c) => ({
                  key: c,
                  header: c,
                  cell: (row: Record<string, string>) => row[c] ?? "",
                }))}
                rows={preview.data.rows}
                getRowId={(row) => String(preview.data?.rows.indexOf(row) ?? 0)}
                emptyTitle="No rows"
                emptyDescription="The file has no data rows."
              />
              {selectedTarget ? (
                <div>
                  <strong>Field mapping</strong>
                  <Stack>
                    {Object.entries(selectedTarget.fields).map(([key, field]) => (
                      <Select
                        key={key}
                        label={field.label + (field.required ? " *" : "")}
                        value={mapping[key] ?? ""}
                        options={[
                          { value: "", label: "— not mapped —" },
                          ...columns.map((c) => ({ value: c, label: c })),
                        ]}
                        onChange={(e) => setMapping((m) => ({ ...m, [key]: e.target.value }))}
                      />
                    ))}
                  </Stack>
                </div>
              ) : null}
            </>
          ) : null}
        </Stack>
      ),
    },
    {
      id: "validate",
      title: "Validate",
      content: (
        <Stack>
          <Button
            loading={dryRun.isPending || (undefined !== dryRun.data && !dryRunSettled)}
            onClick={() => dryRun.mutate()}
          >
            Check for errors
          </Button>
          {dryRunResult && dryRunSettled ? (
            <>
              <StatCard label="Valid rows" value={dryRunResult.skipped} />
              <StatCard label="Invalid rows" value={dryRunResult.failed} />
              {(dryRunResult.new ?? 0) +
                (dryRunResult.existing ?? 0) +
                (dryRunResult.duplicate ?? 0) >
              0 ? (
                <>
                  <StatCard label="New" value={dryRunResult.new ?? 0} />
                  <StatCard label="Existing (will be updated)" value={dryRunResult.existing ?? 0} />
                  {(dryRunResult.duplicate ?? 0) > 0 ? (
                    <StatCard
                      label="Duplicates (within this file)"
                      value={dryRunResult.duplicate ?? 0}
                    />
                  ) : null}
                </>
              ) : null}
              {dryRunResult.errors.length > 0 ? (
                <Alert tone="danger" title="Row errors">
                  <ul>
                    {dryRunResult.errors.slice(0, 10).map((err, i) => (
                      <li key={i}>{err}</li>
                    ))}
                  </ul>
                </Alert>
              ) : null}
            </>
          ) : dryRunResult ? (
            <LoadingState label="Checking rows in the background…" />
          ) : (
            <Alert tone="info" title="Not checked yet">
              Run a check before importing for real — this only reads the file, it never writes
              anything.
            </Alert>
          )}
        </Stack>
      ),
    },
    {
      id: "confirm",
      title: "Import",
      content: (
        <Stack>
          <Alert tone="warning" title="Confirm before importing">
            This will write real records to EventOS. Rows that fail validation are skipped and
            reported, not imported.
          </Alert>
        </Stack>
      ),
    },
  ];

  return (
    <Wizard
      steps={steps}
      current={step}
      onStepChange={setStep}
      onFinish={() => realRun.mutate()}
      finishLabel="Start import"
      busy={realRun.isPending}
      canContinue={
        (0 !== step || "" !== entity) &&
        (1 !== step || null !== attachmentId) &&
        (4 !== step || Boolean(dryRunSettled && dryRunResult && dryRunResult.failed === 0))
      }
    />
  );
}

export function ImportExportView() {
  const toast = useToast();
  const qc = useQueryClient();
  const [rollbackTarget, setRollbackTarget] = useState<number | null>(null);

  const runs = useQuery({
    queryKey: ["eventos", "platform", "import-runs"],
    queryFn: platformApi.importRuns,
    refetchInterval: (query) => {
      const list = query.state.data?.runs ?? [];
      return list.some((r) => "queued" === r.status || "running" === r.status) ? 3000 : false;
    },
  });

  const rollback = useMutation({
    mutationFn: (id: number) => platformApi.rollbackImport(id),
    onSuccess: () => {
      toast.success("Import rolled back.", "Rolled back");
      void qc.invalidateQueries({ queryKey: ["eventos", "platform", "import-runs"] });
      setRollbackTarget(null);
    },
    onError: (err: unknown) => toast.error((err as Error).message, "Rollback failed"),
  });

  const runColumns: DataTableColumn<ImportRun>[] = [
    { key: "entity", header: "Entity", cell: (row) => row.entity },
    { key: "provider", header: "Source", cell: (row) => row.provider },
    {
      key: "status",
      header: "Status",
      cell: (row) => (
        <Badge tone={runStatusTone(row.status)}>{row.dry_run ? "check" : row.status}</Badge>
      ),
    },
    { key: "imported", header: "Imported", cell: (row) => row.imported.toLocaleString() },
    { key: "failed", header: "Failed", cell: (row) => row.failed.toLocaleString() },
    { key: "created_at", header: "Started", cell: (row) => formatDateTime(row.created_at) },
    {
      key: "id",
      header: "",
      cell: (row) =>
        "complete" === row.status && !row.dry_run ? (
          <Button size="sm" variant="danger" onClick={() => setRollbackTarget(row.id)}>
            Roll back
          </Button>
        ) : null,
    },
  ];

  return (
    <PageLayout
      title="Import / Export"
      description="Download EventOS data or bring data in from a CSV file, entity by entity."
    >
      <Stack>
        <ExportSection />

        <Section
          title="Import"
          description="Upload a CSV file and map it onto any registered entity."
        >
          <Card>
            <ImportWizard
              onStarted={() =>
                void qc.invalidateQueries({ queryKey: ["eventos", "platform", "import-runs"] })
              }
            />
          </Card>
        </Section>

        <Section title="Import history">
          <Card flush>
            <DataTable
              caption="Import runs"
              columns={runColumns}
              rows={runs.data?.runs ?? []}
              getRowId={(row) => String(row.id)}
              loading={runs.isLoading}
              emptyTitle="No imports yet"
              emptyDescription="Runs appear here once you start an import above."
            />
          </Card>
        </Section>
      </Stack>

      <ConfirmDialog
        open={null !== rollbackTarget}
        title="Roll back this import?"
        description="Every record this run created will be deleted where possible. Rows it only updated (e.g. an existing CRM person) are not reverted — see each entity's own rollback notes."
        confirmLabel="Roll back"
        destructive
        busy={rollback.isPending}
        onCancel={() => setRollbackTarget(null)}
        onConfirm={() => {
          if (null !== rollbackTarget) rollback.mutate(rollbackTarget);
        }}
      />
    </PageLayout>
  );
}
