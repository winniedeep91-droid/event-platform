/** Runtime configuration injected by the EventOS WordPress plugin. */
export interface EventOSConfig {
  restUrl: string;
  nonce: string;
  adminUrl: string;
  pluginUrl: string;
  version: string;
  locale: string;
  branding: {
    colors: Record<"primary" | "secondary" | "accent", string>;
    logos: Record<string, { id: number; url: string }>;
  };
  general: Record<string, unknown>;
  menu: Array<{ slug: string; view: string; title: string; url: string }>;
  capabilities: {
    view_dashboard: boolean;
    manage_settings: boolean;
    manage_team: boolean;
  };
  currentUser: {
    id: number;
    name: string;
    email: string;
    avatar: string;
    roles: string[];
  };
}

declare global {
  interface Window {
    eventosAdmin?: EventOSConfig;
    wp?: {
      media?: (args: unknown) => {
        on: (event: string, cb: () => void) => void;
        open: () => void;
        state: () => { get: (key: string) => { first: () => { toJSON: () => Attachment } } };
      };
    };
  }
}

export interface Attachment {
  id: number;
  url: string;
  sizes?: { thumbnail?: { url: string } };
}

export function config(): EventOSConfig {
  const value = window.eventosAdmin;

  if (!value) {
    throw new Error("EventOS admin configuration is missing.");
  }

  return value;
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const { restUrl, nonce } = config();

  const response = await fetch(`${restUrl}${path}`, {
    ...init,
    credentials: "same-origin",
    headers: {
      "Content-Type": "application/json",
      "X-WP-Nonce": nonce,
      ...(init.headers ?? {}),
    },
  });

  const body = (await response.json().catch(() => null)) as unknown;

  if (!response.ok) {
    const message =
      body && typeof body === "object" && "message" in body
        ? String((body as { message: unknown }).message)
        : `Request failed with status ${response.status}`;
    throw new Error(message);
  }

  return body as T;
}

export interface SettingsField {
  key: string;
  label: string;
  type: string;
  default: unknown;
  choices: string[];
}

export interface SettingsGroup {
  group: string;
  label: string;
  description: string;
  fields: SettingsField[];
}

export interface SettingsPayload {
  schema: SettingsGroup[];
  values: Record<string, Record<string, unknown>>;
  branding: EventOSConfig["branding"];
}

export interface TeamMember {
  id: number;
  name: string;
  email: string;
  avatar: string;
  wp_roles: string[];
  eventos_roles: string[];
  capabilities: string[];
  registered_date: string;
}

export interface Invitation {
  id: number;
  email: string;
  roles: string[];
  status: string;
  created_at: string;
  expires_at: string;
  accepted_at: string | null;
  invited_by: { id: number; name: string };
}

export interface DashboardPayload {
  system: {
    plugin_version: string;
    db_version: string;
    wordpress_version: string;
    php_version: string;
    mysql_version: string;
    woocommerce: { active: boolean; version: string; currency: string };
    multisite: boolean;
    healthy: boolean;
    checks: Array<{ id: string; label: string; value: string; passing: boolean }>;
    storage: {
      used_human: string;
      file_count: number;
      attachments: number;
      disk_free_bytes: number;
      disk_total_bytes: number;
    };
  };
  branding: EventOSConfig["branding"];
  general: Record<string, string>;
  activity: Array<{
    id: number;
    action: string;
    created_at: string;
    user: { id: number; name: string };
    context: Record<string, unknown>;
  }>;
  upcoming_events: Array<{ id: number; title: string; starts_at: string }>;
  current_user: {
    id: number;
    name: string;
    email: string;
    avatar: string;
    eventos_roles: string[];
  };
}

export const api = {
  dashboard: () => request<DashboardPayload>("dashboard"),
  settings: () => request<SettingsPayload>("settings"),
  saveSettings: (group: string, values: Record<string, unknown>) =>
    request<{ values: Record<string, unknown>; branding: EventOSConfig["branding"] }>(
      `settings/${group}`,
      { method: "POST", body: JSON.stringify(values) },
    ),
  roles: () =>
    request<{
      roles: Array<{ slug: string; label: string; capabilities: string[] }>;
      capabilities: Array<{ key: string; label: string }>;
    }>("team/roles"),
  members: (search: string) =>
    request<{ members: TeamMember[]; total: number }>(
      `team/members?per_page=50&search=${encodeURIComponent(search)}`,
    ),
  updateMember: (id: number, roles: string[]) =>
    request<TeamMember>(`team/members/${id}`, {
      method: "POST",
      body: JSON.stringify({ roles }),
    }),
  invitations: () => request<{ invitations: Invitation[] }>("invitations"),
  createInvitation: (email: string, roles: string[]) =>
    request<Invitation>("invitations", {
      method: "POST",
      body: JSON.stringify({ email, roles }),
    }),
  revokeInvitation: (id: number) =>
    request<{ revoked: boolean }>(`invitations/${id}`, { method: "DELETE" }),
};

/** Opens the WordPress media library and resolves with the chosen attachment. */
export function selectAttachment(title: string): Promise<Attachment | null> {
  return new Promise((resolve) => {
    const media = window.wp?.media;

    if (!media) {
      resolve(null);
      return;
    }

    const frame = media({ title, multiple: false, library: { type: "image" } });

    frame.on("select", () => {
      resolve(frame.state().get("selection").first().toJSON());
    });

    frame.open();
  });
}

/* ------------------------------------------------------------------------ */
/* Events module                                                             */
/* ------------------------------------------------------------------------ */

/** Envelope produced by the REST registry for enveloped endpoints. */
interface Envelope<T> {
  success: boolean;
  data: T;
  meta: Record<string, unknown>;
}

export interface CollectionMeta {
  total: number;
  page: number;
  perPage: number;
  totalPages: number;
}

export interface Collection<T> extends CollectionMeta {
  items: T[];
}

function metaNumber(meta: Record<string, unknown>, key: string, fallback: number): number {
  const value = meta[key];
  return typeof value === "number" ? value : fallback;
}

async function unwrap<T>(path: string, init: RequestInit = {}): Promise<T> {
  const body = await request<Envelope<T>>(path, init);
  return body.data;
}

async function unwrapCollection<T>(path: string): Promise<Collection<T>> {
  const body = await request<Envelope<T[]>>(path);
  const meta = body.meta ?? {};

  return {
    items: Array.isArray(body.data) ? body.data : [],
    total: metaNumber(meta, "total", 0),
    page: metaNumber(meta, "page", 1),
    perPage: metaNumber(meta, "per_page", 20),
    totalPages: metaNumber(meta, "total_pages", 1),
  };
}

function query(params: Readonly<Record<string, string | number | undefined>>): string {
  const search = new URLSearchParams();

  Object.entries(params).forEach(([key, value]) => {
    if (value === undefined || value === "" || value === 0) return;
    search.set(key, String(value));
  });

  const serialised = search.toString();

  return serialised ? `?${serialised}` : "";
}

export interface EventArtist {
  id: number;
  artist_id: number;
  artist_name: string;
  image_id: number;
  billing: string;
  stage: string;
  starts_at: string | null;
  ends_at: string | null;
  position: number;
  notes: string;
}

export interface EventMediaItem {
  id: number;
  attachment_id: number;
  type: string;
  title: string;
  position: number;
  url: string;
}

export interface EventSchedule {
  id: number;
  label: string;
  type: string;
  stage: string;
  artist_id: number;
  artist_name: string;
  starts_at: string | null;
  ends_at: string | null;
  position: number;
  notes: string;
}

export interface EventRecurrence {
  frequency?: string;
  interval?: number;
  count?: number;
  until?: string;
  [key: string]: unknown;
}

export interface EventRecord {
  id: number;
  title: string;
  subtitle: string;
  slug: string;
  description: string;
  short_description: string;
  status: string;
  visibility: string;
  password_protected: boolean;
  ticket_visibility: string;
  venue_id: number;
  venue_name: string;
  venue_city: string;
  timezone: string;
  starts_at: string | null;
  ends_at: string | null;
  doors_open_at: string | null;
  capacity: number;
  age_restriction: string;
  accessibility: string;
  featured_image_id: number;
  featured_image_url: string;
  organisers: number[];
  collaborators: number[];
  recurrence: EventRecurrence;
  published_at: string | null;
  created_by: number;
  updated_by: number;
  created_at: string;
  updated_at: string;
  artists?: EventArtist[];
  media?: EventMediaItem[];
  schedules?: EventSchedule[];
  categories?: number[];
  tags?: number[];
}

export interface VenueRecord {
  id: number;
  name: string;
  slug: string;
  address_line1: string;
  address_line2: string;
  city: string;
  province: string;
  postal_code: string;
  country: string;
  latitude: number | null;
  longitude: number | null;
  maps_url: string;
  parking_info: string;
  capacity: number;
  seating_configuration: Record<string, unknown>;
  notes: string;
  created_at: string;
  updated_at: string;
  event_count?: number;
}

export interface ArtistPerformance {
  event_id: number;
  event_title: string;
  event_status: string;
  event_starts_at: string | null;
  billing: string;
  stage: string;
  starts_at: string | null;
  ends_at: string | null;
}

export interface ArtistRecord {
  id: number;
  name: string;
  slug: string;
  biography: string;
  genres: string[];
  social_links: Record<string, string>;
  website: string;
  country: string;
  image_id: number;
  image_url: string;
  created_at: string;
  updated_at: string;
  performances?: ArtistPerformance[];
}

export type EventTaxonomy = "category" | "tag";

export interface EventTerm {
  id: number;
  name: string;
  slug: string;
  description: string;
  parent_id: number;
  usage_count: number;
  taxonomy: EventTaxonomy;
  created_at: string;
}

export type DashboardPeriod = "7d" | "30d" | "90d" | "year";

/** Brand-wide, all-time performance totals — the dashboard's KPI cards. */
export interface BrandPerformanceSummary {
  currency: string;
  total_revenue: number;
  tickets_sold: number;
  attendance: number;
  complimentary: number;
  orders: number;
}

/** Day-bucketed brand performance for the selected period — the dashboard's charts. */
export interface BrandPerformanceSeries {
  period: DashboardPeriod;
  from: string;
  to: string;
  currency: string;
  revenue_by_day: Array<{ date: string; revenue: number; orders: number }>;
  tickets_by_day: Array<{ date: string; tickets: number }>;
}

/** One My Events table row — an event record enriched with its performance. */
export interface DashboardEventSummary extends EventRecord {
  tickets_sold: number;
  checked_in: number;
  revenue: number;
}

/** The next upcoming event's full per-event report — powers the Next Event card. */
export interface NextEventReport extends EventReportPayload {
  event_id: number;
  title: string;
  starts_at: string | null;
  venue_name: string;
  status: string;
}

export interface EventsDashboardPayload {
  counts: Record<string, number>;
  total: number;
  next_30_days: number;
  upcoming_capacity: number;
  venues: number;
  artists: number;
  upcoming: EventRecord[];
  drafts: EventRecord[];
  activity: Array<{
    id: number;
    action: string;
    created_at: string;
    user?: { id: number; name: string };
    entity_type?: string;
    entity_id?: number;
  }>;
  /** Additive commercial-performance fields — see Brand_Report_Builder. */
  brand: BrandPerformanceSummary;
  brand_series: BrandPerformanceSeries;
  next_event: NextEventReport | null;
  my_events: DashboardEventSummary[];
}

export interface EventFormOptions {
  statuses: Record<string, string>;
  transitions: Record<string, string[]>;
  visibilities: Record<string, string>;
  ticket_visibilities: Record<string, string>;
  timezones: string[];
  default_timezone: string;
  venues: VenueRecord[];
  artists: ArtistRecord[];
  categories: EventTerm[];
  tags: EventTerm[];
  users: Array<{ id: number; name: string; email: string }>;
}

// ── Ticketing types ───────────────────────────────────────────────────────

export type TicketTier =
  "standard" | "early_bird" | "vip" | "table" | "backstage" | "complimentary" | "custom";
export type TicketVisibility = "public" | "private" | "hidden";
export type TicketStatus = "active" | "paused" | "sold_out" | "archived";

export interface TicketTypeRecord {
  id: number;
  event_id: number;
  wc_product_id: number;
  name: string;
  description: string;
  tier: TicketTier;
  price: number;
  capacity: number | null;
  sold: number;
  reserved: number;
  available: number | null;
  visibility: TicketVisibility;
  status: TicketStatus;
  sale_start: string | null;
  sale_end: string | null;
  min_per_order: number;
  max_per_order: number | null;
  waitlist_enabled: boolean;
  waitlist_count: number;
  sort_order: number;
  created_at: string;
  updated_at: string;
}

export interface ComplimentaryPayload {
  ticket_type_id: number;
  quantity: number;
  recipient_name: string;
  recipient_email: string;
  label?: string;
  note?: string;
}

// ── Order types ───────────────────────────────────────────────────────────

export type OrderStatus =
  "pending" | "processing" | "on-hold" | "completed" | "cancelled" | "refunded" | "failed";

export interface OrderRecord {
  id: number;
  wc_order_id: number;
  event_id: number;
  customer_id: number;
  customer_name: string;
  customer_email: string;
  status: OrderStatus;
  payment_method: string;
  total: number;
  subtotal: number;
  tax: number;
  currency: string;
  ticket_count: number;
  tickets: OrderTicket[];
  refunds: OrderRefund[];
  notes: string;
  billing_address: string;
  created_at: string;
  updated_at: string;
}

export interface OrderTicket {
  id: number;
  ticket_type_id: number;
  ticket_type_name: string;
  quantity: number;
  price: number;
  total: number;
}

export interface OrderRefund {
  id: number;
  amount: number;
  reason: string;
  created_at: string;
}

export interface OrderListParams {
  page?: number;
  per_page?: number;
  search?: string;
  status?: string;
  orderby?: string;
  order?: string;
  [key: string]: string | number | undefined;
}

// ── Guest types ───────────────────────────────────────────────────────────

export type GuestStatus = "confirmed" | "waitlisted" | "cancelled" | "no_show";

export interface GuestRecord {
  id: number;
  event_id: number;
  wc_order_id: number;
  ticket_type_id: number;
  ticket_type_name: string;
  ticket_number: string;
  customer_id: number;
  name: string;
  email: string;
  phone: string;
  status: GuestStatus;
  checked_in: boolean;
  checked_in_at: string | null;
  checked_in_by: string | null;
  is_complimentary: boolean;
  tags: string[];
  notes: GuestNote[];
  attendance_history: AttendanceRecord[];
  created_at: string;
}

export interface GuestNote {
  id: number;
  note: string;
  author: string;
  created_at: string;
}

export interface AttendanceRecord {
  event_id: number;
  event_title: string;
  event_starts_at: string | null;
  checked_in: boolean;
  checked_in_at: string | null;
}

export interface GuestListParams {
  page?: number;
  per_page?: number;
  search?: string;
  status?: string;
  ticket_type_id?: number;
  checked_in?: boolean;
  orderby?: string;
  order?: string;
  [key: string]: string | number | boolean | undefined;
}

// ── Scanner types ─────────────────────────────────────────────────────────

export type ScanOutcome = "admitted" | "already_scanned" | "invalid" | "cancelled";

export interface ScanRecord {
  id: number;
  event_id: number;
  ticket_number: string;
  guest_name: string;
  ticket_type_name: string;
  outcome: ScanOutcome;
  method: "qr" | "manual";
  operator: string;
  device: string;
  entry_point: string;
  scanned_at: string;
}

export interface ScanResult {
  valid: boolean;
  outcome: ScanOutcome;
  message: string;
  ticket_number: string;
  guest_name: string;
  ticket_type_name: string;
  already_scanned_at: string | null;
}

export interface ScannerSession {
  id: number;
  event_id: number;
  operator: string;
  device: string;
  entry_point: string;
  scans: number;
  started_at: string;
  ended_at: string | null;
}

// ── Marketing types ───────────────────────────────────────────────────────

export type CampaignStatus = "draft" | "active" | "paused" | "expired" | "archived";
export type DiscountType = "percent" | "fixed";

export interface DiscountCampaign {
  id: number;
  event_id: number;
  wc_coupon_id: number;
  name: string;
  code: string;
  type: DiscountType;
  value: number;
  status: CampaignStatus;
  applies_to: "all" | "specific_types";
  ticket_type_ids: number[];
  min_spend: number | null;
  max_uses: number | null;
  uses: number;
  expires_at: string | null;
  created_at: string;
}

export interface PromoLink {
  id: number;
  event_id: number;
  label: string;
  url: string;
  utm_source: string;
  utm_medium: string;
  utm_campaign: string;
  clicks: number;
  created_at: string;
}

export interface AudienceSegment {
  id: number;
  name: string;
  description: string;
  criteria: Record<string, unknown>;
  count: number;
}

// ── Report types ──────────────────────────────────────────────────────────

export interface EventReportPayload {
  currency: string;
  summary: {
    gross_revenue: number;
    net_revenue: number;
    refunds: number;
    tickets_sold: number;
    tickets_available: number | null;
    capacity: number | null;
    attendance_rate: number | null;
    checked_in: number;
    complimentary: number;
    average_order_value: number;
    orders: number;
  };
  revenue_by_day: Array<{ date: string; gross: number; net: number; orders: number }>;
  revenue_by_ticket_type: Array<{
    ticket_type_id: number;
    name: string;
    tier: TicketTier;
    sold: number;
    gross: number;
    net: number;
    capacity: number | null;
  }>;
  sales_velocity: Array<{ date: string; tickets: number }>;
  top_customers: Array<{
    customer_id: number;
    name: string;
    email: string;
    orders: number;
    spend: number;
  }>;
  refund_breakdown: Array<{ date: string; amount: number; count: number }>;
}

export interface EventListParams {
  [key: string]: string | number | undefined;
  search?: string;
  status?: string;
  visibility?: string;
  venue_id?: number;
  artist_id?: number;
  category_id?: number;
  tag_id?: number;
  from?: string;
  to?: string;
  orderby?: string;
  order?: string;
  page?: number;
  per_page?: number;
}

export interface VenueListParams {
  [key: string]: string | number | undefined;
  search?: string;
  city?: string;
  country?: string;
  orderby?: string;
  order?: string;
  page?: number;
  per_page?: number;
}

export interface ArtistListParams {
  [key: string]: string | number | undefined;
  search?: string;
  genre?: string;
  country?: string;
  orderby?: string;
  order?: string;
  page?: number;
  per_page?: number;
}

export type EventPayload = Record<string, unknown>;

export const eventsApi = {
  dashboard: (period?: DashboardPeriod) =>
    unwrap<EventsDashboardPayload>(`events/dashboard${query({ period })}`),
  options: () => unwrap<EventFormOptions>("events/options"),
  list: (params: EventListParams) => unwrapCollection<EventRecord>(`events${query(params)}`),
  calendar: (from: string, to: string) =>
    unwrap<{ from: string; to: string; events: EventRecord[] }>(
      `events/calendar${query({ from, to })}`,
    ),
  get: (id: number) => unwrap<EventRecord>(`events/${id}`),
  create: (payload: EventPayload) =>
    unwrap<EventRecord>("events", { method: "POST", body: JSON.stringify(payload) }),
  update: (id: number, payload: EventPayload) =>
    unwrap<EventRecord>(`events/${id}`, { method: "POST", body: JSON.stringify(payload) }),
  remove: (id: number) => unwrap<{ deleted: boolean }>(`events/${id}`, { method: "DELETE" }),
  transition: (id: number, status: string) =>
    unwrap<EventRecord>(`events/${id}/status`, {
      method: "POST",
      body: JSON.stringify({ status }),
    }),
  duplicate: (id: number) => unwrap<EventRecord>(`events/${id}/duplicate`, { method: "POST" }),
  generateOccurrences: (id: number, recurrence: EventRecurrence) =>
    unwrap<{ created: number[] } | EventRecord[]>(`events/${id}/occurrences`, {
      method: "POST",
      body: JSON.stringify({ recurrence }),
    }),

  venues: (params: VenueListParams) => unwrapCollection<VenueRecord>(`venues${query(params)}`),
  venue: (id: number) => unwrap<VenueRecord>(`venues/${id}`),
  createVenue: (payload: EventPayload) =>
    unwrap<VenueRecord>("venues", { method: "POST", body: JSON.stringify(payload) }),
  updateVenue: (id: number, payload: EventPayload) =>
    unwrap<VenueRecord>(`venues/${id}`, { method: "POST", body: JSON.stringify(payload) }),
  removeVenue: (id: number) => unwrap<{ deleted: boolean }>(`venues/${id}`, { method: "DELETE" }),

  artists: (params: ArtistListParams) => unwrapCollection<ArtistRecord>(`artists${query(params)}`),
  artist: (id: number) => unwrap<ArtistRecord>(`artists/${id}`),
  createArtist: (payload: EventPayload) =>
    unwrap<ArtistRecord>("artists", { method: "POST", body: JSON.stringify(payload) }),
  updateArtist: (id: number, payload: EventPayload) =>
    unwrap<ArtistRecord>(`artists/${id}`, { method: "POST", body: JSON.stringify(payload) }),
  removeArtist: (id: number) => unwrap<{ deleted: boolean }>(`artists/${id}`, { method: "DELETE" }),

  terms: (taxonomy: EventTaxonomy, search: string) =>
    unwrap<{ taxonomy: EventTaxonomy; items: EventTerm[] }>(
      `event-terms/${taxonomy}${query({ search })}`,
    ),
  createTerm: (taxonomy: EventTaxonomy, payload: EventPayload) =>
    unwrap<EventTerm>(`event-terms/${taxonomy}`, { method: "POST", body: JSON.stringify(payload) }),
  updateTerm: (taxonomy: EventTaxonomy, id: number, payload: EventPayload) =>
    unwrap<EventTerm>(`event-terms/${taxonomy}/${id}`, {
      method: "POST",
      body: JSON.stringify(payload),
    }),
  removeTerm: (taxonomy: EventTaxonomy, id: number) =>
    unwrap<{ deleted: boolean }>(`event-terms/${taxonomy}/${id}`, { method: "DELETE" }),

  // ── Ticketing ──────────────────────────────────────────────────────────
  ticketTypes: (eventId: number) =>
    unwrap<{ ticket_types: TicketTypeRecord[] }>(`events/${eventId}/ticket-types`),
  createTicketType: (eventId: number, payload: EventPayload) =>
    unwrap<TicketTypeRecord>(`events/${eventId}/ticket-types`, {
      method: "POST",
      body: JSON.stringify(payload),
    }),
  updateTicketType: (eventId: number, typeId: number, payload: EventPayload) =>
    unwrap<TicketTypeRecord>(`events/${eventId}/ticket-types/${typeId}`, {
      method: "POST",
      body: JSON.stringify(payload),
    }),
  removeTicketType: (eventId: number, typeId: number) =>
    unwrap<{ deleted: boolean }>(`events/${eventId}/ticket-types/${typeId}`, { method: "DELETE" }),
  reorderTicketTypes: (eventId: number, ids: number[]) =>
    unwrap<{ reordered: boolean }>(`events/${eventId}/ticket-types/reorder`, {
      method: "POST",
      body: JSON.stringify({ ids }),
    }),
  issueComplimentary: (eventId: number, payload: ComplimentaryPayload) =>
    unwrap<{ issued: number; ticket_ids: number[] }>(`events/${eventId}/complimentary`, {
      method: "POST",
      body: JSON.stringify(payload),
    }),

  // ── Orders ────────────────────────────────────────────────────────────
  eventOrders: (eventId: number, params: OrderListParams) =>
    unwrapCollection<OrderRecord>(`events/${eventId}/orders${query(params)}`),
  exportOrders: (eventId: number, format: "csv" = "csv") =>
    `${config().restUrl}exports/event_orders/${format}?event_id=${eventId}&_wpnonce=${config().nonce}`,

  // ── Guests ────────────────────────────────────────────────────────────
  eventGuests: (eventId: number, params: GuestListParams) =>
    unwrapCollection<GuestRecord>(
      `events/${eventId}/guests${query({
        ...params,
        checked_in: params.checked_in !== undefined ? String(params.checked_in) : undefined,
      } as Record<string, string | number | undefined>)}`,
    ),
  guest: (eventId: number, guestId: number) =>
    unwrap<GuestRecord>(`events/${eventId}/guests/${guestId}`),
  checkinGuest: (eventId: number, guestId: number) =>
    unwrap<{ checked_in: boolean; checked_in_at: string }>(
      `events/${eventId}/guests/${guestId}/checkin`,
      { method: "POST" },
    ),
  undoCheckin: (eventId: number, guestId: number) =>
    unwrap<{ checked_in: boolean }>(`events/${eventId}/guests/${guestId}/checkin`, {
      method: "DELETE",
    }),
  addGuestNote: (eventId: number, guestId: number, note: string) =>
    unwrap<GuestNote>(`events/${eventId}/guests/${guestId}/notes`, {
      method: "POST",
      body: JSON.stringify({ note }),
    }),
  updateGuestTags: (eventId: number, guestId: number, tags: string[]) =>
    unwrap<GuestRecord>(`events/${eventId}/guests/${guestId}/tags`, {
      method: "POST",
      body: JSON.stringify({ tags }),
    }),

  // ── Scanner ───────────────────────────────────────────────────────────
  scannerSessions: (eventId: number) =>
    unwrap<{ sessions: ScannerSession[] }>(`events/${eventId}/scanner/sessions`),
  scanHistory: (eventId: number, params?: { page?: number; per_page?: number }) =>
    unwrapCollection<ScanRecord>(`events/${eventId}/scanner/history${query(params ?? {})}`),
  validateTicket: (eventId: number, code: string, method: "qr" | "manual") =>
    unwrap<ScanResult>(`events/${eventId}/scanner/validate`, {
      method: "POST",
      body: JSON.stringify({ code, method }),
    }),
  undoScan: (eventId: number, scanId: number) =>
    unwrap<{ reversed: boolean }>(`events/${eventId}/scanner/history/${scanId}`, {
      method: "DELETE",
    }),
  liveCount: (eventId: number) =>
    unwrap<{ checked_in: number; total: number; capacity: number }>(
      `events/${eventId}/scanner/count`,
    ),

  // ── Marketing ─────────────────────────────────────────────────────────
  discountCampaigns: (eventId: number) =>
    unwrap<{ campaigns: DiscountCampaign[] }>(`events/${eventId}/marketing/campaigns`),
  createDiscountCampaign: (eventId: number, payload: EventPayload) =>
    unwrap<DiscountCampaign>(`events/${eventId}/marketing/campaigns`, {
      method: "POST",
      body: JSON.stringify(payload),
    }),
  updateDiscountCampaign: (eventId: number, campaignId: number, payload: EventPayload) =>
    unwrap<DiscountCampaign>(`events/${eventId}/marketing/campaigns/${campaignId}`, {
      method: "POST",
      body: JSON.stringify(payload),
    }),
  removeDiscountCampaign: (eventId: number, campaignId: number) =>
    unwrap<{ deleted: boolean }>(`events/${eventId}/marketing/campaigns/${campaignId}`, {
      method: "DELETE",
    }),
  promoLinks: (eventId: number) =>
    unwrap<{ links: PromoLink[] }>(`events/${eventId}/marketing/links`),
  createPromoLink: (eventId: number, payload: EventPayload) =>
    unwrap<PromoLink>(`events/${eventId}/marketing/links`, {
      method: "POST",
      body: JSON.stringify(payload),
    }),
  removePromoLink: (eventId: number, linkId: number) =>
    unwrap<{ deleted: boolean }>(`events/${eventId}/marketing/links/${linkId}`, {
      method: "DELETE",
    }),
  audiences: (eventId: number) =>
    unwrap<{ audiences: AudienceSegment[] }>(`events/${eventId}/marketing/audiences`),

  // ── Reports ───────────────────────────────────────────────────────────
  eventReport: (eventId: number) => unwrap<EventReportPayload>(`events/${eventId}/reports`),
  exportReport: (eventId: number, format: "csv" | "pdf") =>
    `${config().restUrl}exports/event_report/${format}?event_id=${eventId}&_wpnonce=${config().nonce}`,
};

/* ------------------------------------------------------------------------ */
/* Platform infrastructure                                                   */
/* ------------------------------------------------------------------------ */

export interface ActivityEntryRecord {
  id: number;
  action: string;
  module: string;
  severity: string;
  object_type: string;
  object_id: string;
  entity: { type: string; id: string };
  before: unknown;
  after: unknown;
  context: Record<string, unknown>;
  created_at: string;
  user: { id: number; name: string };
}

export interface ActivityListParams {
  search?: string;
  module?: string;
  action?: string;
  severity?: string;
  entity_type?: string;
  entity_id?: string;
  user_id?: number;
  since?: string;
  until?: string;
  order?: string;
  page?: number;
  per_page?: number;
}

export interface ActivityFilters {
  modules: string[];
  severities: string[];
  total: number;
}

export interface NotificationRecord {
  key: string;
  type: string;
  title: string;
  message: string;
  module: string;
  dismissible: boolean;
  persistent: boolean;
  actions: Array<{ label: string; url: string }>;
  created_at: string;
}

export interface NotificationListParams {
  search?: string;
  type?: string;
  module?: string;
  page?: number;
  per_page?: number;
}

export interface SyncTarget {
  slug: string;
  label: string;
  description: string;
  module: string;
  enabled: boolean;
  interval: number;
  last_run_at: string;
  last_status: string;
  last_message: string;
  last_duration: number;
  running: boolean;
}

export interface SyncRun {
  id: string;
  target: string;
  label: string;
  status: string;
  trigger: string;
  message: string;
  processed: number;
  failed: number;
  duration: number;
  started_at: string;
  finished_at: string;
}

export interface SyncHistoryParams {
  search?: string;
  target?: string;
  status?: string;
  trigger?: string;
  page?: number;
  per_page?: number;
}

export interface DiagnosticsCheck {
  id: string;
  label: string;
  category: string;
  status: "pass" | "warn" | "fail";
  value: string;
  description: string;
  hint: string;
}

export interface DiagnosticsReport {
  generated_at: string;
  healthy: boolean;
  summary: Record<"pass" | "warn" | "fail", number>;
  categories: Array<{ slug: string; label: string }>;
  checks: DiagnosticsCheck[];
  system: DashboardPayload["system"];
  jobs: Record<string, number>;
  sync: Record<string, number>;
}

export interface JobRecord {
  id: number;
  type: string;
  module: string;
  group: string;
  status: string;
  attempts: number;
  max_attempts: number;
  last_error: string;
  scheduled_at: string;
  started_at: string;
  completed_at: string;
  created_at: string;
}

export interface JobListParams {
  search?: string;
  status?: string;
  job_type?: string;
  module?: string;
  page?: number;
  per_page?: number;
}

export const platformApi = {
  activity: (params: ActivityListParams) =>
    unwrapCollection<ActivityEntryRecord>(`platform/activity${query({ ...params })}`),
  audit: (params: ActivityListParams) =>
    unwrapCollection<ActivityEntryRecord>(`platform/audit${query({ ...params })}`),
  activityFilters: () => unwrap<ActivityFilters>("platform/activity/filters"),
  purgeActivity: (days: number) =>
    unwrap<{ deleted: number }>("platform/activity/purge", {
      method: "POST",
      body: JSON.stringify({ days }),
    }),

  notifications: (params: NotificationListParams) =>
    unwrapCollection<NotificationRecord>(`platform/notifications${query({ ...params })}`),
  dismissNotification: (key: string) =>
    unwrap<{ dismissed: boolean }>("platform/notifications/dismiss", {
      method: "POST",
      body: JSON.stringify({ key }),
    }),
  removeNotification: (key: string) =>
    unwrap<{ removed: boolean }>("platform/notifications/remove", {
      method: "POST",
      body: JSON.stringify({ key }),
    }),
  clearNotifications: () =>
    unwrap<{ cleared: boolean }>("platform/notifications/clear", { method: "POST" }),

  branding: () => unwrap<EventOSConfig["branding"]>("platform/branding"),

  syncTargets: () =>
    unwrap<{ targets: SyncTarget[]; stats: Record<string, number> }>("platform/sync"),
  syncHistory: (params: SyncHistoryParams) =>
    unwrapCollection<SyncRun>(`platform/sync/history${query({ ...params })}`),
  runSync: (target: string) =>
    unwrap<SyncRun>("platform/sync/run", { method: "POST", body: JSON.stringify({ target }) }),
  queueSync: (target: string) =>
    unwrap<{ job_id: number }>("platform/sync/queue", {
      method: "POST",
      body: JSON.stringify({ target }),
    }),
  toggleSync: (target: string, enabled: boolean) =>
    unwrap<{ updated: boolean; targets: SyncTarget[] }>("platform/sync/toggle", {
      method: "POST",
      body: JSON.stringify({ target, enabled }),
    }),
  clearSyncHistory: () =>
    unwrap<{ cleared: boolean }>("platform/sync/history/clear", { method: "POST" }),

  diagnostics: () => unwrap<DiagnosticsReport>("platform/diagnostics"),
  jobs: (params: JobListParams) =>
    unwrapCollection<JobRecord>(`platform/jobs${query({ ...params })}`),
  retryJob: (id: number) =>
    unwrap<{ retried: boolean }>("platform/jobs/retry", {
      method: "POST",
      body: JSON.stringify({ id }),
    }),
  cancelJob: (id: number) =>
    unwrap<{ cancelled: boolean }>("platform/jobs/cancel", {
      method: "POST",
      body: JSON.stringify({ id }),
    }),
};

// ── WooCommerce integration types ─────────────────────────────────────────

export type WcProductStatus = "publish" | "draft" | "private" | "pending" | "trash";
export type WcOrderStatus =
  "pending" | "processing" | "on-hold" | "completed" | "cancelled" | "refunded" | "failed";
export type WcStockStatus = "instock" | "outofstock" | "onbackorder";
export type WcCouponType = "percent" | "fixed_cart" | "fixed_product";
export type WebhookEvent = "order.created" | "order.updated" | "order.completed" | "order.refunded";
export type WebhookStatus = "pending" | "processed" | "failed" | "skipped";
export type WcSyncStatusValue = "idle" | "running" | "error" | "complete";

export interface WcProductRecord {
  id: number;
  name: string;
  slug: string;
  type: string;
  status: WcProductStatus;
  description: string;
  short_description: string;
  sku: string;
  price: number;
  regular_price: number;
  sale_price: number | null;
  stock_quantity: number | null;
  stock_status: WcStockStatus;
  manage_stock: boolean;
  categories: Array<{ id: number; name: string; slug: string }>;
  tags: Array<{ id: number; name: string; slug: string }>;
  images: Array<{ id: number; src: string; alt: string }>;
  eos_event_id: number | null;
  eos_ticket_type_id: number | null;
  eos_synced_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface WcProductSyncResult {
  product_id: number;
  event_id: number;
  ticket_type_id: number | null;
  action: "created" | "updated" | "skipped";
  message: string;
}

export interface WcAddress {
  first_name: string;
  last_name: string;
  company: string;
  address_1: string;
  address_2: string;
  city: string;
  state: string;
  postcode: string;
  country: string;
  email: string;
  phone: string;
}

export interface WcLineItem {
  id: number;
  product_id: number;
  variation_id: number;
  name: string;
  quantity: number;
  price: number;
  subtotal: number;
  total: number;
  tax: number;
  eos_ticket_type_id: number | null;
}

export interface WcRefundRecord {
  id: number;
  amount: number;
  reason: string;
  refunded_by: string;
  created_at: string;
}

export interface WcCouponLine {
  id: number;
  code: string;
  discount: number;
}

export interface WcOrderNote {
  id: number;
  note: string;
  added_by: string;
  customer_note: boolean;
  created_at: string;
}

export interface WcOrderRecord {
  id: number;
  wc_order_id: number;
  status: WcOrderStatus;
  currency: string;
  total: number;
  subtotal: number;
  tax: number;
  shipping_total: number;
  discount_total: number;
  customer_id: number;
  customer_name: string;
  customer_email: string;
  customer_phone: string;
  billing: WcAddress;
  shipping: WcAddress;
  payment_method: string;
  payment_method_title: string;
  transaction_id: string;
  line_items: WcLineItem[];
  refunds: WcRefundRecord[];
  coupon_lines: WcCouponLine[];
  notes: WcOrderNote[];
  eos_event_id: number | null;
  eos_synced_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface WcCustomerRecord {
  id: number;
  wc_customer_id: number;
  email: string;
  first_name: string;
  last_name: string;
  username: string;
  avatar_url: string;
  billing: WcAddress;
  total_spent: number;
  total_orders: number;
  date_created: string;
  date_modified: string;
  eos_events_attended: number;
  eos_attendance_history: AttendanceRecord[];
  eos_segments: string[];
  eos_synced_at: string | null;
}

export interface WcCouponRecord {
  id: number;
  wc_coupon_id: number;
  code: string;
  type: WcCouponType;
  amount: number;
  description: string;
  usage_count: number;
  usage_limit: number | null;
  usage_limit_per_user: number | null;
  individual_use: boolean;
  free_shipping: boolean;
  minimum_amount: number | null;
  maximum_amount: number | null;
  date_expires: string | null;
  eos_campaign_id: number | null;
  eos_event_id: number | null;
  created_at: string;
  updated_at: string;
}

export interface WebhookLogRecord {
  id: number;
  event: WebhookEvent;
  wc_order_id: number;
  status: WebhookStatus;
  payload_summary: string;
  error: string | null;
  processed_at: string | null;
  received_at: string;
}

export interface WcSyncModuleStatus {
  status: WcSyncStatusValue;
  last_run: string | null;
  total: number;
  synced: number;
  errors: number;
}

export interface WcSyncStatus {
  products: WcSyncModuleStatus;
  orders: WcSyncModuleStatus;
  customers: WcSyncModuleStatus;
  coupons: WcSyncModuleStatus;
}

export interface WcConnectionStatus {
  connected: boolean;
  woocommerce_version: string;
  store_currency: string;
  store_url: string;
  api_accessible: boolean;
  webhooks_registered: boolean;
  last_checked: string;
}

export interface WcListParams {
  page?: number;
  per_page?: number;
  search?: string;
  status?: string;
  event?: string;
  event_id?: number;
  synced?: boolean;
  orderby?: string;
  order?: string;
}

export const wcApi = {
  // ── Connection ────────────────────────────────────────────────────────
  connectionStatus: () => unwrap<WcConnectionStatus>("woocommerce/status"),

  recheckConnection: () =>
    unwrap<WcConnectionStatus>("woocommerce/status/recheck", { method: "POST" }),

  // ── Products ──────────────────────────────────────────────────────────
  products: (params: WcListParams = {}) => {
    const safe: Record<string, string | number | undefined> = {};
    for (const [k, v] of Object.entries(params)) {
      if (v === undefined || v === "" || v === false) continue;
      safe[k] = typeof v === "boolean" ? (v ? "1" : "0") : v;
    }
    return unwrapCollection<WcProductRecord>(`woocommerce/products${query(safe)}`);
  },

  product: (id: number) => unwrap<WcProductRecord>(`woocommerce/products/${id}`),

  syncProducts: (eventId?: number) =>
    unwrap<{ queued: boolean; job_id: string }>("woocommerce/products/sync", {
      method: "POST",
      body: JSON.stringify(eventId ? { event_id: eventId } : {}),
    }),

  mapProductToEvent: (productId: number, eventId: number, ticketTypeId?: number) =>
    unwrap<WcProductRecord>(`woocommerce/products/${productId}/map`, {
      method: "POST",
      body: JSON.stringify({ event_id: eventId, ticket_type_id: ticketTypeId ?? null }),
    }),

  unmapProduct: (productId: number) =>
    unwrap<{ unmapped: boolean }>(`woocommerce/products/${productId}/map`, {
      method: "DELETE",
    }),

  // ── Orders ────────────────────────────────────────────────────────────
  orders: (params: WcListParams = {}) => {
    const safe: Record<string, string | number | undefined> = {};
    for (const [k, v] of Object.entries(params)) {
      if (v === undefined || v === "" || v === false) continue;
      safe[k] = typeof v === "boolean" ? (v ? "1" : "0") : v;
    }
    return unwrapCollection<WcOrderRecord>(`woocommerce/orders${query(safe)}`);
  },

  order: (id: number) => unwrap<WcOrderRecord>(`woocommerce/orders/${id}`),

  syncOrders: (eventId?: number) =>
    unwrap<{ queued: boolean; job_id: string }>("woocommerce/orders/sync", {
      method: "POST",
      body: JSON.stringify(eventId ? { event_id: eventId } : {}),
    }),

  syncOrderStatus: (wcOrderId: number) =>
    unwrap<{ synced: boolean; eos_event_id: number | null }>(
      `woocommerce/orders/${wcOrderId}/sync`,
      { method: "POST" },
    ),

  exportOrders: (params: WcListParams = {}) =>
    `${config().restUrl}woocommerce/orders/export?${new URLSearchParams(
      Object.fromEntries(
        Object.entries(params)
          .filter(([, v]) => v !== undefined && v !== "")
          .map(([k, v]) => [k, String(v)]),
      ),
    ).toString()}&_wpnonce=${config().nonce}`,

  // ── Customers ─────────────────────────────────────────────────────────
  customers: (params: WcListParams = {}) => {
    const safe: Record<string, string | number | undefined> = {};
    for (const [k, v] of Object.entries(params)) {
      if (v === undefined || v === "" || v === false) continue;
      safe[k] = typeof v === "boolean" ? (v ? "1" : "0") : v;
    }
    return unwrapCollection<WcCustomerRecord>(`woocommerce/customers${query(safe)}`);
  },

  customer: (id: number) => unwrap<WcCustomerRecord>(`woocommerce/customers/${id}`),

  syncCustomers: () =>
    unwrap<{ queued: boolean; job_id: string }>("woocommerce/customers/sync", {
      method: "POST",
    }),

  customerSegments: () =>
    unwrap<{ segments: Array<{ id: string; label: string; count: number }> }>(
      "woocommerce/customers/segments",
    ),

  // ── Coupons ───────────────────────────────────────────────────────────
  coupons: (params: WcListParams = {}) => {
    const safe: Record<string, string | number | undefined> = {};
    for (const [k, v] of Object.entries(params)) {
      if (v === undefined || v === "" || v === false) continue;
      safe[k] = typeof v === "boolean" ? (v ? "1" : "0") : v;
    }
    return unwrapCollection<WcCouponRecord>(`woocommerce/coupons${query(safe)}`);
  },

  coupon: (id: number) => unwrap<WcCouponRecord>(`woocommerce/coupons/${id}`),

  assignCouponToCampaign: (wcCouponId: number, campaignId: number, eventId: number) =>
    unwrap<WcCouponRecord>(`woocommerce/coupons/${wcCouponId}/assign`, {
      method: "POST",
      body: JSON.stringify({ campaign_id: campaignId, event_id: eventId }),
    }),

  unassignCoupon: (wcCouponId: number) =>
    unwrap<{ unassigned: boolean }>(`woocommerce/coupons/${wcCouponId}/assign`, {
      method: "DELETE",
    }),

  syncCoupons: () =>
    unwrap<{ queued: boolean; job_id: string }>("woocommerce/coupons/sync", {
      method: "POST",
    }),

  // ── Sync status ───────────────────────────────────────────────────────
  syncStatus: () => unwrap<WcSyncStatus>("woocommerce/sync/status"),

  // ── Webhooks ──────────────────────────────────────────────────────────
  webhookLog: (params: WcListParams = {}) => {
    const safe: Record<string, string | number | undefined> = {};
    for (const [k, v] of Object.entries(params)) {
      if (v === undefined || v === "" || v === false) continue;
      safe[k] = typeof v === "boolean" ? (v ? "1" : "0") : v;
    }
    return unwrapCollection<WebhookLogRecord>(`woocommerce/webhooks/log${query(safe)}`);
  },

  registerWebhooks: () =>
    unwrap<{ registered: WebhookEvent[]; already_registered: WebhookEvent[] }>(
      "woocommerce/webhooks/register",
      { method: "POST" },
    ),

  deregisterWebhooks: () =>
    unwrap<{ deregistered: WebhookEvent[] }>("woocommerce/webhooks/register", { method: "DELETE" }),

  retryWebhook: (logId: number) =>
    unwrap<{ retried: boolean; status: WebhookStatus }>(`woocommerce/webhooks/log/${logId}/retry`, {
      method: "POST",
    }),

  // ── Log export ────────────────────────────────────────────────────────
  exportLog: () => `${config().restUrl}woocommerce/log/export?_wpnonce=${config().nonce}`,
};

/* ------------------------------------------------------------------------ */
/* CRM / permanent Person                                                    */
/* ------------------------------------------------------------------------ */

export interface PersonSummary {
  person_id: number;
  display_name: string;
  primary_email: string;
  primary_phone: string;
  first_event_id: number;
  last_event_id: number;
  total_events_attended: number;
  total_tickets_purchased: number;
  total_spend: number;
  vip_purchase_count: number;
  last_attendance_at: string | null;
  last_purchase_at: string | null;
}

export interface PersonListParams {
  q?: string;
  wc_customer_id?: number;
  person_id?: number;
  page?: number;
  per_page?: number;
}

export interface PersonIdentitySignal {
  id: number;
  person_id: number;
  type: string;
  value: string;
  confidence: string;
  created_at: string;
}

export interface PersonTag {
  id: number;
  person_id: number;
  tag: string;
  created_at: string;
}

export interface PersonNote {
  id: number;
  person_id: number;
  author_user_id: number;
  author_name: string;
  body: string;
  created_at: string;
}

export interface PersonConsent {
  id: number;
  person_id: number;
  channel: string;
  granted_at: string | null;
  source: string;
  revoked_at: string | null;
  active: boolean;
  created_at: string;
}

export interface SegmentRecord {
  id: number;
  name: string;
  slug: string;
  rule_config: Record<string, unknown>;
  archived: boolean;
  is_system: boolean;
  created_at: string;
  updated_at: string;
  /** Only present when read via a Person's own segment membership. */
  computed_at?: string;
}

export interface SegmentMember {
  person_id: number;
  display_name: string;
  primary_email: string;
  computed_at: string;
}

/**
 * A relationship-timeline entry. Stored entries (person_created,
 * identity_attached) carry id/person_id/created_at; entries derived live
 * from tickets/orders/tags/notes/consent at read time do not — see
 * Person_Timeline_Service::relationship_history() — so those fields are
 * optional here rather than faked.
 */
export interface TimelineEntry {
  id?: number;
  person_id?: number;
  type: string;
  payload: Record<string, unknown>;
  occurred_at: string;
  created_at?: string;
}

export interface PersonEventHistoryEntry {
  event_id: number;
  event_title: string;
  starts_at: string | null;
  tickets: number;
  attended: boolean;
}

export interface PersonProfile {
  identity: {
    person_id: number;
    display_name: string;
    first_name: string;
    last_name: string;
    primary_email: string;
    primary_phone: string;
    avatar_url: string;
    location: string;
    date_of_birth: string | null;
  };
  relationship_metrics: {
    first_interaction: string;
    first_event_id: number;
    last_event_id: number;
    total_events_attended: number;
    total_tickets_purchased: number;
    total_spend: number;
    avg_order_value: number;
    avg_ticket_value: number;
    vip_purchase_count: number;
    complimentary_count: number;
    refund_count: number;
    cancellation_count: number;
    last_purchase_at: string | null;
    last_attendance_at: string | null;
    attendance_rate: number | null;
  };
  identity_signals: PersonIdentitySignal[];
  tags: PersonTag[];
  notes: PersonNote[];
  consents: PersonConsent[];
  segments: SegmentRecord[];
  event_history: PersonEventHistoryEntry[];
  relationship_timeline: TimelineEntry[];
}

export interface EventPersonEntry {
  person_id: number | null;
  display_name: string;
  guest_id: number;
  ticket_id: number;
  ticket_number: string;
  ticket_type_name: string;
  tier: string;
  ticket_status: string;
  wc_order_id: number;
  checked_in: boolean;
  checked_in_at: string | null;
  purchaser_context: { wc_customer_id: number; email: string };
}

export interface RelationshipInsights {
  total_known_people: number;
  purchased_count: number;
  attended_count: number;
  repeat_customer_count: number;
  repeat_customer_definition: string;
  known_revenue: number;
  top_relationships: Array<{
    person_id: number;
    display_name: string;
    primary_email: string;
    total_spend: number;
    total_events_attended: number;
  }>;
  lapsed_customers: { available: false; reason: string };
}

export interface BackfillRun {
  id: number;
  status: "queued" | "running" | "complete" | "failed";
  stage: "wc_customers" | "guests" | "complete";
  offset: number;
  resolved: number;
  created: number;
  conflicts: number;
  started_at: string;
  updated_at: string;
  completed_at: string | null;
}

export const crmApi = {
  // ── Insights ──────────────────────────────────────────────────────────
  insights: () => unwrap<RelationshipInsights>("crm/insights"),

  // ── Persons ───────────────────────────────────────────────────────────
  persons: (params: PersonListParams = {}) =>
    unwrapCollection<PersonSummary>(
      `crm/persons${query(params as Record<string, string | number | undefined>)}`,
    ),
  person: (id: number) => unwrap<PersonProfile>(`crm/persons/${id}`),

  // ── Tags ──────────────────────────────────────────────────────────────
  tags: (personId: number) => unwrap<{ tags: PersonTag[] }>(`crm/persons/${personId}/tags`),
  attachTag: (personId: number, tag: string) =>
    unwrap<{ tag: PersonTag }>(`crm/persons/${personId}/tags`, {
      method: "POST",
      body: JSON.stringify({ tag }),
    }),
  detachTag: (personId: number, tag: string) =>
    unwrap<{ deleted: boolean }>(`crm/persons/${personId}/tags/${encodeURIComponent(tag)}`, {
      method: "DELETE",
    }),

  // ── Notes ─────────────────────────────────────────────────────────────
  notes: (personId: number) => unwrap<{ notes: PersonNote[] }>(`crm/persons/${personId}/notes`),
  createNote: (personId: number, body: string) =>
    unwrap<{ note: PersonNote }>(`crm/persons/${personId}/notes`, {
      method: "POST",
      body: JSON.stringify({ body }),
    }),

  // ── Consent ───────────────────────────────────────────────────────────
  consents: (personId: number) =>
    unwrap<{ consents: PersonConsent[] }>(`crm/persons/${personId}/consents`),
  grantConsent: (personId: number, channel: string, source?: string) =>
    unwrap<{ consent: PersonConsent }>(`crm/persons/${personId}/consents`, {
      method: "POST",
      body: JSON.stringify({ channel, source }),
    }),
  revokeConsent: (personId: number, channel: string) =>
    unwrap<{ revoked: boolean }>(
      `crm/persons/${personId}/consents/${encodeURIComponent(channel)}`,
      { method: "DELETE" },
    ),

  // ── Segments ──────────────────────────────────────────────────────────
  segments: (includeArchived = false) =>
    unwrap<{ segments: SegmentRecord[] }>(
      `crm/segments${includeArchived ? "?include_archived=1" : ""}`,
    ),
  createSegment: (payload: {
    name: string;
    slug?: string;
    rule_config?: Record<string, unknown>;
  }) => unwrap<SegmentRecord>("crm/segments", { method: "POST", body: JSON.stringify(payload) }),
  updateSegment: (
    id: number,
    payload: Partial<{ name: string; slug: string; rule_config: Record<string, unknown> }>,
  ) =>
    unwrap<SegmentRecord>(`crm/segments/${id}`, { method: "POST", body: JSON.stringify(payload) }),
  archiveSegment: (id: number) => unwrap<SegmentRecord>(`crm/segments/${id}`, { method: "DELETE" }),
  segmentMembers: (id: number, page = 1, perPage = 20) =>
    unwrapCollection<SegmentMember>(
      `crm/segments/${id}/members${query({ page, per_page: perPage })}`,
    ),
  attachSegmentMember: (segmentId: number, personId: number) =>
    unwrap<{ attached: boolean }>(`crm/segments/${segmentId}/members`, {
      method: "POST",
      body: JSON.stringify({ person_id: personId }),
    }),
  detachSegmentMember: (segmentId: number, personId: number) =>
    unwrap<{ detached: boolean }>(`crm/segments/${segmentId}/members/${personId}`, {
      method: "DELETE",
    }),

  // ── Event-scoped view ─────────────────────────────────────────────────
  eventPersons: (eventId: number, page = 1, perPage = 20) =>
    unwrapCollection<EventPersonEntry>(
      `events/${eventId}/crm/persons${query({ page, per_page: perPage })}`,
    ),

  // ── Historical backfill ───────────────────────────────────────────────
  backfillRuns: () => unwrap<{ runs: BackfillRun[] }>("crm/backfill/runs"),
  startBackfill: () => unwrap<BackfillRun>("crm/backfill/start", { method: "POST" }),
};
