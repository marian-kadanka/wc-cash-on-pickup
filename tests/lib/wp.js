/**
 * WP-CLI helpers. Every test drives the store through the same option updates an
 * administrator would make on the gateway settings screen.
 */
const { execFileSync } = require('child_process');
const config = require('../config.js');

function wp(args) {
  return execFileSync('wp', args, { cwd: config.wpPath, encoding: 'utf8' }).trim();
}

function wpEval(php) {
  return wp(['eval', php]);
}

function getOption(name) {
  try { return wp(['option', 'get', name, '--format=json']); } catch (e) { return null; }
}

/** Writes the gateway settings, starting from config.gatewaySettings. */
function setGatewaySettings(overrides = {}) {
  const settings = Object.assign({}, config.gatewaySettings, overrides);
  wp(['option', 'update', 'woocommerce_cop_settings', JSON.stringify(settings), '--format=json']);
  return settings;
}

module.exports = { wp, wpEval, getOption, setGatewaySettings };
