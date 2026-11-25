-- Runs once when the local database is created.
CREATE EXTENSION IF NOT EXISTS pg_trgm;    -- fuzzy search on names and addresses
CREATE EXTENSION IF NOT EXISTS citext;     -- case-insensitive emails
CREATE EXTENSION IF NOT EXISTS unaccent;   -- search without diacritics
CREATE EXTENSION IF NOT EXISTS pgcrypto;   -- gen_random_uuid(), digests
