import { useEffect, useRef, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import jsQR from "jsqr";
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
import { eventsApi, type ScanOutcome, type ScanRecord, type ScanResult } from "../../../api";
import { errorMessage, formatDateTime } from "../shared";

/** Minimum time before the same decoded code is accepted again, so holding a
 * ticket in front of the camera for a moment doesn't fire repeat scans. */
const RESCAN_COOLDOWN_MS = 4000;

/** Short confirmation/error tone, synthesized so no audio asset is needed. */
function playBeep(kind: "success" | "error") {
  try {
    const Ctx =
      window.AudioContext ||
      (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
    if (!Ctx) return;
    const ctx = new Ctx();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = "sine";
    osc.frequency.value = kind === "success" ? 880 : 220;
    gain.gain.setValueAtTime(0.15, ctx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.2);
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.start();
    osc.stop(ctx.currentTime + 0.2);
    osc.onended = () => void ctx.close();
  } catch {
    // Audio is a nicety, never block scanning on it.
  }
}

function CameraScanner({ eventId }: { eventId: number }) {
  const toast = useToast();
  const qc = useQueryClient();
  const videoRef = useRef<HTMLVideoElement>(null);
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const streamRef = useRef<MediaStream | null>(null);
  const rafRef = useRef<number | null>(null);
  const lastSeenRef = useRef<Map<string, number>>(new Map());
  const pendingRef = useRef(false);

  const [running, setRunning] = useState(false);
  const [cameraError, setCameraError] = useState<string | null>(null);
  const [lastResult, setLastResult] = useState<ScanResult | null>(null);
  const [flash, setFlash] = useState<"success" | "warning" | "danger" | null>(null);

  const validate = useMutation({
    mutationFn: (code: string) => eventsApi.validateTicket(eventId, code, "qr"),
    onSuccess: (res) => {
      pendingRef.current = false;
      setLastResult(res);
      const tone =
        res.outcome === "admitted"
          ? "success"
          : res.outcome === "already_scanned"
            ? "warning"
            : "danger";
      setFlash(tone);
      playBeep(res.outcome === "admitted" ? "success" : "error");
      window.setTimeout(() => setFlash(null), 600);
      void qc.invalidateQueries({ queryKey: ["eventos", "scanner", "count", eventId] });
      void qc.invalidateQueries({ queryKey: ["eventos", "scan-history", eventId] });
    },
    onError: (err: unknown) => {
      pendingRef.current = false;
      toast.error(errorMessage(err), "Validation failed");
    },
  });

  const handleDecoded = (code: string) => {
    const now = Date.now();
    const seenAt = lastSeenRef.current.get(code);
    if (pendingRef.current || (seenAt && now - seenAt < RESCAN_COOLDOWN_MS)) return;
    lastSeenRef.current.set(code, now);
    pendingRef.current = true;
    validate.mutate(code);
  };

  useEffect(() => {
    if (!running) return;

    let cancelled = false;

    const tick = () => {
      if (cancelled) return;
      const video = videoRef.current;
      const canvas = canvasRef.current;
      if (video && canvas && video.readyState === video.HAVE_ENOUGH_DATA) {
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        const ctx = canvas.getContext("2d", { willReadFrequently: true });
        if (ctx) {
          ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
          const frame = ctx.getImageData(0, 0, canvas.width, canvas.height);
          const decoded = jsQR(frame.data, frame.width, frame.height, {
            inversionAttempts: "dontInvert",
          });
          if (decoded && decoded.data) handleDecoded(decoded.data);
        }
      }
      rafRef.current = requestAnimationFrame(tick);
    };

    void (async () => {
      try {
        const stream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: "environment" },
        });
        if (cancelled) {
          stream.getTracks().forEach((t) => t.stop());
          return;
        }
        streamRef.current = stream;
        if (videoRef.current) {
          videoRef.current.srcObject = stream;
          await videoRef.current.play();
        }
        setCameraError(null);
        rafRef.current = requestAnimationFrame(tick);
      } catch (err) {
        setCameraError(
          err instanceof DOMException && err.name === "NotAllowedError"
            ? "Camera access was denied. Allow camera permission for this site and try again."
            : "Could not access a camera on this device.",
        );
        setRunning(false);
      }
    })();

    return () => {
      cancelled = true;
      if (rafRef.current) cancelAnimationFrame(rafRef.current);
      streamRef.current?.getTracks().forEach((t) => t.stop());
      streamRef.current = null;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [running]);

  const secureContextAvailable = window.isSecureContext && !!navigator.mediaDevices?.getUserMedia;

  return (
    <Card title="Camera scanner">
      <Stack>
        {!secureContextAvailable ? (
          <Alert tone="warning" title="Camera unavailable">
            Scanning with the camera requires this page to be loaded over HTTPS. Use manual entry
            below, or open this screen on a secure (https://) address.
          </Alert>
        ) : (
          <>
            <div className="eos-inline">
              {!running ? (
                <Button variant="primary" onClick={() => setRunning(true)}>
                  Start camera
                </Button>
              ) : (
                <Button variant="danger" onClick={() => setRunning(false)}>
                  Stop camera
                </Button>
              )}
            </div>

            {cameraError && (
              <Alert tone="danger" title="Camera error">
                {cameraError}
              </Alert>
            )}

            {running && (
              <div
                style={{
                  position: "relative",
                  maxWidth: 480,
                  borderRadius: 12,
                  overflow: "hidden",
                  border: `3px solid ${
                    flash === "success"
                      ? "var(--eos-success)"
                      : flash === "warning"
                        ? "var(--eos-warning)"
                        : flash === "danger"
                          ? "var(--eos-danger)"
                          : "var(--eos-border)"
                  }`,
                  transition: "border-color 150ms ease",
                }}
              >
                <video
                  ref={videoRef}
                  playsInline
                  muted
                  style={{ width: "100%", display: "block", background: "#000" }}
                />
                <canvas ref={canvasRef} style={{ display: "none" }} />
              </div>
            )}
          </>
        )}

        {lastResult && (
          <Alert
            tone={
              lastResult.outcome === "admitted"
                ? "success"
                : lastResult.outcome === "already_scanned"
                  ? "warning"
                  : "danger"
            }
            title={
              lastResult.outcome === "admitted"
                ? "Admitted"
                : lastResult.outcome === "already_scanned"
                  ? "Already scanned"
                  : lastResult.outcome === "cancelled"
                    ? "Cancelled"
                    : "Invalid"
            }
          >
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
            </Stack>
          </Alert>
        )}
      </Stack>
    </Card>
  );
}

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
        Open this screen on a phone or tablet at the door to scan tickets with the camera, or use
        manual entry below to look up a ticket by number.
      </Alert>
      <LiveCounter eventId={eventId} />
      <CameraScanner eventId={eventId} />
      <ManualValidation eventId={eventId} />
      <ScannerSessions eventId={eventId} />
      <ScanHistory eventId={eventId} />
    </Stack>
  );
}
