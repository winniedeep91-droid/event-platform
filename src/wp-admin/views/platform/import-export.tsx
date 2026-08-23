/**
 * Import / Export screen: download any registered entity, or run a CSV
 * import against any registered target — generic over whatever modules have
 * registered with Export_Registry / Import_Registry, nothing here is
 * hardcoded to a specific entity.
 */
import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  platformApi,
  selectAttachment,
  type ImportMappingSpec,
  type ImportProfile,
  type ImportProfileValidation,
  type ImportRun,
  type ImportSource,
  type ImportTargetField,
} from "../../api";
import {
  Alert,
  Badge,
  Button,
  Card,
  ConfirmDialog,
  DataTable,
  Inline,
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

function profileStatusTone(status: string): "success" | "warning" | "neutral" {
  if ("ready" === status) return "success";
  if ("stub" === status) return "neutral";
  return "warning";
}

/**
 * One stage's mapping-review table: EventOS field / required / mapping
 * type / source column / validation state. The backend
 * (Import_Profile_Mapper) owns every transform and every default
 * suggestion — this only edits the *pointer* (which source column, or a
 * fixed value) a field resolves to.
 */
function MappingReviewTable({
  targetFields,
  columns,
  mapping,
  onChange,
  validation,
}: {
  targetFields: Record<string, ImportTargetField>;
  columns: string[];
  mapping: Record<string, ImportMappingSpec>;
  onChange: (field: string, column: string) => void;
  validation?: ImportProfileValidation;
}) {
  const errorsByField = new Map((validation?.errors ?? []).map((e) => [e.field, e.message]));

  return (
    <Stack>
      {Object.entries(targetFields).map(([key, field]) => {
        const spec = mapping[key];
        const isConst = "object" === typeof spec && null !== spec && "const" in spec;
        const currentColumn =
          "string" === typeof spec ? spec : spec && "column" in spec ? spec.column : "";
        const transform =
          spec && "object" === typeof spec && "transform" in spec ? spec.transform : undefined;
        const error = errorsByField.get(key);

        return (
          <div key={key}>
            {isConst ? (
              <Inline>
                <span>
                  {field.label}
                  {field.required ? " *" : ""}
                </span>
                <Badge tone="neutral">
                  fixed value: {String((spec as { const: string }).const)}
                </Badge>
              </Inline>
            ) : (
              <Select
                label={
                  field.label + (field.required ? " *" : "") + (transform ? ` (${transform})` : "")
                }
                value={currentColumn}
                options={[
                  { value: "", label: "— not mapped —" },
                  ...columns.map((c) => ({ value: c, label: c })),
                ]}
                onChange={(e) => onChange(key, e.target.value)}
              />
            )}
            {error ? <Badge tone="danger">{error}</Badge> : null}
          </div>
        );
      })}
    </Stack>
  );
}

/**
 * Profile-aware import: upload → select Import Profile → resolve/edit the
 * mapping the profile suggests → validate → preview mapped rows → confirm
 * → the existing Import_Engine/Ticketing_Import_Orchestrator via
 * Import_Registry::start_profile_import()/start_profile_bundle(). A stub
 * profile (no verified export sample yet — see Phase 3) is shown but never
 * selectable.
 */
function ProfileImportWizard({ onStarted }: { onStarted: () => void }) {
  const toast = useToast();
  const [step, setStep] = useState(0);
  const [profileId, setProfileId] = useState("");
  const [mode, setMode] = useState<"single" | "bundle">("single");
  const [entity, setEntity] = useState("");
  const [attachmentId, setAttachmentId] = useState<number | null>(null);
  const [fileName, setFileName] = useState("");
  const [mapping, setMapping] = useState<Record<string, ImportMappingSpec>>({});
  const [stageAttachments, setStageAttachments] = useState<
    Record<string, { id: number; name: string }>
  >({});
  const [stageMappings, setStageMappings] = useState<
    Record<string, Record<string, ImportMappingSpec>>
  >({});

  const profiles = useQuery({
    queryKey: ["eventos", "platform", "import-profiles"],
    queryFn: platformApi.importProfiles,
  });
  const targets = useQuery({
    queryKey: ["eventos", "platform", "import-targets"],
    queryFn: platformApi.importTargets,
  });

  const profileList = profiles.data?.profiles ?? [];
  const selectedProfile: ImportProfile | null = profileList.find((p) => p.id === profileId) ?? null;
  const bundleCapable = (selectedProfile?.bundle.length ?? 0) > 1;
  const bundleStages = "bundle" === mode ? (selectedProfile?.bundle ?? []) : [];
  const stageEntities =
    selectedProfile && Object.keys(selectedProfile.stages).length > 0
      ? Object.keys(selectedProfile.stages)
      : [];

  const targetLabel = (e: string) => targets.data?.targets.find((t) => t.entity === e)?.label ?? e;
  const targetFieldsFor = (e: string) =>
    targets.data?.targets.find((t) => t.entity === e)?.fields ?? {};

  // ── Single-stage source/mapping/preview ─────────────────────────────
  const source: ImportSource | null =
    null !== attachmentId ? { provider: "csv", attachment_id: attachmentId } : null;

  const resolvedMapping = useQuery({
    queryKey: ["eventos", "platform", "profile-mapping", profileId, entity, attachmentId],
    queryFn: () => platformApi.resolveProfileMapping(profileId, entity, source as ImportSource),
    enabled: "single" === mode && null !== source && "" !== entity && "" !== profileId && step >= 2,
  });

  useEffect(() => {
    if (resolvedMapping.data) {
      setMapping(resolvedMapping.data.mapping);
    }
  }, [resolvedMapping.data]);

  const validation = useQuery({
    queryKey: [
      "eventos",
      "platform",
      "profile-validate",
      entity,
      mapping,
      resolvedMapping.data?.columns,
    ],
    queryFn: () =>
      platformApi.validateProfileMapping(
        profileId,
        entity,
        mapping,
        resolvedMapping.data?.columns ?? [],
      ),
    enabled: "single" === mode && Object.keys(mapping).length > 0 && step >= 2,
  });

  const preview = useQuery({
    queryKey: ["eventos", "platform", "profile-preview", profileId, entity, attachmentId, mapping],
    queryFn: () =>
      platformApi.previewProfileMapping(profileId, entity, source as ImportSource, mapping, 5),
    enabled: "single" === mode && null !== source && "" !== entity && step >= 3,
  });

  // ── Bundle source/mapping/preview ───────────────────────────────────
  const bundleReady =
    bundleStages.length > 0 && bundleStages.every((s) => undefined !== stageAttachments[s]);

  const bundleMapping = useQuery({
    queryKey: [
      "eventos",
      "platform",
      "profile-bundle-mapping",
      profileId,
      bundleStages.map((s) => stageAttachments[s]?.id).join(","),
    ],
    queryFn: async () => {
      const result: Record<
        string,
        { columns: string[]; mapping: Record<string, ImportMappingSpec> }
      > = {};
      for (const s of bundleStages) {
        const src: ImportSource = { provider: "csv", attachment_id: stageAttachments[s].id };
        result[s] = await platformApi.resolveProfileMapping(profileId, s, src);
      }
      return result;
    },
    enabled: bundleReady && step >= 2,
  });

  useEffect(() => {
    if (bundleMapping.data) {
      setStageMappings((prev) => {
        const next = { ...prev };
        for (const [stageEntity, resolved] of Object.entries(bundleMapping.data)) {
          if (undefined === next[stageEntity]) {
            next[stageEntity] = resolved.mapping;
          }
        }
        return next;
      });
    }
  }, [bundleMapping.data]);

  const bundlePreview = useQuery({
    queryKey: [
      "eventos",
      "platform",
      "profile-bundle-preview",
      profileId,
      bundleStages.map((s) => stageAttachments[s]?.id).join(","),
      stageMappings,
    ],
    queryFn: async () => {
      const result: Record<
        string,
        Awaited<ReturnType<typeof platformApi.previewProfileMapping>>
      > = {};
      for (const s of bundleStages) {
        const src: ImportSource = { provider: "csv", attachment_id: stageAttachments[s].id };
        result[s] = await platformApi.previewProfileMapping(
          profileId,
          s,
          src,
          stageMappings[s] ?? {},
          5,
        );
      }
      return result;
    },
    enabled: bundleReady && bundleStages.every((s) => undefined !== stageMappings[s]) && step >= 3,
  });

  const startSingle = useMutation({
    mutationFn: () =>
      platformApi.startProfileImport(profileId, entity, source as ImportSource, mapping),
    onSuccess: () => {
      toast.success("Import started — processing in the background.", "Started");
      onStarted();
    },
    onError: (err: unknown) => toast.error((err as Error).message, "Import failed to start"),
  });

  const startBundle = useMutation({
    mutationFn: () => {
      const stageSources: Record<string, ImportSource> = {};
      for (const s of bundleStages) {
        stageSources[s] = { provider: "csv", attachment_id: stageAttachments[s].id };
      }
      return platformApi.startProfileBundle(profileId, stageSources, stageMappings);
    },
    onSuccess: () => {
      toast.success(
        "Bundle import started — every stage runs in the background, one after another.",
        "Started",
      );
      onStarted();
    },
    onError: (err: unknown) => toast.error((err as Error).message, "Bundle failed to start"),
  });

  const columns = resolvedMapping.data?.columns ?? [];
  const requiredCount = Object.values(targetFieldsFor(entity)).filter((f) => f.required).length;
  const mappedRequiredCount = Object.entries(targetFieldsFor(entity)).filter(
    ([key, f]) => f.required && undefined !== mapping[key],
  ).length;

  const steps: StepDefinition[] = [
    {
      id: "profile",
      title: "Profile",
      content: (
        <Stack>
          <Alert tone="info" title="Choose an Import Profile">
            A profile describes how one platform&rsquo;s exported file maps onto EventOS. Only
            &ldquo;ready&rdquo; profiles have a verified mapping — others are placeholders for a
            platform we don&rsquo;t have a real export sample for yet.
          </Alert>
          {profiles.isLoading ? (
            <LoadingState label="Loading import profiles…" />
          ) : (
            <Stack>
              <Select
                label="Import Profile"
                required
                value={profileId}
                options={[
                  { value: "", label: "Choose a profile…" },
                  ...profileList.map((p) => ({
                    value: p.id,
                    label: `${p.name}${"ready" !== p.status ? ` (${p.status})` : ""}`,
                    disabled: "ready" !== p.status,
                  })),
                ]}
                onChange={(e) => {
                  setProfileId(e.target.value);
                  setEntity("");
                  setMapping({});
                  setStageAttachments({});
                  setStageMappings({});
                }}
              />
              {selectedProfile ? (
                <Inline>
                  <Badge tone={profileStatusTone(selectedProfile.status)}>
                    {selectedProfile.status}
                  </Badge>
                  <span className="eos-page__description">{selectedProfile.description}</span>
                </Inline>
              ) : null}
            </Stack>
          )}
          {selectedProfile && bundleCapable ? (
            <Select
              label="Import as"
              value={mode}
              options={[
                { value: "single", label: "A single file" },
                { value: "bundle", label: `A full bundle (${selectedProfile.bundle.join(" → ")})` },
              ]}
              onChange={(e) => setMode(e.target.value as "single" | "bundle")}
            />
          ) : null}
          {selectedProfile && "single" === mode ? (
            <Select
              label="Entity"
              required
              value={entity}
              options={[
                { value: "", label: "Choose an entity…" },
                ...stageEntities.map((e) => ({ value: e, label: targetLabel(e) })),
              ]}
              onChange={(e) => {
                setEntity(e.target.value);
                setMapping({});
              }}
            />
          ) : null}
        </Stack>
      ),
    },
    {
      id: "files",
      title: "File(s)",
      content: (
        <Stack>
          {"single" === mode ? (
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
          ) : (
            <Stack>
              {bundleStages.map((s) => (
                <Inline key={s}>
                  <strong style={{ minWidth: 140 }}>{targetLabel(s)}</strong>
                  <Button
                    size="sm"
                    onClick={async () => {
                      const picked = await selectAttachment(
                        `Choose the ${targetLabel(s)} CSV file`,
                        "text",
                      );
                      if (picked) {
                        setStageAttachments((prev) => ({
                          ...prev,
                          [s]: { id: picked.id, name: picked.url.split("/").pop() ?? "file.csv" },
                        }));
                        setStageMappings((prev) => {
                          const next = { ...prev };
                          delete next[s];
                          return next;
                        });
                      }
                    }}
                  >
                    {stageAttachments[s] ? "Change file" : "Choose CSV file"}
                  </Button>
                  {stageAttachments[s] ? (
                    <span className="eos-page__description">{stageAttachments[s].name}</span>
                  ) : (
                    <Badge tone="warning">not selected</Badge>
                  )}
                </Inline>
              ))}
            </Stack>
          )}
        </Stack>
      ),
    },
    {
      id: "mapping",
      title: "Mapping",
      content: (
        <Stack>
          {"single" === mode ? (
            resolvedMapping.isLoading ? (
              <LoadingState label="Detecting columns…" />
            ) : (
              <MappingReviewTable
                targetFields={targetFieldsFor(entity)}
                columns={columns}
                mapping={mapping}
                validation={validation.data}
                onChange={(field, column) =>
                  setMapping((m) => ({ ...m, [field]: column ? { column } : { const: "" } }))
                }
              />
            )
          ) : bundleMapping.isLoading ? (
            <LoadingState label="Detecting columns for every stage…" />
          ) : (
            <Stack>
              {bundleStages.map((s) => (
                <Section key={s} title={targetLabel(s)}>
                  <MappingReviewTable
                    targetFields={targetFieldsFor(s)}
                    columns={bundleMapping.data?.[s]?.columns ?? []}
                    mapping={stageMappings[s] ?? {}}
                    onChange={(field, column) =>
                      setStageMappings((prev) => ({
                        ...prev,
                        [s]: { ...(prev[s] ?? {}), [field]: column ? { column } : { const: "" } },
                      }))
                    }
                  />
                </Section>
              ))}
            </Stack>
          )}
        </Stack>
      ),
    },
    {
      id: "preview",
      title: "Preview",
      content: (
        <Stack>
          {"single" === mode ? (
            preview.isLoading ? (
              <LoadingState label="Applying the mapping to a sample of rows…" />
            ) : preview.data ? (
              <>
                <p className="eos-page__description">
                  {preview.data.total.toLocaleString()} row(s) detected. Showing the mapped result
                  for the first {preview.data.mapped_rows.length}.
                </p>
                <DataTable
                  caption="Mapped preview"
                  columns={Object.keys(targetFieldsFor(entity)).map((key) => ({
                    key,
                    header: targetFieldsFor(entity)[key].label,
                    cell: (row: Record<string, unknown>) => String(row[key] ?? ""),
                  }))}
                  rows={preview.data.mapped_rows}
                  getRowId={(row) => String(preview.data?.mapped_rows.indexOf(row) ?? 0)}
                  emptyTitle="No rows"
                  emptyDescription="The file has no data rows."
                />
              </>
            ) : null
          ) : bundlePreview.isLoading ? (
            <LoadingState label="Applying the mapping to a sample of rows for every stage…" />
          ) : (
            <Stack>
              {bundleStages.map((s) => {
                const stagePreview = bundlePreview.data?.[s];
                return (
                  <Section key={s} title={targetLabel(s)}>
                    {stagePreview ? (
                      <DataTable
                        caption={`${targetLabel(s)} mapped preview`}
                        columns={Object.keys(targetFieldsFor(s)).map((key) => ({
                          key,
                          header: targetFieldsFor(s)[key].label,
                          cell: (row: Record<string, unknown>) => String(row[key] ?? ""),
                        }))}
                        rows={stagePreview.mapped_rows}
                        getRowId={(row) => String(stagePreview.mapped_rows.indexOf(row))}
                        emptyTitle="No rows"
                        emptyDescription="The file has no data rows."
                      />
                    ) : null}
                  </Section>
                );
              })}
            </Stack>
          )}
        </Stack>
      ),
    },
    {
      id: "confirm",
      title: "Confirm",
      content: (
        <Stack>
          <Alert tone="warning" title="Confirm before importing">
            This will write real records to EventOS through the existing import engine. Rows that
            fail validation are skipped and reported, not imported.
          </Alert>
          {"single" === mode ? (
            <Inline>
              <StatCard label="Profile" value={selectedProfile?.name ?? ""} />
              <StatCard label="Entity" value={targetLabel(entity)} />
              <StatCard label="Rows detected" value={preview.data?.total ?? 0} />
              <StatCard
                label="Required fields mapped"
                value={`${mappedRequiredCount} / ${requiredCount}`}
              />
            </Inline>
          ) : (
            <Inline>
              <StatCard label="Profile" value={selectedProfile?.name ?? ""} />
              <StatCard label="Stages" value={bundleStages.length} />
              {bundleStages.map((s) => (
                <StatCard
                  key={s}
                  label={`${targetLabel(s)} rows`}
                  value={bundlePreview.data?.[s]?.total ?? 0}
                />
              ))}
            </Inline>
          )}
        </Stack>
      ),
    },
  ];

  return (
    <Wizard
      steps={steps}
      current={step}
      onStepChange={setStep}
      onFinish={() => ("single" === mode ? startSingle.mutate() : startBundle.mutate())}
      finishLabel="Start import"
      busy={startSingle.isPending || startBundle.isPending}
      canContinue={
        (0 !== step || ("single" === mode ? "" !== entity : bundleCapable)) &&
        (1 !== step ||
          ("single" === mode
            ? null !== attachmentId
            : bundleStages.every((s) => stageAttachments[s]))) &&
        (4 !== step ||
          ("single" === mode ? true === validation.data?.valid : bundleStages.length > 0))
      }
    />
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
          title="Import with a profile"
          description="Upload an exported file and apply a registered Import Profile — the recommended way to bring in Events, Ticket Types and Tickets, single files or a full multi-file bundle."
        >
          <Card>
            <ProfileImportWizard
              onStarted={() =>
                void qc.invalidateQueries({ queryKey: ["eventos", "platform", "import-runs"] })
              }
            />
          </Card>
        </Section>

        <Section
          title="Generic import"
          description="Upload a CSV file and map it onto any registered entity by hand — for targets with no Import Profile."
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
