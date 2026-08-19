/**
 * Cmd/Ctrl+K global search — queries every entity the user may access in one
 * request and renders results grouped by entity, each group in the order
 * the registry returned it (relevance-ranked within the group server-side).
 */
import { useEffect, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { searchApi, type SearchGroup } from "./api";
import { Alert, Badge, EmptyState, LoadingState, Modal, SearchInput } from "./ui";

const MIN_TERM_LENGTH = 2;

function badgeTone(status: string): "success" | "warning" | "neutral" | "danger" {
  const positive = ["confirmed", "published", "active", "processing", "completed", "checked_in"];
  const negative = ["cancelled", "failed", "no_show", "archived"];

  if (positive.includes(status)) return "success";
  if (negative.includes(status)) return "danger";
  if ("" === status) return "neutral";
  return "warning";
}

function ResultGroup({ group, onNavigate }: { group: SearchGroup; onNavigate: () => void }) {
  return (
    <div className="eos-search-group">
      <h3 className="eos-search-group__label">
        {group.label}
        <span className="eos-search-group__count">{group.total}</span>
      </h3>
      <ul className="eos-search-group__list">
        {group.items.map((item) => (
          <li key={`${item.entity}-${item.id}`}>
            <a className="eos-search-result" href={item.url || "#"} onClick={() => onNavigate()}>
              <span className="eos-search-result__type">{group.entity}</span>
              <span className="eos-search-result__title">{item.title}</span>
              {item.subtitle ? (
                <span className="eos-search-result__subtitle">{item.subtitle}</span>
              ) : null}
              {item.status ? <Badge tone={badgeTone(item.status)}>{item.status}</Badge> : null}
            </a>
          </li>
        ))}
      </ul>
    </div>
  );
}

export function GlobalSearch({ open, onClose }: { open: boolean; onClose: () => void }) {
  const [term, setTerm] = useState("");

  // Fresh input every time the palette opens, rather than showing the last
  // session's stale results the instant it reopens.
  useEffect(() => {
    if (open) setTerm("");
  }, [open]);

  const trimmed = term.trim();
  const enabled = open && trimmed.length >= MIN_TERM_LENGTH;

  const results = useQuery({
    queryKey: ["eventos", "search", trimmed],
    queryFn: () => searchApi.search(trimmed),
    enabled,
  });

  const groups = results.data?.groups ?? [];
  const totalResults = groups.reduce((sum, group) => sum + group.items.length, 0);

  return (
    <Modal open={open} onClose={onClose} title="Search EventOS" size="lg">
      <SearchInput
        label="Search"
        value={term}
        onChange={setTerm}
        placeholder="Search events, people, guests, tickets, orders, campaigns…"
        autoFocus
      />

      <div className="eos-search-results">
        {!enabled && "" === trimmed ? (
          <EmptyState
            title="Search across EventOS"
            description="Find events, venues, artists, people, guests, tickets, orders and campaigns from one place."
          />
        ) : !enabled ? (
          <EmptyState
            title="Keep typing…"
            description={`Enter at least ${MIN_TERM_LENGTH} characters to search.`}
          />
        ) : results.isLoading ? (
          <LoadingState label="Searching…" />
        ) : results.isError ? (
          <Alert tone="danger" title="Search failed">
            {(results.error as Error).message}
          </Alert>
        ) : 0 === totalResults ? (
          <EmptyState title="No results" description={`Nothing matched "${trimmed}".`} />
        ) : (
          groups.map((group) => (
            <ResultGroup key={group.entity} group={group} onNavigate={onClose} />
          ))
        )}
      </div>
    </Modal>
  );
}
