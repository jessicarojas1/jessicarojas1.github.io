import { useState } from 'react';
import { Share2 } from 'lucide-react';
import { useCreateShare } from '@/hooks';
import { useUserLookup } from '@/hooks/useUserLookup';
import { useAuth } from '@/lib/auth';
import { useToast } from '@/lib/toast';
import { getErrorMessage } from '@/lib/api';

interface ShareButtonProps {
  entityType: string;
  entityId: string | number;
  label: string;
}

/** Share this record (read-only, in-app) with another authenticated user. */
export function ShareButton({ entityType, entityId, label }: ShareButtonProps) {
  const [open, setOpen] = useState(false);
  const [userId, setUserId] = useState('');
  const [note, setNote] = useState('');
  const { map } = useUserLookup();
  const { user } = useAuth();
  const create = useCreateShare();
  const { notify } = useToast();

  const users = Object.entries(map)
    .filter(([id, u]) => u.is_active && Number(id) !== Number(user?.id))
    .sort((a, b) => a[1].full_name.localeCompare(b[1].full_name));

  const submit = () => {
    if (!userId) return;
    create.mutate(
      { entity_type: entityType, entity_id: String(entityId), label, shared_with_user_id: Number(userId), note: note.trim() || null },
      {
        onSuccess: () => {
          notify('Shared', 'success');
          setOpen(false);
          setUserId('');
          setNote('');
        },
        onError: (err) => notify(getErrorMessage(err), 'danger'),
      },
    );
  };

  return (
    <div className="saved-views">
      <button type="button" className="btn btn-sm no-print" onClick={() => setOpen((o) => !o)} aria-expanded={open}>
        <Share2 size={14} /> Share
      </button>
      {open && (
        <div className="saved-views__menu" role="menu" style={{ minWidth: 260, padding: 10 }}>
          <label className="field-label" htmlFor="share-user">Share with</label>
          <select id="share-user" className="input" value={userId} onChange={(e) => setUserId(e.target.value)}>
            <option value="">Select a user…</option>
            {users.map(([id, u]) => (
              <option key={id} value={id}>{u.full_name}</option>
            ))}
          </select>
          <label className="field-label" htmlFor="share-note" style={{ marginTop: 8 }}>Note (optional)</label>
          <input id="share-note" className="input" value={note} onChange={(e) => setNote(e.target.value)} placeholder="e.g. Please review" />
          <button type="button" className="btn btn-primary btn-sm" style={{ marginTop: 10, width: '100%' }} onClick={submit} disabled={create.isPending || !userId}>
            Share record
          </button>
        </div>
      )}
    </div>
  );
}
