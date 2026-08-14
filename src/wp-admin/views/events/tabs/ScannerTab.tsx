import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Alert,
  Badge,
  Button,
  Card,
  DataTable,
  Grid,
  Input,
  LoadingState,
  Pagination,
  Stack,
  StatCard,
  useToast,
  type DataTableColumn,
  type Tone,
} from "../../../ui";
import { eventsApi, type ScanOutcome, type ScanRecord } from "../../../api";
import { errorMessage, formatDateTime } from "../shared";

interface Props {
  eventId: number;
}

function outcomeTone(outcome: ScanOutcome): "success" | "danger" | "warning" | "neutral" {
  const map: Record<ScanOutcome, "success" | "danger" | "warning" | "neutral"> = {
    admitted: "success",
    already_scanned: "warning",
    invalid: "danger",
    cancelled: "neutral",
  };
  return map[outcome] ?? "neutral";
}

function outcomeLabel(outcome: ScanOutcome): string {
  const map: Record<ScanOutcome, string> = {
    admitted: "Admitted",
    already_scanned: "Already scanned",
    invalid: "Invalid",
    cancelled: "Cancelled",
  };
  return map[outcome] ?? outcome;
}

function LiveCounter({ eventId }: { eventId: number }) {
  const { data } = useQuery({
    queryKey: ["eventos", "scanner", "count", eventId],
    queryFn: () => eventsApi.liveCount(eventId),
    refetchInterval: 10_000,
    retry: false,
  });

  const checkedIn = data?.checked_in ?? 0;
  const total = data?.total ?? 0;
  const capacity = data?.capacity ?? 0;
  const pct = total > 0 ? Math.min(100, Math.round((checkedIn / total) * 100)) : 0;
  const capPct = capacity > 0 ? Math.min(100, Math.round((checkedIn / capacity) * 100)) : 0;

  return (
    <Grid minColumnWidth={160}>
      <StatCard
        label="Checked in"
        value={checkedIn.toLocaleString()}
        hint={`${pct}% of sold tickets`}
        trend={{ direction: checkedIn > 0 ? "up" : "flat", label: "live" }}
      />
      <StatCard
        label="Remaining"
        value={(total - checkedIn).toLocaleString()}
        hint="Not yet admitted"
      />
      <StatCard label="Total tickets" value={total.toLocaleString()} />
      {capacity > 0 && (
        <StatCard label="Capacity" value={`${capPct}%`} hint={`${checkedIn} of ${capacity}`} />
      )}
    </Grid>
  );
}

function ManualValidation({ eventId }: { eventId: number }) {
  const toast = useToast();
  const qc = useQueryClient();
  const [code, setCode] = useState("");
  const [lastResult, setLastResult] = useState<{
    valid: boolean;
    outcome: ScanOutcome;
    message: string;
    guest_name: string;
    ticket_type_name: string;
    ticket_number: string;
    already_scanned_at: string | null;
  } | null>(null);

  const validate = useMutation({
    mutationFn: (c: string) => eventsApi.validateTicket(eventId, c, "manual"),
    onSuccess: (res) => {
      setLastResult(res);
      setCode("");
      void qc.invalidateQueries({ queryKey: ["eventos", "scanner", "count", eventId] });
      void qc.invalidateQueries({ queryKey: ["eventos", "scan-history", eventId] });
    },
    onError: (err: unknown) => toast.error(errorMessage(err), "Validation failed"),
  });

  const handleSubmit = () => {
    const trimmed = code.trim();
    if (!trimmed) return;
    validate.mutate(trimmed);
  };

  const resultTone: Tone =
    lastResult?.outcome === "admitted"
      ? "success"
      : lastResult?.outcome === "already_scanned"
        ? "warning"
        : "danger";

  return (
    <Card title="Manual entry">
      <Stack>
        <div className="eos-inline">
          <Input
            value={code}
            onChange={(e) => setCode(e.target.value)}
            onKeyDown={(e) => e.key === "Enter" && handleSubmit()}
            placeholder="Ticket number or QR code value…"
            autoFocus
          />
          <Button
            variant="primary"
            loading={validate.isPending}
            disabled={!code.trim()}
            onClick={handleSubmit}
          >
            Validate
          </Button>
          {lastResult && <Button onClick={() => setLastResult(null)}>Clear</Button>}
        </div>

        {lastResult && (
          <Alert tone={resultTone} title={outcomeLabel(lastResult.outcome)}>
            <Stack>
              <p className="eos-page__description">{lastResult.message}</p>
              {lastResult.valid && (
                <Grid minColumnWidth={140}>
                  <div>
                    <p className="eos-field__label">Guest</p>
                    <strong>{lastResult.guest_name || "—"}</strong>
                  </div>
                  <div>
                    <p className="eos-field__label">Ticket type</p>
                    <span>{lastResult.ticket_type_name || "—"}</span>
                  </div>
                  <div>
                    <p className="eos-field__label">Ticket #</p>
                    <span>{lastResult.ticket_number || "—"}</span>
                  </div>
                </Grid>
              )}
              {lastResult.outcome === "already_scanned" && lastResult.already_scanned_at && (
                <p className="eos-page__description">
                  Previously admitted: {formatDateTime(lastResult.already_scanned_at)}
                </p>
              )}
            </Stack>
          </Alert>
        )}
      </Stack>
    </Card>
  );
}

function ScanHistory({ eventId }: { eventId: number }) {
  const toast = useToast();
  const qc = useQueryClient();
  const [page, setPage] = useState(1);
  const PER_PAGE = 25;

  const { data, isLoading, error } = useQuery({
    queryKey: ["eventos", "scan-history", eventId, { page }],
    queryFn: () => eventsApi.scanHistory(eventId, { page, per_page: PER_PAGE }),
    placeholderData: (prev) => prev,
  });

  const undo = useMutation({
    mutationFn: (scanId: number) => eventsApi.undoScan(eventId, scanId),
    onSuccess: () => {
      toast.success("Check-in reversed.", "Reversed");
      void qc.invalidateQueries({ queryKey: ["eventos", "scan-history", eventId] });
      void qc.invalidateQueries({ queryKey: ["eventos", "scanner", "count", eventId] });
      void qc.invalidateQueries({ queryKey: ["eventos", "guests", eventId] });
    },
    onError: (err: unknown) => toast.error(errorMessage(err), "Undo failed"),
  });

  const columns: DataTableColumn<ScanRecord>[] = [
    { key: "scanned_at", header: "Time", cell: (row) => formatDateTime(row.scanned_at) },
    { key: "guest_name", header: "Guest", cell: (row) => <strong>{row.guest_name}</strong> },
    { key: "ticket_type_name", header: "Ticket type", cell: (row) => row.ticket_type_name },
    {
      key: "ticket_number",
      header: "Ticket #",
      cell: (row) => (
        <span style={{ fontFamily: "monospace", fontSize: "0.85em" }}>{row.ticket_number}</span>
      ),
    },
    {
      key: "outcome",
      header: "Result",
      cell: (row) => <Badge tone={outcomeTone(row.outcome)}>{outcomeLabel(row.outcome)}</Badge>,
    },
    { key: "method", header: "Method", cell: (row) => <Badge tone="neutral">{row.method}</Badge> },
    { key: "operator", header: "Operator", cell: (row) => row.operator || "—" },
    { key: "entry_point", header: "Entry", cell: (row) => row.entry_point || "—" },
    {
      key: "id",
      header: "",
      cell: (row) =>
        row.outcome === "admitted" ? (
          <Button
            size="sm"
            loading={undo.isPending && undo.variables === row.id}
            onClick={() => undo.mutate(row.id)}
          >
            Undo
          </Button>
        ) : null,
    },
  ];

  const scans = data?.items ?? [];
  const totalPages = data?.totalPages ?? 1;

  return (
    <Card title={`Scan history${data ? ` (${data.total.toLocaleString()})` : ""}`}>
      {isLoading ? (
        <LoadingState label="Loading scan history…" />
      ) : error ? (
        <Alert tone="danger" title="Could not load scan history">
          {errorMessage(error)}
        </Alert>
      ) : (
        <Stack>
          <DataTable
            caption="Scan history for this event"
            columns={columns}
            rows={scans}
            getRowId={(row) => String(row.id)}
            emptyTitle="No scans yet"
            emptyDescription="Scan history will appear here once check-ins begin."
          />
          {totalPages > 1 && (
            <Pagination
              page={page}
              totalPages={totalPages}
              total={data?.total}
              onPageChange={setPage}
            />
          )}
        </Stack>
      )}
    </Card>
  );
}

function ScannerSessions({ eventId }: { eventId: number }) {
  const { data, isLoading } = useQuery({
    queryKey: ["eventos", "scanner", "sessions", eventId],
    queryFn: () => eventsApi.scannerSessions(eventId),
    retry: false,
  });

  const sessions = data?.sessions ?? [];
  if (isLoading || sessions.length === 0) return null;

  return (
    <Card title="Scanner sessions">
      <DataTable
        caption="Active and recent scanner sessions"
        columns={[
          { key: "operator", header: "Operator", cell: (row) => row.operator || "—" },
          { key: "device", header: "Device", cell: (row) => row.device || "—" },
          { key: "entry_point", header: "Entry point", cell: (row) => row.entry_point || "Main" },
          { key: "scans", header: "Scans", cell: (row) => row.scans.toLocaleString() },
          { key: "started_at", header: "Started", cell: (row) => formatDateTime(row.started_at) },
          {
            key: "ended_at",
            header: "Ended",
            cell: (row) =>
              row.ended_at ? formatDateTime(row.ended_at) : <Badge tone="success">Active</Badge>,
          },
        ]}
        rows={sessions}
        getRowId={(row) => String(row.id)}
        emptyTitle="No sessions"
      />
    </Card>
  );
}

export function ScannerTab({ eventId }: Props) {
  return (
    <Stack>
      <Alert tone="info" title="Scanner">
        Use the EventOS Scanner app on a mobile device or tablet to scan QR codes at the door.
        Manual validation below lets you look up a ticket by number directly from this screen.
      </Alert>
      <LiveCounter eventId={eventId} />
      <ManualValidation eventId={eventId} />
      <ScannerSessions eventId={eventId} />
      <ScanHistory eventId={eventId} />
    </Stack>
  );
}
