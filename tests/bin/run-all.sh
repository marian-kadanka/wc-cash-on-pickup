#!/usr/bin/env bash
#
# Runs the whole suite and always puts the site back, even if a phase fails.
#
#   tests/bin/run-all.sh            # everything
#   tests/bin/run-all.sh unit       # just the unit tests (no site changes)
#
set -uo pipefail

TESTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_REL="wp-content/plugins/wc-cash-on-pickup"
WP_PATH="$(node -p "require('$TESTS_DIR/config.json').wpPath")"
CLASSIC_PAGE="$(node -p "require('$TESTS_DIR/config.json').classicCheckoutPageId")"
BLOCK_PAGE="$(node -p "require('$TESTS_DIR/config.json').blockCheckoutPageId")"

only="${1:-all}"
failed=0
run() { echo; echo "=== $1 ==="; shift; "$@" || failed=1; }

# ---- unit tests: no site state involved ---------------------------------------------------
run "unit: blocks checkout.js" node "$TESTS_DIR/unit/blocks-js.test.js"
run "unit: gateway shipping logic" wp --path="$WP_PATH" eval-file "$WP_PATH/$PLUGIN_REL/tests/unit/gateway-logic.php"

if [ "$only" = "unit" ]; then
  echo; [ $failed -eq 0 ] && echo "ALL UNIT TESTS PASSED" || echo "UNIT TESTS FAILED"
  exit $failed
fi

# ---- end to end: changes site options, always restored below ------------------------------
wp --path="$WP_PATH" eval-file "$WP_PATH/$PLUGIN_REL/tests/bin/setup-site.php" || exit 1

cleanup() {
  echo; echo "=== restoring site ==="
  wp --path="$WP_PATH" eval-file "$WP_PATH/$PLUGIN_REL/tests/bin/restore-site.php"
}
trap cleanup EXIT

# The classic checkout has to be the store's checkout page for is_checkout() to hold.
wp --path="$WP_PATH" option update woocommerce_checkout_page_id "$CLASSIC_PAGE" >/dev/null
run "e2e: classic checkout matrix" node "$TESTS_DIR/e2e/classic-checkout.test.js"
run "e2e: classic order placement" node "$TESTS_DIR/e2e/classic-order-placement.test.js"

# The block checkout has to be the store's checkout page for "pickup_location" to exist.
wp --path="$WP_PATH" option update woocommerce_checkout_page_id "$BLOCK_PAGE" >/dev/null
run "e2e: block checkout matrix" node "$TESTS_DIR/e2e/block-checkout.test.js"
run "e2e: order placement" node "$TESTS_DIR/e2e/order-placement.test.js"

echo
[ $failed -eq 0 ] && echo "ALL TESTS PASSED" || echo "SOME TESTS FAILED"
exit $failed
