import type { ReactNode } from "react";
import { Button } from "./buttons";
import { EmptyState, LoadingState } from "./feedback";
import { SearchInput, Select, type SelectOption } from "./forms";
import { cx, type StyleProps } from "./utils";

export type SortDirection = "asc" | "desc";

export interface DataTableColumn<T> {
  key: string;
  header: ReactNode;
  cell: (row: T) => ReactNode;
  sortable?: boolean;
  align?: "left" | "right" | "center";
  width?: string;
}

export interface DataTableProps<T> extends StyleProps {
  columns: DataTableColumn<T>[];
  rows: T[];
  getRowId: (row: T) => string;
  caption: string;
  loading?: boolean;
  emptyTitle?: string;
  emptyDescription?: string;
  emptyAction?: ReactNode;
  sort?: { key: string; direction: SortDirection };
  onSortChange?: (sort: { key: string; direction: SortDirection }) => void;
  footer?: ReactNode;
}

/** Data table with optional server-driven sorting. */
export function DataTable<T>({
  columns,
  rows,
  getRowId,
  caption,
  loading = false,
  emptyTitle = "No records",
  emptyDescription,
  emptyAction,
  sort,
  onSortChange,
  footer,
  className,
  style,
}: DataTableProps<T>) {
  if (loading) return <LoadingState label={`Loading ${caption.toLowerCase()}…`} />;
  if (!rows.length) return <EmptyState title={emptyTitle} description={emptyDescription} action={emptyAction} />;

  const toggleSort = (key: string) => {
    if (!onSortChange) return;
    const direction: SortDirection = sort?.key === key && sort.direction === "asc" ? "desc" : "asc";
    onSortChange({ key, direction });
  };

  return (
    <div className={cx("eos-table-wrap", className)} style={style}>
      <table className="eos-table">
        <caption className="eos-visually-hidden">{caption}</caption>
        <thead>
          <tr>
            {columns.map((column) => {
              const isSorted = sort?.key === column.key;
              return (
                <th
                  key={column.key}
                  scope="col"
                  style={{ width: column.width, textAlign: column.align }}
                  aria-sort={isSorted ? (sort?.direction === "asc" ? "ascending" : "descending") : undefined}
                >
                  {column.sortable && onSortChange ? (
                    <button type="button" className="eos-table__sort" onClick={() => toggleSort(column.key)}>
                      {column.header}
                      <span aria-hidden="true">{isSorted ? (sort?.direction === "asc" ? "▲" : "▼") : "↕"}</span>
                    </button>
                  ) : (
                    column.header
                  )}
                </th>
              );
            })}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={getRowId(row)}>
              {columns.map((column) => (
                <td key={column.key} style={{ textAlign: column.align }} data-label={String(column.header)}>
                  {column.cell(row)}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
      {footer}
    </div>
  );
}

/** Client-side sort helper for tables that hold their full dataset in memory. */
export function sortRows<T>(rows: T[], key: keyof T & string, direction: SortDirection): T[] {
  return [...rows].sort((a, b) => {
    const left = a[key];
    const right = b[key];
    if (left === right) return 0;
    const result = left! > right! ? 1 : -1;
    return direction === "asc" ? result : -result;
  });
}

export interface PaginationProps extends StyleProps {
  page: number;
  totalPages: number;
  total?: number;
  perPage?: number;
  onPageChange: (page: number) => void;
  onPerPageChange?: (perPage: number) => void;
  perPageOptions?: number[];
}

/** Pagination bar matching the REST framework's pagination envelope. */
export function Pagination({
  page,
  totalPages,
  total,
  perPage,
  onPageChange,
  onPerPageChange,
  perPageOptions = [10, 20, 50, 100],
  className,
  style,
}: PaginationProps) {
  return (
    <nav className={cx("eos-pagination", className)} style={style} aria-label="Pagination">
      <span className="eos-pagination__summary">
        Page {page} of {Math.max(totalPages, 1)}
        {typeof total === "number" ? ` · ${total} records` : ""}
      </span>
      <div className="eos-inline">
        {onPerPageChange && perPage ? (
          <Select
            aria-label="Records per page"
            value={String(perPage)}
            options={perPageOptions.map<SelectOption>((option) => ({ value: String(option), label: `${option} / page` }))}
            onChange={(event) => onPerPageChange(Number(event.target.value))}
          />
        ) : null}
        <Button size="sm" disabled={page <= 1} onClick={() => onPageChange(page - 1)}>
          Previous
        </Button>
        <Button size="sm" disabled={page >= totalPages} onClick={() => onPageChange(page + 1)}>
          Next
        </Button>
      </div>
    </nav>
  );
}

export interface FilterDefinition {
  key: string;
  label: string;
  options: SelectOption[];
  placeholder?: string;
}

export interface FilterBarProps extends StyleProps {
  search?: { value: string; onChange: (value: string) => void; placeholder?: string };
  filters?: FilterDefinition[];
  values?: Record<string, string>;
  onFilterChange?: (key: string, value: string) => void;
  onReset?: () => void;
  actions?: ReactNode;
}

/** Search + dropdown filters used above tables and lists. */
export function FilterBar({
  search,
  filters = [],
  values = {},
  onFilterChange,
  onReset,
  actions,
  className,
  style,
}: FilterBarProps) {
  const hasActiveFilters = Object.values(values).some(Boolean) || Boolean(search?.value);

  return (
    <div className={cx("eos-filters", className)} style={style} role="search">
      {search ? (
        <div className="eos-filters__search">
          <SearchInput value={search.value} onChange={search.onChange} placeholder={search.placeholder} />
        </div>
      ) : null}
      {filters.map((filter) => (
        <Select
          key={filter.key}
          aria-label={filter.label}
          placeholder={filter.placeholder ?? filter.label}
          value={values[filter.key] ?? ""}
          options={filter.options}
          onChange={(event) => onFilterChange?.(filter.key, event.target.value)}
        />
      ))}
      {onReset && hasActiveFilters ? (
        <Button variant="ghost" size="sm" onClick={onReset}>
          Reset
        </Button>
      ) : null}
      {actions}
    </div>
  );
}
