# Binance Gateway for Crypto Signal Scanner 2.9

The WordPress plugin no longer connects to Binance directly.

Flow:

WordPress Cron/Admin -> Supabase Edge Function `market-data` -> data-api.binance.vision

## Deploy

1. In your Supabase project, create/deploy an Edge Function named `market-data`.
2. Use `functions/market-data/index.ts` as the function source.
3. Apply `migrations/20260902_market_data_cache.sql` if `market_data_cache` does not already exist.
4. Set the Supabase secret `CSS_BINANCE_GATEWAY_TOKEN` to a long random value.
5. The function URL will be:
   `https://YOUR_PROJECT_REF.supabase.co/functions/v1/market-data`
6. In WordPress Settings -> Crypto Signal Scanner -> Data Provider:
   - Provider: Binance Spot
   - Supabase Binance Gateway URL: the function URL above
   - Supabase Gateway Token: the same value as `CSS_BINANCE_GATEWAY_TOKEN`
   - Quote Asset: USDT

If the token secret is left unset in Supabase, the function accepts requests without the custom token. For a production site, set it.
