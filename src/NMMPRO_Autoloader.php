<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Autoloader for the plugin's own NMMPRO_-prefixed classes.
 *
 * The bootstrap used to pull every class in with
 * require_once(plugin_basename('src/...')). plugin_basename() returns a
 * RELATIVE path unchanged, so those includes were resolved by PHP's
 * include_path / calling-directory fallback rather than by an absolute path.
 * That happens to work, but it is fragile under open_basedir, under a
 * symlinked plugin directory, and under an unusual include_path - and it costs
 * a handful of failed stat() calls per include on every single request.
 *
 * Everything in src/ that is a single NMMPRO_-prefixed class living in a file of
 * the same name is resolved here, on first use, from an absolute path.
 *
 * DELIBERATELY NOT AUTOLOADED (the bootstrap keeps explicit requires for
 * these, in their original positions - see the comments there):
 *
 *  - src/vendor/*: the bundled libraries do not use the NMMPRO_ prefix, several
 *    files define many classes at once or a class whose name does not match
 *    the file name (phpqrcode.php defines QRinput/QRcode/QRencode/..., and
 *    CashAddress.php defines the namespaced \CashAddress\CashAddress), and
 *    HdHelper.php has load-time side effects the sibling classes depend on
 *    (it define()s USE_EXT, which CurveFp and NumberTheory read). None of that
 *    can be mapped from a class name to a file, so it stays explicit.
 *  - src/NMMPRO_Hooks.php and src/NMMPRO_Cron.php: plain functions, no class.
 *  - src/NMMPRO_Admin.php: calls NMMPRO_Admin::init() at file scope, so nothing
 *    would ever reference the class and trigger an autoload.
 *  - NMMPRO_Gateway / NMMPRO_Blocks_Support (see self::$notAutoloadable).
 */
class NMMPRO_Autoloader {

	/** Absolute path to src/, with a trailing slash. Set by register(). */
	private static $srcDir = '';

	/**
	 * Classes that must NOT be reachable through the autoloader.
	 *
	 * Both extend a class that only exists once WooCommerce (or the
	 * WooCommerce Blocks package) has loaded, so their files are required
	 * behind the class_exists() guards already in the bootstrap. Left
	 * autoloadable, a stray class_exists('NMMPRO_Gateway') from another plugin on
	 * a request where WooCommerce is not active would load the file and fatal
	 * on the missing parent - a failure mode the eager, guarded requires never
	 * had. Keeping them out preserves exactly today's behaviour.
	 */
	private static $notAutoloadable = array(
		'nmmpro_gateway'        => true,
		'nmmpro_blocks_support' => true,
	);

	/**
	 * @param string $srcDir Absolute path to the plugin's src/ directory.
	 */
	public static function register($srcDir) {
		self::$srcDir = rtrim($srcDir, '/\\') . '/';
		spl_autoload_register(array(__CLASS__, 'load'));
	}

	public static function load($class) {
		// Every class_exists()/new in the request reaches this callback, so
		// bail on anything that is not ours before touching the filesystem.
		if (stripos($class, 'NMMPRO_') !== 0) {
			return;
		}

		// PHP class names are CASE-INSENSITIVE, so class_exists('NMMPRO_GATEWAY')
		// reaches here for the same class as NMMPRO_Gateway. Compare the deny-list
		// case-insensitively or the guard is trivially side-stepped: on a
		// case-insensitive filesystem the mis-cased name still resolves to
		// NMMPRO_Gateway.php, which then fatals on its missing WooCommerce parent
		// exactly as the deny-list exists to prevent.
		if (isset(self::$notAutoloadable[strtolower($class)])) {
			return;
		}

		// Defensive: only ever turn a bare NMMPRO_ identifier into a file name, so
		// no namespace separator or directory traversal can reach the path.
		if (!preg_match('/^NMMPRO_[A-Za-z0-9_]+$/i', $class)) {
			return;
		}

		$file = self::$srcDir . $class . '.php';

		if (is_readable($file)) {
			require_once $file;
		}
	}
}
