<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Real wallet-address validation.
 *
 * The old per-coin checks in NMM_Cryptocurrencies were bare preg_match calls
 * with no '$' anchor and no checksum, so a truncated address, 'addr?x=1', or
 * 'hello bc1q...' all saved cleanly - and a merchant who pasted a mistyped
 * Classic/Autopay address would silently collect customer payments into an
 * address nobody controls. Funds paid to such an address are unrecoverable,
 * which is why every format below that HAS a checksum now gets it verified:
 *
 *  - base58check (BTC/LTC/DOGE/DASH/...): base58 decode, double-SHA256,
 *    4-byte checksum, and the coin's known version byte(s).
 *  - bech32 / bech32m (BIP-173 / BIP-350) for native segwit: BTC 'bc',
 *    LTC 'ltc', DGB 'dgb', BTG 'btg', BTX 'btx'. Witness v0 must use bech32,
 *    v1+ must use bech32m. Taproot (bc1p..., witness v1) is ACCEPTED for
 *    merchant receiving addresses: every modern wallet can pay a taproot
 *    output and the verification explorers the plugin queries index them.
 *  - CashAddr for BCH (and BSV wallets that emit it), with or without the
 *    'bitcoincash:' prefix; legacy base58check stays accepted too.
 *
 * Coins whose checksum is NOT double-SHA256 base58 (Groestlcoin uses Groestl
 * hashing, Decred uses BLAKE256, XRP a different base58 alphabet, XMR Keccak,
 * Stellar CRC16, ...) keep pattern validation - but with properly anchored
 * (^...$) and grouped regexes so nothing can be smuggled before or after the
 * address. Inventing checksum rules for those would risk rejecting genuinely
 * valid addresses, which is worse than a pattern check.
 *
 * Everything here is pure PHP: base58 decoding uses byte-carry arithmetic
 * (the same technique as the bundled CashAddress::old2new) instead of
 * HdHelper's GMP/BCMath decoder, for two load-bearing reasons:
 *  1. HdHelper::base58_decode requires a math extension and throws without
 *     one; address validation must never fatal on a host with neither.
 *  2. HdHelper's decoder drops leading zero bytes, so every version-0x00
 *     address (BTC '1...') would checksum over the wrong bytes.
 * hash('sha256', ...) is core PHP and always available.
 */
class NMM_Address {

	const B58_CHARSET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
	const BECH32_CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
	const BECH32M_CONST = 0x2bc830a3;

	// One-shot re-validation of already-stored addresses (see
	// flag_invalid_stored_addresses): option flag guarding the pass, and the
	// option holding whatever it found for the admin notice.
	const REVALIDATED_FLAG = 'nmm_addresses_revalidated';
	const INVALID_STORED_OPTION = 'nmm_invalid_stored_addresses';

	/**
	 * Main entry point: is $address a well-formed mainnet address for
	 * $cryptoId? Unknown coin ids return false - NMM_Cryptocurrencies keeps
	 * its historical throw for those, see is_known().
	 */
	public static function validate($cryptoId, $address) {
		if (!is_string($address) || $address === '') {
			return false;
		}

		// Nothing supported is longer than a Bytecoin/Monero-integrated
		// address; cap early so pathological input never reaches the math.
		if (strlen($address) > 256) {
			return false;
		}

		switch ($cryptoId) {

			// --- full checksum validation: base58check and/or segwit ---

			case 'BTC':
				// P2PKH 0x00, P2SH 0x05; native segwit hrp 'bc' (v0 bech32
				// P2WPKH/P2WSH and v1+ bech32m, i.e. taproot - accepted, see
				// the class comment).
				return self::is_base58check($address, array("\x00", "\x05"))
					|| self::is_segwit($address, 'bc');

			case 'LTC':
				// 0x30 'L...', 0x32 'M...'; the deprecated-but-spendable
				// 0x05 '3...' P2SH form stays accepted because the old regex
				// accepted it and coins sent there are still the merchant's.
				return self::is_base58check($address, array("\x30", "\x32", "\x05"))
					|| self::is_segwit($address, 'ltc');

			case 'DOGE':
				return self::is_base58check($address, array("\x1e", "\x16"));

			case 'DASH':
				return self::is_base58check($address, array("\x4c", "\x10"));

			case 'DGB':
				return self::is_base58check($address, array("\x1e", "\x3f"))
					|| self::is_segwit($address, 'dgb');

			case 'QTUM':
				// qtum chainparams: p2pkh 58 'Q', p2sh 50 'M', bech32 hrp 'qc'.
				return self::is_base58check($address, array("\x3a", "\x32"))
					|| self::is_segwit($address, 'qc');

			case 'XMY':
				// p2pkh 50 'M...' (pinned by tests/test-hd-addrtype.php);
				// p2sh 9 yields the '4'/'5' prefixes the old regex allowed;
				// myriadcoin chainparams also define bech32 hrp 'my'.
				return self::is_base58check($address, array("\x32", "\x09"))
					|| self::is_segwit($address, 'my');

			case 'BTX':
				return self::is_base58check($address, array("\x03", "\x7d"))
					|| self::is_segwit($address, 'btx');

			case 'BTG':
				return self::is_base58check($address, array("\x26", "\x17"))
					|| self::is_segwit($address, 'btg');

			case 'BCH':
				// Both forms are the same coin: legacy base58check and
				// CashAddr (with or without 'bitcoincash:'). Token-aware
				// CashAddr types (CashTokens upgrade) are valid BCH
				// destinations too, including for plain BCH payments.
				return self::is_base58check($address, array("\x00", "\x05"))
					|| self::is_cashaddr($address, true);

			case 'BSV':
				// BSV kept BTC's version bytes; some wallets still emit the
				// CashAddr form it inherited from the BCH split. Token-aware
				// types stay REJECTED here: BSV forked before CashTokens and
				// never adopted token-aware addresses.
				return self::is_base58check($address, array("\x00", "\x05"))
					|| self::is_cashaddr($address);

			case 'BCD':
				return self::is_base58check($address, array("\x00", "\x05"));

			case 'ZEC':
				// Transparent addresses use two-byte version prefixes
				// (t1 = 0x1CB8 P2PKH, t3 = 0x1CBD P2SH) - still plain
				// base58check. Shielded sprout z-addresses have no
				// double-SHA256 checksum we can verify; anchored pattern
				// only (sapling zs1/unified u1 were never accepted before
				// and stay out of scope).
				return self::is_base58check($address, array("\x1c\xb8", "\x1c\xbd"))
					|| preg_match('/^z[a-zA-Z0-9]{90,96}$/', $address) === 1;

			case 'BLK':
				// BlackCoin activated native segwit on mainnet (March 2026);
				// blackcoin-more v26.2.0 chain params define bech32 hrp 'blk'.
				// Same witness rules as bc/ltc/dgb apply.
				return self::is_base58check($address, array("\x19", "\x55"))
					|| self::is_segwit($address, 'blk');

			case 'VRC':
				// vericoin chainparams: p2pkh 70 'V', p2sh 132 'v' (lower case
				// - a different version byte, not a case variant).
				return self::is_base58check($address, array("\x46", "\x84"));

			case 'TRX':
			case 'USDTTRX':
				// Tron is base58check with version 0x41 ('T...').
				return self::is_base58check($address, array("\x41"));

			// --- checksum verified, version byte not pinned: the checksum
			// alone kills every typo/truncation, and the anchored legacy
			// prefix pattern keeps the historical prefix requirement without
			// betting funds on a version byte we could not confirm. ---

			case 'ONION':
				// deeponion chainparams: p2pkh 31 'D', p2sh 78 'Y', bech32 hrp
				// 'dpn'. Pinning these replaces an any-version + 'D' prefix
				// rule that both rejected every P2SH address and accepted
				// versions 30 and 32 - i.e. a Dogecoin address pasted into the
				// DeepOnion field validated cleanly.
				return self::is_base58check($address, array("\x1f", "\x4e"))
					|| self::is_segwit($address, 'dpn');

			case 'POT':
				// potcoin src/base58.h: PUBKEY_ADDRESS 55 'P', SCRIPT_ADDRESS 5
				// (Bitcoin's, so it renders as '3'). Same fix as ONION: the old
				// any-version + 'P' prefix rule rejected every P2SH address
				// while accepting versions 56 and 57.
				return self::is_base58check($address, array("\x37", "\x05"));

			case 'ONT':
				return self::is_base58check($address, array())
					&& preg_match('/^A[0-9a-zA-Z]{25,35}$/', $address) === 1;

			// --- EVM-style: 0x + exactly 40 hex chars. No EIP-55 keccak
			// (out of scope; case-insensitive addresses are valid as-is). ---

			case 'ETH':
			case 'ETC':
			case 'VET':
			case 'GUSD':
				return self::is_evm($address);

			case 'BNB':
				// Beacon-chain bech32 (hrp 'bnb') or BSC 0x form.
				return self::is_bech32($address, 'bnb')
					|| self::is_evm($address);

			case 'ADA':
				// Legacy Byron base58 forms (anchored, as before) plus
				// Shelley 'addr1...' bech32, which the old pattern wrongly
				// rejected. Cardano bech32 exceeds BIP-173's 90-char cap
				// (base addresses run ~103 chars), hence the raised limit.
				return preg_match('/^(Ddz[0-9a-zA-Z]{80,120}|Ae2tdPwUPE[0-9a-zA-Z]{46,53})$/', $address) === 1
					|| self::is_bech32($address, 'addr', 120);

			// --- other schemes: exotic or non-SHA256d checksums; anchored,
			// grouped patterns only (see class comment for the why). ---

			case 'XMR':
				// Keccak checksum out of scope. Standard (95 chars, '4...'),
				// subaddress (95 chars, '8...'), integrated (106 chars).
				return preg_match('/^(4|8)[1-9A-HJ-NP-Za-km-z]{94}([1-9A-HJ-NP-Za-km-z]{11})?$/', $address) === 1;

			case 'XRP':
				// Ripple base58 uses its own alphabet ordering but the same
				// 58 characters; checksum out of scope.
				return preg_match('/^r[1-9A-HJ-NP-Za-km-z]{24,34}$/', $address) === 1;

			case 'SOL':
				return preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $address) === 1;

			case 'XLM':
				// Stellar strkey is base32 (A-Z, 2-7), 56 chars; CRC16
				// checksum out of scope.
				return preg_match('/^G[A-Z2-7]{55}$/', $address) === 1;

			case 'XTZ':
				// tz1 (Ed25519), tz2 (secp256k1), tz3 (P-256) and tz4 (BLS)
				// implicit accounts - all hold funds and are valid payment
				// destinations - + 33 base58 chars (36 total).
				return preg_match('/^tz[1234][1-9A-HJ-NP-Za-km-z]{33}$/', $address) === 1;

			case 'EOS':
				// Account names: 1-12 chars from a-z, 1-5 and '.'.
				return preg_match('/^[a-z1-5.]{1,12}$/', $address) === 1;

			case 'DCR':
				// Decred base58check uses BLAKE256, not SHA256d - running
				// our checksum would REJECT every valid address. Pattern only.
				return preg_match('/^D[0-9a-zA-Z]{31,35}$/', $address) === 1;

			case 'GRS':
				// Groestlcoin base58check uses Groestl-512 hashing, not
				// SHA256d - same reasoning as DCR. Pattern only.
				return preg_match('/^[F3][a-km-zA-HJ-NP-Z0-9]{24,42}$/', $address) === 1
					|| self::is_segwit($address, 'grs');

			case 'BCN':
				return preg_match('/^2[0-9a-zA-Z]{91,99}$/', $address) === 1;

			case 'LSK':
				return preg_match('/^[0-9a-zA-Z]{17,22}L$/', $address) === 1;

			case 'XEM':
				return preg_match('/^N[0-9a-zA-Z]{35,45}$/', $address) === 1;

			case 'WAVES':
				return preg_match('/^3[0-9a-zA-Z]{31,35}$/', $address) === 1;

			case 'MIOTA':
				return preg_match('/^[0-9a-zA-Z]{85,95}$/', $address) === 1;

			case 'APL':
				return preg_match('/^APL-[a-zA-Z0-9-]{8,42}$/', $address) === 1;
		}

		return false;
	}

	// Every id validate() has a rule for. NMM_Cryptocurrencies uses this to
	// keep its historical "unknown cryptoId" exception for anything else.
	public static function is_known($cryptoId) {
		static $known = array(
			'BTC', 'LTC', 'DOGE', 'DASH', 'DGB', 'QTUM', 'XMY', 'BTX', 'BTG',
			'BCH', 'BSV', 'BCD', 'ZEC', 'BLK', 'VRC', 'TRX', 'USDTTRX',
			'ONION', 'POT', 'ONT', 'ETH', 'ETC', 'VET', 'GUSD', 'BNB', 'ADA',
			'XMR', 'XRP', 'SOL', 'XLM', 'XTZ', 'EOS', 'DCR', 'GRS', 'BCN',
			'LSK', 'XEM', 'WAVES', 'MIOTA', 'APL',
		);

		return in_array($cryptoId, $known, true);
	}

	// Every ERC-20-style token (and each EVM chain the plugin supports)
	// shares the 0x form. Exactly 40 hex chars: nothing valid is ever 41-42
	// as the old '{40,42}' pattern implied.
	public static function is_evm($address) {
		return preg_match('/^0x[a-fA-F0-9]{40}$/', $address) === 1;
	}

	// ------------------------------------------------------------------
	// base58check
	// ------------------------------------------------------------------

	/**
	 * True when $address base58-decodes to versionprefix + 20-byte hash +
	 * valid 4-byte double-SHA256 checksum. $versionPrefixes is a list of
	 * binary version prefixes (usually one byte; ZEC uses two); an empty
	 * list means "any single-byte version" - checksum-only mode for coins
	 * whose exact version byte we could not confirm.
	 */
	private static function is_base58check($address, array $versionPrefixes) {
		$decoded = self::base58_decode($address);

		if ($decoded === null || strlen($decoded) < 5) {
			return false;
		}

		$payload = substr($decoded, 0, -4);
		$checksum = substr($decoded, -4);

		if (substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4) !== $checksum) {
			return false;
		}

		if (count($versionPrefixes) === 0) {
			// checksum-only mode still pins the standard structure:
			// 1 version byte + 20-byte hash160
			return strlen($payload) === 21;
		}

		foreach ($versionPrefixes as $prefix) {
			if (strlen($payload) === strlen($prefix) + 20
				&& strncmp($payload, $prefix, strlen($prefix)) === 0) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Base58 -> binary string, or null on any invalid character. Pure-PHP
	 * byte-carry conversion (no GMP/BCMath - see the class comment) with the
	 * standard leading-'1' => leading-0x00-byte rule that HdHelper's decoder
	 * gets wrong.
	 */
	private static function base58_decode($input) {
		$len = strlen($input);

		if ($len === 0 || $len > 128) {
			return null;
		}

		$bytes = array(); // little-endian
		for ($i = 0; $i < $len; $i++) {
			$carry = strpos(self::B58_CHARSET, $input[$i]);
			if ($carry === false) {
				return null;
			}

			for ($j = 0, $n = count($bytes); $j < $n; $j++) {
				$carry += $bytes[$j] * 58;
				$bytes[$j] = $carry & 0xff;
				$carry >>= 8;
			}
			while ($carry > 0) {
				$bytes[] = $carry & 0xff;
				$carry >>= 8;
			}
		}

		// each leading '1' encodes one leading zero byte
		for ($i = 0; $i < $len && $input[$i] === '1'; $i++) {
			$bytes[] = 0;
		}

		$out = '';
		for ($i = count($bytes) - 1; $i >= 0; $i--) {
			$out .= chr($bytes[$i]);
		}

		return $out;
	}

	// ------------------------------------------------------------------
	// bech32 / bech32m (BIP-173 / BIP-350)
	// ------------------------------------------------------------------

	/**
	 * Native segwit address check for the given hrp: charset, case rule,
	 * checksum, witness version and program-length rules. Witness v0
	 * requires the bech32 constant and a 20- or 32-byte program; v1..v16
	 * require bech32m (v1 + 32 bytes = taproot, accepted).
	 */
	private static function is_segwit($address, $hrp) {
		$decoded = self::bech32_decode($address, 90);

		if ($decoded === null || $decoded['hrp'] !== $hrp) {
			return false;
		}

		$data = $decoded['data'];
		if (count($data) < 1) {
			return false;
		}

		$version = $data[0];
		if ($version > 16) {
			return false;
		}

		// BIP-350: v0 => bech32 (constant 1), v1+ => bech32m
		$want = ($version === 0) ? 1 : self::BECH32M_CONST;
		if ($decoded['polymod'] !== $want) {
			return false;
		}

		$program = self::convert_bits(array_slice($data, 1), 5, 8, false);
		if ($program === null) {
			return false;
		}

		$programLen = count($program);
		if ($programLen < 2 || $programLen > 40) {
			return false;
		}
		if ($version === 0 && $programLen !== 20 && $programLen !== 32) {
			return false;
		}

		return true;
	}

	// Plain bech32 checksum check (constant 1) for non-segwit users of the
	// encoding (Cardano 'addr', Binance 'bnb'): no witness-program semantics.
	private static function is_bech32($address, $hrp, $maxLen = 90) {
		$decoded = self::bech32_decode($address, $maxLen);

		return $decoded !== null
			&& $decoded['hrp'] === $hrp
			&& $decoded['polymod'] === 1
			&& count($decoded['data']) >= 1;
	}

	/**
	 * Shared bech32 string decode: enforces the no-mixed-case rule, splits
	 * on the last '1', maps the data charset, and returns hrp, the data
	 * values with the 6 checksum values stripped, and the raw polymod so
	 * callers can distinguish bech32 from bech32m.
	 */
	private static function bech32_decode($address, $maxLen) {
		$len = strlen($address);

		if ($len < 8 || $len > $maxLen) {
			return null;
		}

		// BIP-173: lowercase and uppercase are both valid, mixing is not
		$lower = strtolower($address);
		if ($address !== $lower && $address !== strtoupper($address)) {
			return null;
		}

		$pos = strrpos($lower, '1');
		// hrp non-empty; data part at least 6 checksum chars
		if ($pos === false || $pos < 1 || $pos + 7 > $len) {
			return null;
		}

		$hrp = substr($lower, 0, $pos);
		for ($i = 0; $i < $pos; $i++) {
			$ord = ord($hrp[$i]);
			if ($ord < 33 || $ord > 126) {
				return null;
			}
		}

		$data = array();
		for ($i = $pos + 1; $i < $len; $i++) {
			$v = strpos(self::BECH32_CHARSET, $lower[$i]);
			if ($v === false) {
				return null;
			}
			$data[] = $v;
		}

		$polymod = self::bech32_polymod(array_merge(self::bech32_hrp_expand($hrp), $data));

		return array(
			'hrp' => $hrp,
			'data' => array_slice($data, 0, count($data) - 6),
			'polymod' => $polymod,
		);
	}

	private static function bech32_hrp_expand($hrp) {
		$expanded = array();
		$len = strlen($hrp);

		for ($i = 0; $i < $len; $i++) {
			$expanded[] = ord($hrp[$i]) >> 5;
		}
		$expanded[] = 0;
		for ($i = 0; $i < $len; $i++) {
			$expanded[] = ord($hrp[$i]) & 31;
		}

		return $expanded;
	}

	private static function bech32_polymod(array $values) {
		static $gen = array(0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3);

		$chk = 1;
		foreach ($values as $value) {
			$top = $chk >> 25;
			$chk = (($chk & 0x1ffffff) << 5) ^ $value;
			for ($i = 0; $i < 5; $i++) {
				if (($top >> $i) & 1) {
					$chk ^= $gen[$i];
				}
			}
		}

		return $chk;
	}

	// ------------------------------------------------------------------
	// CashAddr (BCH)
	// ------------------------------------------------------------------

	/**
	 * CashAddr validation. The bundled CashAddress::decodeNewAddr is NOT
	 * reused for this: with error-fixing off it returns the payload even
	 * when the checksum is wrong (it only tries corrections when fixing is
	 * on), and it lowercases input before checking, so mixed-case
	 * addresses - invalid per spec - would slip through. The polymod below
	 * matches the one in src/vendor/CashAddress.php.
	 */
	private static function is_cashaddr($address, $acceptTokenAware = false) {
		// all-lower or all-upper, never mixed (same rule as bech32)
		$lower = strtolower($address);
		if ($address !== $lower && $address !== strtoupper($address)) {
			return false;
		}

		$colon = strpos($lower, ':');
		if ($colon === false) {
			$prefix = 'bitcoincash';
			$payloadPart = $lower;
		}
		else {
			// mainnet only: 'bchtest:'/'bchreg:' addresses must not pass
			$prefix = substr($lower, 0, $colon);
			$payloadPart = substr($lower, $colon + 1);
			if ($prefix !== 'bitcoincash') {
				return false;
			}
		}

		$len = strlen($payloadPart);
		// version byte (2 chars min) + hash + 8 checksum chars
		if ($len < 10 || $len > 112) {
			return false;
		}

		$data = array();
		for ($i = 0; $i < $len; $i++) {
			$v = strpos(self::BECH32_CHARSET, $payloadPart[$i]);
			if ($v === false) {
				return false;
			}
			$data[] = $v;
		}

		// checksum input: prefix chars (& 0x1f), a zero separator, then data
		$checksumInput = array();
		for ($i = 0, $n = strlen($prefix); $i < $n; $i++) {
			$checksumInput[] = ord($prefix[$i]) & 0x1f;
		}
		$checksumInput[] = 0;
		$checksumInput = array_merge($checksumInput, $data);

		if (self::cashaddr_polymod($checksumInput) !== 0) {
			return false;
		}

		$payload = self::convert_bits(array_slice($data, 0, count($data) - 8), 5, 8, false);
		if ($payload === null || count($payload) < 1) {
			return false;
		}

		$versionByte = $payload[0];
		if (($versionByte & 0x80) !== 0) {
			return false;
		}

		// type bits: 0 = P2PKH, 1 = P2SH; 2 and 3 are their token-aware
		// counterparts standardized by the CashTokens upgrade - valid BCH
		// payment destinations, so BCH opts in via $acceptTokenAware. BSV
		// forked before CashTokens and never adopted them, so it does not.
		$type = ($versionByte >> 3) & 0x0f;
		if ($type > ($acceptTokenAware ? 3 : 1)) {
			return false;
		}

		// size bits -> hash length in bytes, per the CashAddr spec
		static $hashSizes = array(20, 24, 28, 32, 40, 48, 56, 64);
		$hashLen = $hashSizes[$versionByte & 0x07];

		return count($payload) === 1 + $hashLen;
	}

	// 40-bit BCH code over GF(32); needs 64-bit PHP ints, which the plugin
	// already requires (the bundled CashAddress refuses to run on 32-bit).
	private static function cashaddr_polymod(array $values) {
		static $gen = array(0x98f2bc8e61, 0x79b76d99e2, 0xf33e5fb3c4, 0xae2eabe2a8, 0x1e4f43e470);

		$chk = 1;
		foreach ($values as $value) {
			$top = $chk >> 35;
			$chk = (($chk & 0x07ffffffff) << 5) ^ $value;
			for ($i = 0; $i < 5; $i++) {
				if (($top >> $i) & 1) {
					$chk ^= $gen[$i];
				}
			}
		}

		return $chk ^ 1;
	}

	// ------------------------------------------------------------------
	// shared bit regrouping (BIP-173 convertbits, strict: no padding)
	// ------------------------------------------------------------------

	private static function convert_bits(array $data, $fromBits, $toBits, $pad) {
		$acc = 0;
		$bits = 0;
		$ret = array();
		$maxv = (1 << $toBits) - 1;

		foreach ($data as $value) {
			if ($value < 0 || ($value >> $fromBits) !== 0) {
				return null;
			}
			$acc = (($acc << $fromBits) | $value) & ((1 << ($fromBits + $toBits - 1)) - 1);
			$bits += $fromBits;
			while ($bits >= $toBits) {
				$bits -= $toBits;
				$ret[] = ($acc >> $bits) & $maxv;
			}
		}

		if ($pad) {
			if ($bits > 0) {
				$ret[] = ($acc << ($toBits - $bits)) & $maxv;
			}
		}
		else if ($bits >= $fromBits || ((($acc << ($toBits - $bits))) & $maxv)) {
			return null;
		}

		return $ret;
	}

	// ------------------------------------------------------------------
	// one-shot re-validation of stored addresses
	// ------------------------------------------------------------------

	/**
	 * Stored addresses saved under the old sloppy validation are NOT
	 * deleted or rejected at runtime - a merchant may be depending on one
	 * mid-flight, and yanking it would change payment behaviour behind
	 * their back. Instead this one-shot pass (guarded by an option flag,
	 * same pattern as nmm_legacy_qr_files_cleaned) re-validates everything
	 * stored and records the failures; NMM_Admin surfaces them in a
	 * dismissible notice telling the merchant exactly which coin/address
	 * to re-check.
	 */
	public static function flag_invalid_stored_addresses() {
		if (get_option(self::REVALIDATED_FLAG)) {
			return;
		}

		$values = get_option(NMM_REDUX_ID, array());
		$invalid = array();

		if (is_array($values) && count($values) > 0) {
			$settings = new NMM_Settings($values);

			foreach (NMM_Cryptocurrencies::get() as $crypto) {
				$cryptoId = $crypto->get_id();

				foreach ($settings->get_addresses($cryptoId) as $address) {
					$address = trim((string) $address);

					if ($address === '') {
						continue;
					}

					if (!NMM_Cryptocurrencies::is_valid_wallet_address($cryptoId, $address)) {
						$invalid[] = array(
							'crypto' => $crypto->get_name() . ' (' . $cryptoId . ')',
							'address' => $address,
						);
					}
				}
			}
		}

		if (count($invalid) > 0) {
			update_option(self::INVALID_STORED_OPTION, $invalid);
			NMM_Util::log(__FILE__, __LINE__, count($invalid) . ' stored wallet address(es) failed the new checksum validation; flagged for the merchant.', 'warning');
		}

		update_option(self::REVALIDATED_FLAG, 1);
	}
}

?>
