<?php
/**
 * Sage Accounting API v3.1 client.
 *
 * @package SKM_SSA
 */

defined( 'ABSPATH' ) || exit;

/**
 * JSON requests with the bearer token and X-Business header.
 */
class SKM_SSA_API {

	const BASE = 'https://api.accounting.sage.com/v3.1';

	/**
	 * Send a request.
	 *
	 * @param string     $method       HTTP method.
	 * @param string     $path         Path starting with a slash.
	 * @param array|null $body         JSON body.
	 * @param array      $query        Query args.
	 * @param bool       $use_business Send the X-Business header.
	 * @return array|WP_Error Decoded body. WP_Error data: status, retry, body.
	 */
	public static function request( $method, $path, $body = null, $query = array(), $use_business = true ) {
		$url = self::BASE . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
		}
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$token = SKM_SSA_OAuth::access_token( $attempt > 0 );
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$headers  = array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
			);
			$business = (string) get_option( 'skm_ssa_business_id', '' );
			if ( $use_business && '' !== $business ) {
				$headers['X-Business'] = $business;
			}
			$args = array(
				'method'  => $method,
				'timeout' => 30,
				'headers' => $headers,
			);
			if ( null !== $body ) {
				$args['body'] = wp_json_encode( $body );
			}
			$res = wp_remote_request( $url, $args );
			if ( is_wp_error( $res ) ) {
				SKM_SSA_Logger::log( $method . ' ' . $path . ' failed: ' . $res->get_error_message(), 'error' );
				return new WP_Error( 'skm_ssa_http', $res->get_error_message(), array( 'retry' => true ) );
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			if ( 401 === $code && 0 === $attempt ) {
				continue;
			}
			$data = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( $code >= 200 && $code < 300 ) {
				return is_array( $data ) ? $data : array();
			}
			$message = self::error_message( $data, $code );
			SKM_SSA_Logger::log( $method . ' ' . $path . ' returned HTTP ' . $code . ': ' . $message, 'error' );
			return new WP_Error(
				'skm_ssa_api',
				$message,
				array(
					'status' => $code,
					'retry'  => true,
					'body'   => $data,
				)
			);
		}
		return new WP_Error( 'skm_ssa_auth', 'Sage rejected the access token.', array( 'retry' => true ) );
	}

	/**
	 * Readable message from a Sage error body: [{"$severity":"error","$dataCode":"..","$message":"..","$source":".."}].
	 *
	 * @param mixed $data Decoded body.
	 * @param int   $code HTTP status.
	 * @return string
	 */
	public static function error_message( $data, $code ) {
		$parts = array();
		if ( is_array( $data ) ) {
			$list = isset( $data[0] ) ? $data : array( $data );
			foreach ( $list as $err ) {
				if ( is_array( $err ) && isset( $err['$message'] ) ) {
					$parts[] = ( isset( $err['$source'] ) && '' !== $err['$source'] ? $err['$source'] . ': ' : '' ) . $err['$message'];
				}
			}
		}
		return $parts ? implode( '; ', array_slice( $parts, 0, 5 ) ) : 'HTTP ' . $code;
	}

	/**
	 * GET a list endpoint and return its $items.
	 *
	 * @param string $path  Path.
	 * @param array  $query Query args.
	 * @return array|WP_Error
	 */
	public static function items( $path, $query = array() ) {
		$res = self::request( 'GET', $path, null, $query );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return isset( $res['$items'] ) && is_array( $res['$items'] ) ? $res['$items'] : array();
	}
}
