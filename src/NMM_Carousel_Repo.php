<?php

class NMM_Carousel_Repo {
	private $tableName;
	public function __construct() {
		global $wpdb;
		$this->tableName = $wpdb->prefix . NMM_CAROUSEL_TABLE;

		$countCryptos = count(NMM_Cryptocurrencies::get());
		$countCryptosInDb = self::get_count();

		if ($countCryptos != $countCryptosInDb) {
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
			@$wpdb->query($wpdb->prepare(
				"INSERT INTO `$tableName` (`cryptocurrency`) VALUES " . implode(', ', $placeholders),
				$values
			));
		}
	}

	public static function get_count() {
		global $wpdb;
		$tableName = $wpdb->prefix . NMM_CAROUSEL_TABLE;
		$query = "SELECT count(*) FROM `$tableName`";

		$result = $wpdb->get_var($query);

		return $result;
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
