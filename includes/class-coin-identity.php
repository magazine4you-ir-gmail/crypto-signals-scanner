<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * مدیریت شناسه استاندارد ارزها بین CoinGecko و Binance
 * Canonical ID = نماد پایه به حروف بزرگ (BTC, ETH, SOL, ...)
 */
class CSS_Coin_Identity {

    /** مپینگ رایج نماد پایه → CoinGecko ID */
    private static array $symbol_to_coingecko = array(
        'BTC'  => 'bitcoin',
        'ETH'  => 'ethereum',
        'USDT' => 'tether',
        'BNB'  => 'binancecoin',
        'SOL'  => 'solana',
        'XRP'  => 'ripple',
        'USDC' => 'usd-coin',
        'ADA'  => 'cardano',
        'DOGE' => 'dogecoin',
        'TRX'  => 'tron',
        'AVAX' => 'avalanche-2',
        'DOT'  => 'polkadot',
        'LINK' => 'chainlink',
        'MATIC'=> 'matic-network',
        'POL'  => 'matic-network',
        'SHIB' => 'shiba-inu',
        'LTC'  => 'litecoin',
        'BCH'  => 'bitcoin-cash',
        'NEAR' => 'near',
        'UNI'  => 'uniswap',
        'ICP'  => 'internet-computer',
        'APT'  => 'aptos',
        'FIL'  => 'filecoin',
        'ATOM' => 'cosmos',
        'ARB'  => 'arbitrum',
        'OP'   => 'optimism',
        'IMX'  => 'immutable-x',
        'HBAR' => 'hedera-hashgraph',
        'VET'  => 'vechain',
        'INJ'  => 'injective-protocol',
        'SUI'  => 'sui',
        'SEI'  => 'sei-network',
        'TIA'  => 'celestia',
        'RENDER'=> 'render-token',
        'FET'  => 'fetch-ai',
        'PEPE' => 'pepe',
        'WIF'  => 'dogwifcoin',
        'BONK' => 'bonk',
        'FLOKI'=> 'floki',
        'AAVE' => 'aave',
        'MKR'  => 'maker',
        'CRV'  => 'curve-dao-token',
        'LDO'  => 'lido-dao',
        'RUNE' => 'thorchain',
        'GRT'  => 'the-graph',
        'SAND' => 'the-sandbox',
        'MANA' => 'decentraland',
        'AXS'  => 'axie-infinity',
        'GALA' => 'gala',
        'CHZ'  => 'chiliz',
        'ENS'  => 'ethereum-name-service',
        'XLM'  => 'stellar',
        'XMR'  => 'monero',
        'ETC'  => 'ethereum-classic',
        'ALGO' => 'algorand',
        'FLOW' => 'flow',
        'MINA' => 'mina-protocol',
        'KAVA' => 'kava',
        'ZEC'  => 'zcash',
        'DASH' => 'dash',
        'NEO'  => 'neo',
        'QTUM' => 'qtum',
        'ZIL'  => 'zilliqa',
        'IOTA' => 'iota',
        'XTZ'  => 'tezos',
        'EOS'  => 'eos',
        'THETA'=> 'theta-token',
        'CAKE' => 'pancakeswap-token',
        '1INCH'=> '1inch',
        'COMP' => 'compound-governance-token',
        'SNX'  => 'havven',
        'YFI'  => 'yearn-finance',
        'SUSHI'=> 'sushi',
        'BAL'  => 'balancer',
        'ZRX'  => '0x',
        'BAT'  => 'basic-attention-token',
        'ENJ'  => 'enjincoin',
        'ANKR' => 'ankr',
        'STORJ'=> 'storj',
        'SKL'  => 'skale',
        'CELR' => 'celer-network',
        'CTSI' => 'cartesi',
        'BAND' => 'band-protocol',
        'API3' => 'api3',
        'MASK' => 'mask-network',
        'LRC'  => 'loopring',
        'OMG'  => 'omisego',
        'ICX'  => 'icon',
        'ONT'  => 'ontology',
        'IOST' => 'iostoken',
        'WAVES'=> 'waves',
        'KSM'  => 'kusama',
        'RVN'  => 'ravencoin',
        'DCR'  => 'decred',
        'DGB'  => 'digibyte',
        'SC'   => 'siacoin',
        'ZEN'  => 'horizen',
        'XEM'  => 'nem',
        'LSK'  => 'lisk',
        'NANO' => 'nano',
        'DYDX' => 'dydx-chain',
        'GMX'  => 'gmx',
        'PENDLE'=> 'pendle',
        'JUP'  => 'jupiter-exchange-solana',
        'WLD'  => 'worldcoin-wld',
        'STRK' => 'starknet',
        'PYTH' => 'pyth-network',
        'JTO'  => 'jito-governance-token',
        'W'    => 'wormhole',
        'ENA'  => 'ethena',
        'EIGEN'=> 'eigenlayer',
        'BOME' => 'book-of-meme',
        'NOT'  => 'notcoin',
        'TON'  => 'the-open-network',
        'TAO'  => 'bittensor',
    );

    /**
     * نماد پایه استاندارد را برمی‌گرداند (BTC)
     */
    public static function normalize_symbol( string $input ): string {
        $input = strtoupper( trim( $input ) );

        // اگر به شکل BTCUSDT اومده بود
        $quotes = array( 'USDT', 'USDC', 'BUSD', 'FDUSD', 'TUSD', 'USD', 'BTC', 'ETH', 'BNB' );
        foreach ( $quotes as $q ) {
            if ( str_ends_with( $input, $q ) && strlen( $input ) > strlen( $q ) ) {
                return substr( $input, 0, -strlen( $q ) );
            }
        }

        return $input;
    }

    /**
     * از هر چیزی (coin_id یا symbol) نماد پایه استاندارد بساز
     */
    public static function to_canonical( string $id_or_symbol ): string {
        $id_or_symbol = trim( $id_or_symbol );

        // اگر CoinGecko ID بود
        $flipped = array_flip( self::$symbol_to_coingecko );
        if ( isset( $flipped[ strtolower( $id_or_symbol ) ] ) ) {
            return $flipped[ strtolower( $id_or_symbol ) ];
        }

        return self::normalize_symbol( $id_or_symbol );
    }

    /**
     * نماد پایه → CoinGecko ID
     */
    public static function to_coingecko_id( string $symbol ): ?string {
        $symbol = self::normalize_symbol( $symbol );
        return self::$symbol_to_coingecko[ $symbol ] ?? null;
    }

    /**
     * نماد پایه → نماد بایننس (با quote)
     */
    public static function to_binance_symbol( string $symbol, string $quote = 'USDT' ): string {
        $base = self::normalize_symbol( $symbol );
        $quote = strtoupper( $quote );
        return $base . $quote;
    }

    /**
     * آیا این شناسه مربوط به بایننس است؟
     */
    public static function is_binance_style( string $id ): bool {
        $id = strtoupper( $id );
        return (bool) preg_match( '/^[A-Z0-9]{2,15}(USDT|USDC|BUSD|FDUSD|TUSD|BTC|ETH|BNB)$/', $id );
    }
}
