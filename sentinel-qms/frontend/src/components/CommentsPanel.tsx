import { useMemo, useState } from 'react';
import { MessageSquare, Trash2 } from 'lucide-react';
import { useComments, useAddComment, useDeleteComment } from '@/hooks/useComments';
import { useUserLookup } from '@/hooks/useUserLookup';
import { useAuth } from '@/lib/auth';
import { useToast } from '@/lib/toast';
import { getErrorMessage } from '@/lib/api';
import { UserName } from './UserName';

interface Props {
  entityType: string;
  entityId?: string | number | null;
  canEditPage?: string;
}

function fmt(ts: string | null): string {
  if (!ts) return '';
  const d = new Date(ts);
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleString();
}

export function CommentsPanel({ entityType, entityId }: Props) {
  const eid = entityId != null ? String(entityId) : '';
  const { data, isLoading } = useComments(entityType, eid);
  const add = useAddComment(entityType, eid);
  const del = useDeleteComment(entityType, eid);
  const { map } = useUserLookup();
  const { user } = useAuth();
  const { notify } = useToast();

  const [body, setBody] = useState('');
  const [mentions, setMentions] = useState<number[]>([]);

  const userOptions = useMemo(
    () => Object.values(map).sort((a, b) => a.full_name.localeCompare(b.full_name)),
    [map],
  );

  const submit = async () => {
    if (!body.trim()) return;
    try {
      await add.mutateAsync({ body: body.trim(), mentions });
      setBody('');
      setMentions([]);
    } catch (err) {
      notify(getErrorMessage(err), 'danger');
    }
  };

  const remove = async (id: number) => {
    try {
      await del.mutateAsync(id);
    } catch (err) {
      notify(getErrorMessage(err), 'danger');
    }
  };

  return (
    <div className="comments">
      {isLoading ? (
        <div className="loading-block"><span className="spinner" /></div>
      ) : !data || data.length === 0 ? (
        <div className="empty-state-sm">No comments yet. Start the conversation.</div>
      ) : (
        <ul className="comment-list">
          {data.map((c) => (
            <li key={c.id} className="comment">
              <div className="comment__head">
                <span className="comment__author"><UserName id={c.author_id} /></span>
                <span className="comment__time">{fmt(c.created_at)}</span>
                {user && String(c.author_id) === String(user.id) && (
                  <button
                    type="button"
                    className="comment__delete"
                    title="Delete"
                    onClick={() => remove(c.id)}
                  >
                    <Trash2 size={13} />
                  </button>
                )}
              </div>
              <div className="comment__body">{c.body}</div>
            </li>
          ))}
        </ul>
      )}

      <div className="comment-composer">
        <textarea
          className="input"
          rows={2}
          placeholder="Add a comment…"
          value={body}
          onChange={(e) => setBody(e.target.value)}
        />
        <div className="comment-composer__row">
          <label className="comment-mention">
            <span className="muted text-sm">Notify:</span>
            <select
              value=""
              onChange={(e) => {
                const id = Number(e.target.value);
                if (id && !mentions.includes(id)) setMentions((m) => [...m, id]);
              }}
            >
              <option value="">+ mention…</option>
              {userOptions.map((u) => (
                <option key={u.id} value={u.id}>{u.full_name}</option>
              ))}
            </select>
          </label>
          <div className="comment-mention__chips">
            {mentions.map((id) => (
              <span key={id} className="doc-chip">
                @{map[id]?.full_name ?? `User #${id}`}
                <button type="button" onClick={() => setMentions((m) => m.filter((x) => x !== id))}>×</button>
              </span>
            ))}
          </div>
          <button
            type="button"
            className="btn btn-primary btn-sm"
            disabled={!body.trim() || add.isPending}
            onClick={submit}
          >
            Comment
          </button>
        </div>
      </div>
    </div>
  );
}

export function CommentsPanelCard({ entityType, entityId, canEditPage }: Props) {
  return (
    <div className="card">
      <div className="card__header">
        <div className="card__title">
          <MessageSquare size={15} style={{ verticalAlign: '-2px', marginRight: 6 }} />
          Comments
        </div>
      </div>
      <div className="card__body">
        <CommentsPanel entityType={entityType} entityId={entityId} canEditPage={canEditPage} />
      </div>
    </div>
  );
}
