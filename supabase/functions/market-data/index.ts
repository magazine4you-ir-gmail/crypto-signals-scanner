import "jsr:@supabase/functions-js/edge-runtime.d.ts";
import { createClient } from "npm:@supabase/supabase-js@2.57.4";

const corsHeaders = {
  "Access-Control-Allow-Origin": "*",
  "Access-Control-Allow-Methods": "GET, OPTIONS",
  "Access-Control-Allow-Headers": "Content-Type, Authorization, Apikey, X-Client-Info",
};

const BINANCE_BASE = "https://data-api.binance.vision";
const SYMBOL_RE = /^[A-Z0-9]{3,20}$/;
const QUOTE_RE = /^[A-Z0-9]{2,10}$/;
const INTERVAL_ALLOWLIST = new Set(["1m", "5m", "15m", "30m", "1h", "4h", "1d", "1w"]);

const TTL = {
  klines: 30,
  ticker: 15,
  exchangeInfo: 86400,
};

function jsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { ...corsHeaders, "Content-Type": "application/json; charset=utf-8" },
  });
}

function validateSymbol(value: string | null): string {
  const symbol = (value || "").toUpperCase().trim();
  if (!SYMBOL_RE.test(symbol)) throw new Error("invalid symbol");
  return symbol;
}

function validateQuote(value: string | null): string {
  const quote = (value || "USDT").toUpperCase().trim();
  if (!QUOTE_RE.test(quote)) throw new Error("invalid quote asset");
  return quote;
}

function validateInterval(value: string | null): string {
  const interval = (value || "1d").trim();
  if (!INTERVAL_ALLOWLIST.has(interval)) throw new Error("invalid interval");
  return interval;
}

const supabase = createClient(
  Deno.env.get("SUPABASE_URL")!,
  Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!,
);

function authorized(req: Request): boolean {
  const expected = (Deno.env.get("CSS_BINANCE_GATEWAY_TOKEN") || "").trim();
  if (!expected) return true;
  const auth = req.headers.get("Authorization") || "";
  return auth === `Bearer ${expected}`;
}

async function readCache(provider: string, cacheKey: string) {
  try {
    const { data } = await supabase
      .from("market_data_cache")
      .select("payload, fetched_at, ttl_seconds")
      .eq("provider", provider)
      .eq("cache_key", cacheKey)
      .maybeSingle();

    if (!data) return null;
    const age = (Date.now() - new Date(data.fetched_at).getTime()) / 1000;
    if (age > Number(data.ttl_seconds || 0)) return null;
    return data.payload;
  } catch {
    return null;
  }
}

async function writeCache(provider: string, cacheKey: string, payload: unknown, ttlSeconds: number) {
  try {
    await supabase.from("market_data_cache").upsert(
      {
        provider,
        cache_key: cacheKey,
        payload,
        fetched_at: new Date().toISOString(),
        ttl_seconds: ttlSeconds,
      },
      { onConflict: "provider,cache_key" },
    );
  } catch {
    // Cache failure must never break market-data delivery.
  }
}

async function fetchWithBackoff(url: string, maxRetries = 3): Promise<Response> {
  for (let attempt = 0; ; attempt++) {
    const res = await fetch(url, {
      headers: {
        Accept: "application/json",
        "User-Agent": "Crypto-Signal-Scanner-Supabase-Gateway/2.9",
      },
    });

    if (res.status !== 429 && res.status !== 418) return res;
    if (attempt >= maxRetries) return res;

    const retryAfter = Number(res.headers.get("Retry-After") || 0);
    const delay = Math.max(retryAfter * 1000, 500 * 2 ** attempt);
    await new Promise((resolve) => setTimeout(resolve, delay));
  }
}

async function cachedFetch(cacheKey: string, ttlSeconds: number, url: string) {
  const cached = await readCache("binance", cacheKey);
  if (cached !== null) return { data: cached, fromCache: true };

  const res = await fetchWithBackoff(url);
  const text = await res.text();
  if (!res.ok) {
    throw new Error(`Binance request failed (${res.status}): ${text.slice(0, 250)}`);
  }

  let data: unknown;
  try {
    data = JSON.parse(text);
  } catch {
    throw new Error("Binance returned invalid JSON");
  }

  await writeCache("binance", cacheKey, data, ttlSeconds);
  return { data, fromCache: false };
}

async function handleKlines(params: URLSearchParams) {
  const symbol = validateSymbol(params.get("symbol"));
  const interval = validateInterval(params.get("interval"));
  const limitRaw = Number(params.get("limit") || "300");
  const limit = Number.isFinite(limitRaw) ? Math.min(Math.max(Math.trunc(limitRaw), 1), 1000) : 300;
  const startRaw = Number(params.get("startTime") || "0");
  const startTime = Number.isFinite(startRaw) && startRaw > 0 ? Math.trunc(startRaw) : null;

  const query = new URLSearchParams({ symbol, interval, limit: String(limit) });
  if (startTime !== null) query.set("startTime", String(startTime));

  const cacheKey = `klines:${symbol}:${interval}:${limit}:${startTime || 0}`;
  const result = await cachedFetch(
    cacheKey,
    TTL.klines,
    `${BINANCE_BASE}/api/v3/klines?${query.toString()}`,
  );

  const candles = Array.isArray(result.data) ? result.data : [];
  return jsonResponse({ provider: "binance", symbol, interval, candles, fromCache: result.fromCache });
}

async function handleTicker(params: URLSearchParams) {
  const symbolsParam = params.get("symbols");
  const symbols = symbolsParam
    ? symbolsParam.split(",").map((s) => validateSymbol(s))
    : [];

  const query = new URLSearchParams();
  if (symbols.length) query.set("symbols", JSON.stringify(symbols));

  const cacheKey = `ticker:${symbols.sort().join(",") || "ALL"}`;
  const result = await cachedFetch(
    cacheKey,
    TTL.ticker,
    `${BINANCE_BASE}/api/v3/ticker/24hr${query.toString() ? `?${query.toString()}` : ""}`,
  );

  const tickers = Array.isArray(result.data) ? result.data : [];
  return jsonResponse({ provider: "binance", tickers, fromCache: result.fromCache });
}

async function getExchangeInfo() {
  const result = await cachedFetch(
    "exchangeInfo",
    TTL.exchangeInfo,
    `${BINANCE_BASE}/api/v3/exchangeInfo`,
  );
  return { data: result.data, fromCache: result.fromCache };
}

async function handleExchangeInfo() {
  const { data, fromCache } = await getExchangeInfo();
  const symbols = Array.isArray((data as any)?.symbols)
    ? (data as any).symbols
        .filter((s: any) => s.status === "TRADING" && s.isSpotTradingAllowed !== false)
        .map((s: any) => ({ symbol: s.symbol, baseAsset: s.baseAsset, quoteAsset: s.quoteAsset }))
    : [];

  return jsonResponse({ provider: "binance", symbols, fromCache });
}

async function handleUniverse(params: URLSearchParams) {
  const quote = validateQuote(params.get("quote"));
  const startRaw = Number(params.get("start") || "1");
  const endRaw = Number(params.get("end") || "100");
  const start = Number.isFinite(startRaw) ? Math.max(1, Math.trunc(startRaw)) : 1;
  const end = Number.isFinite(endRaw) ? Math.max(start, Math.min(5000, Math.trunc(endRaw))) : 100;

  const { data: exchangeData } = await getExchangeInfo();
  const allowed = new Set<string>();
  for (const s of Array.isArray((exchangeData as any)?.symbols) ? (exchangeData as any).symbols : []) {
    if (s.status === "TRADING" && String(s.quoteAsset).toUpperCase() === quote && s.isSpotTradingAllowed !== false) {
      allowed.add(String(s.symbol).toUpperCase());
    }
  }

  const result = await cachedFetch("ticker:ALL", TTL.ticker, `${BINANCE_BASE}/api/v3/ticker/24hr`);
  const ranked: any[] = [];
  for (const ticker of Array.isArray(result.data) ? result.data : []) {
    const symbol = String(ticker.symbol || "").toUpperCase();
    if (!allowed.has(symbol)) continue;
    const price = Number(ticker.lastPrice);
    if (!Number.isFinite(price) || price <= 0) continue;
    ranked.push({
      id: symbol,
      symbol,
      name: `${symbol.slice(0, -quote.length)}/${quote}`,
      market_cap_rank: null,
      current_price: price,
      market_data: {
        total_volume: Number(ticker.quoteVolume) || 0,
        high_24h: Number(ticker.highPrice) || null,
        low_24h: Number(ticker.lowPrice) || null,
        change_pct_24h: Number(ticker.priceChangePercent) || 0,
      },
      quote_asset: quote,
    });
  }

  ranked.sort((a, b) => (b.market_data.total_volume || 0) - (a.market_data.total_volume || 0));
  const coins = ranked.slice(start - 1, end).map((coin, index) => ({
    ...coin,
    market_cap_rank: start + index,
  }));

  return jsonResponse({ provider: "binance", quote, start, end, coins, fromCache: false });
}

async function handleStatus() {
  try {
    const res = await fetchWithBackoff(`${BINANCE_BASE}/api/v3/ping`);
    return jsonResponse({
      binance: { configured: true, reachable: res.ok },
      gateway: { ok: true, upstream: "data-api.binance.vision" },
      checkedAt: new Date().toISOString(),
    });
  } catch {
    return jsonResponse({
      binance: { configured: true, reachable: false },
      gateway: { ok: true, upstream: "data-api.binance.vision" },
      checkedAt: new Date().toISOString(),
    });
  }
}

Deno.serve(async (req: Request) => {
  if (req.method === "OPTIONS") return new Response(null, { status: 204, headers: corsHeaders });
  if (req.method !== "GET") return jsonResponse({ error: "method not allowed" }, 405);
  if (!authorized(req)) return jsonResponse({ error: "unauthorized" }, 401);

  try {
    const url = new URL(req.url);
    const action = url.searchParams.get("action");
    switch (action) {
      case "status": return await handleStatus();
      case "exchange-info": return await handleExchangeInfo();
      case "universe": return await handleUniverse(url.searchParams);
      case "ticker": return await handleTicker(url.searchParams);
      case "klines": return await handleKlines(url.searchParams);
      default: return jsonResponse({ error: "unknown or missing action" }, 400);
    }
  } catch (error) {
    const message = error instanceof Error ? error.message : "internal error";
    const status = message.includes("invalid") ? 400 : 502;
    return jsonResponse({ error: message }, status);
  }
});
