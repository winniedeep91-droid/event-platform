import { useState } from "react";
import { config } from "./api";
import {
  EventsDashboardView,
  EventsListView,
  EventWizardView,
  EventWorkspaceView,
  EventsCalendarView,
  VenuesView,
  ArtistsView,
  EventTermsView,
} from "./views/events";
import {
  ActivityView,
  AuditView,
  BrandingView,
  DiagnosticsView,
  ImportExportView,
  NotificationsView,
  OrganisationSettingsView,
  SyncView,
  TeamView,
} from "./views/platform";
import { queryParam } from "./views/events/shared";
import {
  ProductsView,
  OrdersView,
  CustomersView,
  CouponsView,
  WebhooksView,
  WcDiagnosticsView,
  SynchronisationView,
} from "./views/woocommerce";
import { PeopleListView, PersonProfileView, SegmentsView, InsightsView } from "./views/crm";
import { FinanceOverviewView } from "./views/finance";
import { Sidebar } from "./Sidebar";
import { TopBar } from "./TopBar";
import { Drawer } from "./ui";
import type { EventTaxonomy } from "./api";

export function AdminApp({ view }: { view: string }) {
  const [mobileNavOpen, setMobileNavOpen] = useState(false);
  const { menu, branding, currentUser } = config();

  // Reuses the Events module's own dashboard rather than a separate,
  // duplicate landing page — see EventsDashboardView for what it shows.
  let content = <EventsDashboardView />;

  // ── Events module ──────────────────────────────────────────────────────
  if (view === "events/dashboard") {
    content = <EventsDashboardView />;
  } else if (view === "events/list") {
    const action = queryParam("action");
    const eventParam = queryParam("event");

    if (action === "new") {
      // Creation wizard
      content = <EventWizardView />;
    } else if (eventParam) {
      // Single event workspace
      const eventId = parseInt(eventParam, 10);
      content = <EventWorkspaceView eventId={eventId} />;
    } else {
      // Filterable list
      content = <EventsListView />;
    }
  } else if (view === "events/calendar") {
    content = <EventsCalendarView />;
  } else if (view === "events/venues") {
    content = <VenuesView />;
  } else if (view === "events/artists") {
    content = <ArtistsView />;
  } else if (view === "events/terms") {
    // taxonomy=category or taxonomy=tag — validated against the union type
    const rawTaxonomy = queryParam("taxonomy");
    const taxonomy: EventTaxonomy =
      rawTaxonomy === "category" || rawTaxonomy === "tag" ? rawTaxonomy : "category";
    content = <EventTermsView taxonomy={taxonomy} />;

    // ── Platform ────────────────────────────────────────────────────────────
  } else if (view === "platform/activity") {
    content = <ActivityView />;
  } else if (view === "platform/audit") {
    content = <AuditView />;
  } else if (view === "platform/notifications") {
    content = <NotificationsView />;
  } else if (view === "platform/branding") {
    content = <BrandingView />;
  } else if (view === "platform/sync") {
    content = <SyncView />;
  } else if (view === "platform/diagnostics") {
    content = <DiagnosticsView />;
  } else if (view === "platform/import-export") {
    content = <ImportExportView />;
  } else if (view === "platform/settings") {
    content = <OrganisationSettingsView />;

    // ── WooCommerce module ────────────────────────────────────────────────
  } else if (view === "wc-products") {
    content = <ProductsView />;
  } else if (view === "wc-orders") {
    content = <OrdersView />;
  } else if (view === "wc-customers") {
    content = <CustomersView />;
  } else if (view === "wc-coupons") {
    content = <CouponsView />;
  } else if (view === "wc-webhooks") {
    content = <WebhooksView />;
  } else if (view === "wc-diagnostics") {
    content = <WcDiagnosticsView />;
  } else if (view === "wc-sync") {
    content = <SynchronisationView />;

    // ── CRM module ─────────────────────────────────────────────────────────
  } else if (view === "crm/people") {
    const personParam = queryParam("person");
    content = personParam ? (
      <PersonProfileView personId={parseInt(personParam, 10)} />
    ) : (
      <PeopleListView />
    );
  } else if (view === "crm/segments") {
    content = <SegmentsView />;
  } else if (view === "crm/insights") {
    content = <InsightsView />;

    // ── Finance module ─────────────────────────────────────────────────────
  } else if (view === "finance/overview") {
    content = <FinanceOverviewView />;

    // ── Settings ────────────────────────────────────────────────────────────
  } else if (view === "settings/team") {
    content = <TeamView />;
  } else if (
    view === "settings/general" ||
    view === "settings/regional" ||
    view === "settings/security"
  ) {
    // These are separate legacy WordPress admin pages, but they read and
    // write the exact same settings schema/group as Organisation Settings —
    // open the matching tab there instead of a second, duplicate form.
    content = <OrganisationSettingsView initialGroup={view.replace("settings/", "")} />;
  }

  return (
    <div className="eos-shell">
      <aside className="eos-shell__sidebar">
        <Sidebar menu={menu} view={view} branding={branding} />
      </aside>

      <Drawer
        open={mobileNavOpen}
        onClose={() => setMobileNavOpen(false)}
        title="Menu"
        side="left"
        className="eos-shell__sidebar-drawer"
      >
        <Sidebar
          menu={menu}
          view={view}
          branding={branding}
          onNavigate={() => setMobileNavOpen(false)}
        />
      </Drawer>

      <div className="eos-shell__main">
        <TopBar
          view={view}
          menu={menu}
          currentUser={currentUser}
          onOpenSidebar={() => setMobileNavOpen(true)}
        />
        <div className="eos-shell__content">{content}</div>
      </div>
    </div>
  );
}
