<?php
/**
 * Logging to WooCommerce > Status > Logs.
 *
 * @package SKM_SSA
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around wc_get_logger() with a fixed source.
 */
class SKM_SSA_Logger {

	/**
	 * Log source shown in WooCommerce > Status > Logs.
	 */
	const SOURCE = 'skillmerc-sync-for-sage-accounting';

	/**
	 * Write a log line. Never pass tokens or secrets here.
	 *
	 * @param string $message Message.
	 * @param string $level   Level (info, warning, error, debug).
	 */
	public static function log( $message, $level = 'info' ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		if ( 'debug' === $level && 'yes' !== get_option( 'skm_ssa_debug', 'no' ) ) {
			return;
		}
		wc_get_logger()->log( $level, $message, array( 'source' => self::SOURCE ) );
	}
}
