<?php
/**
 * OAuth 2 connection to Sage Accounting with the merchant's own Sage developer app.
 *
 * @package SKM_SSA
 */

defined( 'ABSPATH' ) || exit;

/**
 * Connect, callback, token storage and refresh.
 */
class SKM_SSA_OAuth {

	const AUTH_URL  = 'https://www.sageone.com/oauth2/auth/central?filter=apiv3.1';
	const TOKEN_URL = 'https://oauth.accounting.sage.com/token';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_post_skm_ssa_connect', array( __CLASS__, 'handle_connect' ) );
		add_action( 'admin_post_skm_ssa_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_handle_callback' ) );
		add_filter( 'allowed_redirect_hosts', array( __CLASS__, 'allowed_hosts' ) );
	}

	/**
	 * Allow the redirect to the Sage sign-in page.
	 *
	 * @param array $hosts Hosts.
	 * @return array
	 */
	public static function allowed_hosts( $hosts ) {
		$hosts[] = 'www.sageone.com';
		return $hosts;
	}

	/**
	 * The redirect URI the merchant registers in their Sage app. Sage appends ?code=..&state=..&country=..
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return admin_url( 'admin.php' );
	}

	/**
	 * Settings screen URL.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public static function settings_url( $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=wc-settings&tab=skm_ssa' ) );
	}

	/**
	 * Build the Sage authorisation URL and remember the state.
	 *
	 * @return string Empty when no client id is saved.
	 */
	public static function authorize_url() {
		$client_id = (string) get_option( 'skm_ssa_client_id', '' );
		if ( '' === $client_id ) {
			return '';
		}
		$state = wp_generate_password( 32, false );
		set_transient( 'skm_ssa_state_' . md5( $state ), get_current_user_id(), 15 * MINUTE_IN_SECONDS );
		return add_query_arg(
			array(
				'response_type' => 'code',
				'scope'         => 'full_access',
				'client_id'     => rawurlencode( $client_id ),
				'redirect_uri'  => rawurlencode( self::redirect_uri() ),
				'state'         => rawurlencode( $state ),
			),
			self::AUTH_URL
		);
	}

	/**
	 * Start the connection: redirect to Sage.
	 */
	public static function handle_connect() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			wp_die( esc_html__( 'You are not allowed to do this.', 'skillmerc-sync-for-sage-accounting' ) );
		}
		check_admin_referer( 'skm_ssa_connect' );
		$url = self::authorize_url();
		if ( '' === $url ) {
			wp_safe_redirect( self::settings_url( array( 'skm_ssa_msg' => 'no_client' ) ) );
			exit;
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Forget the tokens.
	 */
	public static function handle_disconnect() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			wp_die( esc_html__( 'You are not allowed to do this.', 'skillmerc-sync-for-sage-accounting' ) );
		}
		check_admin_referer( 'skm_ssa_disconnect' );
		self::disconnect();
		wp_safe_redirect( self::settings_url( array( 'skm_ssa_msg' => 'disconnected' ) ) );
		exit;
	}

	/**
	 * Clear tokens and business details.
	 */
	public static function disconnect() {
		delete_option( 'skm_ssa_tokens' );
		delete_option( 'skm_ssa_business_id' );
		delete_option( 'skm_ssa_business_name' );
		delete_option( 'skm_ssa_base_currency' );
		delete_transient( 'skm_ssa_lists' );
		SKM_SSA_Logger::log( 'Disconnected from Sage.' );
	}

	/**
	 * Handle the redirect back from Sage on admin.php?code=..&state=..
	 */
	public static function maybe_handle_callback() {
		// The state value is the CSRF check here (Sage cannot send a WordPress nonce back).
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['state'] ) || ( ! isset( $_GET['code'] ) && ! isset( $_GET['error'] ) ) ) {
			return;
		}
		$state = sanitize_text_field( wp_unslash( $_GET['state'] ) );
		$key   = 'skm_ssa_state_' . md5( $state );
		$user  = get_transient( $key );
		if ( false === $user ) {
			return;
		}
		delete_transient( $key );
		if ( get_current_user_id() !== (int) $user || ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			return;
		}
		if ( isset( $_GET['error'] ) ) {
			SKM_SSA_Logger::log( 'Sage authorisation refused: ' . sanitize_text_field( wp_unslash( $_GET['error'] ) ), 'warning' );
			wp_safe_redirect( self::settings_url( array( 'skm_ssa_msg' => 'refused' ) ) );
			exit;
		}
		$code = sanitize_text_field( wp_unslash( $_GET['code'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = self::exchange_code( $code );
		$msg    = is_wp_error( $result ) ? 'connect_failed' : 'connected';
		wp_safe_redirect( self::settings_url( array( 'skm_ssa_msg' => $msg ) ) );
		exit;
	}

	/**
	 * Exchange an authorisation code for tokens, then load the business.
	 *
	 * @param string $code Authorisation code.
	 * @return true|WP_Error
	 */
	public static function exchange_code( $code ) {
		$tokens = self::token_request(
			array(
				'grant_type'   => 'authorization_code',
				'code'         => $code,
				'redirect_uri' => self::redirect_uri(),
			)
		);
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}
		return self::load_business();
	}

	/**
	 * Read the first business (and its base currency) after connecting.
	 *
	 * @return true|WP_Error
	 */
	public static function load_business() {
		$res = SKM_SSA_API::request( 'GET', '/businesses', null, array(), false );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$items = isset( $res['$items'] ) && is_array( $res['$items'] ) ? $res['$items'] : array();
		if ( empty( $items ) ) {
			return new WP_Error( 'skm_ssa_no_business', 'No Sage business on this login.' );
		}
		update_option( 'skm_ssa_business_id', sanitize_text_field( $items[0]['id'] ), false );
		update_option( 'skm_ssa_business_name', sanitize_text_field( isset( $items[0]['displayed_as'] ) ? $items[0]['displayed_as'] : '' ), false );
		$fin = SKM_SSA_API::request( 'GET', '/financial_settings' );
		if ( ! is_wp_error( $fin ) && isset( $fin['base_currency']['id'] ) ) {
			update_option( 'skm_ssa_base_currency', sanitize_text_field( $fin['base_currency']['id'] ), false );
		}
		delete_transient( 'skm_ssa_lists' );
		SKM_SSA_Logger::log( 'Connected to Sage business ' . get_option( 'skm_ssa_business_name' ) . '.' );
		return true;
	}

	/**
	 * POST to the token endpoint and store the result.
	 *
	 * @param array $params Grant parameters.
	 * @return array|WP_Error Stored tokens.
	 */
	public static function token_request( $params ) {
		$params['client_id']     = (string) get_option( 'skm_ssa_client_id', '' );
		$params['client_secret'] = (string) get_option( 'skm_ssa_client_secret', '' );
		$res                     = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 20,
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => $params,
			)
		);
		if ( is_wp_error( $res ) ) {
			SKM_SSA_Logger::log( 'Token request failed: ' . $res->get_error_message(), 'error' );
			return new WP_Error( 'skm_ssa_token', $res->get_error_message(), array( 'retry' => true ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || empty( $body['access_token'] ) ) {
			$err = is_array( $body ) && isset( $body['error'] ) ? sanitize_text_field( $body['error'] ) : 'HTTP ' . $code;
			SKM_SSA_Logger::log( 'Token request (' . $params['grant_type'] . ') refused: ' . $err, 'error' );
			if ( 'invalid_grant' === $err && 'refresh_token' === $params['grant_type'] ) {
				delete_option( 'skm_ssa_tokens' );
			}
			return new WP_Error( 'skm_ssa_token', $err, array( 'retry' => $code >= 500 ) );
		}
		$now    = time();
		$old    = self::get_tokens();
		$tokens = array(
			'access_token'       => (string) $body['access_token'],
			'refresh_token'      => ! empty( $body['refresh_token'] ) ? (string) $body['refresh_token'] : ( isset( $old['refresh_token'] ) ? $old['refresh_token'] : '' ),
			'expires_at'         => $now + ( isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 300 ),
			'refresh_expires_at' => $now + ( isset( $body['refresh_token_expires_in'] ) ? (int) $body['refresh_token_expires_in'] : 2678400 ),
		);
		update_option( 'skm_ssa_tokens', $tokens, false );
		return $tokens;
	}

	/**
	 * Stored tokens.
	 *
	 * @return array
	 */
	public static function get_tokens() {
		$t = get_option( 'skm_ssa_tokens', array() );
		return is_array( $t ) ? $t : array();
	}

	/**
	 * Whether a refresh token is stored.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$t = self::get_tokens();
		return ! empty( $t['refresh_token'] ) && ( empty( $t['refresh_expires_at'] ) || $t['refresh_expires_at'] > time() );
	}

	/**
	 * A valid access token, refreshing it first when it expires within 60 seconds.
	 * Sage rotates the refresh token on every refresh, so only one request may refresh at a time.
	 *
	 * @param bool $force Refresh even if the current token looks valid.
	 * @return string|WP_Error
	 */
	public static function access_token( $force = false ) {
		$t = self::get_tokens();
		if ( empty( $t['refresh_token'] ) ) {
			return new WP_Error( 'skm_ssa_not_connected', 'Not connected to Sage.', array( 'retry' => false ) );
		}
		if ( ! $force && ! empty( $t['access_token'] ) && $t['expires_at'] - 60 > time() ) {
			return $t['access_token'];
		}
		$stale = $t['access_token'];
		if ( ! add_option( 'skm_ssa_refresh_lock', time(), '', false ) ) {
			$since = (int) get_option( 'skm_ssa_refresh_lock' );
			if ( $since > time() - 30 ) {
				// Another request is refreshing: wait for it and reuse its token.
				for ( $i = 0; $i < 10; $i++ ) {
					sleep( 1 );
					wp_cache_delete( 'skm_ssa_tokens', 'options' );
					wp_cache_delete( 'alloptions', 'options' );
					$t = self::get_tokens();
					if ( ! empty( $t['access_token'] ) && $t['access_token'] !== $stale ) {
						return $t['access_token'];
					}
				}
				return new WP_Error( 'skm_ssa_refresh_busy', 'Token refresh in progress.', array( 'retry' => true ) );
			}
			update_option( 'skm_ssa_refresh_lock', time(), false );
		}
		$new = self::token_request(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $t['refresh_token'],
			)
		);
		delete_option( 'skm_ssa_refresh_lock' );
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		return $new['access_token'];
	}
}
