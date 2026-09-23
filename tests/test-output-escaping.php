<?php
/**
 * Verify the gateway's inline QR SVG is escaped with its intended vocabulary.
 * Run with WordPress loaded (for example: wp eval-file tests/test-output-escaping.php).
 */

if (!function_exists('wp_kses') || !class_exists('NMMPRO_Gateway') || !class_exists('NMMPRO_Qr')) {
	echo "test-output-escaping: skipped (requires WordPress and the plugin)\n";
	return;
}

function nmmpro_output_ok($pass, $message) {
	if (!$pass) {
		throw new RuntimeException($message);
	}
	echo "ok: $message\n";
}

$reflection = new ReflectionMethod('NMMPRO_Gateway', 'qr_svg_allowed_html');
if (PHP_VERSION_ID < 80100) {
	$reflection->setAccessible(true);
}
$allowed = $reflection->invoke(null);

$generated = NMMPRO_Qr::svg('output escaping test', 200);
$generated_escaped = wp_kses($generated, $allowed);
$generated_rects = array();
$escaped_rects = array();
preg_match_all('/<rect\b[^>]*>/i', $generated, $generated_matches);
preg_match_all('/<rect\b[^>]*>/i', $generated_escaped, $escaped_matches);
foreach ($generated_matches[0] as $rect) {
	$generated_rects[] = preg_replace('/\s+/', '', strtolower($rect));
}
foreach ($escaped_matches[0] as $rect) {
	$escaped_rects[] = preg_replace('/\s+/', '', strtolower($rect));
}
sort($generated_rects);
sort($escaped_rects);
nmmpro_output_ok($generated_rects === $escaped_rects && count($generated_rects) > 0, 'generated QR rectangle geometry preserved');

$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 9 9" width="200" height="200" shape-rendering="crispEdges" role="img" aria-label="Payment QR code" onload="alert(1)">'
	. '<rect width="9" height="9" fill="#ffffff"/><g fill="#000000" onclick="alert(1)"><rect x="4" y="4" width="1" height="1"/></g>'
	. '<script>alert(1)</script></svg>';
$escaped = wp_kses($svg, $allowed);

nmmpro_output_ok(strpos($escaped, '<svg ') !== false, 'SVG element preserved');
nmmpro_output_ok(strpos($escaped, '<rect ') !== false && strpos($escaped, '<g ') !== false, 'QR drawing elements preserved');
nmmpro_output_ok((bool) preg_match('/viewbox="0 0 9 9"/i', $escaped), 'SVG viewBox preserved');
nmmpro_output_ok(strpos($escaped, 'onload=') === false && strpos($escaped, 'onclick=') === false, 'event attributes removed');
nmmpro_output_ok(strpos($escaped, '<script') === false, 'script element removed');

echo "OUTPUT-ESCAPING CHECKS PASSED\n";
