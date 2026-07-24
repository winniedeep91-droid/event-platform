import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useServerFn } from "@tanstack/react-start";
import { listMyOrganizations, type OrganizationSummary } from "@/lib/organizations.functions";
import { roleHasPermission, type PermissionKey } from "@/lib/permissions";
import { useAuth } from "./use-auth";

const STORAGE_KEY = "eventos.currentOrgId";

type CurrentOrgContextValue = {
  organizations: OrganizationSummary[];
  currentOrg: OrganizationSummary | null;
  setCurrentOrgId: (id: string) => void;
  isLoading: boolean;
  refresh: () => Promise<void>;
  can: (permission: PermissionKey) => boolean;
};

const CurrentOrgContext = createContext<CurrentOrgContextValue | undefined>(undefined);

export function CurrentOrgProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth();
  const qc = useQueryClient();
  const fetchOrgs = useServerFn(listMyOrganizations);

  const { data, isLoading } = useQuery({
    queryKey: ["organizations", user?.id],
    queryFn: () => fetchOrgs(),
    enabled: !!user,
  });

  const [currentOrgId, setCurrentOrgIdState] = useState<string | null>(() => {
    if (typeof window === "undefined") return null;
    return window.localStorage.getItem(STORAGE_KEY);
  });

  const organizations = data ?? [];

  useEffect(() => {
    if (!organizations.length) return;
    if (!currentOrgId || !organizations.some((o) => o.id === currentOrgId)) {
      const next = organizations[0].id;
      setCurrentOrgIdState(next);
      if (typeof window !== "undefined") window.localStorage.setItem(STORAGE_KEY, next);
    }
  }, [organizations, currentOrgId]);

  const setCurrentOrgId = useCallback((id: string) => {
    setCurrentOrgIdState(id);
    if (typeof window !== "undefined") window.localStorage.setItem(STORAGE_KEY, id);
  }, []);

  const currentOrg = useMemo(
    () => organizations.find((o) => o.id === currentOrgId) ?? null,
    [organizations, currentOrgId],
  );

  const value: CurrentOrgContextValue = {
    organizations,
    currentOrg,
    setCurrentOrgId,
    isLoading,
    refresh: async () => {
      await qc.invalidateQueries({ queryKey: ["organizations"] });
    },
    can: (permission) => roleHasPermission(currentOrg?.role, permission),
  };

  return <CurrentOrgContext.Provider value={value}>{children}</CurrentOrgContext.Provider>;
}

export function useCurrentOrg() {
  const ctx = useContext(CurrentOrgContext);
  if (!ctx) throw new Error("useCurrentOrg must be used within CurrentOrgProvider");
  return ctx;
}
