-- Optional but recommended for the Binance Supabase Gateway cache.
-- Safe to run if the table already exists.
CREATE TABLE IF NOT EXISTS market_data_cache (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  provider text NOT NULL,
  cache_key text NOT NULL,
  payload jsonb NOT NULL,
  fetched_at timestamptz NOT NULL DEFAULT now(),
  ttl_seconds integer NOT NULL DEFAULT 60,
  UNIQUE (provider, cache_key)
);

ALTER TABLE market_data_cache ENABLE ROW LEVEL SECURITY;
