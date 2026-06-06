import { isValidElement, useState, type ReactNode } from 'react';
import {
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  ChevronsUpDown,
  ChevronUp,
  Download,
  Filter,
  Search,
} from 'lucide-react';
import { EmptyState } from './EmptyState';

export interface Column<T> {
  key: string;
  header: string;
  /** Render the cell. Defaults to String(row[key]). */
  render?: (row: T) => ReactNode;
  sortable?: boolean;
  align?: 'left' | 'right' | 'center';
  width?: string;
  className?: string;
}

export interface DataTableProps<T> {
  columns: Column<T>[];
  rows: T[];
  rowKey: (row: T) => string;
  loading?: boolean;
  error?: string | null;
  onRowClick?: (row: T) => void;
  /** Server-driven sort state. */
  sort?: string;
  order?: 'asc' | 'desc';
  onSortChange?: (sort: string, order: 'asc' | 'desc') => void;
  /** Search box. */
  search?: string;
  onSearchChange?: (value: string) => void;
  searchPlaceholder?: string;
  /** Pagination (server-driven). */
  page?: number;
  pageSize?: number;
  total?: number;
  onPageChange?: (page: number) => void;
  /** Slot for filter controls revealed by the Filters toggle. */
  filters?: ReactNode;
  /** Slot for action buttons in the toolbar. */
  toolbarActions?: ReactNode;
  emptyTitle?: string;
  emptyDescription?: string;
  /**
   * When provided, shows an "Export CSV" button that serializes the currently
   * loaded rows using the column definitions. Value is the filename (without
   * extension); ".csv" is appended automatically.
   */
  exportFilename?: string;
}

/** Coerce a rendered cell value into a plain string for CSV output. */
function cellToText(value: ReactNode): string {
  if (value == null || value === false) return '';
  if (typeof value === 'string') return value;
  if (typeof value === 'number' || typeof value === 'boolean') return String(value);
  if (Array.isArray(value)) return value.map(cellToText).join(' ');
  if (isValidElement(value)) {
    const props = value.props as { children?: ReactNode };
    return cellToText(props.children);
  }
  return '';
}

/** RFC-4180 style escaping: wrap in quotes when needed, double inner quotes. */
function escapeCsv(text: string): string {
  if (/[",\n\r]/.test(text)) {
    return `"${text.replace(/"/g, '""')}"`;
  }
  return text;
}

function downloadCsv<T>(filename: string, columns: Column<T>[], rows: T[]) {
  const header = columns.map((c) => escapeCsv(c.header)).join(',');
  const lines = rows.map((row) =>
    columns
      .map((col) => {
        const raw = col.render
          ? col.render(row)
          : ((row as Record<string, unknown>)[col.key] as ReactNode);
        return escapeCsv(cellToText(raw));
      })
      .join(','),
  );
  // Prepend a UTF-8 BOM so Excel detects encoding correctly.
  const csv = '﻿' + [header, ...lines].join('\r\n');
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename.endsWith('.csv') ? filename : `${filename}.csv`;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

export function DataTable<T>({
  columns,
  rows,
  rowKey,
  loading,
  error,
  onRowClick,
  sort,
  order,
  onSortChange,
  search,
  onSearchChange,
  searchPlaceholder = 'Search…',
  page = 1,
  pageSize = 20,
  total,
  onPageChange,
  filters,
  toolbarActions,
  emptyTitle = 'No records found',
  emptyDescription = 'Try adjusting your search or filters.',
  exportFilename,
}: DataTableProps<T>) {
  const [filtersOpen, setFiltersOpen] = useState(false);

  const totalPages = total != null ? Math.max(1, Math.ceil(total / pageSize)) : 1;
  const showToolbar =
    Boolean(onSearchChange) || Boolean(filters) || Boolean(toolbarActions) || Boolean(exportFilename);

  const handleSort = (col: Column<T>) => {
    if (!col.sortable || !onSortChange) return;
    if (sort === col.key) {
      onSortChange(col.key, order === 'asc' ? 'desc' : 'asc');
    } else {
      onSortChange(col.key, 'asc');
    }
  };

  return (
    <div className="card">
      {showToolbar && (
        <>
          <div className="table-toolbar">
            {onSearchChange && (
              <div className="search-box">
                <Search size={15} />
                <input
                  type="search"
                  value={search ?? ''}
                  onChange={(e) => onSearchChange(e.target.value)}
                  placeholder={searchPlaceholder}
                  aria-label="Search records"
                />
              </div>
            )}
            <div className="row" style={{ marginLeft: 'auto', gap: 8 }}>
              {filters && (
                <button
                  type="button"
                  className="btn btn-sm"
                  onClick={() => setFiltersOpen((o) => !o)}
                  aria-expanded={filtersOpen}
                >
                  <Filter size={14} /> Filters
                </button>
              )}
              {exportFilename && (
                <button
                  type="button"
                  className="btn btn-sm"
                  onClick={() => downloadCsv(exportFilename, columns, rows)}
                  disabled={rows.length === 0}
                  title="Export the currently loaded rows to CSV"
                >
                  <Download size={14} /> Export CSV
                </button>
              )}
              {toolbarActions}
            </div>
          </div>
          {filters && <div className={`filter-bar ${filtersOpen ? 'open' : ''}`}>{filters}</div>}
        </>
      )}

      <div className="table-wrap">
        <table className="data-table">
          <thead>
            <tr>
              {columns.map((col) => (
                <th
                  key={col.key}
                  className={`${col.sortable ? 'sortable' : ''} ${
                    col.align === 'right' ? 'num' : ''
                  }`}
                  style={col.width ? { width: col.width } : undefined}
                  onClick={() => handleSort(col)}
                  aria-sort={
                    sort === col.key ? (order === 'asc' ? 'ascending' : 'descending') : undefined
                  }
                >
                  {col.header}
                  {col.sortable && (
                    <span className="sort-ico">
                      {sort === col.key ? (
                        order === 'asc' ? (
                          <ChevronUp size={13} />
                        ) : (
                          <ChevronDown size={13} />
                        )
                      ) : (
                        <ChevronsUpDown size={13} />
                      )}
                    </span>
                  )}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr className="empty-row">
                <td colSpan={columns.length}>
                  <div className="loading-block">
                    <span className="spinner" /> Loading records…
                  </div>
                </td>
              </tr>
            ) : error ? (
              <tr className="empty-row">
                <td colSpan={columns.length}>
                  <div className="empty-state-sm" style={{ color: 'var(--danger)' }}>
                    {error}
                  </div>
                </td>
              </tr>
            ) : rows.length === 0 ? (
              <tr className="empty-row">
                <td colSpan={columns.length}>
                  <EmptyState title={emptyTitle} description={emptyDescription} />
                </td>
              </tr>
            ) : (
              rows.map((row) => (
                <tr
                  key={rowKey(row)}
                  className={onRowClick ? 'clickable' : ''}
                  onClick={onRowClick ? () => onRowClick(row) : undefined}
                >
                  {columns.map((col) => (
                    <td
                      key={col.key}
                      className={`${col.align === 'right' ? 'num' : ''} ${col.className ?? ''}`}
                    >
                      {col.render
                        ? col.render(row)
                        : String((row as Record<string, unknown>)[col.key] ?? '—')}
                    </td>
                  ))}
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {onPageChange && total != null && total > 0 && (
        <div className="pagination">
          <span>
            Showing {(page - 1) * pageSize + 1}–{Math.min(page * pageSize, total)} of {total}
          </span>
          <div className="pagination__controls">
            <button
              type="button"
              className="btn btn-sm"
              disabled={page <= 1}
              onClick={() => onPageChange(page - 1)}
            >
              <ChevronLeft size={14} /> Prev
            </button>
            <span className="nowrap">
              Page {page} of {totalPages}
            </span>
            <button
              type="button"
              className="btn btn-sm"
              disabled={page >= totalPages}
              onClick={() => onPageChange(page + 1)}
            >
              Next <ChevronRight size={14} />
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
