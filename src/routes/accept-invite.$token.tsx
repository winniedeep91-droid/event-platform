import { useEffect, useState } from "react";
import { createFileRoute, useNavigate, useParams, Link } from "@tanstack/react-router";
import { useServerFn } from "@tanstack/react-start";
import { Loader2 } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { useAuth } from "@/hooks/use-auth";
import { acceptInvitation } from "@/lib/invitations.functions";

export const Route = createFileRoute("/accept-invite/$token")({
  head: () => ({
    meta: [
      { title: "Accept invitation — EventOS" },
      { name: "robots", content: "noindex" },
    ],
  }),
  component: AcceptInvitePage,
});

function AcceptInvitePage() {
  const { token } = useParams({ from: "/accept-invite/$token" });
  const { user, loading } = useAuth();
  const navigate = useNavigate();
  const accept = useServerFn(acceptInvitation);
  const [state, setState] = useState<"idle" | "accepting" | "done" | "error">("idle");
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (loading || !user || state !== "idle") return;
    setState("accepting");
    accept({ data: { token } })
      .then(() => {
        setState("done");
        toast.success("Invitation accepted");
        navigate({ to: "/dashboard", replace: true });
      })
      .catch((err: Error) => {
        setError(err.message);
        setState("error");
      });
  }, [loading, user, state, token, accept, navigate]);

  return (
    <div className="flex min-h-screen items-center justify-center bg-muted/40 px-4">
      <Card className="w-full max-w-md">
        <CardHeader>
          <CardTitle>Accept invitation</CardTitle>
          <CardDescription>
            {user ? "Adding you to the organization…" : "Sign in to accept this invitation."}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {loading || state === "accepting" ? (
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <Loader2 className="h-4 w-4 animate-spin" />
              Working…
            </div>
          ) : !user ? (
            <Button asChild className="w-full">
              <Link to="/auth" search={{ redirect: `/accept-invite/${token}` }}>
                Sign in to continue
              </Link>
            </Button>
          ) : state === "error" ? (
            <>
              <p className="text-sm text-destructive">{error}</p>
              <Button asChild variant="outline" className="w-full">
                <Link to="/dashboard">Go to dashboard</Link>
              </Button>
            </>
          ) : null}
        </CardContent>
      </Card>
    </div>
  );
}
