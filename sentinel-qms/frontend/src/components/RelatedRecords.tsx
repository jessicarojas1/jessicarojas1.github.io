import { DataList, type DataPoint } from './detail';

/**
 * Standard "Related Records" card: a titled card wrapping a {@link DataList} of
 * linked entities and lifecycle dates. Each item's value is free-form (a link,
 * a "Create" button, a date, or plain text) so modules keep their own linkage
 * logic while sharing one consistent presentation.
 */
export function RelatedRecords({
  items,
  title = 'Related Records',
}: {
  items: DataPoint[];
  title?: string;
}) {
  return (
    <div className="card">
      <div className="card__header">
        <div className="card__title">{title}</div>
      </div>
      <div className="card__body">
        <DataList items={items} />
      </div>
    </div>
  );
}
