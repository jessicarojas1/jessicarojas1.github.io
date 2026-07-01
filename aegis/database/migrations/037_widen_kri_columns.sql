-- 037_widen_kri_columns.sql
-- kris.direction was created VARCHAR(10) in migration 003, but its own valid
-- values ('higher_worse'=12, 'lower_worse'=11) and default exceed 10 chars — a
-- fresh install could not store a valid direction. The widening previously lived
-- only as runtime code in index.php; promote it to a proper migration so an
-- install.php-built database is self-consistent. Idempotent (re-widening an
-- already-wide column is a no-op).
ALTER TABLE kris ALTER COLUMN unit      TYPE varchar(50);
ALTER TABLE kris ALTER COLUMN direction TYPE varchar(20);
