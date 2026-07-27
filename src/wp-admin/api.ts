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
