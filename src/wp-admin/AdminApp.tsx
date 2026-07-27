import { useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  api,
  config,
  selectAttachment,
  type SettingsField,
  type SettingsGroup,
} from "./api";

function Nav({ view }: { view: string }) {
  const { menu } = config();

  return (
    <nav className="eventos-nav">
      {menu.map((item) => (
        <a key={item.slug} href={item.url} aria-current={item.view === view ? "page" : undefined}>
          {item.title}
        </a>
      ))}
    </nav>
  );
}

function Header({ title, subtitle }: { title: string; subtitle: string }) {
  const { branding } = config();
  const logo = branding.logos.dashboard?.url || branding.logos.business?.url || "";

  return (
    <header className="eventos-app__header">
      <div className="eventos-app__brand">
        {logo ? <img src={logo} alt="" /> : null}
        <div>
          <h1 className="eventos-app__title">{title}</h1>
          <p className="eventos-app__subtitle">{subtitle}</p>
        </div>
      </div>
    </header>
  );
}

function Dashboard() {
  const { data, isLoading, error } = useQuery({ queryKey: ["eventos", "dashboard"], queryFn: api.dashboard });

  if (isLoading) return <p>Loading dashboard…</p>;
  if (error) return <div className="eventos-notice eventos-notice--error">{(error as Error).message}</div>;
  if (!data) return null;

  const { system, activity, upcoming_events: upcoming, current_user: user } = data;
  const { menu } = config();

  return (
    <>
      <Header title={String(data.general.business_name || "EventOS")} subtitle="Core configuration overview" />
      <div className="eventos-grid">
        <section className="eventos-card">
          <h2 className="eventos-card__title">System status</h2>
          <p className={`eventos-card__metric eventos-status ${system.healthy ? "eventos-status--ok" : "eventos-status--fail"}`}>
            {system.healthy ? "All checks passing" : "Attention required"}
          </p>
          <ul className="eventos-list">
            {system.checks.map((check) => (
              <li key={check.id}>
                <span>{check.label}</span>
                <span className={`eventos-status ${check.passing ? "eventos-status--ok" : "eventos-status--fail"}`}>
                  {check.value}
                </span>
              </li>
            ))}
          </ul>
        </section>

        <section className="eventos-card">
          <h2 className="eventos-card__title">Environment</h2>
          <ul className="eventos-list">
            <li><span>Plugin version</span><span>{system.plugin_version}</span></li>
            <li><span>Database schema</span><span>{system.db_version}</span></li>
            <li><span>WordPress</span><span>{system.wordpress_version}</span></li>
            <li><span>PHP</span><span>{system.php_version}</span></li>
            <li><span>MySQL</span><span>{system.mysql_version}</span></li>
            <li>
              <span>WooCommerce</span>
              <span>{system.woocommerce.active ? system.woocommerce.version || "Active" : "Not installed"}</span>
            </li>
          </ul>
        </section>

        <section className="eventos-card">
          <h2 className="eventos-card__title">Storage usage</h2>
          <p className="eventos-card__metric">{system.storage.used_human}</p>
          <ul className="eventos-list">
            <li><span>Files</span><span>{system.storage.file_count}</span></li>
            <li><span>Media attachments</span><span>{system.storage.attachments}</span></li>
          </ul>
        </section>

        <section className="eventos-card">
          <h2 className="eventos-card__title">Current user</h2>
          <p className="eventos-card__metric">{user.name}</p>
          <ul className="eventos-list">
            <li><span>Email</span><span>{user.email}</span></li>
            <li>
              <span>EventOS roles</span>
              <span>{user.eventos_roles.length ? user.eventos_roles.join(", ") : "None assigned"}</span>
            </li>
          </ul>
        </section>

        <section className="eventos-card">
          <h2 className="eventos-card__title">Quick actions</h2>
          <ul className="eventos-list">
            {menu.filter((item) => item.view !== "dashboard").map((item) => (
              <li key={item.slug}>
                <a href={item.url}>{item.title}</a>
              </li>
            ))}
          </ul>
        </section>

        <section className="eventos-card">
          <h2 className="eventos-card__title">Upcoming events</h2>
          {upcoming.length ? (
            <ul className="eventos-list">
              {upcoming.map((event) => (
                <li key={event.id}><span>{event.title}</span><span>{event.starts_at}</span></li>
              ))}
            </ul>
          ) : (
            <p className="eventos-empty">No events yet. The Events module will populate this card.</p>
          )}
        </section>

        <section className="eventos-card">
          <h2 className="eventos-card__title">Recent activity</h2>
          {activity.length ? (
            <ul className="eventos-list">
              {activity.map((entry) => (
                <li key={entry.id}>
                  <span>{entry.action.replace(/_/g, " ")} — {entry.user.name}</span>
                  <span>{entry.created_at}</span>
                </li>
              ))}
            </ul>
          ) : (
            <p className="eventos-empty">No activity recorded yet.</p>
          )}
        </section>
      </div>
    </>
  );
}

function Field({
  field,
  value,
  onChange,
}: {
  field: SettingsField;
  value: unknown;
  onChange: (value: unknown) => void;
}) {
  const id = `eventos-field-${field.key}`;

  if (field.type === "boolean") {
    return (
      <div className="eventos-field">
        <label htmlFor={id}>
          <input
            id={id}
            type="checkbox"
            checked={Boolean(value)}
            onChange={(event) => onChange(event.target.checked)}
          />{" "}
          {field.label}
        </label>
      </div>
    );
  }

  if (field.type === "choice") {
    return (
      <div className="eventos-field">
        <label htmlFor={id}>{field.label}</label>
        <select id={id} value={String(value ?? "")} onChange={(event) => onChange(event.target.value)}>
          {field.choices.map((choice) => (
            <option key={choice} value={choice}>{choice}</option>
          ))}
        </select>
      </div>
    );
  }

  if (field.type === "attachment") {
    const attachment = value as number;
    return (
      <div className="eventos-field">
        <label htmlFor={id}>{field.label}</label>
        <div className="eventos-field__media" id={id}>
          <button
            type="button"
            className="button"
            onClick={async () => {
              const picked = await selectAttachment(field.label);
              if (picked) onChange(picked.id);
            }}
          >
            {attachment ? "Replace image" : "Select image"}
          </button>
          {attachment ? (
            <button type="button" className="button-link" onClick={() => onChange(0)}>
              Remove
            </button>
          ) : null}
          <span>{attachment ? `Attachment #${attachment}` : "No image selected"}</span>
        </div>
      </div>
    );
  }

  if (field.type === "color") {
    return (
      <div className="eventos-field">
        <label htmlFor={id}>{field.label}</label>
        <input id={id} type="color" value={String(value ?? "#000000")} onChange={(event) => onChange(event.target.value)} />
      </div>
    );
  }

  if (field.type === "list") {
    return (
      <div className="eventos-field">
        <label htmlFor={id}>{field.label}</label>
        <input
          id={id}
          type="text"
          value={Array.isArray(value) ? value.join(", ") : ""}
          placeholder="example.com, partner.org"
          onChange={(event) => onChange(event.target.value.split(",").map((item) => item.trim()).filter(Boolean))}
        />
      </div>
    );
  }

  const inputType =
    field.type === "email" ? "email" : field.type === "url" ? "url" : field.type === "number" || field.type === "integer" ? "number" : "text";

  return (
    <div className="eventos-field">
      <label htmlFor={id}>{field.label}</label>
      <input
        id={id}
        type={inputType}
        value={String(value ?? "")}
        onChange={(event) =>
          onChange(inputType === "number" ? Number(event.target.value) : event.target.value)
        }
      />
    </div>
  );
}

function SettingsView({ group }: { group: string }) {
  const queryClient = useQueryClient();
  const { data, isLoading, error } = useQuery({ queryKey: ["eventos", "settings"], queryFn: api.settings });
  const [draft, setDraft] = useState<Record<string, unknown> | null>(null);
  const [saved, setSaved] = useState(false);

  const definition: SettingsGroup | undefined = useMemo(
    () => data?.schema.find((entry) => entry.group === group),
    [data, group],
  );

  const values = draft ?? data?.values[group] ?? {};

  const mutation = useMutation({
    mutationFn: (payload: Record<string, unknown>) => api.saveSettings(group, payload),
    onSuccess: () => {
      setSaved(true);
      setDraft(null);
      queryClient.invalidateQueries({ queryKey: ["eventos"] });
    },
  });

  if (isLoading) return <p>Loading settings…</p>;
  if (error) return <div className="eventos-notice eventos-notice--error">{(error as Error).message}</div>;
  if (!definition) return <div className="eventos-notice eventos-notice--error">Unknown settings group.</div>;

  return (
    <>
      <Header title={definition.label} subtitle={definition.description} />
      {mutation.error ? (
        <div className="eventos-notice eventos-notice--error">{(mutation.error as Error).message}</div>
      ) : null}
      {saved && !mutation.isPending ? (
        <div className="eventos-notice eventos-notice--success">Settings saved.</div>
      ) : null}
      <form
        className="eventos-form"
        onSubmit={(event) => {
          event.preventDefault();
          setSaved(false);
          mutation.mutate(values);
        }}
      >
        {definition.fields.map((field) => (
          <Field
            key={field.key}
            field={field}
            value={values[field.key]}
            onChange={(next) => {
              setSaved(false);
              setDraft({ ...values, [field.key]: next });
            }}
          />
        ))}
        <div className="eventos-actions">
          <button type="submit" className="button button-primary" disabled={mutation.isPending}>
            {mutation.isPending ? "Saving…" : "Save changes"}
          </button>
        </div>
      </form>
    </>
  );
}

function TeamView() {
  const queryClient = useQueryClient();
  const [search, setSearch] = useState("");
  const [email, setEmail] = useState("");
  const [inviteRoles, setInviteRoles] = useState<string[]>([]);

  const roles = useQuery({ queryKey: ["eventos", "roles"], queryFn: api.roles });
  const members = useQuery({ queryKey: ["eventos", "members", search], queryFn: () => api.members(search) });
  const invitations = useQuery({ queryKey: ["eventos", "invitations"], queryFn: api.invitations });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ["eventos"] });

  const updateMember = useMutation({
    mutationFn: ({ id, next }: { id: number; next: string[] }) => api.updateMember(id, next),
    onSuccess: invalidate,
  });

  const invite = useMutation({
    mutationFn: () => api.createInvitation(email, inviteRoles),
    onSuccess: () => {
      setEmail("");
      setInviteRoles([]);
      invalidate();
    },
  });

  const revoke = useMutation({
    mutationFn: (id: number) => api.revokeInvitation(id),
    onSuccess: invalidate,
  });

  const roleList = roles.data?.roles ?? [];

  return (
    <>
      <Header title="Team" subtitle="Assign EventOS roles to WordPress users and invite new team members." />

      {[roles.error, members.error, invitations.error, updateMember.error, invite.error, revoke.error]
        .filter(Boolean)
        .slice(0, 1)
        .map((err, index) => (
          <div className="eventos-notice eventos-notice--error" key={index}>
            {(err as Error).message}
          </div>
        ))}

      <section className="eventos-card" style={{ marginBottom: 20 }}>
        <h2 className="eventos-card__title">Invite a team member</h2>
        <form
          onSubmit={(event) => {
            event.preventDefault();
            invite.mutate();
          }}
        >
          <div className="eventos-field">
            <label htmlFor="eventos-invite-email">Email address</label>
            <input
              id="eventos-invite-email"
              type="email"
              required
              value={email}
              onChange={(event) => setEmail(event.target.value)}
            />
          </div>
          <div className="eventos-field">
            <span>Roles</span>
            <div className="eventos-roles">
              {roleList.map((role) => (
                <label key={role.slug}>
                  <input
                    type="checkbox"
                    checked={inviteRoles.includes(role.slug)}
                    onChange={(event) =>
                      setInviteRoles((current) =>
                        event.target.checked
                          ? [...current, role.slug]
                          : current.filter((slug) => slug !== role.slug),
                      )
                    }
                  />
                  {role.label}
                </label>
              ))}
            </div>
          </div>
          <div className="eventos-actions">
            <button
              type="submit"
              className="button button-primary"
              disabled={invite.isPending || !inviteRoles.length}
            >
              {invite.isPending ? "Sending…" : "Send invitation"}
            </button>
          </div>
        </form>
      </section>

      <section className="eventos-card" style={{ marginBottom: 20 }}>
        <h2 className="eventos-card__title">Pending invitations</h2>
        {invitations.data?.invitations.length ? (
          <table className="eventos-table">
            <thead>
              <tr><th>Email</th><th>Roles</th><th>Status</th><th>Expires</th><th /></tr>
            </thead>
            <tbody>
              {invitations.data.invitations.map((invitation) => (
                <tr key={invitation.id}>
                  <td>{invitation.email}</td>
                  <td>{invitation.roles.join(", ")}</td>
                  <td>{invitation.status}</td>
                  <td>{invitation.expires_at}</td>
                  <td>
                    {invitation.status === "pending" ? (
                      <button type="button" className="button-link" onClick={() => revoke.mutate(invitation.id)}>
                        Revoke
                      </button>
                    ) : null}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          <p className="eventos-empty">No invitations yet.</p>
        )}
      </section>

      <section className="eventos-card">
        <h2 className="eventos-card__title">WordPress users</h2>
        <div className="eventos-field">
          <label htmlFor="eventos-member-search">Search users</label>
          <input
            id="eventos-member-search"
            type="text"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
          />
        </div>
        <table className="eventos-table">
          <thead>
            <tr><th>User</th><th>WordPress roles</th><th>EventOS roles</th></tr>
          </thead>
          <tbody>
            {(members.data?.members ?? []).map((member) => (
              <tr key={member.id}>
                <td>
                  <strong>{member.name}</strong>
                  <br />
                  {member.email}
                </td>
                <td>{member.wp_roles.join(", ")}</td>
                <td>
                  <div className="eventos-roles">
                    {roleList.map((role) => (
                      <label key={role.slug}>
                        <input
                          type="checkbox"
                          checked={member.eventos_roles.includes(role.slug)}
                          disabled={updateMember.isPending}
                          onChange={(event) => {
                            const next = event.target.checked
                              ? [...member.eventos_roles, role.slug]
                              : member.eventos_roles.filter((slug) => slug !== role.slug);
                            updateMember.mutate({ id: member.id, next });
                          }}
                        />
                        {role.label}
                      </label>
                    ))}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>
    </>
  );
}

export function AdminApp({ view }: { view: string }) {
  let content = <Dashboard />;

  if (view === "settings/team") {
    content = <TeamView />;
  } else if (view.startsWith("settings/")) {
    content = <SettingsView group={view.replace("settings/", "")} />;
  }

  return (
    <div className="eventos-app">
      <Nav view={view} />
      {content}
    </div>
  );
}
