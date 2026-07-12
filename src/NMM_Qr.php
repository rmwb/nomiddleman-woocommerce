<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders payment QR codes entirely in memory: inline SVG for pages, PNG
 * bytes embedded as CID attachments for emails. No QR file is ever written
 * to disk (the old approach left world-readable tmp{orderId}_qrcode.png
 * files - one per order - in the plugin directory).
 */
class NMM_Qr {

	private static $emailImages = array();
	private static $mailerHooked = false;

	// decimal coin amount -> integer base units as a string (no float rounding)
	public static function to_base_units($amountDecimal, $precision) {
		$amount = trim((string) $amountDecimal);

		if (!is_numeric($amount)) {
			return '0';
		}

		$precision = (int) $precision;

		// bcmath when present (gmp-only hosts are supported too); otherwise a
		// pure-string scaling that is correct with neither extension and never
		// loses precision to float rounding.
		if (function_exists('bcmul')) {
			$units = bcmul($amount, bcpow('10', (string) $precision, 0), 0);
			return ltrim($units, '+');
		}

		return self::decimal_to_base_units_string($amount, $precision);
	}

	// Scale a decimal string by 10^precision using string ops only, so it is
	// correct with no bcmath/gmp and never loses precision to float rounding.
	private static function decimal_to_base_units_string($amount, $precision) {
		$negative = false;
		if (strpos($amount, '-') === 0) {
			$negative = true;
			$amount = substr($amount, 1);
		}

		$parts = explode('.', $amount, 2);
		$intPart = $parts[0] === '' ? '0' : $parts[0];
		$fracPart = isset($parts[1]) ? $parts[1] : '';

		// Pad or truncate the fractional part to exactly $precision digits.
		if (strlen($fracPart) < $precision) {
			$fracPart = str_pad($fracPart, $precision, '0');
		}
		else {
			$fracPart = substr($fracPart, 0, $precision);
		}

		$digits = ltrim($intPart . $fracPart, '0');
		if ($digits === '') {
			$digits = '0';
		}

		return ($negative && $digits !== '0') ? '-' . $digits : $digits;
	}

	/**
	 * Wallet-scannable payment URI for a coin:
	 * - EVM native (ETH):   EIP-681  ethereum:<to>@<chain>?value=<wei>
	 * - EVM tokens:         EIP-681  ethereum:<contract>@<chain>/transfer?address=<to>&uint256=<units>
	 * - SOL:                Solana Pay  solana:<to>?amount=<decimal>
	 * - XMR:                monero:<to>?tx_amount=<decimal>
	 * - everything else:    BIP-21 style  <name>:<to>?amount=<decimal>
	 */
	public static function payment_uri($crypto, $address, $amountDecimal) {
		$cryptoId = $crypto->get_id();

		if ($cryptoId === 'ETH') {
			return 'ethereum:' . $address . '@1?value=' . self::to_base_units($amountDecimal, $crypto->get_round_precision());
		}

		$contract = $crypto->get_erc20_contract();
		if (is_string($contract) && $contract !== '') {
			$chainId = NMM_Cryptocurrencies::evm_chain_id($cryptoId);

			return 'ethereum:' . $contract . '@' . $chainId . '/transfer?address=' . $address
				. '&uint256=' . self::to_base_units($amountDecimal, $crypto->get_round_precision());
		}

		if ($cryptoId === 'SOL') {
			return 'solana:' . $address . '?amount=' . $amountDecimal;
		}

		if ($cryptoId === 'XMR') {
			return 'monero:' . $address . '?tx_amount=' . $amountDecimal;
		}

		if ($cryptoId === 'USDTTRX') {
			// no cross-wallet URI standard on Tron; neutral scheme, address is copyable text
			return 'tether:' . $address . '?amount=' . $amountDecimal;
		}

		$prefix = strtolower(str_replace(' ', '', $crypto->get_name()));

		return $prefix . ':' . $address . '?amount=' . $amountDecimal;
	}

	// matrix of '1'/'0' strings from the bundled encoder, highest error correction
	private static function matrix($data) {
		return QRcode::text($data, false, QR_ECLEVEL_H);
	}

	public static function svg($data, $sizePx = 200) {
		$matrix = self::matrix($data);

		if (!is_array($matrix) || count($matrix) === 0) {
			return '';
		}

		$quiet = 4; // quiet-zone modules around the symbol
		$total = count($matrix) + 2 * $quiet;

		$rects = '';
		foreach ($matrix as $y => $row) {
			$x = 0;
			$len = strlen($row);
			while ($x < $len) {
				if ($row[$x] === '1') {
					// merge horizontal runs to keep the markup small
					$run = 1;
					while ($x + $run < $len && $row[$x + $run] === '1') {
						$run++;
					}
					$rects .= sprintf('<rect x="%d" y="%d" width="%d" height="1"/>', $x + $quiet, $y + $quiet, $run);
					$x += $run;
				}
				else {
					$x++;
				}
			}
		}

		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" width="%2$d" height="%2$d" shape-rendering="crispEdges" role="img" aria-label="Payment QR code"><rect width="%1$d" height="%1$d" fill="#ffffff"/><g fill="#000000">%3$s</g></svg>',
			$total,
			(int) $sizePx,
			$rects
		);
	}

	public static function png_bytes($data, $pixelsPerModule = 4) {
		if (!function_exists('imagecreate')) {
			return '';
		}

		$matrix = self::matrix($data);

		if (!is_array($matrix) || count($matrix) === 0) {
			return '';
		}

		$quiet = 4;
		$sizePx = (count($matrix) + 2 * $quiet) * $pixelsPerModule;

		$im = imagecreate($sizePx, $sizePx);
		$white = imagecolorallocate($im, 255, 255, 255);
		$black = imagecolorallocate($im, 0, 0, 0);
		imagefill($im, 0, 0, $white);

		foreach ($matrix as $y => $row) {
			for ($x = 0, $len = strlen($row); $x < $len; $x++) {
				if ($row[$x] === '1') {
					imagefilledrectangle(
						$im,
						($x + $quiet) * $pixelsPerModule,
						($y + $quiet) * $pixelsPerModule,
						($x + $quiet + 1) * $pixelsPerModule - 1,
						($y + $quiet + 1) * $pixelsPerModule - 1,
						$black
					);
				}
			}
		}

		ob_start();
		imagepng($im);

		return ob_get_clean();
	}

	/**
	 * Stash PNG bytes for the email currently being built; PHPMailer attaches
	 * them as an inline (CID) image when the email is actually sent. Returns
	 * the content id to use in the img tag, or '' if no image could be built
	 * (the email must always carry the address and amount as text regardless -
	 * API-based mail plugins bypass PHPMailer and never see the attachment).
	 */
	public static function stash_email_image($orderId, $data) {
		$bytes = self::png_bytes($data);

		if ($bytes === '') {
			return '';
		}

		$cid = 'nmm-qr-' . $orderId;
		self::$emailImages[$cid] = $bytes;

		if (!self::$mailerHooked) {
			add_action('phpmailer_init', array(__CLASS__, 'attach_stashed_images'));
			self::$mailerHooked = true;
		}

		return $cid;
	}

	public static function attach_stashed_images($phpmailer) {
		foreach (self::$emailImages as $cid => $bytes) {
			$phpmailer->addStringEmbeddedImage($bytes, $cid, $cid . '.png', 'base64', 'image/png');
		}

		self::$emailImages = array();
	}
}
