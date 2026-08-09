/**
 * Organisation branding screen: logos and brand colours with a live preview.
 */
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api, platformApi } from "../../api";
import {
  Alert,
  Card,
  Grid,
  LoadingState,
  PageLayout,
  Section,
  Stack,
  useToast,
} from "../../ui";
import { SettingsForm, type SettingsValues } from "./settings-form";
import { humanise } from "./shared";

export function BrandingView() {
  const queryClient = useQueryClient();
  const toast = useToast();

  const settings = useQuery({ queryKey: ["eventos", "settings"], queryFn: api.settings });
  const branding = useQuery({
    queryKey: ["eventos", "platform", "branding"],
    queryFn: platformApi.branding,
  });

  const save = useMutation({
    mutationFn: (values: SettingsValues) => api.saveSettings("branding", values),
    onSuccess: () => {
      toast.success("Branding saved.");
      void queryClient.invalidateQueries({ queryKey: ["eventos", "settings"] });
      void queryClient.invalidateQueries({ queryKey: ["eventos", "platform", "branding"] });
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const group = settings.data?.schema.find((item) => item.group === "branding");
  const values = settings.data?.values.branding ?? {};
  const resolved = branding.data ?? settings.data?.branding;

  return (
    <PageLayout
      title="Branding"
      description="Logos and colours applied across the admin, documents and customer emails."
    >
      <Stack>
        {settings.error ? <Alert tone="danger">{(settings.error as Error).message}</Alert> : null}
        {branding.error ? <Alert tone="danger">{(branding.error as Error).message}</Alert> : null}

        {settings.isLoading ? <LoadingState label="Loading branding…" /> : null}

        {resolved ? (
          <Section title="Current brand" description="Resolved values used by every module.">
            <Grid minColumnWidth={220}>
              {Object.entries(resolved.colors).map(([key, value]) => (
                <Card key={key} title={humanise(key)}>
                  <Stack>
                    <span
                      aria-hidden="true"
                      style={{
                        display: "block",
                        height: 44,
                        borderRadius: 8,
                        background: value,
                        border: "1px solid rgba(0,0,0,.1)",
                      }}
                    />
                    <code>{value}</code>
                  </Stack>
                </Card>
              ))}
              {Object.entries(resolved.logos).map(([key, logo]) => (
                <Card key={key} title={humanise(key)}>
                  {logo?.url ? (
                    <img src={logo.url} alt="" style={{ maxHeight: 56, maxWidth: "100%" }} />
                  ) : (
                    <p className="eos-field__hint">No image selected.</p>
                  )}
                </Card>
              ))}
            </Grid>
          </Section>
        ) : null}

        {group ? (
          <Section title={group.label} description={group.description}>
            <Card>
              <SettingsForm
                group={group}
                values={values}
                saving={save.isPending}
                onSubmit={(next) => save.mutate(next)}
              />
            </Card>
          </Section>
        ) : null}
      </Stack>
    </PageLayout>
  );
}
