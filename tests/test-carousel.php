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

if (!isset($GLOBALS['wpdb']) || !is_object($GLOBALS['wpdb']) || !defined('NMM_CAROUSEL_TABLE')) {
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
		"UPDATE `{$wpdb->prefix}" . NMM_CAROUSEL_TABLE . "` SET `current_index` = %d WHERE `cryptocurrency` = %s",
		$index, $cryptoId
	));
}

function car_index($cryptoId) {
	global $wpdb;
	return (int) $wpdb->get_var($wpdb->prepare(
		"SELECT `current_index` FROM `{$wpdb->prefix}" . NMM_CAROUSEL_TABLE . "` WHERE `cryptocurrency` = %s",
		$cryptoId
	));
}

function car_rows($cryptoId) {
	global $wpdb;
	return (int) $wpdb->get_var($wpdb->prepare(
		"SELECT COUNT(*) FROM `{$wpdb->prefix}" . NMM_CAROUSEL_TABLE . "` WHERE `cryptocurrency` = %s",
		$cryptoId
	));
}

function car_next() {
	$carousel = new NMM_Carousel('BTC');
	return $carousel->get_next_address();
}

$repo = new NMM_Carousel_Repo();

// Real addresses so NMM_Cryptocurrencies::is_valid_wallet_address('BTC', ...)
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
$requestA = new NMM_Carousel('BTC');
$requestB = new NMM_Carousel('BTC');
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
	"DELETE FROM `{$wpdb->prefix}" . NMM_CAROUSEL_TABLE . "` WHERE `cryptocurrency` = %s", 'BTC'
));
cok('the counter row is really gone',                  car_rows('BTC') === 0);
$seeded = $repo->claim_next_index('BTC', 3);
cok('a missing counter row yields seat 0',             $seeded === 0, 'got=' . var_export($seeded, true));
cok('and the row is seeded back',                      car_rows('BTC') === 1);

// Leave the table as we found it for a clean re-run.
NMM_Carousel_Repo::init();
$repo->set_buffer('BTC', array());
car_set_index('BTC', 0);

echo $GLOBALS['car_ok'] ? "\nCAROUSEL CHECKS PASSED\n" : "\nCAROUSEL CHECKS FAILED\n";
