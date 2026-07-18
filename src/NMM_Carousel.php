<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NMM_Carousel {
	private $buffer;
	private $cryptoId;

	public function __construct($cryptoId) {
		$this->cryptoId = $cryptoId;

		$carouselRepo = new NMM_Carousel_Repo();
		$this->buffer = self::usable_seats($carouselRepo->get_buffer($cryptoId));
	}

	/**
	 * Normalize the stored buffer into a densely-indexed list of candidate
	 * addresses. The column is merchant-controlled and get_buffer() returns
	 * false outright when the coin has no row, so this must tolerate a
	 * non-array, a sparse array, and blank seats. Everything downstream indexes
	 * this list by seat number and takes its length as the seat count, so it has
	 * to be dense and free of obvious junk.
	 *
	 * @return string[]
	 */
	private static function usable_seats($rawBuffer) {
		if (!is_array($rawBuffer)) {
			return array();
		}

		$seats = array();

		foreach ($rawBuffer as $address) {
			if (is_string($address) && trim($address) !== '') {
				$seats[] = trim($address);
			}
		}

		return $seats;
	}

	/**
	 * Claim the next usable carousel address.
	 *
	 * @throws \Exception When no usable address exists. The caller is checkout
	 *                    (see NMM_Gateway::thank_you_page), which fails the
	 *                    order and shows the message - far better than handing
	 *                    the customer a junk address, and better than the old
	 *                    behaviour of looping forever on a buffer with no valid
	 *                    entry, which hung the request until PHP timed out.
	 */
	public function get_next_address() {
		$seatCount = count($this->buffer);

		if ($seatCount < 1) {
			NMM_Util::log(__FILE__, __LINE__, 'No carousel addresses configured for ' . $this->cryptoId . '.', 'error');
			throw new \Exception(esc_html__('No payment address is available for the cryptocurrency you selected. Please choose another, or contact the site administrator.', 'nomiddleman-crypto-payments-for-woocommerce'));
		}

		$carouselRepo = new NMM_Carousel_Repo();

		// Bounded by the seat count: every seat gets at most one look, so a
		// buffer in which nothing validates ends in an exception rather than an
		// endless loop.
		for ($attempt = 0; $attempt < $seatCount; $attempt++) {
			$seat = $carouselRepo->claim_next_index($this->cryptoId, $seatCount);

			if ($seat === null) {
				// Could not claim atomically (database error). Do not fall back
				// to a non-atomic read: that is the collision this exists to
				// prevent.
				throw new \Exception(esc_html__('We could not allocate a payment address for your order. Please try again, or contact the site administrator.', 'nomiddleman-crypto-payments-for-woocommerce'));
			}

			$address = $this->buffer[$seat];

			if (NMM_Cryptocurrencies::is_valid_wallet_address($this->cryptoId, $address)) {
				return $address;
			}

			NMM_Util::log(__FILE__, __LINE__, 'Carousel seat ' . $seat . ' for ' . $this->cryptoId . ' holds an invalid address; skipping it.', 'warning');
		}

		NMM_Util::log(__FILE__, __LINE__, 'No valid carousel address for ' . $this->cryptoId . ' among ' . $seatCount . ' seat(s).', 'error');
		throw new \Exception(esc_html__('No valid payment address is configured for the cryptocurrency you selected. Please choose another, or contact the site administrator.', 'nomiddleman-crypto-payments-for-woocommerce'));
	}
}

?>