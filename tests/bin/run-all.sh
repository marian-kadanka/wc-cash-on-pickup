#!/usr/bin/env bash
#
# Runs the whole suite and always puts the site back, even if a phase fails.
#
#   tests/bin/run-all.sh                 # unit tests, then both store shapes
#   tests/bin/run-all.sh unit            # just the unit tests (no site changes)
#   tests/bin/run-all.sh grandfathered   # unit tests, then that shape only
#   tests/bin/run-all.sh block-first
#
# The two shapes are not interchangeable. A store that predates the block checkout still has a
# zone based local_pickup; a store created after the block checkout became the default cannot
# have one, because WooCommerce hides local_pickup from the shipping method picker unless a zone
# already offers it. Only the second shape is what a new store looks like, so both are covered.
#
set -uo pipefail

TESTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_REL="wp-content/plugins/wc-cash-on-pickup"
WP_PATH="$(node -p "require('$TESTS_DIR/config.json').wpPath")"

only="${1:-all}"
failed=0
run() { echo; echo "=== $1 ==="; shift; "$@" || failed=1; }
wp_() { wp --path="$WP_PATH" "$@"; }

# ---- unit tests: no site state involved ---------------------------------------------------
run "unit: blocks checkout.js" node "$TESTS_DIR/unit/blocks-js.test.js"
run "unit: gateway shipping logic" wp_ eval-file "$WP_PATH/$PLUGIN_REL/tests/unit/gateway-logic.php"

if [ "$only" = "unit" ]; then
  echo; [ $failed -eq 0 ] && echo "ALL UNIT TESTS PASSED" || echo "UNIT TESTS FAILED"
  exit $failed
fi

case "$only" in
  all)                     shapes="grandfathered block-first" ;;
  grandfathered|block-first) shapes="$only" ;;
  *) echo "Unknown argument \"$only\". Use unit, grandfathered, block-first, or nothing."; exit 1 ;;
esac

# Restore whenever a backup is outstanding: after each shape below, but also if a phase aborts
# or the run is interrupted. Guarding on the file means the trap stays harmless after the loop
# has already restored - removing the trap instead used to leave the site changed when
# setup-site.php itself failed.
BACKUP_FILE="$(node -p "require('$TESTS_DIR/config.js').artifactsBase")/site-backup.json"
restore() {
  [ -f "$BACKUP_FILE" ] || return 0
  echo; echo "=== restoring site ==="
  wp_ eval-file "$WP_PATH/$PLUGIN_REL/tests/bin/restore-site.php"
}
trap restore EXIT

for shape in $shapes; do
  echo; echo "############ store shape: $shape ############"
  wp_ eval-file "$WP_PATH/$PLUGIN_REL/tests/bin/setup-site.php" "$shape" || { failed=1; break; }

  # setup-site.php resolved the pages and rate ids; config.js merges them in for us.
  CLASSIC_PAGE="$(node -p "require('$TESTS_DIR/config.js').classicCheckoutPageId")"
  BLOCK_PAGE="$(node -p "require('$TESTS_DIR/config.js').blockCheckoutPageId")"

  # The classic checkout needs a zone based local_pickup, which a block-first store does not
  # have and cannot be given. Skipping it there is the correct coverage, not a gap.
  if [ "$shape" = "grandfathered" ]; then
    # The classic checkout has to be the store's checkout page for is_checkout() to hold.
    wp_ option update woocommerce_checkout_page_id "$CLASSIC_PAGE" >/dev/null
    run "e2e [$shape]: classic checkout matrix" node "$TESTS_DIR/e2e/classic-checkout.test.js"
    run "e2e [$shape]: classic order placement" node "$TESTS_DIR/e2e/classic-order-placement.test.js"
  fi

  # The block checkout has to be the store's checkout page for "pickup_location" to exist.
  wp_ option update woocommerce_checkout_page_id "$BLOCK_PAGE" >/dev/null
  run "e2e [$shape]: block checkout matrix" node "$TESTS_DIR/e2e/block-checkout.test.js"
  run "e2e [$shape]: block pickup locations UI" node "$TESTS_DIR/e2e/block-pickup-ui.test.js"
  run "e2e [$shape]: block order placement" node "$TESTS_DIR/e2e/order-placement.test.js"

  restore
done

echo
[ $failed -eq 0 ] && echo "ALL TESTS PASSED" || echo "SOME TESTS FAILED"
exit $failed
