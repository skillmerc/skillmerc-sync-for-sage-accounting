<?php
/**
 * Plugin Name:       Skillmerc Sync for Sage Accounting
 * Description:       Sends paid WooCommerce orders to Sage Accounting (UK and Ireland, API v3.1): finds or creates the customer, creates a sales invoice with VAT per line and a shipping line, and records the payment against it.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            SKILLMERC
 * Author URI:        https://profiles.wordpress.org/skillmerc/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       skillmerc-sync-for-sage-accounting
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   11.1
 *
 * @package SKM_SSA
 */

defined( 'ABSPATH' ) || exit;

define( 'SKM_SSA_VERSION', '0.1.0' );
define( 'SKM_SSA_FILE', __FILE__ );
define( 'SKM_SSA_DIR', plugin_dir_path( __FILE__ ) );

require_once SKM_SSA_DIR . 'includes/class-skm-ssa-logger.php';
require_once SKM_SSA_DIR . 'includes/class-skm-ssa-oauth.php';
require_once SKM_SSA_DIR . 'includes/class-skm-ssa-api.php';
require_once SKM_SSA_DIR . 'includes/class-skm-ssa-sync.php';
require_once SKM_SSA_DIR . 'includes/class-skm-ssa-settings.php';

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage.
 */
function skm_ssa_declare_hpos() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SKM_SSA_FILE, true );
	}
}
add_action( 'before_woocommerce_init', 'skm_ssa_declare_hpos' );

/**
 * Boot the plugin once WooCommerce is loaded.
 */
function skm_ssa_boot() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	SKM_SSA_OAuth::init();
	SKM_SSA_Sync::init();
	SKM_SSA_Settings::init();
}
add_action( 'plugins_loaded', 'skm_ssa_boot', 20 );

/**
 * Add a Settings link on the Plugins screen.
 *
 * @param array $links Action links.
 * @return array
 */
function skm_ssa_action_links( $links ) {
	$url = admin_url( 'admin.php?page=wc-settings&tab=' . SKM_SSA_Settings::TAB );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'skillmerc-sync-for-sage-accounting' ) . '</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'skm_ssa_action_links' );
