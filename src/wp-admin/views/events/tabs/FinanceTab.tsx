import { useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Alert,
  Badge,
  Button,
  Card,
  ConfirmDialog,
  DataTable,
  Grid,
  Input,
  LoadingState,
  Modal,
  Select,
  Stack,
  StatCard,
  Textarea,
  useToast,
  type DataTableColumn,
  type SelectOption,
} from "../../../ui";
import { financeApi, type ExpenseRecord, type FinancePnlPayload } from "../../../api";
import { errorMessage, formatDate } from "../shared";

interface Props {
  eventId: number;
}

function fmt(amount: number, cur: string) {
  return new Intl.NumberFormat(undefined, {
    style: "currency",
    currency: cur || "USD",
    maximumFractionDigits: 2,
  }).format(amount);
}

function categoryLabel(category: string): string {
  return category
    .split("_")
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(" ");
}

interface ExpenseFormValues {
  description: string;
  amount: string;
  category: string;
  expense_date: string;
  payee: string;
  reference: string;
  notes: string;
}

const EMPTY_FORM: ExpenseFormValues = {
  description: "",
  amount: "",
  category: "other",
  expense_date: "",
  payee: "",
  reference: "",
  notes: "",
};

function ExpenseFormModal({
  eventId,
  expense,
  categories,
  onClose,
}: {
  eventId: number;
  expense: ExpenseRecord | null;
  categories: string[];
  onClose: () => void;
}) {
  const toast = useToast();
  const qc = useQueryClient();
  const [form, setForm] = useState<ExpenseFormValues>(
    expense
      ? {
          description: expense.description,
          amount: String(expense.amount),
          category: expense.category,
          expense_date: expense.expense_date ?? "",
          payee: expense.payee,
          reference: expense.reference,
          notes: expense.notes,
        }
      : EMPTY_FORM,
  );

  const set = <K extends keyof ExpenseFormValues>(key: K, value: ExpenseFormValues[K]) =>
    setForm((current) => ({ ...current, [key]: value }));

  const categoryOptions: SelectOption[] = useMemo(() => {
    const known = new Set(categories);
    const options = categories.map((c) => ({ value: c, label: categoryLabel(c) }));
    if (form.category && !known.has(form.category)) {
      options.unshift({ value: form.category, label: categoryLabel(form.category) });
    }
    return options;
  }, [categories, form.category]);

  const invalidate = () => {
    void qc.invalidateQueries({ queryKey: ["eventos", "finance", eventId] });
  };

  const save = useMutation({
    mutationFn: () => {
      const payload = {
        description: form.description,
        amount: Number(form.amount),
        category: form.category,
        expense_date: form.expense_date || undefined,
        payee: form.payee,
        reference: form.reference,
        notes: form.notes,
      };
      return expense
        ? financeApi.updateExpense(eventId, expense.id, payload)
        : financeApi.createExpense(eventId, payload);
    },
    onSuccess: () => {
      toast.success(expense ? "Expense updated." : "Expense recorded.", "Saved");
      invalidate();
      onClose();
    },
    onError: (err: unknown) => toast.error(errorMessage(err), "Save failed"),
  });

  const amountValid = Number(form.amount) > 0;

  return (
    <Modal
      open
      onClose={onClose}
      title={expense ? "Edit expense" : "Record expense"}
      footer={
        <div className="eos-inline">
          <Button onClick={onClose}>Cancel</Button>
          <Button
            variant="primary"
            loading={save.isPending}
            disabled={!form.description || !amountValid}
            onClick={() => save.mutate()}
          >
            {expense ? "Save changes" : "Record expense"}
          </Button>
        </div>
      }
    >
      <Stack>
        <Input
          label="Description"
          required
          value={form.description}
          onChange={(e) => set("description", e.target.value)}
          placeholder="e.g. Venue hire deposit"
        />
        <Grid minColumnWidth={160}>
          <Input
            label="Amount"
            type="number"
            min="0"
            step="0.01"
            required
            value={form.amount}
            onChange={(e) => set("amount", e.target.value)}
          />
          <Select
            label="Category"
            options={categoryOptions}
            value={form.category}
            onChange={(e) => set("category", e.target.value)}
          />
        </Grid>
        <Grid minColumnWidth={160}>
          <Input
            label="Date"
            type="date"
            value={form.expense_date}
            onChange={(e) => set("expense_date", e.target.value)}
          />
          <Input
            label="Payee / supplier"
            value={form.payee}
            onChange={(e) => set("payee", e.target.value)}
          />
        </Grid>
        <Input
          label="Reference"
          hint="Invoice number, PO number, etc."
          value={form.reference}
          onChange={(e) => set("reference", e.target.value)}
        />
        <Textarea
          label="Notes"
          rows={3}
          value={form.notes}
          onChange={(e) => set("notes", e.target.value)}
        />
      </Stack>
    </Modal>
  );
}

function PnlSummary({ pnl }: { pnl: FinancePnlPayload }) {
  const { revenue, adjustments, fees, expenses, result, currency } = pnl;
  const profitable = result.net_profit >= 0;

  return (
    <Stack>
      <Grid minColumnWidth={170}>
        <StatCard label="Gross revenue" value={fmt(result.gross_revenue, currency)} />
        <StatCard label="Net revenue" value={fmt(result.net_revenue, currency)} />
        <StatCard label="Total fees" value={fmt(result.total_fees, currency)} />
        <StatCard label="Total expenses" value={fmt(result.total_expenses, currency)} />
        <StatCard
          label={profitable ? "Net profit" : "Net loss"}
          value={fmt(result.net_profit, currency)}
          trend={{
            direction: profitable ? "up" : "down",
            label: profitable ? "Profitable" : "Loss",
          }}
        />
        <StatCard
          label="Profit margin"
          value={result.profit_margin != null ? `${result.profit_margin.toFixed(1)}%` : "—"}
          hint={result.profit_margin == null ? "No net revenue yet" : undefined}
        />
      </Grid>

      <Grid minColumnWidth={260}>
        <Card title="Revenue">
          <Stack>
            <div className="eos-inline" style={{ justifyContent: "space-between" }}>
              <span>Ticket revenue</span>
              <strong>{fmt(revenue.ticket_revenue, currency)}</strong>
            </div>
            <div className="eos-inline" style={{ justifyContent: "space-between" }}>
              <span>Other revenue</span>
              <span>{fmt(revenue.other_revenue, currency)}</span>
            </div>
            <div className="eos-inline" style={{ justifyContent: "space-between" }}>
              <strong>Total revenue</strong>
              <strong>{fmt(revenue.total_revenue, currency)}</strong>
            </div>
          </Stack>
        </Card>

        <Card title="Adjustments">
          <Stack>
            <div className="eos-inline" style={{ justifyContent: "space-between" }}>
              <span>Discounts</span>
              <span>−{fmt(adjustments.discounts, currency)}</span>
            </div>
            <div className="eos-inline" style={{ justifyContent: "space-between" }}>
              <span>Refunds</span>
              <span>−{fmt(adjustments.refunds, currency)}</span>
            </div>
            <div className="eos-inline" style={{ justifyContent: "space-between" }}>
              <span>Other adjustments</span>
              <span>−{fmt(adjustments.other_adjustments, currency)}</span>
            </div>
            <div className="eos-inline" style={{ justifyContent: "space-between" }}>
              <strong>Total adjustments</strong>
              <strong>−{fmt(adjustments.total_adjustments, currency)}</strong>
            </div>
          </Stack>
        </Card>

        <Card
          title="Fees"
          actions={
            <Badge tone={fees.fee_status === "recorded" ? "neutral" : "warning"}>
              {fees.fee_status === "recorded" ? "Recorded" : "No fee data"}
            </Badge>
          }
        >
          <Stack>
            <div className="eos-inline" style={{ justifyContent: "space-between" }}>
              <span>Payment / processing fees</span>
              <span>−{fmt(fees.payment_fees, currency)}</span>
            </div>
            <div className="eos-inline" style={{ justifyContent: "space-between" }}>
              <span>Platform / ticketing fees</span>
              <span>−{fmt(fees.platform_fees, currency)}</span>
            </div>
            <div className="eos-inline" style={{ justifyContent: "space-between" }}>
              <span>Other fees</span>
              <span>−{fmt(fees.other_fees, currency)}</span>
            </div>
            <div className="eos-inline" style={{ justifyContent: "space-between" }}>
              <strong>Total fees</strong>
              <strong>−{fmt(fees.total_fees, currency)}</strong>
            </div>
            {fees.fee_status === "unknown" && (
              <p className="eos-page__description">
                WooCommerce recorded no payment processing fee line items for this event's orders.
                This is not the same as zero fees being charged — it means this install has no fee
                data to show.
              </p>
            )}
          </Stack>
        </Card>

        <Card title="Expenses">
          <Stack>
            <div className="eos-inline" style={{ justifyContent: "space-between" }}>
              <strong>Total expenses</strong>
              <strong>−{fmt(expenses.total_expenses, currency)}</strong>
            </div>
            {expenses.by_category.length === 0 ? (
              <p className="eos-page__description">No expenses recorded yet.</p>
            ) : (
              expenses.by_category.map((row) => (
                <div
                  key={row.category}
                  className="eos-inline"
                  style={{ justifyContent: "space-between" }}
                >
                  <span>
                    {categoryLabel(row.category)}{" "}
                    <span className="eos-page__description">({row.count})</span>
                  </span>
                  <span>{fmt(row.total, currency)}</span>
                </div>
              ))
            )}
          </Stack>
        </Card>
      </Grid>
    </Stack>
  );
}

export function FinanceTab({ eventId }: Props) {
  const [page, setPage] = useState(1);
  const [formTarget, setFormTarget] = useState<ExpenseRecord | "new" | null>(null);
  const [voidTarget, setVoidTarget] = useState<ExpenseRecord | null>(null);
  const toast = useToast();
  const qc = useQueryClient();

  const pnlQuery = useQuery({
    queryKey: ["eventos", "finance", eventId, "summary"],
    queryFn: () => financeApi.summary(eventId),
    retry: false,
  });

  const expensesQuery = useQuery({
    queryKey: ["eventos", "finance", eventId, "expenses", page],
    queryFn: () => financeApi.expenses(eventId, { page, per_page: 20 }),
    placeholderData: (prev) => prev,
  });

  const categoriesQuery = useQuery({
    queryKey: ["eventos", "finance", "expense-categories"],
    queryFn: () => financeApi.expenseCategories(),
    staleTime: Infinity,
  });

  const voidMutation = useMutation({
    mutationFn: () => financeApi.voidExpense(eventId, voidTarget!.id),
    onSuccess: () => {
      toast.success("Expense voided.", "Voided");
      void qc.invalidateQueries({ queryKey: ["eventos", "finance", eventId] });
      setVoidTarget(null);
    },
    onError: (err: unknown) => toast.error(errorMessage(err), "Void failed"),
  });

  const expenses = expensesQuery.data?.items ?? [];
  const totalPages = expensesQuery.data?.totalPages ?? 1;

  const columns: DataTableColumn<ExpenseRecord>[] = [
    {
      key: "description",
      header: "Description",
      cell: (row) => (
        <Stack>
          <strong>{row.description}</strong>
          {row.payee && <span className="eos-page__description">{row.payee}</span>}
        </Stack>
      ),
    },
    {
      key: "category",
      header: "Category",
      cell: (row) => <Badge tone="neutral">{categoryLabel(row.category)}</Badge>,
    },
    {
      key: "expense_date",
      header: "Date",
      cell: (row) => formatDate(row.expense_date),
    },
    {
      key: "amount",
      header: "Amount",
      cell: (row) => fmt(row.amount, row.currency || pnlQuery.data?.currency || "USD"),
    },
    {
      key: "id",
      header: "",
      cell: (row) => (
        <div className="eos-inline">
          <Button size="sm" onClick={() => setFormTarget(row)}>
            Edit
          </Button>
          <Button size="sm" variant="ghost" onClick={() => setVoidTarget(row)}>
            Void
          </Button>
        </div>
      ),
    },
  ];

  return (
    <Stack>
      <div className="eos-inline" style={{ justifyContent: "flex-end" }}>
        <a
          href={financeApi.exportPnl(eventId, "csv")}
          download
          className="eos-btn eos-btn--secondary eos-btn--md"
        >
          Export P&amp;L CSV
        </a>
        <a
          href={financeApi.exportExpenses(eventId, "csv")}
          download
          className="eos-btn eos-btn--secondary eos-btn--md"
        >
          Export expenses CSV
        </a>
        <Button variant="primary" onClick={() => setFormTarget("new")}>
          Record expense
        </Button>
      </div>

      {pnlQuery.isLoading ? (
        <LoadingState label="Loading P&amp;L…" />
      ) : pnlQuery.error ? (
        <Alert tone="warning" title="P&amp;L unavailable">
          {errorMessage(pnlQuery.error)}
        </Alert>
      ) : pnlQuery.data ? (
        <PnlSummary pnl={pnlQuery.data} />
      ) : null}

      <Card title={`Expenses${expensesQuery.data ? ` (${expensesQuery.data.total})` : ""}`}>
        <Stack>
          {expensesQuery.isLoading ? (
            <LoadingState label="Loading expenses…" />
          ) : expensesQuery.error ? (
            <Alert tone="danger" title="Could not load expenses">
              {errorMessage(expensesQuery.error)}
            </Alert>
          ) : (
            <DataTable
              caption="Event expenses"
              columns={columns}
              rows={expenses}
              getRowId={(row) => String(row.id)}
              emptyTitle="No expenses recorded yet"
              emptyDescription="Costs like venue hire, artist fees and production will show here once recorded."
            />
          )}
          {totalPages > 1 && (
            <div className="eos-inline" style={{ justifyContent: "flex-end" }}>
              <Button size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                Previous
              </Button>
              <span className="eos-page__description">
                Page {page} of {totalPages}
              </span>
              <Button size="sm" disabled={page >= totalPages} onClick={() => setPage((p) => p + 1)}>
                Next
              </Button>
            </div>
          )}
        </Stack>
      </Card>

      {formTarget && (
        <ExpenseFormModal
          eventId={eventId}
          expense={formTarget === "new" ? null : formTarget}
          categories={categoriesQuery.data?.categories ?? []}
          onClose={() => setFormTarget(null)}
        />
      )}

      {voidTarget && (
        <ConfirmDialog
          open
          onCancel={() => setVoidTarget(null)}
          onConfirm={() => voidMutation.mutate()}
          title="Void this expense?"
          description={`"${voidTarget.description}" will be removed from every financial total. This cannot be undone.`}
          confirmLabel="Void expense"
          destructive
          busy={voidMutation.isPending}
        />
      )}
    </Stack>
  );
}
