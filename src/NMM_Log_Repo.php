<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only reader for the operational log entries this plugin writes through
 * NMM_Util::log() (WooCommerce logger, source "nomiddleman").
 *
 * WooCommerce exposes a writer (wc_get_logger()) but no reader, so the status
 * screen has to go to the storage directly. That is only possible when the
 * merchant's configured log handler is the DATABASE one: the file handler
 * writes rotated, hash-suffixed files under wp-content/uploads, which this
 * plugin has no business reading. Everything here is therefore gated on
 * database logging actually being in use, and callers are expected to say
 * "logs live in files, open WooCommerce > Status > Logs" rather than render an
 * empty box when it is not.
 *
 * The table belongs to WooCommerce, so it is never created, altered or written
 * to here - only SELECTed, and only after its existence has been confirmed.
 */
class NMM_Log_Repo {

	// Source string NMM_Util::log() tags every entry with.
	const LOG_SOURCE = 'nomiddleman';

	// WC_Log_Levels severity for 'warning'. Hard-coded fallback for the (older
	// or partially loaded) WooCommerce where the class is not available. Note
	// this is WooCommerce's own scale (debug 100 ... emergency 800), not the
	// PSR-3 numbers - warning is 400 there, and it has been since WC 3.0.
	const WARNING_SEVERITY = 400;

	private $tableName;

	public function __construct() {
		global $wpdb;

		$this->tableName = $wpdb->prefix . 'woocommerce_log';
	}

	/**
	 * Is WooCommerce storing log entries in the database, where they can be
	 * read back? False when WooCommerce is absent, when logging is switched
	 * off, when the merchant uses a file handler, or when the log table does
	 * not exist on this site.
	 *
	 * @return bool
	 */
	public function database_logging_available() {
		global $wpdb;

		if (!function_exists('wc_get_logger') || !class_exists('WC_Log_Handler_DB')) {
			return false;
		}

		if (!$this->database_handler_selected()) {
			return false;
		}

		return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->tableName)) === $this->tableName;
	}

	/**
	 * Is the database handler the one WooCommerce would log through?
	 *
	 * LoggingUtil is WooCommerce's public accessor for the logging settings
	 * (namespace Automattic\WooCommerce\Utilities), added in WC 8.6. On an
	 * older WooCommerce there is no way to ask, so answer "no" and let the
	 * caller point the merchant at the Logs screen - a wrong "yes" would show
	 * a permanently empty list on every file-logging store.
	 *
	 * @return bool
	 */
	private function database_handler_selected() {
		if (!class_exists('\Automattic\WooCommerce\Utilities\LoggingUtil')) {
			return false;
		}

		if (!\Automattic\WooCommerce\Utilities\LoggingUtil::logging_is_enabled()) {
			return false;
		}

		return ltrim(\Automattic\WooCommerce\Utilities\LoggingUtil::get_default_handler(), '\\') === 'WC_Log_Handler_DB';
	}

	/**
	 * The most recent warning-or-worse entries this plugin logged, newest
	 * first. Debug and info entries are deliberately excluded: they are tracing
	 * noise, and the status screen exists to surface things that may need
	 * intervention.
	 *
	 * Returns a list of ['timestamp' => string, 'level' => int,
	 * 'message' => string]. Callers MUST escape every value before output - a
	 * log message is arbitrary text, including whatever a third-party API
	 * echoed back.
	 *
	 * @param int $limit Maximum rows to return.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_recent_warnings($limit = 10) {
		global $wpdb;

		$limit = max(1, min(50, (int) $limit));
		$severity = class_exists('WC_Log_Levels') ? (int) WC_Log_Levels::get_level_severity('warning') : self::WARNING_SEVERITY;

		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT `timestamp`, `level`, `message`
			 FROM `$this->tableName`
			 WHERE `source` = %s
			 AND `level` >= %d
			 ORDER BY `timestamp` DESC, `log_id` DESC
			 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the only interpolation is $this->tableName ($wpdb->prefix + a fixed WooCommerce table name); every value is bound through $wpdb->prepare().
			self::LOG_SOURCE, $severity, $limit
		), ARRAY_A);

		return is_array($rows) ? $rows : array();
	}
}

?>
