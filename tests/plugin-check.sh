#!/usr/bin/env bash
# WordPress.org Plugin Check against what would actually ship.
#
# Scans the git-archive payload of a ref (the release zip's contents: tests/,
# docs/ and dev config are export-ignored) under the real plugin slug.
# Scanning the working tree instead reports false positives from tests/ and
# .github/, and a different directory name turns every translated string into
# a TextDomainMismatch.
#
# Needs a WordPress install with the plugin-check plugin active:
#   wp plugin install plugin-check --activate --path=<wp>
#
# Usage: WP_PATH=/path/to/wordpress tests/plugin-check.sh [ref] [extra wp plugin check args]
#   ref defaults to HEAD. Exits 1 if Plugin Check reports any ERROR, so the
#   gate is "zero errors"; warnings are listed by code for triage against
#   docs/PLUGIN-CHECK-TRIAGE.md. Exits 2 if the scan itself did not run or
#   its output could not be understood - never "0 errors" by default.
set -euo pipefail

: "${WP_PATH:?set WP_PATH to the WordPress install that has plugin-check active}"
WP=${WP_CLI:-wp}
SLUG=nomiddleman-crypto-payments-for-woocommerce
REPO=$(cd "$(dirname "$0")/.." && pwd)
REF=${1:-HEAD}
shift || true

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/$SLUG"
git -C "$REPO" archive "$REF" | tar -x -C "$WORK/$SLUG"
echo "Plugin Check: $REF ($(git -C "$REPO" rev-parse --short "$REF")), $(find "$WORK/$SLUG" -type f | wc -l | tr -d ' ') files in payload" >&2

set +e
$WP plugin check "$WORK/$SLUG" --slug="$SLUG" --format=json --path="$WP_PATH" "$@" > "$WORK/result.txt" 2> "$WORK/stderr.txt"
WP_STATUS=$?
set -e

# Output is one "FILE: <path>" line followed by a JSON array per file.
# A run counts only if it produced per-file results or Plugin Check's own
# clean-scan line; a missing command, a PHP fatal or an unknown format is not
# "no findings".
set +e
php -r '
$items = array(); $file = null; $files = 0; $clean = false;
foreach (file($argv[1], FILE_IGNORE_NEW_LINES) as $line) {
    if (strpos($line, "Success: Checks complete.") === 0) { $clean = true; continue; }
    if (strpos($line, "FILE: ") === 0) { $file = substr($line, 6); $files++; continue; }
    if ($line === "" || $line[0] !== "[") { continue; }
    $decoded = json_decode($line, true);
    if (!is_array($decoded) || $file === null) { fwrite(STDERR, "unparseable Plugin Check output\n"); exit(2); }
    foreach ($decoded as $f) {
        if (!isset($f["type"], $f["code"], $f["line"], $f["message"])) { fwrite(STDERR, "unexpected Plugin Check result shape\n"); exit(2); }
        $f["file"] = $file; $items[] = $f;
    }
}
if (!$clean && ($files === 0 || count($items) === 0)) { fwrite(STDERR, "Plugin Check produced no results\n"); exit(2); }
$byType = array("ERROR" => 0, "WARNING" => 0); $byCode = array();
foreach ($items as $f) {
    $byType[$f["type"]] = ($byType[$f["type"]] ?? 0) + 1;
    $key = $f["type"] . "  " . $f["code"];
    $byCode[$key] = ($byCode[$key] ?? 0) + 1;
}
ksort($byCode);
printf("%d errors, %d warnings\n", $byType["ERROR"], $byType["WARNING"]);
foreach ($byCode as $k => $n) { printf("  %4d  %s\n", $n, $k); }
foreach ($items as $f) {
    if ($f["type"] === "ERROR") { printf("ERROR %s:%d  %s  %s\n", $f["file"], $f["line"], $f["code"], $f["message"]); }
}
exit($byType["ERROR"] > 0 ? 1 : 0);
' "$WORK/result.txt"
PARSE_STATUS=$?
set -e
if [ "$PARSE_STATUS" -eq 2 ]; then
    echo "Plugin Check did not complete (wp exit $WP_STATUS):" >&2
    head -20 "$WORK/stderr.txt" "$WORK/result.txt" >&2
    exit 2
fi
exit "$PARSE_STATUS"
