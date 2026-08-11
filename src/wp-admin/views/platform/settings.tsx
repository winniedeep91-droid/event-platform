/**
 * Organisation settings screen: every non-branding settings group rendered
 * from the REST schema with tabbed navigation.
 */
import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api } from "../../api";
import {
  Alert,
  Card,
  EmptyState,
  LoadingState,
  PageLayout,
  Section,
  Stack,
  Tabs,
  useToast,
} from "../../ui";
import { SettingsForm, type SettingsValues } from "./settings-form";

export function OrganisationSettingsView() {
  const queryClient = useQueryClient();
  const toast = useToast();
  const [active, setActive] = useState("");

  const settings = useQuery({ queryKey: ["eventos", "settings"], queryFn: api.settings });

  const save = useMutation({
    mutationFn: ({ group, values }: { group: string; values: SettingsValues }) =>
      api.saveSettings(group, values),
    onSuccess: () => {
      toast.success("Settings saved.");
      void queryClient.invalidateQueries({ queryKey: ["eventos", "settings"] });
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const groups = (settings.data?.schema ?? []).filter((group) => group.group !== "branding");
  const current = groups.find((group) => group.group === active) ?? groups[0];

  return (
    <PageLayout
      title="Organisation Settings"
      description="Configure this installation: identity, regional formats, email, storage and security."
    >
      <Stack>
        {settings.error ? <Alert tone="danger">{(settings.error as Error).message}</Alert> : null}
        {settings.isLoading ? <LoadingState label="Loading settings…" /> : null}

        {!settings.isLoading && !groups.length ? (
          <EmptyState
            title="No settings registered"
            description="Modules register their settings groups with the settings registry."
          />
        ) : null}

        {groups.length ? (
          <Tabs
            label="Settings groups"
            value={current?.group ?? groups[0].group}
            onChange={setActive}
            items={groups.map((group) => ({
              id: group.group,
              label: group.label,
              content: (
                <Section title={group.label} description={group.description}>
                  <Card>
                    <SettingsForm
                      key={group.group}
                      group={group}
                      values={settings.data?.values[group.group] ?? {}}
                      saving={save.isPending}
                      onSubmit={(values) => save.mutate({ group: group.group, values })}
                    />
                  </Card>
                </Section>
              ),
            }))}
          />
        ) : null}
      </Stack>
    </PageLayout>
  );
}
