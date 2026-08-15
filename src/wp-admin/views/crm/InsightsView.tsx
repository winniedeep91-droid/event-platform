import { useQuery } from "@tanstack/react-query";
import {
  Alert,
  Button,
  Card,
  DataTable,
  Grid,
  LinkButton,
  LoadingState,
  PageLayout,
  Stack,
  StatCard,
  type DataTableColumn,
} from "../../ui";
import { crmApi, type RelationshipInsights } from "../../api";
import { crmErrorMessage, fmtMoney, personProfileUrl } from "./shared";

type TopRelationship = RelationshipInsights["top_relationships"][number];

export function InsightsView() {
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ["crm", "insights"],
    queryFn: () => crmApi.insights(),
  });

  const relationshipColumns: DataTableColumn<TopRelationship>[] = [
    { key: "display_name", header: "Person", cell: (row) => row.display_name || "—" },
    { key: "primary_email", header: "Email", cell: (row) => row.primary_email || "—" },
    { key: "total_spend", header: "Lifetime spend", cell: (row) => fmtMoney(row.total_spend) },
    {
      key: "total_events_attended",
      header: "Events attended",
      cell: (row) => row.total_events_attended,
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
      title="Relationship Insights"
      description="A brand-wide view of the permanent Person CRM — not the per-event analytics dashboard."
    >
      {isLoading ? (
        <LoadingState label="Loading insights…" />
      ) : error || !data ? (
        <Alert
          tone="danger"
          title="Could not load insights"
          actions={
            <Button size="sm" onClick={() => void refetch()}>
              Retry
            </Button>
          }
        >
          {error ? crmErrorMessage(error) : "No data returned."}
        </Alert>
      ) : (
        <Stack>
          <Grid minColumnWidth={180}>
            <StatCard label="Known people" value={data.total_known_people.toLocaleString()} />
            <StatCard label="Have purchased" value={data.purchased_count.toLocaleString()} />
            <StatCard label="Have attended" value={data.attended_count.toLocaleString()} />
            <StatCard
              label="Repeat customers"
              value={data.repeat_customer_count.toLocaleString()}
              hint={data.repeat_customer_definition}
            />
            <StatCard label="Known customer revenue" value={fmtMoney(data.known_revenue)} />
          </Grid>

          <Card title="Strongest relationships">
            {data.top_relationships.length > 0 ? (
              <DataTable
                caption="Persons ranked by lifetime spend"
                columns={relationshipColumns}
                rows={data.top_relationships}
                getRowId={(row) => String(row.person_id)}
                emptyTitle="No purchases yet"
              />
            ) : (
              <p className="eos-page__description">
                No known purchases yet — this list populates once Persons have recorded spend.
              </p>
            )}
          </Card>

          <Card title="Customers who haven't returned recently">
            <Alert tone="info" title="Not available yet">
              {data.lapsed_customers.reason}
            </Alert>
          </Card>
        </Stack>
      )}
    </PageLayout>
  );
}
