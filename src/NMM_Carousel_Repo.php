<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NMM_Carousel_Repo {
	private $tableName;
	public function __construct() {
		global $wpdb;
		$this->tableName = $wpdb->prefix . NMM_CAROUSEL_TABLE;

		// re-seed only when the coin registry has grown since the last init;
		// the autoloaded option check avoids a COUNT query on every construct
		$countCryptos = count(NMM_Cryptocurrencies::get());

		if ((int) get_option('nmm_carousel_seeded_count', 0) !== $countCryptos) {
			self::init();
		}
	}

	public static function init() {
		global $wpdb;
		$tableName = $wpdb->prefix . NMM_CAROUSEL_TABLE;

		$cryptos = NMM_Cryptocurrencies::get();

		$placeholders = array();
		$values = array();

		foreach ($cryptos as $crypto) {
			$cryptoId = $crypto->get_id();

			if (!self::record_exists($cryptoId)) {
				$placeholders[] = '(%s)';
				$values[] = $cryptoId;
			}
		}

		if (count($values) > 0) {
			$wpdb->query($wpdb->prepare(
				"INSERT INTO `$tableName` (`cryptocurrency`) VALUES " . implode(', ', $placeholders),
				$values
			));
		}

		update_option('nmm_carousel_seeded_count', count($cryptos));
	}

	public static function record_exists($cryptoId) {
		global $wpdb;
		$tableName = $wpdb->prefix . NMM_CAROUSEL_TABLE;

		$result = $wpdb->get_var($wpdb->prepare(
			"SELECT count(*) FROM `$tableName` WHERE `cryptocurrency` = %s",
			$cryptoId
		));

		return $result;
	}

	/**
	 * Atomically claim the next carousel seat and advance the counter.
	 *
	 * Read-then-write across separate queries let two concurrent checkouts read
	 * the same index, hand both customers the same address, and lose one of the
	 * increments - so orders collide on a single seat and Autopay then has two
	 * unpaid rows on one address. A single UPDATE closes that window:
	 * LAST_INSERT_ID(expr) stores expr for this connection AND evaluates to it,
	 * so the row moves to the next seat while handing back the seat this caller
	 * claimed. The IF() folds an out-of-range stored index (the merchant removed
	 * addresses since it was written) back to 0 rather than returning a seat the
	 * buffer no longer has.
	 *
	 * @param string $cryptoId
	 * @param int    $seatCount Number of usable seats, from the validated buffer.
	 * @return int|null The claimed seat, or null if it could not be claimed.
	 */
	public function claim_next_index($cryptoId, $seatCount) {
		$seatCount = (int) $seatCount;

		if ($seatCount < 1) {
			return null;
		}

		if ($seatCount === 1) {
			// Degenerate carousel: seat 0 every time. Special-cased because the
			// UPDATE would be a no-op ((0 + 1) MOD 1 === 0), and MySQL reports 0
			// changed rows for a no-op update - indistinguishable from a missing
			// row.
			return 0;
		}

		$claim = $this->advance_counter($cryptoId, $seatCount);

		if ($claim['state'] === 'missing') {
			// No counter row for this coin: it was added to the registry after
			// the table was seeded, or the row was removed by hand. Seed it, then
			// claim through the same atomic path as everyone else - taking seat 0
			// directly here would hand seat 0 to this caller AND to the next one,
			// which is the collision this method exists to prevent.
			NMM_Util::log(__FILE__, __LINE__, 'No carousel counter row for ' . $cryptoId . '; seeding it.', 'warning');
			self::init();
			$claim = $this->advance_counter($cryptoId, $seatCount);
		}

		return $claim['state'] === 'ok' ? $claim['seat'] : null;
	}

	/**
	 * One atomic advance of the counter.
	 *
	 * @return array{state: string, seat: ?int} state is 'ok', 'missing' (no row
	 *         for this coin) or 'error' (the claim could not be made).
	 */
	private function advance_counter($cryptoId, $seatCount) {
		global $wpdb;

		$affected = $wpdb->query($wpdb->prepare(
			"UPDATE `$this->tableName`
			 SET `current_index` = (LAST_INSERT_ID(IF(`current_index` < 0 OR `current_index` >= %d, 0, `current_index`)) + 1) MOD %d
			 WHERE `cryptocurrency` = %s",
			$seatCount, $seatCount, $cryptoId
		));

		if ($affected === false) {
			NMM_Util::log(__FILE__, __LINE__, 'Failed to claim a carousel seat for ' . $cryptoId . ': ' . $wpdb->last_error, 'error');
			return array('state' => 'error', 'seat' => null);
		}

		if ($affected === 0) {
			return array('state' => 'missing', 'seat' => null);
		}

		// Read back the seat the UPDATE just claimed. It MUST come from
		// LAST_INSERT_ID() over the same connection: $wpdb->insert_id is not
		// usable here, because wpdb only refreshes that property for INSERT and
		// REPLACE - after an UPDATE it still holds an id from some unrelated
		// earlier insert in this request (an order row, say), which would hand
		// out a nonsense seat.
		$seat = $wpdb->get_var('SELECT LAST_INSERT_ID()');

		if ($seat === null) {
			NMM_Util::log(__FILE__, __LINE__, 'Could not read back the claimed carousel seat for ' . $cryptoId . ': ' . $wpdb->last_error, 'error');
			return array('state' => 'error', 'seat' => null);
		}

		return array('state' => 'ok', 'seat' => (int) $seat);
	}

	public function set_index($cryptoId, $index) {
		global $wpdb;
		NMM_Util::log(__FILE__, __LINE__, 'Updating index for ' . $cryptoId . ' to ' . $index);

		$wpdb->query($wpdb->prepare(
			"UPDATE `$this->tableName` SET `current_index` = %d WHERE `cryptocurrency` = %s",
			$index, $cryptoId
		));
	}

	public function get_index($cryptoId) {
		global $wpdb;

		$currentIndex = $wpdb->get_var($wpdb->prepare(
			"SELECT `current_index` FROM `$this->tableName` WHERE `cryptocurrency` = %s",
			$cryptoId
		));
		NMM_Util::log(__FILE__, __LINE__, 'Getting index: ' . $currentIndex);
		return $currentIndex;
	}

	public function set_buffer($cryptoId, $buffer) {
		global $wpdb;

		// plain serialize; prepare() handles escaping
		$serializedBuffer = serialize($buffer);

		$wpdb->query($wpdb->prepare(
			"UPDATE `$this->tableName` SET `buffer` = %s WHERE `cryptocurrency` = %s",
			$serializedBuffer, $cryptoId
		));
	}

	public function get_buffer($cryptoId) {
		global $wpdb;

		$serializedResult = $wpdb->get_results($wpdb->prepare(
			"SELECT `buffer` FROM `$this->tableName` WHERE `cryptocurrency` = %s",
			$cryptoId
		), ARRAY_A);

		if (empty($serializedResult) || !isset($serializedResult[0]['buffer'])) {
			return false;
		}

		// buffers only ever hold arrays of address strings; never revive objects
		$result = unserialize($serializedResult[0]['buffer'], array('allowed_classes' => false));

		NMM_Util::log(__FILE__, __LINE__, 'Getting buffer: ' . print_r($result, true));

		return $result;
	}
}

?>
