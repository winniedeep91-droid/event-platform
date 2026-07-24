import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { useCurrentOrg } from "@/hooks/use-current-org";
import { ROLE_LABEL } from "@/lib/permissions";

export const Route = createFileRoute("/_authenticated/dashboard")({
  head: () => ({ meta: [{ title: "Dashboard — EventOS" }] }),
  component: DashboardPage,
});

function DashboardPage() {
  const { currentOrg, organizations } = useCurrentOrg();
  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <PageHeader
        title="Dashboard"
        description="Foundation workspace. Business modules are added in future milestones."
      />
      <div className="grid gap-4 sm:grid-cols-3">
        <Card>
          <CardHeader>
            <CardTitle>Current organization</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-lg font-medium">{currentOrg?.name ?? "—"}</p>
            <p className="text-sm text-muted-foreground">
              Your role: {currentOrg ? ROLE_LABEL[currentOrg.role] : "—"}
            </p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>Workspaces</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-3xl font-semibold">{organizations.length}</p>
            <p className="text-sm text-muted-foreground">Organizations you belong to</p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>Status</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-lg font-medium">Ready</p>
            <p className="text-sm text-muted-foreground">Foundation is production-ready.</p>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
