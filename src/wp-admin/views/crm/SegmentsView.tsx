import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Alert,
  Badge,
  Button,
  Card,
  ConfirmDialog,
  DataTable,
  Drawer,
  Input,
  LoadingState,
  Modal,
  PageLayout,
  Stack,
  useToast,
  type DataTableColumn,
} from "../../ui";
import { crmApi, type SegmentMember, type SegmentRecord } from "../../api";
import { crmErrorMessage, fmtDate } from "./shared";

function CreateOrEditSegmentModal({
  segment,
  onClose,
}: {
  segment: SegmentRecord | null;
  onClose: () => void;
}) {
  const toast = useToast();
  const qc = useQueryClient();
  const [name, setName] = useState(segment?.name ?? "");
  const isEdit = segment !== null;

  const save = useMutation({
    mutationFn: () =>
      isEdit ? crmApi.updateSegment(segment.id, { name }) : crmApi.createSegment({ name }),
    onSuccess: () => {
      toast.success(isEdit ? "Segment updated." : "Segment created.", "Saved");
      void qc.invalidateQueries({ queryKey: ["crm", "segments"] });
      onClose();
    },
    onError: (err: unknown) => toast.error(crmErrorMessage(err), "Could not save segment"),
  });

  return (
    <Modal
      open
      onClose={onClose}
      title={isEdit ? "Edit segment" : "Create segment"}
      description="Segments are manually managed in this phase — membership is not evaluated automatically."
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button
            variant="primary"
            loading={save.isPending}
            disabled={!name.trim()}
            onClick={() => save.mutate()}
          >
            {isEdit ? "Save changes" : "Create segment"}
          </Button>
        </>
      }
    >
      <Input
        label="Segment name"
        value={name}
        onChange={(e) => setName(e.target.value)}
        placeholder="e.g. VIP, Industry, Press…"
        required
      />
    </Modal>
  );
}

function MembersDrawer({ segment, onClose }: { segment: SegmentRecord; onClose: () => void }) {
  const toast = useToast();
  const qc = useQueryClient();
  const [personId, setPersonId] = useState("");

  const members = useQuery({
    queryKey: ["crm", "segments", segment.id, "members"],
    queryFn: () => crmApi.segmentMembers(segment.id),
  });

  const attach = useMutation({
    mutationFn: (id: number) => crmApi.attachSegmentMember(segment.id, id),
    onSuccess: () => {
      setPersonId("");
      void qc.invalidateQueries({ queryKey: ["crm", "segments", segment.id, "members"] });
    },
    onError: (err: unknown) => toast.error(crmErrorMessage(err), "Could not add member"),
  });

  const detach = useMutation({
    mutationFn: (id: number) => crmApi.detachSegmentMember(segment.id, id),
    onSuccess: () =>
      void qc.invalidateQueries({ queryKey: ["crm", "segments", segment.id, "members"] }),
    onError: (err: unknown) => toast.error(crmErrorMessage(err), "Could not remove member"),
  });

  const columns: DataTableColumn<SegmentMember>[] = [
    { key: "display_name", header: "Person", cell: (row) => row.display_name || "—" },
    { key: "primary_email", header: "Email", cell: (row) => row.primary_email || "—" },
    { key: "computed_at", header: "Added", cell: (row) => fmtDate(row.computed_at) },
    {
      key: "person_id",
      header: "",
      cell: (row) => (
        <Button size="sm" loading={detach.isPending} onClick={() => detach.mutate(row.person_id)}>
          Remove
        </Button>
      ),
    },
  ];

  return (
    <Drawer open onClose={onClose} title={segment.name} description="Segment membership">
      <Stack>
        <div className="eos-inline">
          <Input
            value={personId}
            onChange={(e) => setPersonId(e.target.value)}
            placeholder="Person ID to add…"
            inputMode="numeric"
          />
          <Button
            size="sm"
            loading={attach.isPending}
            disabled={!personId.trim() || isNaN(parseInt(personId, 10))}
            onClick={() => attach.mutate(parseInt(personId, 10))}
          >
            Add
          </Button>
        </div>
        <p className="eos-page__description">
          Find the Person ID from the Customers list, then add them here — there is no
          search-by-name lookup in this member picker yet.
        </p>

        {members.isLoading ? (
          <LoadingState label="Loading members…" />
        ) : (
          <DataTable
            caption={`Members of ${segment.name}`}
            columns={columns}
            rows={members.data?.items ?? []}
            getRowId={(row) => String(row.person_id)}
            emptyTitle="No members yet"
            emptyDescription="Add a Person by ID above."
          />
        )}
      </Stack>
    </Drawer>
  );
}

export function SegmentsView() {
  const toast = useToast();
  const qc = useQueryClient();
  const [showCreate, setShowCreate] = useState(false);
  const [editing, setEditing] = useState<SegmentRecord | null>(null);
  const [viewingMembers, setViewingMembers] = useState<SegmentRecord | null>(null);
  const [archiving, setArchiving] = useState<SegmentRecord | null>(null);

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ["crm", "segments"],
    queryFn: () => crmApi.segments(),
  });

  const archive = useMutation({
    mutationFn: (id: number) => crmApi.archiveSegment(id),
    onSuccess: () => {
      toast.success("Segment archived.", "Archived");
      setArchiving(null);
      void qc.invalidateQueries({ queryKey: ["crm", "segments"] });
    },
    onError: (err: unknown) => toast.error(crmErrorMessage(err), "Could not archive segment"),
  });

  const segments = data?.segments ?? [];

  const columns: DataTableColumn<SegmentRecord>[] = [
    {
      key: "name",
      header: "Segment",
      cell: (row) => (
        <div>
          <strong>{row.name}</strong>
          {row.is_system && (
            <Badge tone="neutral" style={{ marginLeft: 8 }}>
              System
            </Badge>
          )}
        </div>
      ),
    },
    { key: "slug", header: "Slug", cell: (row) => <code>{row.slug}</code> },
    { key: "created_at", header: "Created", cell: (row) => fmtDate(row.created_at) },
    {
      key: "id",
      header: "",
      cell: (row) => (
        <div className="eos-inline">
          <Button size="sm" onClick={() => setViewingMembers(row)}>
            Members
          </Button>
          <Button size="sm" onClick={() => setEditing(row)}>
            Edit
          </Button>
          <Button size="sm" onClick={() => setArchiving(row)}>
            Archive
          </Button>
        </div>
      ),
    },
  ];

  return (
    <PageLayout
      title="Segments"
      description="Manually managed customer groupings. Membership is set by staff — there is no automatic evaluation engine yet."
      actions={
        <Button variant="primary" onClick={() => setShowCreate(true)}>
          Create segment
        </Button>
      }
    >
      <Card title={`Segments${segments.length > 0 ? ` (${segments.length})` : ""}`}>
        {isLoading ? (
          <LoadingState label="Loading segments…" />
        ) : error ? (
          <Alert
            tone="danger"
            title="Could not load segments"
            actions={
              <Button size="sm" onClick={() => void refetch()}>
                Retry
              </Button>
            }
          >
            {crmErrorMessage(error)}
          </Alert>
        ) : (
          <DataTable
            caption="CRM segments"
            columns={columns}
            rows={segments}
            getRowId={(row) => String(row.id)}
            emptyTitle="No segments yet"
            emptyDescription="Segments can be created manually — attach any permanent Person to one from their profile or from here."
          />
        )}
      </Card>

      {(showCreate || editing) && (
        <CreateOrEditSegmentModal
          segment={editing}
          onClose={() => {
            setShowCreate(false);
            setEditing(null);
          }}
        />
      )}

      {viewingMembers && (
        <MembersDrawer segment={viewingMembers} onClose={() => setViewingMembers(null)} />
      )}

      <ConfirmDialog
        open={archiving !== null}
        title={archiving ? `Archive “${archiving.name}”?` : ""}
        description="Archived segments are hidden from the default list but not deleted, and existing membership is preserved."
        confirmLabel="Archive segment"
        busy={archive.isPending}
        onCancel={() => setArchiving(null)}
        onConfirm={() => {
          if (archiving) archive.mutate(archiving.id);
        }}
      />
    </PageLayout>
  );
}
