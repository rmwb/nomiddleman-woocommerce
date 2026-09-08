<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Crypto Helper
class NMMPRO_Cryptocurrencies {

	// Coins whose Autopay transaction-verification API no longer exists anywhere
	// (chain sunset or every public explorer gone). Classic Mode still works.
	// Re-checked 2026-08-21: Lisk's L1 service is gone (node08.lisk.io and
	// service.lisk.com no longer resolve), the NEM node the plugin shipped
	// stopped answering, DeepOnion's remaining explorer times out, Myriad's
	// blockbook now serves a parked page, and insight.bitcore.cc returns 521.
	// The request code for all five was removed rather than left pointing at
	// dead hosts; chainz.cryptoid.info still covers BTX balances for Privacy
	// Mode.
	private static $autopayUnverifiable = array('LSK', 'XEM', 'ONION', 'XMY', 'BTX');

	// Coins whose Privacy Mode (HD) balance API no longer exists
	private static $hdUnverifiable = array('XMY');

	// EVM chain ids for coins that live on a chain other than Ethereum mainnet
	private static $evmChainIds = array(
		'USDTPOL' => 137,
		'USDCPOL' => 137,
		'USDTARB' => 42161,
		'USDCARB' => 42161,
		'USDCBAS' => 8453,
	);

	// chain id for EVM-native coins and tokens (1 = Ethereum mainnet)
	public static function evm_chain_id($cryptoId) {
		return isset(self::$evmChainIds[$cryptoId]) ? self::$evmChainIds[$cryptoId] : 1;
	}

	// The coin supports Autopay AND a working verification API exists for it
	public static function autopay_verifiable($cryptoId) {
		$cryptos = self::get();

		if (!array_key_exists($cryptoId, $cryptos) || !$cryptos[$cryptoId]->has_autopay()) {
			return false;
		}

		return !in_array($cryptoId, self::$autopayUnverifiable, true);
	}

	// The coin supports Privacy Mode AND a working balance API exists for it
	public static function hd_verifiable($cryptoId) {
		$cryptos = self::get();

		if (!array_key_exists($cryptoId, $cryptos) || !$cryptos[$cryptoId]->has_hd()) {
			return false;
		}

		return !in_array($cryptoId, self::$hdUnverifiable, true);
	}

	public static function get() {
		// built once per request: callers hit this dozens of times and the
		// 58 immutable value objects never change after construction
		static $memo = null;

		if ($memo !== null) {
			return $memo;
		}

        // id, name, round_precision, icon_filename, refresh_time, symbol, has_hd, has_autopay, needs_confirmations, erc20contract
		$cryptoArray = array(

            // privacy mpk
            'BTC' => new NMMPRO_Cryptocurrency('BTC', 'Bitcoin', 8, 'bitcoin_logo_small.png', 60, '₿', true, true, true, ''),
            'LTC' => new NMMPRO_Cryptocurrency('LTC', 'Litecoin', 8, 'litecoin_logo_small.png', 60, 'Ł', true, true, true, ''),
            'QTUM' => new NMMPRO_Cryptocurrency('QTUM', 'Qtum', 8, 'qtum_logo_small.png', 60, '', true, false, true, ''),
            'DASH' => new NMMPRO_Cryptocurrency('DASH', 'Dash', 8, 'dash_logo_small.png', 60, '', true, true, true, ''),
            'DOGE' => new NMMPRO_Cryptocurrency('DOGE', 'Dogecoin', 8, 'dogecoin_logo_small.png', 60, 'Ð', true, true, true, ''),
            'XMY' => new NMMPRO_Cryptocurrency('XMY', 'Myriad', 8, 'myriad_logo_small.png', 60, '', true, true, true, ''),
            'BTX' => new NMMPRO_Cryptocurrency('BTX', 'Bitcore', 8, 'bitcore_logo_small.png', 60, '', true, true, true, ''),

            // auto-pay coins
            'ETH' => new NMMPRO_Cryptocurrency('ETH', 'Ethereum', 18, 'ethereum_logo_small.png', 60, 'Ξ', false, true, true, ''),
            'DGB' => new NMMPRO_Cryptocurrency('DGB', 'Digibyte', 8, 'digibyte_logo_small.png', 60, '', false, true, true, ''),
            'ZEC' => new NMMPRO_Cryptocurrency('ZEC', 'Zcash', 8, 'zcash_logo_small.png', 60, 'ⓩ', false, true, true, ''),
            'DCR' => new NMMPRO_Cryptocurrency('DCR', 'Decred', 8, 'decred_logo_small.png', 60, '', false, true, true, ''),
            'ADA' => new NMMPRO_Cryptocurrency('ADA', 'Cardano', 6, 'cardano_logo_small.png', 60, '', false, true, false, ''),
            'XTZ' => new NMMPRO_Cryptocurrency('XTZ', 'Tezos', 6, 'tezos_logo_small.png', 60, '', false, true, false, ''),
            'TRX' => new NMMPRO_Cryptocurrency('TRX', 'Tron', 6, 'tron_logo_small.png', 60, '', false, true, false, ''),
            'XLM' => new NMMPRO_Cryptocurrency('XLM', 'Stellar', 7, 'stellar_logo_small.png', 60, '', false, true, false, ''),
            'USDTTRX' => new NMMPRO_Cryptocurrency('USDTTRX', 'Tether (TRC-20)', 6, 'tether_logo_small.png', 60, '', false, true, false, ''),
            'SOL' => new NMMPRO_Cryptocurrency('SOL', 'Solana', 9, 'solana_logo_small.png', 60, '◎', false, true, false, ''),
            'BCH' => new NMMPRO_Cryptocurrency('BCH', 'Bitcoin Cash', 8, 'bitcoincash_logo_small.png', 60, '', false, true, true, ''),
            'EOS' => new NMMPRO_Cryptocurrency('EOS', 'EOS', 4, 'eos_logo_small.png', 60, '', false, true, false, ''),
            'BSV' => new NMMPRO_Cryptocurrency('BSV', 'Bitcoin SV', 8, 'bitcoinsv_logo_small.png', 60, '', false, true, false, ''),
            'XRP' => new NMMPRO_Cryptocurrency('XRP', 'XRP', 6, 'xrp_logo_small.png', 60, '', false, true, false, ''),
            'ONION' => new NMMPRO_Cryptocurrency('ONION', 'DeepOnion', 8, 'deeponion_logo_small.png', 60, '', false, true, true, ''),
            'BLK' => new NMMPRO_Cryptocurrency('BLK', 'BlackCoin', 8, 'blackcoin_logo_small.png', 60, '', false, true, true, ''),
            'ETC' => new NMMPRO_Cryptocurrency('ETC', 'Ethereum Classic', 18, 'ethereumclassic_logo_small.png', 60, '', false, true, true, ''),
            'LSK' => new NMMPRO_Cryptocurrency('LSK', 'Lisk', 8, 'lisk_logo_small.png', 60, '', false, true, true, ''),
            'XEM' => new NMMPRO_Cryptocurrency('XEM', 'NEM', 6, 'nem_logo_small.png', 60, '', false, true, true, ''),
            'WAVES' => new NMMPRO_Cryptocurrency('WAVES', 'Waves', 8, 'waves_logo_small.png', 60, '', false, true, true, ''),
            'GRS' => new NMMPRO_Cryptocurrency('GRS', 'Groestlcoin', 8, 'groestlcoin_logo_small.png', 60, '', false, true, true, false),
            'APL' => new NMMPRO_Cryptocurrency('APL', 'Apollo Currency', 8, 'apollocurrency_logo_small.png', 60, '', false, false, true, false),

            // tokens
            'HOT' => new NMMPRO_Cryptocurrency('HOT', 'Holochain', 18, 'holochain_logo_small.png', 60, '', false, true, true, '0x6c6ee5e31d828de241282b9606c8e98ea48526e2'),
            'LINK' => new NMMPRO_Cryptocurrency('LINK', 'Chainlink', 18, 'chainlink_logo_small.png', 60, '', false, true, true, '0x514910771af9ca656af840dff83e8264ecf986ca'),
            'BAT' => new NMMPRO_Cryptocurrency('BAT', 'Basic Attention Token', 18, 'basicattentiontoken_logo_small.png', 60, '', false, true, true, '0x0d8775f648430679a709e98d2b0cb6250d2887ef'),
            'MKR' => new NMMPRO_Cryptocurrency('MKR', 'Maker', 18, 'maker_logo_small.png', 60, '', false, true, true, '0x9f8f72aa9304c8b593d555f12ef6589cc3a579a2'),
            'OMG' => new NMMPRO_Cryptocurrency('OMG', 'OmiseGO', 18, 'omisego_logo_small.png', 60, '', false, true, true, '0xd26114cd6EE289AccF82350c8d8487fedB8A0C07'),
            'REP' => new NMMPRO_Cryptocurrency('REP', 'Augur', 18, 'augur_logo_small.png', 60, '', false, true, true, '0x1985365e9f78359a9B6AD760e32412f4a445E862'),
            'GNO' => new NMMPRO_Cryptocurrency('GNO', 'Gnosis', 18, 'gnosis_logo_small.png', 60, '', false, true, true, '0x6810e776880c02933d47db1b9fc05908e5386b96'),
            'MLN' => new NMMPRO_Cryptocurrency('MLN', 'Melon', 18, 'melon_logo_small.png', 60, '', false, true, true, '0xbeb9ef514a379b997e0798fdcc901ee474b6d9a1'),
            'ZRX' => new NMMPRO_Cryptocurrency('ZRX', '0x', 18, '0x_logo_small.png', 60, '', false, true, true, '0xe41d2489571d322189246dafa5ebde1f4699f498'),
            'USDC' => new NMMPRO_Cryptocurrency('USDC', 'USDC', 6, 'usdc_logo_small.png', 60, '', false, true, true, '0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48'),
            'USDT' => new NMMPRO_Cryptocurrency('USDT', 'Tether', 6, 'tether_logo_small.png', 60, '', false, true, true, '0xdAC17F958D2ee523a2206206994597C13D831ec7'),
            'DAI' => new NMMPRO_Cryptocurrency('DAI', 'Dai', 18, 'dai_logo_small.png', 60, '', false, true, true, '0x6B175474E89094C44Da98b954EedeAC495271d0F'),
            'PYUSD' => new NMMPRO_Cryptocurrency('PYUSD', 'PayPal USD', 6, 'pyusd_logo_small.png', 60, '', false, true, true, '0x6c3ea9036406852006290770BEdFcAbA0e23A0e8'),
            'USDTPOL' => new NMMPRO_Cryptocurrency('USDTPOL', 'Tether (Polygon)', 6, 'tether_logo_small.png', 60, '', false, true, true, '0xc2132D05D31c914a87C6611C10748AEb04B58e8F'),
            'USDCPOL' => new NMMPRO_Cryptocurrency('USDCPOL', 'USDC (Polygon)', 6, 'usdc_logo_small.png', 60, '', false, true, true, '0x3c499c542cEF5E3811e1192ce70d8cC03d5c3359'),
            'USDTARB' => new NMMPRO_Cryptocurrency('USDTARB', 'Tether (Arbitrum)', 6, 'tether_logo_small.png', 60, '', false, true, true, '0xFd086bC7CD5C481DCC9C85ebE478A1C0b69FCbb9'),
            'USDCARB' => new NMMPRO_Cryptocurrency('USDCARB', 'USDC (Arbitrum)', 6, 'usdc_logo_small.png', 60, '', false, true, true, '0xaf88d065e77c8cC2239327C5EDb3A432268e5831'),
            'USDCBAS' => new NMMPRO_Cryptocurrency('USDCBAS', 'USDC (Base)', 6, 'usdc_logo_small.png', 60, '', false, true, true, '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913'),

            // no support
            'XMR' => new NMMPRO_Cryptocurrency('XMR', 'Monero', 12, 'monero_logo_small.png', 60, 'ɱ', false, true, true, ''),
            'VRC' => new NMMPRO_Cryptocurrency('VRC', 'Vericoin', 8, 'vericoin_logo_small.png', 60, '', false, false, true, ''),
            'BTG' => new NMMPRO_Cryptocurrency('BTG', 'Bitcoin Gold', 8, 'bitcoingold_logo_small.png', 60, '', false, false, true, ''),
            'VET' => new NMMPRO_Cryptocurrency('VET', 'VeChain', 18, 'vechain_logo_small.png', 60, '', false, false, true, ''),
            'BCD' => new NMMPRO_Cryptocurrency('BCD', 'Bitcoin Diamond', 8, 'bitcoindiamond_logo_small.png', 60, '', false, false, true, ''),
            'BCN' => new NMMPRO_Cryptocurrency('BCN', 'Bytecoin', 8, 'bytecoin_logo_small.png', 60, '', false, false, true, ''),
            'BNB' => new NMMPRO_Cryptocurrency('BNB', 'Binance Coin', 18, 'binancecoin_logo_small.png', 60, '', false, false, true, ''),
            'GUSD' => new NMMPRO_Cryptocurrency('GUSD', 'Gemini Dollar', 2, 'geminidollar_logo_small.png', 60, '', false, false, true, '0x056Fd409E1d7A124BD7017459dFEa2F387b6d5Cd'),


            // More searching required

            'POT' => new NMMPRO_Cryptocurrency('POT', 'Potcoin', 18, 'potcoin_logo_small.png', 60, '', false, false, true, ''),
            // https://www.reddit.com/r/OntologyNetwork/comments/9duf28/api_to_get_ont_balance/
            'ONT' => new NMMPRO_Cryptocurrency('ONT', 'Ontology', 18, 'ontology_logo_small.png', 60, '', false, false, true, ''),

            'MIOTA' => new NMMPRO_Cryptocurrency('MIOTA', 'Iota', 18, 'iota_logo_small.png', 60, '', false, false, true, ''),
        );

        return $memo = $cryptoArray;
	}

    public static function get_erc20_tokens() {
        $cryptos = self::get();
        $erc20Tokens = [];

        foreach ($cryptos as $crypto) {
            if ($crypto->is_erc20_token()) {
                $erc20Tokens[$crypto->get_id()] = $crypto;
            }
        }

        return $erc20Tokens;
    }

    public static function is_erc20_token($cryptoId) {

        if (array_key_exists($cryptoId, NMMPRO_Cryptocurrencies::get_erc20_tokens())) {
            return true;
        }

        return false;
    }

    public static function get_erc20_contract($cryptoId) {
        $erc20Tokens = NMMPRO_Cryptocurrencies::get_erc20_tokens();

        foreach ($erc20Tokens as $token) {
            if ($token->get_id() === $cryptoId) {
                return $token->get_erc20_contract();
            }
        }

        return '';
    }


    public static function get_alpha() {
        static $memo = null;

        if ($memo !== null) {
            return $memo;
        }

        $cryptoArray = NMMPRO_Cryptocurrencies::get();

        $keys = array_map(function($val) {
                return $val->get_id();
            }, $cryptoArray);
        array_multisort($keys, $cryptoArray);

        return $memo = $cryptoArray;
    }

    // Php likes to convert numbers to scientific notation, so this handles displaying small amounts correctly
    public static function get_price_string($cryptoId, $amount) {
        $cryptos = self::get();
        if (!isset($cryptos[$cryptoId])) { throw new InvalidArgumentException('Unknown currency'); }
        return NMMPRO_Amount::rounded($amount, $cryptos[$cryptoId]->get_round_precision());
    }

	public static function is_valid_wallet_address($cryptoId, $address) {

        // every ERC-20 style token (and each supported EVM chain) shares the
        // 0x address form
        $cryptos = self::get();
        if (array_key_exists($cryptoId, $cryptos) && $cryptos[$cryptoId]->is_erc20_token()) {
            return NMMPRO_Address::is_evm($address);
        }

        // Real validation lives in NMMPRO_Address: checksum verification
        // (base58check / bech32 / bech32m / CashAddr) where the scheme has
        // one, anchored patterns everywhere else. The old inline regexes
        // here were unanchored and checksum-free, so truncated or mistyped
        // addresses saved cleanly and customers paid unspendable outputs.
        if (NMMPRO_Address::is_known($cryptoId)) {
            return NMMPRO_Address::validate($cryptoId, $address);
        }

        NMMPRO_Util::log(__FILE__, __LINE__, 'Invalid cryptoId, contact plug-in developer.');
        throw new Exception('Invalid cryptoId, contact plug-in developer.');
    }
}

?>
