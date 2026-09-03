<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * لایه دسترسی یکنواخت به داده بازار با پشتیبانی همزمان از CoinGecko و Binance
 * شناسه استاندارد همه جا = نماد پایه (BTC, ETH, ...)
 */
class CSS_Data_Fetcher {

    private CSS_Data_Provider_Interface $provider;
    private string $active_provider;

    public function __construct() {
        $settings = get_option( 'css_settings', array() );
        $active   = sanitize_key( $settings['data_provider'] ?? 'coingecko' );

        if ( 'binance' === $active && class_exists( 'CSS_Binance_Provider' ) ) {
            $this->provider        = new CSS_Binance_Provider();
            $this->active_provider = 'binance';
        } else {
            $this->provider        = new CSS_CoinGecko_Provider();
            $this->active_provider = 'coingecko';
        }
    }

    public function get_active_provider(): string {
        return $this->active_provider;
    }

    /**
     * تبدیل هر شناسه‌ای به فرمت مورد نیاز پروایدر فعال
     */
    private function resolve_asset( string $asset ): string {
        $canonical = CSS_Coin_Identity::to_canonical( $asset );

        if ( 'binance' === $this->active_provider ) {
            $settings = get_option( 'css_settings', array() );
            $quote    = strtoupper( $settings['binance_quote_asset'] ?? 'USDT' );
            return CSS_Coin_Identity::to_binance_symbol( $canonical, $quote );
        }

        // CoinGecko
        $cg_id = CSS_Coin_Identity::to_coingecko_id( $canonical );
        return $cg_id ?: strtolower( $canonical );
    }

    public function get_top_coins( int $limit = 100 ): array {
        return $this->normalize_coins( $this->provider->get_top_coins( $limit ) );
    }

    public function get_coins_by_rank_range( int $start, int $end ): array {
        return $this->normalize_coins( $this->provider->get_coins_by_rank_range( $start, $end ) );
    }

    /**
     * خروجی پروایدرها را به فرمت استاندارد تبدیل می‌کند
     * coin_id همیشه = نماد پایه (BTC)
     */
    private function normalize_coins( array $coins ): array {
        $normalized = array();

        foreach ( $coins as $coin ) {
            if ( empty( $coin['symbol'] ) && empty( $coin['id'] ) ) {
                continue;
            }

            $raw_symbol = $coin['symbol'] ?? $coin['id'];
            $canonical  = CSS_Coin_Identity::to_canonical( $raw_symbol );

            $normalized[] = array(
                'id'              => $canonical,                 // شناسه استاندارد
                'symbol'          => $canonical,
                'name'            => $coin['name'] ?? $canonical,
                'market_cap_rank' => $coin['market_cap_rank'] ?? null,
                'current_price'   => $coin['current_price'] ?? null,
                'market_data'     => $coin['market_data'] ?? array(),
                // برای دیباگ و استفاده داخلی
                '_provider_id'    => $coin['id'] ?? null,
                '_raw_symbol'     => $coin['symbol'] ?? null,
            );
        }

        return $normalized;
    }

    public function get_price_history( string $asset, int $days = 30, string $timeframe = 'daily' ): array {
        return $this->provider->get_price_history( $this->resolve_asset( $asset ), $days, $timeframe );
    }

    public function get_ohlc_history( string $asset, int $days = 30, string $timeframe = 'daily' ): array {
        return $this->provider->get_ohlc_history( $this->resolve_asset( $asset ), $days, $timeframe );
    }

    public function get_ohlc_series( string $asset, int $days = 90 ): array {
        return $this->provider->get_ohlc_series( $this->resolve_asset( $asset ), $days );
    }

    public function get_current_price( string $asset ): ?float {
        return $this->provider->get_current_price( $this->resolve_asset( $asset ) );
    }

    public function get_global_market_data(): ?array {
        return $this->provider->get_global_market_data();
    }

    public function test_connection(): array {
        return $this->provider->test_connection();
    }
}
