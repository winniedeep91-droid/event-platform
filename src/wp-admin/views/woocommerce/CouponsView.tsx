import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Alert,
  Badge,
  Button,
  Card,
  DataTable,
  Drawer,
  FilterBar,
  Grid,
  Input,
  LinkButton,
  LoadingState,
  Modal,
  PageLayout,
  Pagination,
  Select,
  Stack,
  StatCard,
  useToast,
  type DataTableColumn,
  type FilterDefinition,
  type SelectOption,
} from "../../ui";
import { wcApi, eventsApi, type WcCouponRecord, type WcCouponType } from "../../api";
import { fmtDate, fmtMoney, wcErrorMessage } from "./shared";

const TYPE_FILTER: FilterDefinition = {
  key: "type",
  label: "Type",
  options: [
    { value: "percent", label: "Percentage" },
    { value: "fixed_cart", label: "Fixed cart" },
    { value: "fixed_product", label: "Fixed product" },
  ],
};

const ASSIGNED_FILTER: FilterDefinition = {
  key: "assigned",
  label: "Campaign",
  options: [
    { value: "true", label: "Assigned to campaign" },
    { value: "false", label: "Unassigned" },
  ],
};

function discountLabel(coupon: WcCouponRecord): string {
  if (coupon.type === "percent") return `${coupon.amount}%`;
  return fmtMoney(coupon.amount);
}

function usagePct(coupon: WcCouponRecord): number | null {
  if (!coupon.usage_limit || coupon.usage_limit === 0) return null;
  return Math.min(100, Math.round((coupon.usage_count / coupon.usage_limit) * 100));
}

function AssignModal({ coupon, onClose }: { coupon: WcCouponRecord; onClose: () => void }) {
  const toast = useToast();
  const qc = useQueryClient();
  const [eventId, setEventId] = useState<string>(
    coupon.eos_event_id ? String(coupon.eos_event_id) : "",
  );
  const [campaignId, setCampaignId] = useState<string>(
    coupon.eos_campaign_id ? String(coupon.eos_campaign_id) : "",
  );

  const eventsQuery = useQuery({
    queryKey: ["eventos", "events", { per_page: 100 }],
    queryFn: () => eventsApi.list({ per_page: 100 }),
  });

  const campaignsQuery = useQuery({
    queryKey: ["eventos", "marketing", "campaigns", Number(eventId)],
    queryFn: () => eventsApi.discountCampaigns(Number(eventId)),
    enabled: !!eventId && Number(eventId) > 0,
  });

  const assignMutation = useMutation({
    mutationFn: () => wcApi.assignCouponToCampaign(coupon.id, Number(campaignId), Number(eventId)),
    onSuccess: () => {
      toast.success("Coupon assigned to campaign.", "Assigned");
      void qc.invalidateQueries({ queryKey: ["wc", "coupons"] });
      onClose();
    },
    onError: (err: unknown) => toast.error(wcErrorMessage(err), "Assignment failed"),
  });

  const unassignMutation = useMutation({
    mutationFn: () => wcApi.unassignCoupon(coupon.id),
    onSuccess: () => {
      toast.success("Coupon unassigned.", "Unassigned");
      void qc.invalidateQueries({ queryKey: ["wc", "coupons"] });
      onClose();
    },
    onError: (err: unknown) => toast.error(wcErrorMessage(err), "Unassign failed"),
  });

  const eventOptions: SelectOption[] = [
    { value: "", label: "Select event…" },
    ...(eventsQuery.data?.items ?? []).map((e) => ({ value: String(e.id), label: e.title })),
  ];

  const campaignOptions: SelectOption[] = [
    { value: "", label: "Select campaign…" },
    ...(campaignsQuery.data?.campaigns ?? []).map((c) => ({
      value: String(c.id),
      label: c.name,
    })),
  ];

  return (
    <Modal
      open
      onClose={onClose}
      title={`Assign coupon: ${coupon.code}`}
      description="Link this WooCommerce coupon to an EventOS campaign for tracking."
      footer={
        <div className="eos-inline">
          <Button onClick={onClose}>Cancel</Button>
          {coupon.eos_campaign_id && (
            <Button
              variant="danger"
              loading={unassignMutation.isPending}
              onClick={() => unassignMutation.mutate()}
            >
              Remove assignment
            </Button>
          )}
          <Button
            variant="primary"
            loading={assignMutation.isPending}
            disabled={!eventId || !campaignId}
            onClick={() => assignMutation.mutate()}
          >
            Save assignment
          </Button>
        </div>
      }
    >
      <Stack>
        <Select
          label="Event"
          value={eventId}
          options={eventOptions}
          onChange={(e) => {
            setEventId(e.target.value);
            setCampaignId("");
          }}
        />
        {eventId && (
          <Select
            label="Campaign"
            value={campaignId}
            options={campaignOptions}
            onChange={(e) => setCampaignId(e.target.value)}
          />
        )}
        <Grid minColumnWidth={140}>
          <div>
            <p className="eos-field__label">Discount</p>
            <strong>{discountLabel(coupon)}</strong>
          </div>
          <div>
            <p className="eos-field__label">Uses</p>
            <span>
              {coupon.usage_count}
              {coupon.usage_limit ? ` / ${coupon.usage_limit}` : ""}
            </span>
          </div>
        </Grid>
      </Stack>
    </Modal>
  );
}

function CouponDrawer({ coupon, onClose }: { coupon: WcCouponRecord; onClose: () => void }) {
  const pct = usagePct(coupon);

  return (
    <Drawer
      open
      onClose={onClose}
      title={coupon.code}
      description={coupon.description || "No description"}
      footer={
        <LinkButton
          href={`/wp-admin/post.php?post=${coupon.wc_coupon_id}&action=edit`}
          target="_blank"
          rel="noreferrer"
        >
          Edit in WooCommerce ↗
        </LinkButton>
      }
    >
      <Stack>
        <Grid minColumnWidth={140}>
          <div>
            <p className="eos-field__label">Type</p>
            <Badge tone="neutral">{coupon.type.replace("_", " ")}</Badge>
          </div>
          <div>
            <p className="eos-field__label">Discount</p>
            <strong>{discountLabel(coupon)}</strong>
          </div>
          <div>
            <p className="eos-field__label">Uses</p>
            <span>
              {coupon.usage_count}
              {coupon.usage_limit ? ` / ${coupon.usage_limit}` : " (unlimited)"}
            </span>
          </div>
          <div>
            <p className="eos-field__label">Per user</p>
            <span>{coupon.usage_limit_per_user ?? "Unlimited"}</span>
          </div>
          <div>
            <p className="eos-field__label">Expires</p>
            <span>{fmtDate(coupon.date_expires)}</span>
          </div>
          <div>
            <p className="eos-field__label">Individual use</p>
            <Badge tone={coupon.individual_use ? "warning" : "neutral"}>
              {coupon.individual_use ? "Yes" : "No"}
            </Badge>
          </div>
          <div>
            <p className="eos-field__label">Free shipping</p>
            <Badge tone={coupon.free_shipping ? "success" : "neutral"}>
              {coupon.free_shipping ? "Yes" : "No"}
            </Badge>
          </div>
        </Grid>

        {(coupon.minimum_amount || coupon.maximum_amount) && (
          <Card title="Spend limits">
            <Grid minColumnWidth={140}>
              <div>
                <p className="eos-field__label">Minimum spend</p>
                <span>{coupon.minimum_amount ? fmtMoney(coupon.minimum_amount) : "None"}</span>
              </div>
              <div>
                <p className="eos-field__label">Maximum spend</p>
                <span>{coupon.maximum_amount ? fmtMoney(coupon.maximum_amount) : "None"}</span>
              </div>
            </Grid>
          </Card>
        )}

        <Card title="Usage">
          {pct != null ? (
            <Stack>
              <div className="eos-inline" style={{ justifyContent: "space-between" }}>
                <span>{coupon.usage_count} used</span>
                <span className="eos-page__description">
                  {pct}% of {coupon.usage_limit}
                </span>
              </div>
              <div
                style={{
                  height: 8,
                  background: "var(--eos-surface-muted)",
                  borderRadius: 8,
                  overflow: "hidden",
                }}
              >
                <div
                  style={{
                    height: "100%",
                    width: `${pct}%`,
                    background: pct > 80 ? "var(--eos-danger)" : "var(--eos-primary)",
                    borderRadius: 8,
                  }}
                />
              </div>
            </Stack>
          ) : (
            <p>
              {coupon.usage_count} use{coupon.usage_count !== 1 ? "s" : ""} (no limit)
            </p>
          )}
        </Card>

        <Card title="EventOS campaign">
          <Grid minColumnWidth={140}>
            <div>
              <p className="eos-field__label">Campaign ID</p>
              <span>{coupon.eos_campaign_id ?? "—"}</span>
            </div>
            <div>
              <p className="eos-field__label">Event ID</p>
              <span>{coupon.eos_event_id ?? "—"}</span>
            </div>
            <div>
              <p className="eos-field__label">Last synced</p>
              <span>{fmtDate(coupon.created_at)}</span>
            </div>
          </Grid>
        </Card>
      </Stack>
    </Drawer>
  );
}

export function CouponsView() {
  const [filterValues, setFilterValues] = useState<Record<string, string>>({});
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [drawer, setDrawer] = useState<WcCouponRecord | null>(null);
  const [assignTarget, setAssignTarget] = useState<WcCouponRecord | null>(null);

  const PER_PAGE = 20;
  const status = filterValues["type"] ?? "";

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ["wc", "coupons", { search, status, page }],
    queryFn: () => wcApi.coupons({ search, status, page, per_page: PER_PAGE }),
    placeholderData: (prev) => prev,
  });

  const coupons = data?.items ?? [];
  const total = data?.total ?? 0;
  const totalPages = data?.totalPages ?? 1;
  const totalUses = coupons.reduce((acc, c) => acc + c.usage_count, 0);
  const assigned = coupons.filter((c) => c.eos_campaign_id != null).length;

  const columns: DataTableColumn<WcCouponRecord>[] = [
    {
      key: "code",
      header: "Code",
      cell: (row) => (
        <Stack>
          <button className="eos-btn eos-btn--link" onClick={() => setDrawer(row)}>
            <code style={{ fontWeight: 700 }}>{row.code}</code>
          </button>
          {row.description && <span className="eos-page__description">{row.description}</span>}
        </Stack>
      ),
    },
    {
      key: "type",
      header: "Type",
      cell: (row) => <Badge tone="neutral">{row.type.replace("_", " ")}</Badge>,
    },
    {
      key: "amount",
      header: "Discount",
      cell: (row) => <strong>{discountLabel(row)}</strong>,
    },
    {
      key: "usage_count",
      header: "Uses",
      cell: (row) => {
        const pct = usagePct(row);
        return (
          <span>
            {row.usage_count}
            {row.usage_limit ? (
              <span className="eos-page__description"> / {row.usage_limit}</span>
            ) : null}
            {pct != null && pct > 80 && (
              <Badge tone="danger" style={{ marginLeft: 6 }}>
                {pct}%
              </Badge>
            )}
          </span>
        );
      },
    },
    {
      key: "date_expires",
      header: "Expires",
      cell: (row) => {
        if (!row.date_expires) return <span className="eos-page__description">Never</span>;
        const expired = new Date(row.date_expires) < new Date();
        return <Badge tone={expired ? "danger" : "neutral"}>{fmtDate(row.date_expires)}</Badge>;
      },
    },
    {
      key: "eos_campaign_id",
      header: "Campaign",
      cell: (row) =>
        row.eos_campaign_id ? (
          <Badge tone="success">#{row.eos_campaign_id}</Badge>
        ) : (
          <Badge tone="neutral">Unassigned</Badge>
        ),
    },
    {
      key: "id",
      header: "",
      cell: (row) => (
        <div className="eos-inline">
          <Button size="sm" onClick={() => setAssignTarget(row)}>
            {row.eos_campaign_id ? "Reassign" : "Assign"}
          </Button>
          <Button size="sm" onClick={() => setDrawer(row)}>
            View
          </Button>
        </div>
      ),
    },
  ];

  return (
    <PageLayout
      title="Coupons"
      description="Every WooCommerce coupon, including those generated automatically by EventOS discount campaigns."
    >
      <Stack>
        <Grid minColumnWidth={160}>
          <StatCard label="Total coupons" value={total.toLocaleString()} />
          <StatCard
            label="Assigned to campaigns"
            value={assigned.toLocaleString()}
            hint="in current page"
          />
          <StatCard label="Total uses" value={totalUses.toLocaleString()} hint="in current page" />
        </Grid>

        <Alert tone="info" title="WooCommerce coupons">
          Coupons are created and validated in WooCommerce. EventOS links them to marketing
          campaigns for tracking. Discount campaigns created in EventOS automatically generate
          WooCommerce coupons.
        </Alert>

        <Card title={`Coupons${total > 0 ? ` (${total.toLocaleString()})` : ""}`}>
          <Stack>
            <FilterBar
              search={{ value: search, onChange: setSearch, placeholder: "Search by code…" }}
              filters={[TYPE_FILTER, ASSIGNED_FILTER]}
              values={filterValues}
              onFilterChange={(key, value) => {
                setFilterValues((prev) => ({ ...prev, [key]: value }));
                setPage(1);
              }}
              onReset={() => {
                setFilterValues({});
                setSearch("");
                setPage(1);
              }}
            />

            {isLoading ? (
              <LoadingState label="Loading coupons…" />
            ) : error ? (
              <Alert
                tone="danger"
                title="Could not load coupons"
                actions={
                  <Button size="sm" onClick={() => void refetch()}>
                    Retry
                  </Button>
                }
              >
                {wcErrorMessage(error)}
              </Alert>
            ) : (
              <>
                <DataTable
                  caption="WooCommerce coupons"
                  columns={columns}
                  rows={coupons}
                  getRowId={(row) => String(row.id)}
                  emptyTitle="No coupons found"
                  emptyDescription={
                    search || status
                      ? "Try adjusting your filters."
                      : "Coupons created in WooCommerce, or generated by a discount campaign, will appear here automatically."
                  }
                />
                {totalPages > 1 && (
                  <Pagination
                    page={page}
                    totalPages={totalPages}
                    total={total}
                    onPageChange={setPage}
                  />
                )}
              </>
            )}
          </Stack>
        </Card>

        {drawer && <CouponDrawer coupon={drawer} onClose={() => setDrawer(null)} />}
        {assignTarget && (
          <AssignModal coupon={assignTarget} onClose={() => setAssignTarget(null)} />
        )}
      </Stack>
    </PageLayout>
  );
}
