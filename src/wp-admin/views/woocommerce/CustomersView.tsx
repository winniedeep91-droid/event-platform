import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Alert,
  Avatar,
  Badge,
  Button,
  Card,
  DataTable,
  Drawer,
  FilterBar,
  Grid,
  LinkButton,
  LoadingState,
  PageLayout,
  Pagination,
  Stack,
  StatCard,
  useToast,
  type DataTableColumn,
  type FilterDefinition,
} from "../../ui";
import { wcApi, type WcCustomerRecord } from "../../api";
import { fmtDate, fmtMoney, wcErrorMessage } from "./shared";

const SEGMENT_FILTER: FilterDefinition = {
  key: "segment",
  label: "Segment",
  options: [
    { value: "repeat", label: "Repeat attendees" },
    { value: "high_value", label: "High value" },
    { value: "lapsed", label: "Lapsed" },
    { value: "new", label: "New" },
  ],
};

function CustomerDrawer({
  customer,
  onClose,
}: {
  customer: WcCustomerRecord;
  onClose: () => void;
}) {
  return (
    <Drawer
      open
      onClose={onClose}
      title={`${customer.first_name} ${customer.last_name}`}
      description={customer.email}
      footer={
        <LinkButton
          href={`/wp-admin/user-edit.php?user_id=${customer.wc_customer_id}`}
          target="_blank"
          rel="noreferrer"
        >
          Edit in WordPress ↗
        </LinkButton>
      }
    >
      <Stack>
        <div className="eos-inline">
          <Avatar name={`${customer.first_name} ${customer.last_name}`} size={48} />
          <Stack>
            <strong style={{ fontSize: "var(--eos-text-lg)" }}>
              {customer.first_name} {customer.last_name}
            </strong>
            <span className="eos-page__description">{customer.username}</span>
          </Stack>
        </div>

        <Grid minColumnWidth={140}>
          <div>
            <p className="eos-field__label">Total spent</p>
            <strong>{fmtMoney(customer.total_spent)}</strong>
          </div>
          <div>
            <p className="eos-field__label">Total orders</p>
            <span>{customer.total_orders}</span>
          </div>
          <div>
            <p className="eos-field__label">Events attended</p>
            <span>{customer.eos_events_attended}</span>
          </div>
          <div>
            <p className="eos-field__label">Last synced</p>
            <span>{fmtDate(customer.eos_synced_at)}</span>
          </div>
          <div>
            <p className="eos-field__label">Customer since</p>
            <span>{fmtDate(customer.date_created)}</span>
          </div>
        </Grid>

        {customer.eos_segments.length > 0 && (
          <Card title="Segments">
            <div className="eos-inline" style={{ flexWrap: "wrap" }}>
              {customer.eos_segments.map((s) => (
                <Badge key={s} tone="info">
                  {s}
                </Badge>
              ))}
            </div>
          </Card>
        )}

        <Card title="Billing address">
          <p>
            {[
              customer.billing.address_1,
              customer.billing.address_2,
              customer.billing.city,
              customer.billing.state,
              customer.billing.postcode,
              customer.billing.country,
            ]
              .filter(Boolean)
              .join(", ") || "—"}
          </p>
          {customer.billing.phone && (
            <p className="eos-page__description">{customer.billing.phone}</p>
          )}
        </Card>

        {customer.eos_attendance_history.length > 0 && (
          <Card title="Attendance history">
            <DataTable
              caption="Events attended by this customer"
              columns={[
                { key: "event_title", header: "Event", cell: (r) => r.event_title },
                { key: "event_starts_at", header: "Date", cell: (r) => fmtDate(r.event_starts_at) },
                {
                  key: "checked_in",
                  header: "Attended",
                  cell: (r) => (
                    <Badge tone={r.checked_in ? "success" : "neutral"}>
                      {r.checked_in ? "Yes" : "No"}
                    </Badge>
                  ),
                },
              ]}
              rows={customer.eos_attendance_history}
              getRowId={(r) => String(r.event_id)}
              emptyTitle="No attendance history"
            />
          </Card>
        )}
      </Stack>
    </Drawer>
  );
}

export function CustomersView() {
  const toast = useToast();
  const qc = useQueryClient();
  const [filterValues, setFilterValues] = useState<Record<string, string>>({});
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<WcCustomerRecord | null>(null);

  const PER_PAGE = 20;
  const segment = filterValues["segment"] ?? "";

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ["wc", "customers", { search, segment, page }],
    queryFn: () => wcApi.customers({ search, status: segment, page, per_page: PER_PAGE }),
    placeholderData: (prev) => prev,
  });

  const segmentsQuery = useQuery({
    queryKey: ["wc", "customer-segments"],
    queryFn: () => wcApi.customerSegments(),
    retry: false,
  });

  const syncMutation = useMutation({
    mutationFn: () => wcApi.syncCustomers(),
    onSuccess: () => {
      toast.success("Customer sync queued.", "Sync started");
      void qc.invalidateQueries({ queryKey: ["wc", "customers"] });
    },
    onError: (err: unknown) => toast.error(wcErrorMessage(err), "Sync failed"),
  });

  const customers = data?.items ?? [];
  const total = data?.total ?? 0;
  const totalPages = data?.totalPages ?? 1;
  const segments = segmentsQuery.data?.segments ?? [];

  const columns: DataTableColumn<WcCustomerRecord>[] = [
    {
      key: "first_name",
      header: "Customer",
      cell: (row) => (
        <div className="eos-inline">
          <Avatar name={`${row.first_name} ${row.last_name}`} size={28} />
          <Stack>
            <button className="eos-btn eos-btn--link" onClick={() => setSelected(row)}>
              <strong>
                {row.first_name} {row.last_name}
              </strong>
            </button>
            <span className="eos-page__description">{row.email}</span>
          </Stack>
        </div>
      ),
    },
    {
      key: "total_spent",
      header: "Total spent",
      cell: (row) => fmtMoney(row.total_spent),
    },
    {
      key: "total_orders",
      header: "Orders",
      cell: (row) => row.total_orders,
    },
    {
      key: "eos_events_attended",
      header: "Events attended",
      cell: (row) => row.eos_events_attended,
    },
    {
      key: "eos_segments",
      header: "Segments",
      cell: (row) => (
        <div className="eos-inline" style={{ flexWrap: "wrap", gap: 4 }}>
          {row.eos_segments.length > 0 ? (
            row.eos_segments.map((s) => (
              <Badge key={s} tone="info">
                {s}
              </Badge>
            ))
          ) : (
            <span className="eos-page__description">—</span>
          )}
        </div>
      ),
    },
    {
      key: "eos_synced_at",
      header: "Last synced",
      cell: (row) => <span className="eos-page__description">{fmtDate(row.eos_synced_at)}</span>,
    },
    {
      key: "id",
      header: "",
      cell: (row) => (
        <Button size="sm" onClick={() => setSelected(row)}>
          View
        </Button>
      ),
    },
  ];

  return (
    <PageLayout
      title="Customers"
      description="Everyone who has registered or checked out through WooCommerce, with attendance and spend at a glance."
    >
      <Stack>
        <Grid minColumnWidth={160}>
          <StatCard label="Total customers" value={total.toLocaleString()} />
          <StatCard label="Segments" value={segments.length} />
          {segments.slice(0, 2).map((s) => (
            <StatCard key={s.id} label={s.label} value={s.count.toLocaleString()} />
          ))}
        </Grid>

        {segments.length > 0 && (
          <Card title="Audience segments">
            <DataTable
              caption="Customer segments"
              columns={[
                { key: "label", header: "Segment", cell: (r) => <strong>{r.label}</strong> },
                { key: "id", header: "ID", cell: (r) => <code>{r.id}</code> },
                { key: "count", header: "Customers", cell: (r) => r.count.toLocaleString() },
              ]}
              rows={segments}
              getRowId={(r) => r.id}
              emptyTitle="No segments"
            />
          </Card>
        )}

        <Card
          title={`Customers${total > 0 ? ` (${total.toLocaleString()})` : ""}`}
          actions={
            <Button
              variant="primary"
              loading={syncMutation.isPending}
              onClick={() => syncMutation.mutate()}
            >
              Sync customers
            </Button>
          }
        >
          <Stack>
            <FilterBar
              search={{
                value: search,
                onChange: setSearch,
                placeholder: "Search by name or email…",
              }}
              filters={[SEGMENT_FILTER]}
              values={filterValues}
              onFilterChange={(key, value) => {
                setFilterValues((prev) => ({ ...prev, [key]: value }));
                setPage(1);
              }}
              onReset={() => {
                setFilterValues({});
                setSearch("");
                setPage(1);
              }}
            />

            {isLoading ? (
              <LoadingState label="Loading customers…" />
            ) : error ? (
              <Alert
                tone="danger"
                title="Could not load customers"
                actions={
                  <Button size="sm" onClick={() => void refetch()}>
                    Retry
                  </Button>
                }
              >
                {wcErrorMessage(error)}
              </Alert>
            ) : (
              <>
                <DataTable
                  caption="WooCommerce customers"
                  columns={columns}
                  rows={customers}
                  getRowId={(row) => String(row.id)}
                  emptyTitle="No customers found"
                  emptyDescription={
                    search || segment
                      ? "Try adjusting your filters."
                      : "Customers who register or check out in WooCommerce will appear here automatically."
                  }
                />
                {totalPages > 1 && (
                  <Pagination
                    page={page}
                    totalPages={totalPages}
                    total={total}
                    onPageChange={setPage}
                  />
                )}
              </>
            )}
          </Stack>
        </Card>

        {selected && <CustomerDrawer customer={selected} onClose={() => setSelected(null)} />}
      </Stack>
    </PageLayout>
  );
}
