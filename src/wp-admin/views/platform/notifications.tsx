/**
 * Notification centre: search, dismiss, delete and clear platform notices.
 */
import { useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { platformApi, type NotificationListParams, type NotificationRecord } from "../../api";
import {
  Alert,
  Badge,
  Button,
  Card,
  ConfirmDialog,
  DataTable,
  FilterBar,
  Grid,
  LinkButton,
  PageLayout,
  Pagination,
  Stack,
  StatCard,
  useToast,
  type DataTableColumn,
} from "../../ui";
import { formatDateTime, humanise, severityTone, slugOptions } from "./shared";

const PER_PAGE = 20;

const TYPE_OPTIONS = [
  { value: "info", label: "Info" },
  { value: "success", label: "Success" },
  { value: "warning", label: "Warning" },
  { value: "error", label: "Error" },
];

export function NotificationsView() {
  const queryClient = useQueryClient();
  const toast = useToast();

  const [search, setSearch] = useState("");
  const [type, setType] = useState("");
  const [module, setModule] = useState("");
  const [page, setPage] = useState(1);
  const [clearOpen, setClearOpen] = useState(false);

  const params = useMemo<NotificationListParams>(
    () => ({ search, type, module, page, per_page: PER_PAGE }),
    [search, type, module, page],
  );

  const filters = useQuery({
    queryKey: ["eventos", "platform", "activity", "filters"],
    queryFn: platformApi.activityFilters,
  });

  const notifications = useQuery({
    queryKey: ["eventos", "platform", "notifications", params],
    queryFn: () => platformApi.notifications(params),
  });

  const invalidate = () =>
    queryClient.invalidateQueries({ queryKey: ["eventos", "platform", "notifications"] });

  const dismiss = useMutation({
    mutationFn: (key: string) => platformApi.dismissNotification(key),
    onSuccess: () => {
      toast.success("Notification dismissed.");
      void invalidate();
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const remove = useMutation({
    mutationFn: (key: string) => platformApi.removeNotification(key),
    onSuccess: () => {
      toast.success("Notification deleted.");
      void invalidate();
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const clear = useMutation({
    mutationFn: () => platformApi.clearNotifications(),
    onSuccess: () => {
      setClearOpen(false);
      toast.success("Notification centre cleared.");
      void invalidate();
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const items = notifications.data?.items ?? [];

  const columns: DataTableColumn<NotificationRecord>[] = [
    {
      key: "title",
      header: "Notification",
      cell: (row) => (
        <Stack>
          <strong>{row.title}</strong>
          {row.message ? <span className="eos-field__hint">{row.message}</span> : null}
        </Stack>
      ),
    },
    {
      key: "type",
      header: "Type",
      width: "120px",
      cell: (row) => <Badge tone={severityTone(row.type)}>{humanise(row.type)}</Badge>,
    },
    { key: "module", header: "Module", width: "150px", cell: (row) => humanise(row.module) },
    {
      key: "created_at",
      header: "Raised",
      width: "170px",
      cell: (row) => formatDateTime(row.created_at),
    },
    {
      key: "actions",
      header: "",
      align: "right",
      width: "260px",
      cell: (row) => (
        <div className="eos-inline">
          {row.actions.map((action) => (
            <LinkButton key={action.url} href={action.url} size="sm" variant="secondary">
              {action.label}
            </LinkButton>
          ))}
          {row.dismissible ? (
            <Button
              size="sm"
              variant="ghost"
              loading={dismiss.isPending}
              onClick={() => dismiss.mutate(row.key)}
            >
              Dismiss
            </Button>
          ) : null}
          <Button
            size="sm"
            variant="danger"
            loading={remove.isPending}
            onClick={() => remove.mutate(row.key)}
          >
            Delete
          </Button>
        </div>
      ),
    },
  ];

  return (
    <PageLayout
      title="Notifications"
      description="Persistent notices raised by EventOS modules."
      actions={
        <Button variant="danger" onClick={() => setClearOpen(true)}>
          Clear all
        </Button>
      }
    >
      <Stack>
        {notifications.error ? (
          <Alert tone="danger">{(notifications.error as Error).message}</Alert>
        ) : null}

        <Grid minColumnWidth={220}>
          <StatCard
            label="Open notifications"
            value={notifications.data?.total ?? 0}
            loading={notifications.isLoading}
          />
          <StatCard
            label="Errors"
            value={items.filter((item) => item.type === "error").length}
            hint="On this page"
          />
          <StatCard
            label="Warnings"
            value={items.filter((item) => item.type === "warning").length}
            hint="On this page"
          />
        </Grid>

        <FilterBar
          search={{
            value: search,
            onChange: (value) => {
              setSearch(value);
              setPage(1);
            },
            placeholder: "Search notifications",
          }}
          filters={[
            { key: "type", label: "Type", placeholder: "All types", options: TYPE_OPTIONS },
            {
              key: "module",
              label: "Module",
              placeholder: "All modules",
              options: slugOptions(filters.data?.modules ?? []),
            },
          ]}
          values={{ type, module }}
          onFilterChange={(key, value) => {
            if (key === "type") setType(value);
            if (key === "module") setModule(value);
            setPage(1);
          }}
          onReset={() => {
            setSearch("");
            setType("");
            setModule("");
            setPage(1);
          }}
        />

        <Card flush>
          <DataTable
            caption="Notifications"
            columns={columns}
            rows={items}
            getRowId={(row) => row.key}
            loading={notifications.isLoading}
            emptyTitle="Nothing needs attention"
            emptyDescription="Notifications raised by modules will appear here."
            footer={
              <Pagination
                page={notifications.data?.page ?? 1}
                totalPages={notifications.data?.totalPages ?? 1}
                total={notifications.data?.total ?? 0}
                onPageChange={setPage}
              />
            }
          />
        </Card>
      </Stack>

      <ConfirmDialog
        open={clearOpen}
        onCancel={() => setClearOpen(false)}
        onConfirm={() => clear.mutate()}
        busy={clear.isPending}
        destructive
        confirmLabel="Clear notifications"
        title="Clear every notification"
        description="All persistent notifications will be deleted. This cannot be undone."
      />
    </PageLayout>
  );
}
