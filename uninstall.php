<?php
/**
 * Removes the plugin settings and stored Sage tokens. Order notes and order meta stay, because they record what was sent to Sage.
 *
 * @package SKM_SSA
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$skm_ssa_options = array(
	'skm_ssa_client_id',
	'skm_ssa_client_secret',
	'skm_ssa_tokens',
	'skm_ssa_business_id',
	'skm_ssa_business_name',
	'skm_ssa_base_currency',
	'skm_ssa_bank_account',
	'skm_ssa_sales_ledger',
	'skm_ssa_shipping_ledger',
	'skm_ssa_default_tax_rate',
	'skm_ssa_zero_tax_rate',
	'skm_ssa_tax_map',
	'skm_ssa_statuses',
	'skm_ssa_record_payment',
	'skm_ssa_debug',
	'skm_ssa_refresh_lock',
);
foreach ( $skm_ssa_options as $skm_ssa_option ) {
	delete_option( $skm_ssa_option );
}
delete_transient( 'skm_ssa_lists' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'skm_ssa_sync_order' );
}
