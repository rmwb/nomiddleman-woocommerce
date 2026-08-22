<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only merchant status screen: "is my crypto payment setup working right
 * now?" on one page.
 *
 * Everything the plugin knows about its own health is already in the database -
 * which coins are enabled and in which mode, whether each has an address or an
 * MPK to hand out, how many orders are still waiting to be paid and how old the
 * oldest is, when the background job last ran and when it runs next - but until
 * now the only way to see any of it was to read logs or query tables by hand.
 *
 * Deliberate non-goals, because this screen is the one place a merchant lands
 * when something looks wrong and it must never make the situation worse:
 *
 *  - It NEVER writes. No option is updated, no row is touched, no cron is
 *    rescheduled, no address is allocated. Reload it as often as you like.
 *  - It NEVER contacts an external service. No explorer, no exchange, no rate
 *    API. A page that hangs on a rate-limited explorer is useless precisely
 *    when the explorers are the problem.
 *  - It builds no SQL of its own: the unpaid backlog comes from
 *    NMM_Payment_Repo and the log entries from NMM_Log_Repo.
 *  - No AJAX, no new tables, no new options.
 */
class NMM_Dashboard {

	// Submenu slug. Prefixed like every other stored/registered name here.
	const PAGE_SLUG = 'nmmpro_status';

	// Same capability as the settings page this hangs under. Anyone who can see
	// the backlog and the wallet configuration can see everything the settings
	// page shows anyway.
	const CAPABILITY = 'manage_options';

	// The background job is nominally a one-minute schedule. Past this, say so:
	// a stalled cron means payments stop being verified and orders age out
	// unpaid, which is the single most damaging silent failure this plugin has.
	const STALE_RUN_SECONDS = 900;

	// How many recent warnings to list.
	const LOG_LIMIT = 10;

	/**
	 * Register the submenu under the plugin's existing settings page. Called
	 * from NMM_Admin::register_menu() so both menu entries are added on the one
	 * admin_menu pass, in order.
	 */
	public static function register_menu() {
		add_submenu_page(
			NMM_REDUX_SLUG,
			__('Nomiddleman Crypto Status', 'nomiddleman-crypto-payments-for-woocommerce'),
			__('Status', 'nomiddleman-crypto-payments-for-woocommerce'),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array(__CLASS__, 'render_page')
		);
	}

	/**
	 * Render the whole screen. WordPress already gates the menu entry on the
	 * capability, but the callback is reachable by URL for anyone who can load
	 * admin.php at all, so it is re-checked here - and again in every section,
	 * since each one reads merchant configuration or order data.
	 */
	public static function render_page() {
		if (!current_user_can(self::CAPABILITY)) {
			return;
		}

		$settings = new NMM_Settings(get_option(NMM_REDUX_ID, array()));
		$paymentRepo = new NMM_Payment_Repo();
		$backlog = $paymentRepo->unpaid_backlog_by_crypto();
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Nomiddleman Crypto Status', 'nomiddleman-crypto-payments-for-woocommerce'); ?></h1>
			<p class="description">
				<?php esc_html_e('A read-only view of how payment acceptance is currently configured and running. Nothing on this page changes any setting, contacts any blockchain service, or writes to your store.', 'nomiddleman-crypto-payments-for-woocommerce'); ?>
				<a href="<?php echo esc_url(admin_url('admin.php?page=' . NMM_REDUX_SLUG)); ?>"><?php esc_html_e('Open settings', 'nomiddleman-crypto-payments-for-woocommerce'); ?></a>
			</p>

			<?php
			self::render_cryptocurrency_section($settings, $backlog);
			self::render_unconfigured_backlog_section($settings, $backlog);
			self::render_background_job_section();
			self::render_log_section();
			?>
		</div>
		<?php
	}

	/**
	 * Per-coin configuration and backlog, for the coins the merchant has
	 * enabled. Mode, whether there is anything to hand a customer, whether the
	 * mode can actually be verified for that coin, and how much is outstanding.
	 */
	private static function render_cryptocurrency_section($settings, $backlog) {
		if (!current_user_can(self::CAPABILITY)) {
			return;
		}

		$coveredAt = get_option('nmm_autopay_scan_covered_at', array());
		if (!is_array($coveredAt)) {
			$coveredAt = array();
		}

		$enabled = array();
		foreach (NMM_Cryptocurrencies::get_alpha() as $crypto) {
			if ($settings->crypto_selected($crypto->get_id())) {
				$enabled[] = $crypto;
			}
		}
		?>
		<h2><?php esc_html_e('Cryptocurrencies', 'nomiddleman-crypto-payments-for-woocommerce'); ?></h2>
		<?php if (count($enabled) === 0) : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e('No cryptocurrencies are enabled, so the payment method cannot be offered at checkout. Enable at least one on the settings page.', 'nomiddleman-crypto-payments-for-woocommerce'); ?></p>
			</div>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e('Cryptocurrency', 'nomiddleman-crypto-payments-for-woocommerce'); ?></th>
					<th scope="col"><?php esc_html_e('Mode', 'nomiddleman-crypto-payments-for-woocommerce'); ?></th>
					<th scope="col"><?php esc_html_e('Address / MPK configured', 'nomiddleman-crypto-payments-for-woocommerce'); ?></th>
					<th scope="col"><?php esc_html_e('Autopay verifiable', 'nomiddleman-crypto-payments-for-woocommerce'); ?></th>
					<th scope="col"><?php esc_html_e('Privacy verifiable', 'nomiddleman-crypto-payments-for-woocommerce'); ?></th>
					<th scope="col"><?php esc_html_e('Unpaid orders', 'nomiddleman-crypto-payments-for-woocommerce'); ?></th>
					<th scope="col"><?php esc_html_e('Oldest unpaid', 'nomiddleman-crypto-payments-for-woocommerce'); ?></th>
					<th scope="col"><?php esc_html_e('Last complete Autopay sweep', 'nomiddleman-crypto-payments-for-woocommerce'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($enabled as $crypto) :
					$cid = $crypto->get_id();
					$unpaidCount = isset($backlog[$cid]) ? $backlog[$cid]['unpaid_count'] : 0;
					$oldest = isset($backlog[$cid]) ? $backlog[$cid]['oldest_ordered_at'] : 0;
					$sweptAt = isset($coveredAt[$cid]) ? (int) $coveredAt[$cid] : 0;
					?>
					<tr>
						<td><?php echo esc_html($crypto->get_name() . ' (' . $cid . ')'); ?></td>
						<td><?php echo esc_html(self::mode_label($settings, $cid)); ?></td>
						<td><?php echo esc_html(self::credential_label($settings, $cid)); ?></td>
						<td><?php echo esc_html(self::yes_no(NMM_Cryptocurrencies::autopay_verifiable($cid))); ?></td>
						<td><?php echo esc_html(self::yes_no(NMM_Cryptocurrencies::hd_verifiable($cid))); ?></td>
						<td><?php echo esc_html(number_format_i18n($unpaidCount)); ?></td>
						<td><?php echo esc_html(self::format_time($oldest)); ?></td>
						<td><?php echo esc_html($settings->autopay_enabled($cid) ? self::format_time($sweptAt) : self::not_applicable()); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php esc_html_e('"Autopay verifiable" and "Privacy verifiable" say whether a working public API still exists for that coin in that mode. A coin configured in a mode it cannot verify will never confirm an order automatically.', 'nomiddleman-crypto-payments-for-woocommerce'); ?>
		</p>
		<?php
	}

	/**
	 * Unpaid rows for coins that are NOT currently enabled. These exist after a
	 * coin is switched off with orders still outstanding, and they are easy to
	 * miss entirely: no enabled-coin row mentions them, yet a customer may
	 * still pay one of those addresses.
	 */
	private static function render_unconfigured_backlog_section($settings, $backlog) {
		if (!current_user_can(self::CAPABILITY)) {
			return;
		}

		$orphans = array();
		foreach ($backlog as $cryptoId => $row) {
			if (!$settings->crypto_selected($cryptoId)) {
				$orphans[$cryptoId] = $row;
			}
		}

		if (count($orphans) === 0) {
			return;
		}
		?>
		<h2><?php esc_html_e('Unpaid orders for cryptocurrencies that are no longer enabled', 'nomiddleman-crypto-payments-for-woocommerce'); ?></h2>
		<div class="notice notice-warning inline">
			<p><?php esc_html_e('These orders were placed while the cryptocurrency was enabled and are still waiting to be paid. They are no longer being checked for payment, so any funds sent to their addresses will not credit the order automatically.', 'nomiddleman-crypto-payments-for-woocommerce'); ?></p>
		</div>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e('Cryptocurrency', 'nomiddleman-crypto-payments-for-woocommerce'); ?></th>
					<th scope="col"><?php esc_html_e('Unpaid orders', 'nomiddleman-crypto-payments-for-woocommerce'); ?></th>
					<th scope="col"><?php esc_html_e('Oldest unpaid', 'nomiddleman-crypto-payments-for-woocommerce'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($orphans as $cryptoId => $row) : ?>
					<tr>
						<td><?php echo esc_html($cryptoId); ?></td>
						<td><?php echo esc_html(number_format_i18n($row['unpaid_count'])); ?></td>
						<td><?php echo esc_html(self::format_time($row['oldest_ordered_at'])); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Background job health. Payment verification, address buffering and order
	 * expiry all happen on the one-minute NMM_cron_hook schedule, so if that is
	 * not running nothing else on this page matters.
	 */
	private static function render_background_job_section() {
		if (!current_user_can(self::CAPABILITY)) {
			return;
		}

		$lastRun = (int) get_option('nmm_autopay_scan_last_run', 0);
		$sweepStart = (int) get_option('nmm_autopay_scan_sweep_start', 0);
		$nextRun = wp_next_scheduled('NMM_cron_hook');
		$nextRun = $nextRun ? (int) $nextRun : 0;

		$retrySet = get_option('nmm_autopay_scan_retry', array());
		$retryCount = is_array($retrySet) ? count($retrySet) : 0;

		$wpCronDisabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
		$stale = ($lastRun > 0 && (time() - $lastRun) > self::STALE_RUN_SECONDS);
		?>
		<h2><?php esc_html_e('Background job', 'nomiddleman-crypto-payments-for-woocommerce'); ?></h2>

		<?php if ($nextRun === 0) : ?>
			<div class="notice notice-error inline">
				<p><?php esc_html_e('The background job is not scheduled. Payments will not be verified and unpaid orders will not expire. Deactivating and reactivating the plugin re-creates the schedule.', 'nomiddleman-crypto-payments-for-woocommerce'); ?></p>
			</div>
		<?php endif; ?>

		<?php if ($lastRun === 0) : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e('The background job has not recorded a run yet. That is expected on a new install until the first scheduled run happens.', 'nomiddleman-crypto-payments-for-woocommerce'); ?></p>
			</div>
		<?php elseif ($stale) : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e('The background job has not run recently. It is meant to run every minute; while it is stalled, payments are not being verified.', 'nomiddleman-crypto-payments-for-woocommerce'); ?></p>
			</div>
		<?php endif; ?>

		<table class="widefat striped">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e('Last run', 'nomiddleman-crypto-payments-for-woocommerce'); ?></th>
					<td><?php echo esc_html(self::format_time($lastRun)); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e('Next scheduled run', 'nomiddleman-crypto-payments-for-woocommerce'); ?></th>
					<td><?php echo esc_html($nextRun > 0 ? self::format_time($nextRun) : __('Not scheduled', 'nomiddleman-crypto-payments-for-woocommerce')); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e('Current Autopay sweep started', 'nomiddleman-crypto-payments-for-woocommerce'); ?></th>
					<td><?php echo esc_html(self::format_time($sweepStart)); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e('Addresses queued for retry', 'nomiddleman-crypto-payments-for-woocommerce'); ?></th>
					<td>
						<?php echo esc_html(number_format_i18n($retryCount)); ?>
						<p class="description"><?php esc_html_e('Addresses whose last blockchain lookup failed. They are re-checked ahead of the normal sweep, so a small number that clears on its own is routine; a number that stays high points at a rate-limited or unreachable API.', 'nomiddleman-crypto-payments-for-woocommerce'); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e('WP-Cron', 'nomiddleman-crypto-payments-for-woocommerce'); ?></th>
					<td>
						<?php echo esc_html($wpCronDisabled
							? __('Disabled by DISABLE_WP_CRON', 'nomiddleman-crypto-payments-for-woocommerce')
							: __('Enabled', 'nomiddleman-crypto-payments-for-woocommerce')); ?>
						<?php if ($wpCronDisabled) : ?>
							<p class="description"><?php esc_html_e('WordPress will not run scheduled jobs on page loads. That is fine as long as a real system cron calls wp-cron.php at least once a minute - check that it does, because nothing else verifies payments.', 'nomiddleman-crypto-payments-for-woocommerce'); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Recent operational warnings the plugin logged. Only readable when
	 * WooCommerce is storing logs in the database; with file logging the
	 * entries live in rotated, hash-suffixed files that this plugin has no
	 * business reading, so say where they are instead of showing an empty box.
	 */
	private static function render_log_section() {
		if (!current_user_can(self::CAPABILITY)) {
			return;
		}
		?>
		<h2><?php esc_html_e('Recent warnings', 'nomiddleman-crypto-payments-for-woocommerce'); ?></h2>
		<?php
		if (!function_exists('wc_get_logger')) {
			?>
			<p><?php esc_html_e('WooCommerce logging is not available on this site, so there is nothing to show here.', 'nomiddleman-crypto-payments-for-woocommerce'); ?></p>
			<?php
			return;
		}

		$logRepo = new NMM_Log_Repo();

		if (!$logRepo->database_logging_available()) {
			?>
			<p>
				<?php esc_html_e('WooCommerce is not storing log entries in the database on this site, so they cannot be listed here. Open WooCommerce > Status > Logs and choose the "nomiddleman" source to read them.', 'nomiddleman-crypto-payments-for-woocommerce'); ?>
			</p>
			<?php
			return;
		}

		$entries = $logRepo->get_recent_warnings(self::LOG_LIMIT);

		if (count($entries) === 0) {
			?>
			<p><?php esc_html_e('No warnings or errors have been logged. Routine debug messages are not shown here.', 'nomiddleman-crypto-payments-for-woocommerce'); ?></p>
			<?php
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e('Time', 'nomiddleman-crypto-payments-for-woocommerce'); ?></th>
					<th scope="col"><?php esc_html_e('Level', 'nomiddleman-crypto-payments-for-woocommerce'); ?></th>
					<th scope="col"><?php esc_html_e('Message', 'nomiddleman-crypto-payments-for-woocommerce'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($entries as $entry) : ?>
					<tr>
						<td><?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $entry['timestamp'])); ?></td>
						<td><?php echo esc_html(self::level_label($entry['level'])); ?></td>
						<td><?php echo esc_html($entry['message']); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// Human name for the stored mode value, including the "nothing chosen yet"
	// case - a selected coin with no mode is offered at checkout by nothing, so
	// it must not read as a working configuration.
	private static function mode_label($settings, $cryptoId) {
		if ($settings->basic_enabled($cryptoId)) {
			return __('Classic', 'nomiddleman-crypto-payments-for-woocommerce');
		}
		if ($settings->autopay_enabled($cryptoId)) {
			return __('Autopay', 'nomiddleman-crypto-payments-for-woocommerce');
		}
		if ($settings->hd_enabled($cryptoId)) {
			return __('Privacy', 'nomiddleman-crypto-payments-for-woocommerce');
		}

		return __('Not set', 'nomiddleman-crypto-payments-for-woocommerce');
	}

	/**
	 * Whether the coin has something to hand a customer: an MPK in Privacy
	 * Mode, at least one wallet address otherwise. Only the presence of a value
	 * is reported - never the value itself, which is why this page shows no
	 * addresses and no extended public key.
	 */
	private static function credential_label($settings, $cryptoId) {
		if ($settings->hd_enabled($cryptoId)) {
			return $settings->get_mpk($cryptoId) !== ''
				? __('Master public key set', 'nomiddleman-crypto-payments-for-woocommerce')
				: __('No master public key', 'nomiddleman-crypto-payments-for-woocommerce');
		}

		$configured = 0;
		foreach ($settings->get_addresses($cryptoId) as $address) {
			if (is_string($address) && trim($address) !== '') {
				$configured++;
			}
		}

		if ($configured === 0) {
			return __('No wallet address', 'nomiddleman-crypto-payments-for-woocommerce');
		}

		/* translators: %s: number of configured wallet addresses */
		return sprintf(_n('%s wallet address', '%s wallet addresses', $configured, 'nomiddleman-crypto-payments-for-woocommerce'), number_format_i18n($configured));
	}

	private static function yes_no($value) {
		return $value
			? __('Yes', 'nomiddleman-crypto-payments-for-woocommerce')
			: __('No', 'nomiddleman-crypto-payments-for-woocommerce');
	}

	private static function not_applicable() {
		return __('Not applicable', 'nomiddleman-crypto-payments-for-woocommerce');
	}

	/**
	 * A unix timestamp in the site's timezone and date format, with a relative
	 * age after it ("2 hours ago") - the age is what actually answers "is this
	 * stuck?", and the absolute time is what a merchant quotes in a support
	 * thread. Zero means "never recorded", which is a real state here rather
	 * than a bug (a job that has not run, a coin with no unpaid orders).
	 */
	private static function format_time($timestamp) {
		$timestamp = (int) $timestamp;

		if ($timestamp <= 0) {
			return __('Never', 'nomiddleman-crypto-payments-for-woocommerce');
		}

		$formatted = wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
		$now = time();

		if ($timestamp > $now) {
			/* translators: 1: formatted date and time, 2: human readable time difference, e.g. "5 mins" */
			return sprintf(__('%1$s (in %2$s)', 'nomiddleman-crypto-payments-for-woocommerce'), $formatted, human_time_diff($now, $timestamp));
		}

		/* translators: 1: formatted date and time, 2: human readable time difference, e.g. "5 mins" */
		return sprintf(__('%1$s (%2$s ago)', 'nomiddleman-crypto-payments-for-woocommerce'), $formatted, human_time_diff($timestamp, $now));
	}

	// WooCommerce stores a PSR-3 severity number, not a level name; turn it
	// back into the name it was logged under where WooCommerce can tell us.
	private static function level_label($severity) {
		if (class_exists('WC_Log_Levels')) {
			$level = WC_Log_Levels::get_severity_level((int) $severity);
			if (is_string($level) && $level !== '') {
				return $level;
			}
		}

		return (string) (int) $severity;
	}
}

?>
