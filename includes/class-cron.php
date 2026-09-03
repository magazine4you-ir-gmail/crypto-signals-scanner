<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * برای جلوگیری از Timeout در هاست‌های اشتراکی، اسکن به‌صورت دسته‌ای (batch) انجام می‌شود:
 *  1) هر ۱ ساعت هوک css_start_scan اجرا می‌شود -> لیست ارزها گرفته و در صف قرار می‌گیرد.
 *  2) هر ۱ دقیقه هوک css_queue_worker چند ارز از صف را پردازش می‌کند.
 *  3) هوک css_evaluate_signals سیگنال‌های قبلی که موعدشان رسیده را با قیمت فعلی می‌سنجد.
 */
class CSS_Cron {

    const BATCH_SIZE = 5;

    public function __construct() {
        add_filter( 'cron_schedules', array( $this, 'add_custom_schedules' ) );
        add_action( 'css_start_scan', array( $this, 'start_scan' ) );
        add_action( 'css_queue_worker', array( $this, 'process_queue_batch' ) );
        add_action( 'css_evaluate_signals', array( $this, 'evaluate_pending_signals' ) );
    }

    public function add_custom_schedules( array $schedules ): array {
        $schedules['css_every_minute'] = array(
            'interval' => 60,
            'display'  => 'هر یک دقیقه (Crypto Signal Scanner)',
        );
        return $schedules;
    }

    /** آیا اسکنی هنوز در حال اجراست؟ */
    public function scan_already_in_progress(): bool {
        $queue  = get_option( 'css_scan_queue', array() );
        $status = get_option( 'css_scan_status', array() );

        if ( empty( $queue ) || empty( $status['started_at'] ) ) {
            return false;
        }

        $age_minutes = ( time() - strtotime( $status['started_at'] ) ) / 60;
        return $age_minutes < 30;
    }

    /** لغو کامل اسکن در حال اجرا */
    public function cancel_scan(): void {
        delete_option( 'css_scan_queue' );
        delete_transient( 'css_rate_limited' );

        $status = get_option( 'css_scan_status', array() );
        $status['remaining']   = 0;
        $status['finished_at'] = current_time( 'mysql' );
        $status['cancelled']   = true;
        update_option( 'css_scan_status', $status, false );

        $this->update_last_scan_log( array(
            'status'      => 'cancelled',
            'finished_at' => current_time( 'mysql' ),
            'note'        => 'اسکن توسط کاربر لغو شد',
        ) );
    }

    public function start_scan( bool $manual = false ): void {
        $settings = get_option( 'css_settings', array() );

        if ( ! $manual && empty( $settings['auto_scan_enabled'] ) ) {
            return;
        }

        if ( $this->scan_already_in_progress() ) {
            return;
        }

        if ( class_exists( 'CSS_Market_Trend' ) ) {
            CSS_Market_Trend::track();
        }

        $rank_start = max( 1, (int) ( $settings['rank_start'] ?? 1 ) );
        $rank_end   = max( $rank_start, (int) ( $settings['rank_end'] ?? 100 ) );

        $fetcher = new CSS_Data_Fetcher();
        $coins   = $fetcher->get_coins_by_rank_range( $rank_start, $rank_end );

        // حذف ارزهای لیست سیاه
        $blacklist = array_map( 'sanitize_key', (array) ( $settings['blacklist_coin_ids'] ?? array() ) );
        if ( ! empty( $blacklist ) ) {
            $blacklist_lookup = array_fill_keys( array_map( 'strtolower', $blacklist ), true );
            $coins = array_values( array_filter( $coins, static function ( $c ) use ( $blacklist_lookup ) {
                $id     = strtolower( (string) ( $c['id'] ?? '' ) );
                $symbol = strtolower( (string) ( $c['symbol'] ?? '' ) );
                return ! isset( $blacklist_lookup[ $id ] ) && ! isset( $blacklist_lookup[ $symbol ] );
            } ) );
        }

        // اطمینان از رعایت دقیق بازه رتبه
        $expected_count = $rank_end - $rank_start + 1;
        if ( count( $coins ) > $expected_count ) {
            $coins = array_slice( $coins, 0, $expected_count );
        }

        // شمارنده تلاش برای جلوگیری از requeue بی‌نهایت
        foreach ( $coins as &$c ) {
            $c['_retries'] = 0;
        }
        unset( $c );

        if ( empty( $coins ) ) {
            $this->append_scan_log( array(
                'started_at'  => current_time( 'mysql' ),
                'finished_at' => current_time( 'mysql' ),
                'total'       => 0,
                'saved'       => 0,
                'status'      => 'failed',
                'note'        => ! empty( $blacklist )
                    ? 'همه ارزهای این بازه در لیست سیاه بودند یا دریافت لیست از Provider فعال ناموفق بود'
                    : 'دریافت لیست ارزها از Provider فعال ناموفق بود (به گزارش خطاها نگاه کنید)',
            ) );
            return;
        }

        update_option( 'css_scan_queue', $coins, false );
        update_option( 'css_scan_status', array(
            'total'      => count( $coins ),
            'remaining'  => count( $coins ),
            'started_at' => current_time( 'mysql' ),
            'saved'      => 0,
            'coins_done' => 0,
        ), false );

        $this->append_scan_log( array(
            'started_at'  => current_time( 'mysql' ),
            'finished_at' => null,
            'total'       => count( $coins ),
            'saved'       => 0,
            'status'      => 'running',
            'note'        => '',
        ) );
    }

    private function append_scan_log( array $entry ): void {
        $log   = get_option( 'css_scan_log', array() );
        $log[] = $entry;
        $log   = array_slice( $log, -30 );
        update_option( 'css_scan_log', $log, false );
    }

    private function update_last_scan_log( array $changes ): void {
        $log = get_option( 'css_scan_log', array() );
        if ( empty( $log ) ) {
            return;
        }
        $last_index = count( $log ) - 1;
        $log[ $last_index ] = array_merge( $log[ $last_index ], $changes );
        update_option( 'css_scan_log', $log, false );
    }

    public function process_queue_batch( bool $manual = false ): void {
        if ( get_transient( 'css_rate_limited' ) ) {
            return;
        }

        $settings = get_option( 'css_settings', array() );

        if ( ! $manual && empty( $settings['auto_scan_enabled'] ) ) {
            return;
        }

        $queue = get_option( 'css_scan_queue', array() );
        if ( empty( $queue ) ) {
            return;
        }

        $history    = (int) ( $settings['history_days'] ?? 30 );
        $delay_ms   = (int) ( $settings['request_delay_ms'] ?? 2500 );
        $timeframes = ! empty( $settings['active_timeframes'] ) ? (array) $settings['active_timeframes'] : array( 'daily' );
        $fetcher    = new CSS_Data_Fetcher();
        $engine     = new CSS_Signal_Engine();

        $needs_ohlc = false;
        foreach ( $settings['active_indicators'] ?? array() as $id ) {
            $ind = CSS_Indicator_Registry::get( $id );
            if ( $ind && $ind->get_requires_ohlc() ) {
                $needs_ohlc = true;
                break;
            }
        }

        $batch       = array_splice( $queue, 0, self::BATCH_SIZE );
        $requeue     = array();
        $saved_count = 0;
        $coins_done  = 0;
        $max_retries = 2;

        foreach ( $batch as $coin ) {
            $coin_failed = false;
            $retries     = (int) ( $coin['_retries'] ?? 0 );

            foreach ( $timeframes as $timeframe ) {
                if ( get_transient( 'css_rate_limited' ) ) {
                    $coin_failed = true;
                    break;
                }

                $history_data = $fetcher->get_price_history( $coin['id'], $history, $timeframe );
                $closes       = array_values( array_filter( (array) ( $history_data['close'] ?? array() ), static function ( $v ) {
                    return is_numeric( $v ) && (float) $v > 0;
                } ) );

                if ( empty( $closes ) ) {
                    $coin_failed = true;
                    break;
                }

                $market_data = array( 'close' => $closes );

                $volume_trend_pct = null;
                if ( ! empty( $history_data['volume'] ) && count( $history_data['volume'] ) > 20 ) {
                    $vol_sma  = CSS_MA_Helper::sma( $history_data['volume'], 20 );
                    $last_sma = CSS_MA_Helper::last_valid( $vol_sma );
                    $last_vol = end( $history_data['volume'] );
                    if ( $last_sma && (float) $last_sma['value'] > 0 ) {
                        $volume_trend_pct = round( ( $last_vol / (float) $last_sma['value'] ) * 100, 1 );
                    }
                }

                if ( $needs_ohlc ) {
                    usleep( max( 500, $delay_ms ) * 1000 );

                    if ( get_transient( 'css_rate_limited' ) ) {
                        $coin_failed = true;
                        break;
                    }

                    $ohlc = $fetcher->get_ohlc_history( $coin['id'], $history, $timeframe );
                    if ( ! empty( $ohlc['close'] ) ) {
                        $market_data['high']       = $ohlc['high'];
                        $market_data['low']        = $ohlc['low'];
                        $market_data['ohlc_close'] = $ohlc['close'];
                    }
                }

                $analysis   = $engine->analyze( $market_data );
                $last_price = (float) end( $closes );
                if ( $last_price <= 0 && isset( $coin['current_price'] ) ) {
                    $last_price = (float) $coin['current_price'];
                }
                if ( $last_price <= 0 ) {
                    $coin_failed = true;
                    break;
                }

                if ( $this->save_result( $coin, $last_price, $analysis, $timeframe, $volume_trend_pct ) ) {
                    $saved_count++;
                }

                usleep( max( 500, $delay_ms ) * 1000 );
            }

            if ( $coin_failed ) {
                if ( $retries < $max_retries ) {
                    $coin['_retries'] = $retries + 1;
                    $requeue[] = $coin;
                }
                // بیش از max_retries → دور انداخته می‌شود
            } else {
                $coins_done++;
            }
        }

        $queue = array_merge( $requeue, $queue );
        update_option( 'css_scan_queue', $queue, false );

        $status               = get_option( 'css_scan_status', array() );
        $status['remaining']  = count( $queue );
        $status['saved']      = (int) ( $status['saved'] ?? 0 ) + $saved_count;
        $status['coins_done'] = (int) ( $status['coins_done'] ?? 0 ) + $coins_done;

        if ( empty( $queue ) ) {
            $status['finished_at'] = current_time( 'mysql' );
        }
        update_option( 'css_scan_status', $status, false );

        $this->update_last_scan_log( array(
            'saved'       => $status['saved'],
            'status'      => empty( $queue ) ? 'completed' : 'running',
            'finished_at' => empty( $queue ) ? current_time( 'mysql' ) : null,
        ) );
    }

    private function save_result( array $coin, float $last_price, array $analysis, string $timeframe, ?float $volume_trend_pct = null ): bool {
        global $wpdb;
        $table = $wpdb->prefix . CSS_TABLE_SIGNALS;

        $previous = $wpdb->get_var( $wpdb->prepare(
            "SELECT trade_signal FROM {$table} WHERE coin_id = %s AND timeframe = %s", $coin['id'], $timeframe
        ) );

        $result = $wpdb->replace( $table, array(
            'coin_id'           => $coin['id'],
            'symbol'            => $coin['symbol'],
            'name'              => $coin['name'],
            'market_cap_rank'   => $coin['market_cap_rank'],
            'price'             => $last_price,
            'trade_signal'      => $analysis['signal'],
            'timeframe'         => $timeframe,
            'indicators_detail' => wp_json_encode( $analysis['details'], JSON_UNESCAPED_UNICODE ),
            'updated_at'        => current_time( 'mysql' ),
        ) );

        if ( false === $result && ! empty( $wpdb->last_error ) ) {
            update_option( 'css_error_log', array_slice( array_merge(
                get_option( 'css_error_log', array() ),
                array( array( 'time' => current_time( 'mysql' ), 'message' => 'خطای ذخیره‌سازی دیتابیس برای ' . $coin['id'] . ': ' . $wpdb->last_error ) )
            ), -50 ), false );
            return false;
        }

        CSS_Coin_CPT::sync_coin( $coin['id'], $coin['symbol'], $coin['name'], $coin['market_cap_rank'], $last_price, $coin['market_data'] ?? array(), $volume_trend_pct );

        if ( in_array( $analysis['signal'], array( 'buy', 'sell' ), true ) && $analysis['signal'] !== $previous ) {
            $this->log_signal_history( $coin, $last_price, $analysis, $timeframe );
            CSS_Coin_CPT::record_signal( $coin['id'], $coin['symbol'], $coin['name'], $analysis, $timeframe, $last_price );

            foreach ( $analysis['details'] as $ind_id => $val ) {
                if ( $val === $analysis['signal'] ) {
                    $indicator = CSS_Indicator_Registry::get( $ind_id );
                    $ind_label = $indicator ? $indicator->get_label() : $ind_id;
                    CSS_Indicator_CPT::record_signal( $ind_id, $ind_label, $coin['id'], $coin['symbol'], $coin['name'], $analysis['signal'], $timeframe, $last_price, $analysis['metrics'][ $ind_id ] ?? array() );
                }
            }
        }

        return true;
    }

    private function log_signal_history( array $coin, float $price, array $analysis, string $timeframe ): void {
        global $wpdb;
        $table    = $wpdb->prefix . CSS_TABLE_HISTORY;
        $settings = get_option( 'css_settings', array() );
        $hours    = max( 1, (int) ( $settings['evaluation_hours'] ?? 24 ) );

        $source = array();
        foreach ( $analysis['details'] as $ind_id => $val ) {
            if ( $val === $analysis['signal'] ) {
                $indicator = CSS_Indicator_Registry::get( $ind_id );
                $source[]  = $indicator ? $indicator->get_label() : $ind_id;
            }
        }

        $wpdb->insert( $table, array(
            'coin_id'            => $coin['id'],
            'symbol'             => $coin['symbol'],
            'trade_signal'       => $analysis['signal'],
            'timeframe'          => $timeframe,
            'source_indicators'  => implode( '، ', $source ),
            'price_at_signal'    => $price,
            'indicators_detail'  => wp_json_encode( $analysis['details'], JSON_UNESCAPED_UNICODE ),
            'outcome'            => 'pending',
            'created_at'         => current_time( 'mysql' ),
            'check_after'        => gmdate( 'Y-m-d H:i:s', time() + ( $hours * HOUR_IN_SECONDS ) ),
        ) );
    }

    public function count_due_pending(): int {
        global $wpdb;
        $table = $wpdb->prefix . CSS_TABLE_HISTORY;
        $now   = current_time( 'mysql' );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE outcome = 'pending' AND check_after <= %s", $now
        ) );
    }

    public function evaluate_pending_signals(): void {
        if ( get_transient( 'css_rate_limited' ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . CSS_TABLE_HISTORY;
        $now   = current_time( 'mysql' );

        $pending = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE outcome = 'pending' AND check_after <= %s ORDER BY check_after ASC LIMIT 15",
            $now
        ), ARRAY_A );

        if ( empty( $pending ) ) {
            return;
        }

        $settings = get_option( 'css_settings', array() );
        $delay_ms = (int) ( $settings['request_delay_ms'] ?? 2500 );
        $fetcher  = new CSS_Data_Fetcher();

        foreach ( $pending as $row ) {
            if ( get_transient( 'css_rate_limited' ) ) {
                break;
            }

            $current_price = $fetcher->get_current_price( $row['coin_id'] );
            if ( null === $current_price ) {
                continue;
            }

            if ( 'buy' === $row['trade_signal'] ) {
                $outcome = $current_price > (float) $row['price_at_signal'] ? 'correct' : 'incorrect';
            } else {
                $outcome = $current_price < (float) $row['price_at_signal'] ? 'correct' : 'incorrect';
            }

            $wpdb->update( $table, array(
                'price_at_check' => $current_price,
                'outcome'        => $outcome,
                'evaluated_at'   => current_time( 'mysql' ),
            ), array( 'id' => $row['id'] ) );

            $signal_date = substr( $row['created_at'], 0, 10 );
            CSS_Coin_CPT::record_outcome(
                $row['coin_id'],
                $signal_date,
                $row['timeframe'],
                $row['trade_signal'],
                $outcome,
                $current_price
            );

            $details = json_decode( $row['indicators_detail'], true ) ?: array();
            foreach ( $details as $ind_id => $val ) {
                if ( $val === $row['trade_signal'] ) {
                    CSS_Indicator_CPT::record_outcome(
                        $ind_id,
                        $signal_date,
                        $row['timeframe'],
                        $row['coin_id'],
                        $row['trade_signal'],
                        $outcome,
                        $current_price
                    );
                }
            }

            usleep( max( 500, $delay_ms ) * 1000 );
        }
    }
}
