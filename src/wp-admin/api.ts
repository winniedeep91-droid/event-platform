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
  dashboard: () => unwrap<EventsDashboardPayload>("events/dashboard"),
  options: () => unwrap<EventFormOptions>("events/options"),
  list: (params: EventListParams) => unwrapCollection<EventRecord>(`events${query(params)}`),
  calendar: (from: string, to: string) =>
    unwrap<{ from: string; to: string; events: EventRecord[] }>(`events/calendar${query({ from, to })}`),
  get: (id: number) => unwrap<EventRecord>(`events/${id}`),
  create: (payload: EventPayload) =>
    unwrap<EventRecord>("events", { method: "POST", body: JSON.stringify(payload) }),
  update: (id: number, payload: EventPayload) =>
    unwrap<EventRecord>(`events/${id}`, { method: "POST", body: JSON.stringify(payload) }),
  remove: (id: number) => unwrap<{ deleted: boolean }>(`events/${id}`, { method: "DELETE" }),
  transition: (id: number, status: string) =>
    unwrap<EventRecord>(`events/${id}/status`, { method: "POST", body: JSON.stringify({ status }) }),
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
};
