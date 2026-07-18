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
	 * Compare-and-swap, the same idiom NMM_Hd_Repo::claim_oldest_ready uses: read
	 * the stored index, then conditionally advance it ONLY if it has not changed
	 * since the read. The seat returned is the value that was read. Two concurrent
	 * checkouts can no longer share a seat: whichever writes second finds the
	 * index already moved, its conditional UPDATE matches nothing, and it retries
	 * from a fresh read.
	 *
	 * This deliberately avoids reading LAST_INSERT_ID() back over a separate
	 * statement. Under a read/write splitter (HyperDB and similar) a table-less
	 * SELECT can be routed to a replica, returning that connection's stale session
	 * id rather than the value the UPDATE set. Here every read names the table, so
	 * a splitter routes it correctly; and even a stale read from a lagging replica
	 * is harmless - the conditional UPDATE simply fails to match and we retry,
	 * never handing out a wrong seat.
	 *
	 * @param string $cryptoId
	 * @param int    $seatCount Number of usable seats, from the validated buffer.
	 * @return int|null The claimed seat, or null if it could not be claimed.
	 */
	public function claim_next_index($cryptoId, $seatCount) {
		global $wpdb;

		$seatCount = (int) $seatCount;

		if ($seatCount < 1) {
			return null;
		}

		if ($seatCount === 1) {
			// Degenerate carousel: seat 0 every time. Special-cased because the
			// advance below would be a no-op ((0 + 1) MOD 1 === 0), and a no-op
			// UPDATE reports 0 matched rows - indistinguishable from a lost CAS.
			return 0;
		}

		$seeded = false;

		// A few attempts absorb concurrent checkouts advancing the index between
		// our read and our conditional write. Bounded so a persistently lagging
		// replica (whose stale reads never match the primary) fails cleanly to the
		// caller - which throws a checkout-safe error - rather than looping.
		for ($attempt = 0; $attempt < 8; $attempt++) {
			$stored = $wpdb->get_var($wpdb->prepare(
				"SELECT `current_index` FROM `$this->tableName` WHERE `cryptocurrency` = %s",
				$cryptoId
			));

			if ($stored === null) {
				// No counter row for this coin: added to the registry after the
				// table was seeded, or removed by hand. Seed once and retry.
				if ($seeded) {
					NMM_Util::log(__FILE__, __LINE__, 'Carousel counter row for ' . $cryptoId . ' still missing after seeding.', 'error');
					return null;
				}
				NMM_Util::log(__FILE__, __LINE__, 'No carousel counter row for ' . $cryptoId . '; seeding it.', 'warning');
				self::init();
				$seeded = true;
				continue;
			}

			$stored = (int) $stored;

			// Fold an out-of-range stored index (the merchant removed addresses
			// since it was written) back to seat 0 rather than a seat the buffer no
			// longer has.
			$seat = ($stored < 0 || $stored >= $seatCount) ? 0 : $stored;
			$next = ($seat + 1) % $seatCount;

			// Advance only if the row still holds exactly the value we read. The
			// WHERE binds the raw stored value, so a competing claim that already
			// moved the counter makes this match nothing.
			$affected = $wpdb->query($wpdb->prepare(
				"UPDATE `$this->tableName`
				 SET `current_index` = %d
				 WHERE `cryptocurrency` = %s AND `current_index` = %d",
				$next, $cryptoId, $stored
			));

			if ($affected === false) {
				NMM_Util::log(__FILE__, __LINE__, 'Failed to claim a carousel seat for ' . $cryptoId . ': ' . $wpdb->last_error, 'error');
				return null;
			}

			if ($affected === 1) {
				return $seat; // we won the seat
			}
			// affected === 0: another checkout advanced the counter first, or the
			// stored value differed (stale replica read). Re-read and retry.
		}

		NMM_Util::log(__FILE__, __LINE__, 'Carousel seat claim exhausted retries for ' . $cryptoId, 'warning');
		return null;
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
