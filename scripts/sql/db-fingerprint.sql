-- Fingerprint of a database for make db-backup / db-restore-check: one line per table (rows and an order-independent MD5 of
-- the rows), per sequence (last value) and one for the schema (columns, types, defaults, collations, constraints, indexes).
-- Row text depends on session settings, so they are fixed here.
SET TimeZone = 'UTC';
SET DateStyle = 'ISO, YMD';
SET IntervalStyle = 'postgres';
SET extra_float_digits = 1;
SELECT format(
    'SELECT %L, count(*), md5(coalesce(string_agg(h, %L ORDER BY h), %L)) FROM (SELECT md5(t::text) AS h FROM %I.%I t) s',
    'table:' || n.nspname || '.' || c.relname, '', '', n.nspname, c.relname)
FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE c.relkind = 'r' AND n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname NOT LIKE 'pg\_%'
ORDER BY n.nspname, c.relname
\gexec
SELECT 'sequence:' || schemaname || '.' || sequencename, coalesce(last_value, 0), '' FROM pg_sequences
WHERE schemaname NOT IN ('pg_catalog', 'information_schema') ORDER BY 1;
SELECT 'schema', count(*), md5(string_agg(x, E'\n' ORDER BY x)) FROM (
    SELECT format('col %s.%s.%s %s %s %s %s', table_schema, table_name, column_name, data_type, is_nullable, coalesce(column_default, ''), coalesce(collation_name, '')) AS x
    FROM information_schema.columns WHERE table_schema NOT IN ('pg_catalog', 'information_schema') AND table_schema NOT LIKE 'pg\_%'
    UNION ALL
    -- A CHECK expression comes back from dump/restore deparsed differently (equivalent casts), so a CHECK is compared by its
    -- table, name and columns; other constraints by their full definition.
    SELECT format('con %s %s %s %s', c.conrelid::regclass, c.conname, c.contype,
        CASE WHEN c.contype = 'c' THEN coalesce(c.conkey::text, '') ELSE pg_get_constraintdef(c.oid) END)
    FROM pg_constraint c JOIN pg_namespace n ON n.oid = c.connamespace WHERE n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname NOT LIKE 'pg\_%'
    UNION ALL
    SELECT format('idx %s.%s %s', schemaname, tablename, indexdef) FROM pg_indexes WHERE schemaname NOT IN ('pg_catalog', 'information_schema') AND schemaname NOT LIKE 'pg\_%'
) s;
