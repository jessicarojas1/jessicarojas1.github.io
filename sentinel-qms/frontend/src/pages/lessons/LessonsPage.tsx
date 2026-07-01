import { useState } from 'react';
import { useCreateLesson, useLessons, useUpdateLesson } from '@/hooks';
import { usePagePerms } from '@/lib/permissions';
import { useToast } from '@/lib/toast';
import { getErrorMessage } from '@/lib/api';
import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/EmptyState';
import { FilterBar } from '@/components/FilterBar';
import type { LessonCategory, LessonLearned, LessonSource, LessonStatus } from '@/types';

const CATEGORIES: LessonCategory[] = [
  'process',
  'quality',
  'supplier',
  'design',
  'safety',
  'project',
  'other',
];
const SOURCES: LessonSource[] = [
  'ncr',
  'capa',
  'audit',
  'complaint',
  'project',
  'incident',
  'customer',
  'other',
];
const STATUSES: LessonStatus[] = ['draft', 'published', 'archived'];
const label = (s: string) => s.replace(/_/g, ' ');

function StatusCell({ item }: { item: LessonLearned }) {
  const update = useUpdateLesson(item.id);
  const { notify } = useToast();
  return (
    <select
      className="input input-sm"
      value={item.status}
      onChange={(e) =>
        update.mutate(
          { status: e.target.value as LessonStatus },
          { onError: (err) => notify(getErrorMessage(err), 'danger') },
        )
      }
      aria-label={`Status for ${item.lesson_number}`}
    >
      {STATUSES.map((s) => (
        <option key={s} value={s}>
          {label(s)}
        </option>
      ))}
    </select>
  );
}

export default function LessonsPage() {
  const { data, isLoading, error } = useLessons();
  const create = useCreateLesson();
  const { canEdit } = usePagePerms();
  const { notify } = useToast();
  const writable = canEdit('lessons_learned');

  const [title, setTitle] = useState('');
  const [category, setCategory] = useState<LessonCategory>('process');
  const [source, setSource] = useState<LessonSource>('other');
  const [sourceRef, setSourceRef] = useState('');
  const [fStatus, setFStatus] = useState('');
  const [fCategory, setFCategory] = useState('');
  const [fDepartment, setFDepartment] = useState('');

  const rows = data ?? [];
  // Distinct department values present in the loaded rows (sorted) for the filter dropdown.
  const departments = Array.from(
    new Set(rows.map((l) => l.department).filter((d): d is string => !!d)),
  ).sort((a, b) => a.localeCompare(b));
  const filtered = rows.filter(
    (l) =>
      (!fStatus || l.status === fStatus) &&
      (!fCategory || l.category === fCategory) &&
      (!fDepartment || l.department === fDepartment),
  );
  const activeFilters = (fStatus ? 1 : 0) + (fCategory ? 1 : 0) + (fDepartment ? 1 : 0);

  const add = (e: React.FormEvent) => {
    e.preventDefault();
    if (!title.trim()) return;
    create.mutate(
      {
        title: title.trim(),
        category,
        source,
        source_ref: sourceRef.trim() === '' ? null : sourceRef.trim(),
      },
      {
        onSuccess: () => {
          setTitle('');
          setSourceRef('');
          notify('Lesson captured', 'success');
        },
        onError: (err) => notify(getErrorMessage(err), 'danger'),
      },
    );
  };

  return (
    <>
      <PageHeader
        title="Lessons Learned"
        subtitle="Reusable lessons captured from NCRs, CAPAs, audits, complaints and projects — institutional memory for continual improvement."
        breadcrumbs={[{ label: 'Operations' }, { label: 'Lessons Learned' }]}
      />

      {writable && (
        <form className="std-new" onSubmit={add}>
          <input
            className="input"
            placeholder="Lesson title"
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            aria-label="Lesson title"
          />
          <select
            className="input"
            value={category}
            onChange={(e) => setCategory(e.target.value as LessonCategory)}
            aria-label="Category"
          >
            {CATEGORIES.map((c) => (
              <option key={c} value={c}>
                {label(c)}
              </option>
            ))}
          </select>
          <select
            className="input"
            value={source}
            onChange={(e) => setSource(e.target.value as LessonSource)}
            aria-label="Source"
          >
            {SOURCES.map((s) => (
              <option key={s} value={s}>
                {label(s)}
              </option>
            ))}
          </select>
          <input
            className="input"
            placeholder="Source ref (e.g. NCR-2026-0007)"
            value={sourceRef}
            onChange={(e) => setSourceRef(e.target.value)}
            aria-label="Source reference"
            style={{ maxWidth: 220 }}
          />
          <button type="submit" className="btn btn-primary btn-sm" disabled={create.isPending}>
            Add
          </button>
        </form>
      )}

      {error ? (
        <div className="card">
          <div className="card__body">
            <EmptyState title="Unable to load" description={getErrorMessage(error)} />
          </div>
        </div>
      ) : isLoading || !data ? (
        <div className="card">
          <div className="card__body">
            <span className="spinner" /> Loading…
          </div>
        </div>
      ) : rows.length === 0 ? (
        <div className="card">
          <div className="card__body">
            <EmptyState
              title="No lessons captured yet"
              description="Capture a lesson from an NCR, CAPA, audit, complaint or project to build institutional memory."
            />
          </div>
        </div>
      ) : (
        <div className="card">
          <FilterBar active={activeFilters}>
            <select
              className="input field"
              value={fStatus}
              onChange={(e) => setFStatus(e.target.value)}
              aria-label="Filter by status"
            >
              <option value="">All statuses</option>
              {STATUSES.map((s) => (
                <option key={s} value={s}>
                  {label(s)}
                </option>
              ))}
            </select>
            <select
              className="input field"
              value={fCategory}
              onChange={(e) => setFCategory(e.target.value)}
              aria-label="Filter by category"
            >
              <option value="">All categories</option>
              {CATEGORIES.map((c) => (
                <option key={c} value={c}>
                  {label(c)}
                </option>
              ))}
            </select>
            <select
              className="input field"
              value={fDepartment}
              onChange={(e) => setFDepartment(e.target.value)}
              aria-label="Filter by department"
            >
              <option value="">All departments</option>
              {departments.map((d) => (
                <option key={d} value={d}>
                  {d}
                </option>
              ))}
            </select>
          </FilterBar>
          <div className="table-wrap">
            <table className="data-table">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Lesson</th>
                  <th>Category</th>
                  <th>Source</th>
                  <th>Reference</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {filtered.length === 0 ? (
                  <tr className="empty-row">
                    <td colSpan={6}>
                      <div className="empty-state-sm">No lessons match the selected filters.</div>
                    </td>
                  </tr>
                ) : (
                  filtered.map((l) => (
                    <tr key={l.id}>
                      <td className="mono">{l.lesson_number}</td>
                      <td>{l.title}</td>
                      <td>{label(l.category)}</td>
                      <td>{label(l.source)}</td>
                      <td className="mono">{l.source_ref ?? '—'}</td>
                      <td>
                        {writable ? (
                          <StatusCell item={l} />
                        ) : (
                          <span className={`con-status con-status--${l.status}`}>
                            {label(l.status)}
                          </span>
                        )}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </>
  );
}
