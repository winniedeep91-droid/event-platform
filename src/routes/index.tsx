import { Link, createFileRoute } from "@tanstack/react-router";
import { ArrowRight, Building2, KeyRound, Users } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useAuth } from "@/hooks/use-auth";

export const Route = createFileRoute("/")({
  head: () => ({
    meta: [
      { title: "EventOS — Enterprise Event Management Platform" },
      {
        name: "description",
        content:
          "EventOS is a multi-tenant event management platform with production-grade authentication, organizations, roles, and permissions.",
      },
      { property: "og:title", content: "EventOS — Enterprise Event Management Platform" },
      {
        property: "og:description",
        content: "Multi-tenant event management for enterprise teams.",
      },
    ],
  }),
  component: LandingPage,
});

function LandingPage() {
  const { user, loading } = useAuth();

  return (
    <div className="min-h-screen bg-background">
      <header className="border-b border-border">
        <div className="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
          <div className="flex items-center gap-2">
            <div className="flex h-8 w-8 items-center justify-center rounded-md bg-primary text-primary-foreground">
              E
            </div>
            <span className="font-semibold tracking-tight">EventOS</span>
          </div>
          <nav className="flex items-center gap-2">
            {loading ? null : user ? (
              <Button asChild>
                <Link to="/dashboard">
                  Open dashboard <ArrowRight className="ml-2 h-4 w-4" />
                </Link>
              </Button>
            ) : (
              <>
                <Button asChild variant="ghost">
                  <Link to="/auth">Sign in</Link>
                </Button>
                <Button asChild>
                  <Link to="/auth" search={{ mode: "signup" }}>
                    Get started
                  </Link>
                </Button>
              </>
            )}
          </nav>
        </div>
      </header>

      <main className="mx-auto max-w-6xl px-6 py-24">
        <section className="mx-auto max-w-3xl text-center">
          <h1 className="text-4xl font-semibold tracking-tight sm:text-5xl">
            The enterprise event management platform
          </h1>
          <p className="mt-6 text-lg text-muted-foreground">
            EventOS gives operations teams a single, secure workspace to manage organizations,
            members, and access &mdash; built on a production foundation with role-based access
            control and row-level security from day one.
          </p>
          <div className="mt-8 flex justify-center gap-3">
            {user ? (
              <Button asChild size="lg">
                <Link to="/dashboard">Open dashboard</Link>
              </Button>
            ) : (
              <>
                <Button asChild size="lg">
                  <Link to="/auth" search={{ mode: "signup" }}>
                    Create your workspace
                  </Link>
                </Button>
                <Button asChild size="lg" variant="outline">
                  <Link to="/auth">Sign in</Link>
                </Button>
              </>
            )}
          </div>
        </section>

        <section className="mt-24 grid gap-6 sm:grid-cols-3">
          {[
            {
              icon: Building2,
              title: "Multi-tenant organizations",
              body: "Every user can belong to multiple workspaces, with data strictly isolated by row-level security policies.",
            },
            {
              icon: KeyRound,
              title: "Role-based access control",
              body: "Owner, admin, member, and viewer roles map to a catalog of permissions enforced in the database.",
            },
            {
              icon: Users,
              title: "Invitations & membership",
              body: "Invite teammates by email with role pre-assigned. Tokenized links expire automatically.",
            },
          ].map((f) => (
            <div key={f.title} className="rounded-lg border border-border bg-card p-6">
              <div className="mb-4 inline-flex h-10 w-10 items-center justify-center rounded-md bg-primary/10 text-primary">
                <f.icon className="h-5 w-5" />
              </div>
              <h3 className="text-base font-semibold">{f.title}</h3>
              <p className="mt-2 text-sm text-muted-foreground">{f.body}</p>
            </div>
          ))}
        </section>
      </main>

      <footer className="border-t border-border">
        <div className="mx-auto flex max-w-6xl items-center justify-between px-6 py-6 text-sm text-muted-foreground">
          <span>&copy; {new Date().getFullYear()} EventOS</span>
          <span>Production foundation</span>
        </div>
      </footer>
    </div>
  );
}
