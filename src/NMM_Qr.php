<?php

/**
 * Renders payment QR codes entirely in memory: inline SVG for pages, PNG
 * bytes embedded as CID attachments for emails. No QR file is ever written
 * to disk (the old approach left world-readable tmp{orderId}_qrcode.png
 * files - one per order - in the plugin directory).
 */
class NMM_Qr {

	private static $emailImages = array();
	private static $mailerHooked = false;

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
