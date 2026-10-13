#!/bin/sh
# Marks the isolated test database. tests/TestCase.php refuses to touch any database
# without this marker, so destructive test resets can never hit the dev database.
set -eu
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
    -c "COMMENT ON DATABASE \"$POSTGRES_DB\" IS 'promtech:test-database'"
