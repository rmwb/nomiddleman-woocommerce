<?php
/**
 * Live-DB test: carousel address allocation. Seats are claimed through a single
 * atomic UPDATE, so two concurrent checkouts can never be handed the same
 * address (the old read-index/advance/write-index sequence let both read the
 * same index and lose one increment). Also covers the buffers a merchant can
 * actually leave behind - missing, empty, blank-padded, non-array, or holding
 * nothing valid - none of which may hang the checkout page.
 * Requires WordPress + a database. Skips cleanly standalone.
 *
 *   Run:  wp eval-file tests/test-carousel.php
 */

if (!isset($GLOBALS['wpdb']) || !is_object($GLOBALS['wpdb']) || !defined('NMMPRO_CAROUSEL_TABLE')) {
	echo "test-carousel: skipped (needs WordPress + DB)\n";
	return;
}

$GLOBALS['car_ok'] = true;
function cok($label, $cond, $extra = '') {
	printf("%-62s %s%s\n", $label, $cond ? 'ok' : 'FAIL', $extra !== '' ? "  $extra" : '');
	if (!$cond) { $GLOBALS['car_ok'] = false; }
}

function car_set_index($cryptoId, $index) {
	global $wpdb;
	$wpdb->query($wpdb->prepare(
		"UPDATE `{$wpdb->prefix}" . NMMPRO_CAROUSEL_TABLE . "` SET `current_index` = %d WHERE `cryptocurrency` = %s",
		$index, $cryptoId
	));
}

function car_index($cryptoId) {
	global $wpdb;
	return (int) $wpdb->get_var($wpdb->prepare(
		"SELECT `current_index` FROM `{$wpdb->prefix}" . NMMPRO_CAROUSEL_TABLE . "` WHERE `cryptocurrency` = %s",
		$cryptoId
	));
}

function car_rows($cryptoId) {
	global $wpdb;
	return (int) $wpdb->get_var($wpdb->prepare(
		"SELECT COUNT(*) FROM `{$wpdb->prefix}" . NMMPRO_CAROUSEL_TABLE . "` WHERE `cryptocurrency` = %s",
		$cryptoId
	));
}

function car_next() {
	$carousel = new NMMPRO_Carousel('BTC');
	return $carousel->get_next_address();
}

$repo = new NMMPRO_Carousel_Repo();

// Real addresses so NMMPRO_Cryptocurrencies::is_valid_wallet_address('BTC', ...)
// accepts them. The junk below deliberately avoids a leading 1/3 and the
// substring 'bc', which the BTC pattern would otherwise match.
$addrs = array(
	'1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa',
	'1BiCdXSDHyeXSzmx2paVPFVTrmyx7BeCGD',
	'1DfqqdJJfsTAy5qnz4LnSdkycQVWrnQR9Y',
);

// --- ordinary round-robin ---
$repo->set_buffer('BTC', $addrs);
car_set_index('BTC', 0);

$got = array();
for ($i = 0; $i < 3; $i++) { $got[] = car_next(); }
cok('three claims hand out three distinct addresses', count(array_unique($got)) === 3, implode(',', array_map('substr', $got, array(0,0,0), array(6,6,6))));
cok('claims follow the buffer order',                 $got === $addrs);
cok('the fourth claim wraps to the first seat',       car_next() === $addrs[0]);

// --- THE regression: two overlapping checkouts ---
// Both requests construct (reading buffer and, formerly, the index) before
// either allocates. The old code read the same index twice and handed both
// customers the same address, then lost one of the two increments. The claim is
// now inside get_next_address(), as a single atomic statement.
car_set_index('BTC', 0);
$requestA = new NMMPRO_Carousel('BTC');
$requestB = new NMMPRO_Carousel('BTC');
$a = $requestA->get_next_address();
$b = $requestB->get_next_address();
cok('two overlapping checkouts never share a seat',   $a !== $b, "$a vs $b");
cok('neither increment is lost',                      car_index('BTC') === 2, 'index=' . car_index('BTC'));

// --- a stored index the buffer no longer has (the merchant removed addresses) ---
car_set_index('BTC', 99);
$stale = car_next();
cok('an out-of-range stored index folds back to seat 0', $stale === $addrs[0], $stale);
cok('and the stored index is left in range',            car_index('BTC') >= 0 && car_index('BTC') < 3, 'index=' . car_index('BTC'));

car_set_index('BTC', -7);
$negative = car_next();
cok('a negative stored index folds back to seat 0',    $negative === $addrs[0], $negative);
cok('and the stored index is left in range',           car_index('BTC') >= 0 && car_index('BTC') < 3, 'index=' . car_index('BTC'));

// --- degenerate single-seat carousel: no counter to advance ---
$repo->set_buffer('BTC', array($addrs[0]));
car_set_index('BTC', 0);
cok('single-seat carousel returns its one address',    car_next() === $addrs[0]);
cok('single-seat carousel repeats safely',             car_next() === $addrs[0]);

// --- blank and padded seats are not addresses ---
$repo->set_buffer('BTC', array('', $addrs[0], '   ', ' ' . $addrs[1] . ' '));
car_set_index('BTC', 0);
$filtered = array(car_next(), car_next());
cok('blank seats are filtered out, whitespace trimmed', $filtered === array($addrs[0], $addrs[1]), implode(',', $filtered));

// --- buffers that cannot yield an address must throw, not hang ---
$repo->set_buffer('BTC', array());
$emptyThrew = false;
try { car_next(); } catch (\Exception $e) { $emptyThrew = true; }
cok('an empty buffer throws instead of hanging',       $emptyThrew);

$repo->set_buffer('BTC', 'not-an-array');
$scalarThrew = false;
try { car_next(); } catch (\Exception $e) { $scalarThrew = true; }
cok('a non-array buffer throws instead of hanging',    $scalarThrew);

// Nothing in the buffer validates. The old loop advanced the index forever
// looking for a valid address and never found one, hanging the customer's
// checkout until PHP timed out. Bounded by the seat count now.
$repo->set_buffer('BTC', array('ZZZ_INVALID_1', 'ZZZ_INVALID_2', 'ZZZ_INVALID_3'));
car_set_index('BTC', 0);
$junkThrew = false;
$startedAt = microtime(true);
try { car_next(); } catch (\Exception $e) { $junkThrew = true; }
$elapsed = microtime(true) - $startedAt;
cok('a buffer with no valid address throws',           $junkThrew);
cok('and it gives up promptly rather than looping',    $elapsed < 5.0, sprintf('%.3fs', $elapsed));

// --- a single bad seat must not cost the sale ---
$repo->set_buffer('BTC', array('ZZZ_INVALID_1', $addrs[1]));
car_set_index('BTC', 0);
cok('an invalid seat is skipped for the next valid one', car_next() === $addrs[1]);

// --- repository-level claim contract ---
cok('claim_next_index rejects a zero seat count',      $repo->claim_next_index('BTC', 0) === null);
cok('claim_next_index rejects a negative seat count',  $repo->claim_next_index('BTC', -3) === null);

$seats = array();
car_set_index('BTC', 0);
for ($i = 0; $i < 12; $i++) { $seats[] = $repo->claim_next_index('BTC', 4); }
cok('claims cycle every seat exactly once per lap',    $seats === array(0,1,2,3,0,1,2,3,0,1,2,3), implode(',', $seats));
cok('every claimed seat is within range',              min($seats) === 0 && max($seats) === 3);

// --- a coin with no counter row at all (added to the registry after seeding,
// or a row removed by hand) is seeded on demand rather than failing checkout ---
global $wpdb;
$wpdb->query($wpdb->prepare(
	"DELETE FROM `{$wpdb->prefix}" . NMMPRO_CAROUSEL_TABLE . "` WHERE `cryptocurrency` = %s", 'BTC'
));
cok('the counter row is really gone',                  car_rows('BTC') === 0);
$seeded = $repo->claim_next_index('BTC', 3);
cok('a missing counter row yields seat 0',             $seeded === 0, 'got=' . var_export($seeded, true));
cok('and the row is seeded back',                      car_rows('BTC') === 1);

// ---------------------------------------------------------------------
// FUND SAFETY: under Autopay the carousel must refuse an address Autopay
// cannot verify, at the moment of use.
//
// The settings save already filters such addresses out of the buffer, but
// the buffer is a DURABLE CACHE and an upgrade writes no settings (nor does
// a mode flipped by WP-CLI, an import, or a migration). The previous release
// accepted 95-char Zcash Sprout z-addresses, so a merchant who has been
// running ZEC Autopay already has one sitting in their stored buffer. Handing
// it out means the merchant RECEIVES the money while Autopay - which asks a
// public explorer which outputs paid a literal address - can never see a
// shielded payment, so the order is auto-cancelled after the customer paid.
// The check therefore has to live in get_next_address(), where nothing can
// bypass it.
//
// Vectors: both are the exact strings pinned in tests/test-address-validation.php.
//   $zecSprout there is the legacy Sprout z-address from the Zcash docs
//   example (that file's "legacy Sprout z-address (docs example)" case) -
//   a VALID ZEC format, but NOT Autopay-verifiable.
//   The transparent t1 there is built as t_b58check_encode("\x1c\xb8", $zeros20)
//   - version 0x1CB8 over 20 zero bytes - which encodes to the literal below.
//   The two self-checks that follow assert exactly those properties, so a
//   mistyped vector fails loudly instead of passing vacuously.
// ---------------------------------------------------------------------
$zecT1     = 't1Hsc1LR8yKnbbe3twRp88p6vFfC5t7DLbs';
$zecSprout = 'zcU1Cd6zYyZCd2VJF8yKgmzjxdiiU1rgTTjEwoN1CGUWCziPkUTXUjXmX7TMqdMNsTfuiGN1jQoVN4kGxUR4sAPN4XZ7pxb';

cok('vector check: t1 is valid AND Autopay-verifiable',
	NMMPRO_Cryptocurrencies::is_valid_wallet_address('ZEC', $zecT1) && NMMPRO_Address::is_autopay_verifiable_form('ZEC', $zecT1));
cok('vector check: Sprout is valid but NOT Autopay-verifiable',
	NMMPRO_Cryptocurrencies::is_valid_wallet_address('ZEC', $zecSprout) && !NMMPRO_Address::is_autopay_verifiable_form('ZEC', $zecSprout));

// The harness DB persists between runs, so capture the merchant's real ZEC
// state and put it back at the end - including the case where 'ZEC_mode' was
// never set at all, which must be restored as ABSENT, not as ''.
$zecSettings    = get_option(NMMPRO_REDUX_ID);
$zecModeWasSet  = is_array($zecSettings) && array_key_exists('ZEC_mode', $zecSettings);
$zecModeOrig    = $zecModeWasSet ? $zecSettings['ZEC_mode'] : null;
$zecBufferOrig  = $repo->get_buffer('ZEC');
$zecIndexOrig   = car_index('ZEC');

function car_set_zec_mode($mode) {
	$s = get_option(NMMPRO_REDUX_ID);
	if (!is_array($s)) { $s = array(); }
	if ($mode === null) { unset($s['ZEC_mode']); } else { $s['ZEC_mode'] = $mode; }
	update_option(NMMPRO_REDUX_ID, $s);
}

function car_zec_next() {
	$carousel = new NMMPRO_Carousel('ZEC');
	return $carousel->get_next_address();
}

// --- Autopay: the shielded seat must never be handed out ---
// Seats are claimed round-robin, so the shielded seat IS selected on some of
// these laps; asking more times than there are seats is what proves the skip
// happens rather than the transparent address merely coming up first.
car_set_zec_mode('1'); // Autopay
$repo->set_buffer('ZEC', array($zecSprout, $zecT1));
car_set_index('ZEC', 0);
$zecGot = array();
$zecThrew = false;
try {
	for ($i = 0; $i < 6; $i++) { $zecGot[] = car_zec_next(); }
} catch (\Exception $e) { $zecThrew = true; }
cok('ZEC Autopay: never throws while a verifiable seat exists', !$zecThrew);
cok('ZEC Autopay: every claim is the TRANSPARENT address',
	!$zecThrew && count($zecGot) === 6 && array_unique($zecGot) === array($zecT1),
	implode(',', array_map(function ($a) { return substr($a, 0, 8); }, $zecGot)));
cok('ZEC Autopay: the shielded address is never handed out',
	!in_array($zecSprout, $zecGot, true));

// A shielded seat first in the buffer with the t-address AFTER it: the very
// first claim lands on the shielded seat and must skip forward, not fail.
car_set_index('ZEC', 0);
$zecFirstClaim = null;
try { $zecFirstClaim = car_zec_next(); } catch (\Exception $e) { $zecFirstClaim = 'THREW'; }
cok('ZEC Autopay: a claim landing on the shielded seat skips forward',
	$zecFirstClaim === $zecT1, 'got=' . substr((string) $zecFirstClaim, 0, 12));

// --- Autopay with NOTHING verifiable: no seat is usable, so throw ---
// Better a failed order (the customer is told and pays nothing) than an
// address that takes the funds and cancels the order.
$repo->set_buffer('ZEC', array($zecSprout, $zecSprout));
car_set_index('ZEC', 0);
$zecAllShieldedThrew = false;
$zecAllShieldedGot = null;
$zecStartedAt = microtime(true);
try { $zecAllShieldedGot = car_zec_next(); } catch (\Exception $e) { $zecAllShieldedThrew = true; }
$zecElapsed = microtime(true) - $zecStartedAt;
cok('ZEC Autopay: an all-unverifiable buffer throws',  $zecAllShieldedThrew, 'got=' . var_export($zecAllShieldedGot, true));
cok('and it gives up promptly rather than looping',    $zecElapsed < 5.0, sprintf('%.3fs', $zecElapsed));

// --- Classic mode: the shielded address IS legitimate ---
// Classic does no on-chain verification; the merchant checks their own wallet,
// where a shielded payment is perfectly visible. Refusing it here would break
// working stores, so the skip must be conditional on Autopay - not global.
car_set_zec_mode('0'); // Classic / basic
$repo->set_buffer('ZEC', array($zecSprout));
car_set_index('ZEC', 0);
$zecClassic = null;
try { $zecClassic = car_zec_next(); } catch (\Exception $e) { $zecClassic = 'THREW: ' . $e->getMessage(); }
cok('ZEC Classic: the shielded address IS returned',   $zecClassic === $zecSprout, 'got=' . substr((string) $zecClassic, 0, 24));

// Restore the merchant's ZEC state exactly as found.
car_set_zec_mode($zecModeWasSet ? $zecModeOrig : null);
$repo->set_buffer('ZEC', is_array($zecBufferOrig) ? $zecBufferOrig : array());
car_set_index('ZEC', $zecIndexOrig);
$zecSettingsBack = get_option(NMMPRO_REDUX_ID);
cok('ZEC mode option restored',
	array_key_exists('ZEC_mode', (array) $zecSettingsBack) === $zecModeWasSet
		&& (!$zecModeWasSet || $zecSettingsBack['ZEC_mode'] === $zecModeOrig));
cok('ZEC buffer restored',
	$repo->get_buffer('ZEC') === (is_array($zecBufferOrig) ? $zecBufferOrig : array()));

// Leave the table as we found it for a clean re-run.
NMMPRO_Carousel_Repo::init();
$repo->set_buffer('BTC', array());
car_set_index('BTC', 0);

echo $GLOBALS['car_ok'] ? "\nCAROUSEL CHECKS PASSED\n" : "\nCAROUSEL CHECKS FAILED\n";
