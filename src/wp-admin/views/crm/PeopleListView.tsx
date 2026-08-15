import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import {
  Alert,
  Avatar,
  Button,
  Card,
  DataTable,
  FilterBar,
  Grid,
  LinkButton,
  LoadingState,
  PageLayout,
  Pagination,
  Stack,
  StatCard,
  type DataTableColumn,
} from "../../ui";
import { crmApi, type PersonSummary } from "../../api";
import { crmErrorMessage, fmtDate, fmtMoney, personProfileUrl } from "./shared";

const PER_PAGE = 20;

export function PeopleListView() {
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ["crm", "persons", { search, page }],
    queryFn: () => crmApi.persons({ q: search, page, per_page: PER_PAGE }),
    placeholderData: (prev) => prev,
  });

  const people = data?.items ?? [];
  const total = data?.total ?? 0;
  const totalPages = data?.totalPages ?? 1;

  const columns: DataTableColumn<PersonSummary>[] = [
    {
      key: "display_name",
      header: "Customer",
      cell: (row) => (
        <div className="eos-inline">
          <Avatar name={row.display_name || row.primary_email || "?"} size={28} />
          <Stack>
            <LinkButton href={personProfileUrl(row.person_id)} variant="link">
              <strong>{row.display_name || "—"}</strong>
            </LinkButton>
            <span className="eos-page__description">{row.primary_email || "—"}</span>
          </Stack>
        </div>
      ),
    },
    {
      key: "primary_phone",
      header: "Phone",
      cell: (row) => row.primary_phone || <span className="eos-page__description">—</span>,
    },
    {
      key: "total_tickets_purchased",
      header: "Tickets",
      cell: (row) => row.total_tickets_purchased,
    },
    {
      key: "total_events_attended",
      header: "Events attended",
      cell: (row) => row.total_events_attended,
    },
    {
      key: "total_spend",
      header: "Lifetime spend",
      cell: (row) => fmtMoney(row.total_spend),
    },
    {
      key: "last_attendance_at",
      header: "Last attendance",
      cell: (row) => (
        <span className="eos-page__description">{fmtDate(row.last_attendance_at)}</span>
      ),
    },
    {
      key: "last_purchase_at",
      header: "Last interaction",
      cell: (row) => <span className="eos-page__description">{fmtDate(row.last_purchase_at)}</span>,
    },
    {
      key: "person_id",
      header: "",
      cell: (row) => (
        <LinkButton href={personProfileUrl(row.person_id)} size="sm">
          View
        </LinkButton>
      ),
    },
  ];

  return (
    <PageLayout
      title="Customers"
      description="Every permanent Person known to the brand, resolved across every event and identity signal — not a per-event guest list."
    >
      <Stack>
        <Grid minColumnWidth={160}>
          <StatCard label="Known people" value={total.toLocaleString()} />
        </Grid>

        <Card title={`Customers${total > 0 ? ` (${total.toLocaleString()})` : ""}`}>
          <Stack>
            <FilterBar
              search={{
                value: search,
                onChange: (value) => {
                  setSearch(value);
                  setPage(1);
                },
                placeholder: "Search by name, email or phone…",
              }}
              onReset={() => {
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
                {crmErrorMessage(error)}
              </Alert>
            ) : (
              <>
                <DataTable
                  caption="Permanent Person records"
                  columns={columns}
                  rows={people}
                  getRowId={(row) => String(row.person_id)}
                  emptyTitle="No customers yet"
                  emptyDescription={
                    search
                      ? "Try adjusting your search."
                      : "Permanent Person records appear here once WooCommerce customers and event guests are resolved into the CRM — see the People module's identity resolver and historical backfill."
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
      </Stack>
    </PageLayout>
  );
}
