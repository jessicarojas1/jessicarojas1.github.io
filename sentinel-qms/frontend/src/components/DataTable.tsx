import { useState, type ReactNode } from 'react';
import {
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  ChevronsUpDown,
  ChevronUp,
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
}: DataTableProps<T>) {
  const [filtersOpen, setFiltersOpen] = useState(false);

  const totalPages = total != null ? Math.max(1, Math.ceil(total / pageSize)) : 1;
  const showToolbar = Boolean(onSearchChange) || Boolean(filters) || Boolean(toolbarActions);

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
