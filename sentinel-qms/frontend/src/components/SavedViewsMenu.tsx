import { useState } from 'react';
import { Bookmark, Check, Trash2 } from 'lucide-react';
import {
  useCreateSavedView,
  useDeleteSavedView,
  useSavedViews,
} from '@/hooks';
import { useToast } from '@/lib/toast';
import { getErrorMessage } from '@/lib/api';

interface SavedViewsMenuProps {
  pageKey: string;
  current: Record<string, unknown>;
  onApply: (params: Record<string, unknown>) => void;
}

/** Toolbar control to save / apply / delete per-user list filter presets. */
export function SavedViewsMenu({ pageKey, current, onApply }: SavedViewsMenuProps) {
  const { data } = useSavedViews(pageKey);
  const create = useCreateSavedView();
  const remove = useDeleteSavedView(pageKey);
  const { notify } = useToast();
  const [open, setOpen] = useState(false);

  const save = () => {
    const name = window.prompt('Save current filters as view named:');
    if (!name || !name.trim()) return;
    create.mutate(
      { page_key: pageKey, name: name.trim(), params: current },
      {
        onSuccess: () => notify('View saved', 'success'),
        onError: (err) => notify(getErrorMessage(err), 'danger'),
      },
    );
  };

  const count = data?.length ?? 0;

  return (
    <div className="saved-views">
      <button
        type="button"
        className="btn btn-sm"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
        title="Saved views"
      >
        <Bookmark size={14} /> Views{count ? ` (${count})` : ''}
      </button>
      {open && (
        <div className="saved-views__menu" role="menu">
          {count === 0 ? (
            <div className="saved-views__empty">No saved views yet.</div>
          ) : (
            data!.map((v) => (
              <div key={v.id} className="saved-views__item">
                <button
                  type="button"
                  className="saved-views__apply"
                  onClick={() => {
                    onApply(v.params);
                    setOpen(false);
                  }}
                >
                  <Check size={13} /> {v.name}
                </button>
                <button
                  type="button"
                  className="btn btn-icon btn-ghost"
                  aria-label={`Delete view ${v.name}`}
                  onClick={() => remove.mutate(v.id)}
                >
                  <Trash2 size={13} />
                </button>
              </div>
            ))
          )}
          <button type="button" className="saved-views__save" onClick={save} disabled={create.isPending}>
            + Save current filters…
          </button>
        </div>
      )}
    </div>
  );
}
