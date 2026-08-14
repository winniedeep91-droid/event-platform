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
import { wcApi, eventsApi, type WcProductRecord, type WcProductStatus } from "../../api";
import { fmtMoney, fmtDate, wcErrorMessage, wcProductStatusTone } from "./shared";

const STATUS_FILTER: FilterDefinition = {
  key: "status",
  label: "Status",
  options: [
    { value: "publish", label: "Published" },
    { value: "draft", label: "Draft" },
    { value: "private", label: "Private" },
    { value: "pending", label: "Pending" },
  ],
};

const MAPPED_FILTER: FilterDefinition = {
  key: "mapped",
  label: "Mapping",
  options: [
    { value: "true", label: "Mapped to event" },
    { value: "false", label: "Unmapped" },
  ],
};

function MappingModal({ product, onClose }: { product: WcProductRecord; onClose: () => void }) {
  const toast = useToast();
  const qc = useQueryClient();
  const [eventId, setEventId] = useState<string>(
    product.eos_event_id ? String(product.eos_event_id) : "",
  );
  const [ticketTypeId, setTicketTypeId] = useState<string>(
    product.eos_ticket_type_id ? String(product.eos_ticket_type_id) : "",
  );

  const eventsQuery = useQuery({
    queryKey: ["eventos", "events", { per_page: 100 }],
    queryFn: () => eventsApi.list({ per_page: 100 }),
  });

  const ticketTypesQuery = useQuery({
    queryKey: ["eventos", "ticket-types", Number(eventId)],
    queryFn: () => eventsApi.ticketTypes(Number(eventId)),
    enabled: !!eventId && Number(eventId) > 0,
  });

  const mapMutation = useMutation({
    mutationFn: () =>
      wcApi.mapProductToEvent(
        product.id,
        Number(eventId),
        ticketTypeId ? Number(ticketTypeId) : undefined,
      ),
    onSuccess: () => {
      toast.success("Product mapped to event.", "Mapped");
      void qc.invalidateQueries({ queryKey: ["wc", "products"] });
      onClose();
    },
    onError: (err: unknown) => toast.error(wcErrorMessage(err), "Mapping failed"),
  });

  const unmapMutation = useMutation({
    mutationFn: () => wcApi.unmapProduct(product.id),
    onSuccess: () => {
      toast.success("Product unmapped.", "Unmapped");
      void qc.invalidateQueries({ queryKey: ["wc", "products"] });
      onClose();
    },
    onError: (err: unknown) => toast.error(wcErrorMessage(err), "Unmap failed"),
  });

  const eventOptions: SelectOption[] = [
    { value: "", label: "Select event…" },
    ...(eventsQuery.data?.items ?? []).map((e) => ({
      value: String(e.id),
      label: e.title,
    })),
  ];

  const ticketTypeOptions: SelectOption[] = [
    { value: "", label: "No specific ticket type" },
    ...(ticketTypesQuery.data?.ticket_types ?? []).map((t) => ({
      value: String(t.id),
      label: `${t.name} — ${fmtMoney(t.price)}`,
    })),
  ];

  return (
    <Modal
      open
      onClose={onClose}
      title={`Map product: ${product.name}`}
      description="Linking a WooCommerce product to an event lets EventOS track orders and issue tickets automatically."
      footer={
        <div className="eos-inline">
          <Button onClick={onClose}>Cancel</Button>
          {product.eos_event_id && (
            <Button
              variant="danger"
              loading={unmapMutation.isPending}
              onClick={() => unmapMutation.mutate()}
            >
              Remove mapping
            </Button>
          )}
          <Button
            variant="primary"
            loading={mapMutation.isPending}
            disabled={!eventId}
            onClick={() => mapMutation.mutate()}
          >
            Save mapping
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
            setTicketTypeId("");
          }}
        />
        {eventId && (
          <Select
            label="Ticket type (optional)"
            value={ticketTypeId}
            options={ticketTypeOptions}
            onChange={(e) => setTicketTypeId(e.target.value)}
          />
        )}
        {product.eos_synced_at && (
          <p className="eos-page__description">Last synced: {fmtDate(product.eos_synced_at)}</p>
        )}
      </Stack>
    </Modal>
  );
}

function ProductDrawer({ product, onClose }: { product: WcProductRecord; onClose: () => void }) {
  return (
    <Drawer
      open
      onClose={onClose}
      title={product.name}
      description={`SKU: ${product.sku || "—"} · #${product.id}`}
      footer={
        <LinkButton
          href={`/wp-admin/post.php?post=${product.id}&action=edit`}
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
            <p className="eos-field__label">Status</p>
            <Badge tone={wcProductStatusTone(product.status)}>{product.status}</Badge>
          </div>
          <div>
            <p className="eos-field__label">Price</p>
            <strong>{fmtMoney(product.price)}</strong>
          </div>
          <div>
            <p className="eos-field__label">Stock</p>
            <span>
              {product.manage_stock
                ? product.stock_quantity != null
                  ? product.stock_quantity.toLocaleString()
                  : "Managed"
                : "Unmanaged"}
            </span>
          </div>
          <div>
            <p className="eos-field__label">Stock status</p>
            <Badge
              tone={
                product.stock_status === "instock"
                  ? "success"
                  : product.stock_status === "outofstock"
                    ? "danger"
                    : "warning"
              }
            >
              {product.stock_status}
            </Badge>
          </div>
        </Grid>

        <Card title="EventOS mapping">
          <Stack>
            <Grid minColumnWidth={140}>
              <div>
                <p className="eos-field__label">Linked event ID</p>
                <span>{product.eos_event_id ?? "—"}</span>
              </div>
              <div>
                <p className="eos-field__label">Ticket type ID</p>
                <span>{product.eos_ticket_type_id ?? "—"}</span>
              </div>
              <div>
                <p className="eos-field__label">Last synced</p>
                <span>{fmtDate(product.eos_synced_at)}</span>
              </div>
            </Grid>
          </Stack>
        </Card>

        {product.categories.length > 0 && (
          <Card title="Categories">
            <div className="eos-inline" style={{ flexWrap: "wrap" }}>
              {product.categories.map((c) => (
                <Badge key={c.id} tone="neutral">
                  {c.name}
                </Badge>
              ))}
            </div>
          </Card>
        )}

        {product.description && (
          <Card title="Description">
            <p className="eos-page__description">{product.description}</p>
          </Card>
        )}
      </Stack>
    </Drawer>
  );
}

export function ProductsView() {
  const toast = useToast();
  const qc = useQueryClient();
  const [filterValues, setFilterValues] = useState<Record<string, string>>({});
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [drawer, setDrawer] = useState<WcProductRecord | null>(null);
  const [mappingTarget, setMappingTarget] = useState<WcProductRecord | null>(null);

  const PER_PAGE = 20;
  const status = filterValues["status"] ?? "";
  const mapped = filterValues["mapped"] ?? "";

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ["wc", "products", { search, status, mapped, page }],
    queryFn: () =>
      wcApi.products({
        search,
        status,
        synced: mapped === "true" ? true : mapped === "false" ? false : undefined,
        page,
        per_page: PER_PAGE,
      }),
    placeholderData: (prev) => prev,
  });

  const syncMutation = useMutation({
    mutationFn: () => wcApi.syncProducts(),
    onSuccess: () => {
      toast.success("Product sync queued.", "Sync started");
      void qc.invalidateQueries({ queryKey: ["wc", "products"] });
    },
    onError: (err: unknown) => toast.error(wcErrorMessage(err), "Sync failed"),
  });

  const products = data?.items ?? [];
  const total = data?.total ?? 0;
  const totalPages = data?.totalPages ?? 1;
  const mapped_count = products.filter((p) => p.eos_event_id != null).length;

  const columns: DataTableColumn<WcProductRecord>[] = [
    {
      key: "name",
      header: "Product",
      cell: (row) => (
        <Stack>
          <button className="eos-btn eos-btn--link" onClick={() => setDrawer(row)}>
            <strong>{row.name}</strong>
          </button>
          <span className="eos-page__description">
            SKU: {row.sku || "—"} · #{row.id}
          </span>
        </Stack>
      ),
    },
    {
      key: "status",
      header: "Status",
      cell: (row) => (
        <Badge tone={wcProductStatusTone(row.status as WcProductStatus)}>{row.status}</Badge>
      ),
    },
    {
      key: "price",
      header: "Price",
      cell: (row) => fmtMoney(row.price),
    },
    {
      key: "stock_status",
      header: "Stock",
      cell: (row) => (
        <Stack>
          <Badge
            tone={
              row.stock_status === "instock"
                ? "success"
                : row.stock_status === "outofstock"
                  ? "danger"
                  : "warning"
            }
          >
            {row.stock_status}
          </Badge>
          {row.manage_stock && row.stock_quantity != null && (
            <span className="eos-page__description">
              {row.stock_quantity.toLocaleString()} units
            </span>
          )}
        </Stack>
      ),
    },
    {
      key: "eos_event_id",
      header: "Event mapping",
      cell: (row) =>
        row.eos_event_id ? (
          <Badge tone="success">Event #{row.eos_event_id}</Badge>
        ) : (
          <Badge tone="neutral">Unmapped</Badge>
        ),
    },
    {
      key: "eos_ticket_type_id",
      header: "Ticket tier",
      cell: (row) =>
        row.eos_ticket_type_id ? (
          <span>Type #{row.eos_ticket_type_id}</span>
        ) : (
          <span className="eos-page__description">—</span>
        ),
    },
    {
      key: "eos_synced_at",
      header: "Last synced",
      cell: (row) => <span className="eos-page__description">{fmtDate(row.eos_synced_at)}</span>,
    },
    {
      key: "id",
      header: "",
      cell: (row) => (
        <div className="eos-inline">
          <Button size="sm" onClick={() => setMappingTarget(row)}>
            {row.eos_event_id ? "Remap" : "Map"}
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
      title="Products"
      description="Every WooCommerce product, mapped to EventOS events and ticket tiers where relevant."
    >
      <Stack>
        <Grid minColumnWidth={160}>
          <StatCard label="Total products" value={total.toLocaleString()} />
          <StatCard
            label="Mapped to events"
            value={mapped_count.toLocaleString()}
            hint="in current page"
          />
          <StatCard
            label="Unmapped"
            value={(products.length - mapped_count).toLocaleString()}
            hint="in current page"
          />
        </Grid>

        <Alert tone="info" title="WooCommerce products">
          Products are managed in WooCommerce and always read live here, so this list is never out
          of date. EventOS maps products to events and ticket tiers for order tracking and guest
          management. "Sync products" just refreshes each product's last-synced timestamp.
        </Alert>

        <Card
          title={`Products${total > 0 ? ` (${total.toLocaleString()})` : ""}`}
          actions={
            <Button
              variant="primary"
              loading={syncMutation.isPending}
              onClick={() => syncMutation.mutate()}
            >
              Sync products
            </Button>
          }
        >
          <Stack>
            <FilterBar
              search={{
                value: search,
                onChange: setSearch,
                placeholder: "Search by name or SKU…",
              }}
              filters={[STATUS_FILTER, MAPPED_FILTER]}
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
              <LoadingState label="Loading products…" />
            ) : error ? (
              <Alert
                tone="danger"
                title="Could not load products"
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
                  caption="WooCommerce products"
                  columns={columns}
                  rows={products}
                  getRowId={(row) => String(row.id)}
                  emptyTitle="No products found"
                  emptyDescription={
                    search || status
                      ? "Try adjusting your filters."
                      : "Products created in WooCommerce will appear here automatically."
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

        {drawer && <ProductDrawer product={drawer} onClose={() => setDrawer(null)} />}
        {mappingTarget && (
          <MappingModal product={mappingTarget} onClose={() => setMappingTarget(null)} />
        )}
      </Stack>
    </PageLayout>
  );
}
