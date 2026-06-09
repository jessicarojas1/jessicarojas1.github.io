import { useState, type ReactNode } from 'react';
import { Filter } from 'lucide-react';

/**
 * Collapsible filter panel for bespoke list pages that don't use DataTable.
 * Renders a funnel toggle in a toolbar row; the filter controls live in a
 * `.filter-bar` that is hidden until the toggle is clicked — matching the
 * pattern DataTable already uses, so filters are consistently tucked behind
 * the filter icon across the app.
 *
 * Place it directly between a card's `card__header` and its `table-wrap`.
 */
export function FilterBar({
  children,
  active = 0,
}: {
  /** The filter controls (selects, inputs) revealed by the toggle. */
  children: ReactNode;
  /** Count of currently-applied filters, shown as a badge on the toggle. */
  active?: number;
}) {
  const [open, setOpen] = useState(false);
  return (
    <>
      <div className="table-toolbar">
        <button
          type="button"
          className="btn btn-sm"
          style={{ marginLeft: 'auto' }}
          onClick={() => setOpen((o) => !o)}
          aria-expanded={open}
        >
          <Filter size={14} /> Filters{active ? ` (${active})` : ''}
        </button>
      </div>
      <div className={`filter-bar ${open ? 'open' : ''}`}>{children}</div>
    </>
  );
}
