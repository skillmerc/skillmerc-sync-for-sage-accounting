<?php
/**
 * Settings tab under WooCommerce > Settings > Sage Sync.
 *
 * @package SKM_SSA
 */

defined( 'ABSPATH' ) || exit;

/**
 * Connection, ledger and tax mapping, and sync options.
 */
class SKM_SSA_Settings {

	const TAB = 'skm_ssa';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_settings_tabs_array', array( __CLASS__, 'add_tab' ), 60 );
		add_action( 'woocommerce_settings_' . self::TAB, array( __CLASS__, 'output' ) );
		add_action( 'woocommerce_settings_save_' . self::TAB, array( __CLASS__, 'save' ) );
		add_action( 'woocommerce_admin_field_skm_ssa_connect', array( __CLASS__, 'field_connect' ) );
		add_action( 'woocommerce_admin_field_skm_ssa_secret', array( __CLASS__, 'field_secret' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option_skm_ssa_client_secret', array( __CLASS__, 'sanitize_secret' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	/**
	 * Add the tab.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public static function add_tab( $tabs ) {
		$tabs[ self::TAB ] = __( 'Sage Sync', 'skillmerc-sync-for-sage-accounting' );
		return $tabs;
	}

	/**
	 * Render the tab.
	 */
	public static function output() {
		WC_Admin_Settings::output_fields( self::fields() );
	}

	/**
	 * Save the tab.
	 */
	public static function save() {
		WC_Admin_Settings::save_fields( self::fields() );
	}

	/**
	 * Keep the stored secret when the field is left empty.
	 *
	 * @param mixed $value     Sanitised value.
	 * @param array $option    Field.
	 * @param mixed $raw_value Raw value.
	 * @return string|null Null keeps the current value.
	 */
	public static function sanitize_secret( $value, $option, $raw_value ) {
		$raw_value = is_string( $raw_value ) ? trim( $raw_value ) : '';
		if ( '' === $raw_value ) {
			return null;
		}
		return sanitize_text_field( $raw_value );
	}

	/**
	 * Lists from Sage for the dropdowns, cached for 12 hours.
	 *
	 * @param bool $refresh Ignore the cache.
	 * @return array|WP_Error Keys: ledgers, banks, taxes (id => label).
	 */
	public static function lists( $refresh = false ) {
		$cached = get_transient( 'skm_ssa_lists' );
		if ( ! $refresh && is_array( $cached ) ) {
			return $cached;
		}
		$ledgers = SKM_SSA_API::items(
			'/ledger_accounts',
			array(
				'visible_in'     => 'sales',
				'items_per_page' => '200',
			)
		);
		if ( is_wp_error( $ledgers ) ) {
			return $ledgers;
		}
		$banks = SKM_SSA_API::items( '/bank_accounts', array( 'items_per_page' => '200' ) );
		if ( is_wp_error( $banks ) ) {
			return $banks;
		}
		$taxes = SKM_SSA_API::items(
			'/tax_rates',
			array(
				'usage'          => 'sales',
				'items_per_page' => '200',
			)
		);
		if ( is_wp_error( $taxes ) ) {
			return $taxes;
		}
		$map  = function ( $items ) {
			$out = array();
			foreach ( $items as $i ) {
				if ( isset( $i['id'] ) ) {
					$out[ (string) $i['id'] ] = isset( $i['displayed_as'] ) ? (string) $i['displayed_as'] : (string) $i['id'];
				}
			}
			return $out;
		};
		$data = array(
			'ledgers' => $map( $ledgers ),
			'banks'   => $map( $banks ),
			'taxes'   => $map( $taxes ),
		);
		set_transient( 'skm_ssa_lists', $data, 12 * HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * Pick a default id from a list by a label fragment, e.g. "(4000)".
	 *
	 * @param array  $options id => label.
	 * @param string $needle Label fragment.
	 * @return string
	 */
	public static function guess( $options, $needle ) {
		foreach ( $options as $id => $label ) {
			if ( false !== strpos( $label, $needle ) ) {
				return (string) $id;
			}
		}
		return '';
	}

	/**
	 * WooCommerce tax rates as id => label.
	 *
	 * @return array
	 */
	public static function wc_tax_rates() {
		global $wpdb;
		// Direct read of the WooCommerce tax rates table; there is no core API that lists every rate.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT tax_rate_id, tax_rate_country, tax_rate, tax_rate_name, tax_rate_class FROM {$wpdb->prefix}woocommerce_tax_rates ORDER BY tax_rate_order, tax_rate_id", ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$class                          = '' !== $r['tax_rate_class'] ? $r['tax_rate_class'] : 'standard';
			$out[ (int) $r['tax_rate_id'] ] = trim( $r['tax_rate_name'] . ' ' . wc_format_decimal( $r['tax_rate'] ) . '% ' . $r['tax_rate_country'] . ' (' . $class . ')' );
		}
		return $out;
	}

	/**
	 * Field definitions.
	 *
	 * @return array
	 */
	public static function fields() {
		$fields = array(
			array(
				'title' => __( 'Connect to Sage Accounting', 'skillmerc-sync-for-sage-accounting' ),
				'type'  => 'title',
				'id'    => 'skm_ssa_connection',
				'desc'  => sprintf(
					/* translators: 1: Sage developer portal URL, 2: redirect URI */
					__( 'Create a free app at %1$s (sign in with GitHub or Google, then "Create App"). Enter this callback URL in the app: %2$s . Paste the app\'s Client ID and Client Secret below, save, then click Connect.', 'skillmerc-sync-for-sage-accounting' ),
					'<a href="https://developerselfservice.sageone.com/" target="_blank" rel="noopener noreferrer">developerselfservice.sageone.com</a>',
					'<code>' . esc_html( SKM_SSA_OAuth::redirect_uri() ) . '</code>'
				),
			),
			array(
				'title'    => __( 'Client ID', 'skillmerc-sync-for-sage-accounting' ),
				'id'       => 'skm_ssa_client_id',
				'type'     => 'text',
				'default'  => '',
				'autoload' => false,
			),
			array(
				'title'    => __( 'Client Secret', 'skillmerc-sync-for-sage-accounting' ),
				'id'       => 'skm_ssa_client_secret',
				'type'     => 'skm_ssa_secret',
				'default'  => '',
				'autoload' => false,
			),
			array(
				'title' => __( 'Connection', 'skillmerc-sync-for-sage-accounting' ),
				'id'    => 'skm_ssa_connect_button',
				'type'  => 'skm_ssa_connect',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'skm_ssa_connection',
			),
		);

		if ( SKM_SSA_OAuth::is_connected() ) {
			$lists = self::lists();
			if ( is_wp_error( $lists ) ) {
				$fields[] = array(
					'title' => __( 'Accounts and VAT', 'skillmerc-sync-for-sage-accounting' ),
					'type'  => 'title',
					'id'    => 'skm_ssa_accounts',
					/* translators: %s: error message */
					'desc'  => esc_html( sprintf( __( 'Could not load your Sage accounts: %s', 'skillmerc-sync-for-sage-accounting' ), $lists->get_error_message() ) ),
				);
				$fields[] = array(
					'type' => 'sectionend',
					'id'   => 'skm_ssa_accounts',
				);
			} else {
				$fields = array_merge( $fields, self::account_fields( $lists ) );
			}
		}

		$statuses = array();
		foreach ( wc_get_order_statuses() as $key => $label ) {
			$status              = 0 === strpos( $key, 'wc-' ) ? substr( $key, 3 ) : $key;
			$statuses[ $status ] = $label;
		}
		$fields[] = array(
			'title' => __( 'Sync', 'skillmerc-sync-for-sage-accounting' ),
			'type'  => 'title',
			'id'    => 'skm_ssa_sync',
			'desc'  => __( 'Orders are sent in the background a few seconds after they reach one of these statuses. Failed sends retry up to 5 times (5 minutes, 30 minutes, 2 hours, 6 hours). Each order also gets a "Sync to Sage now" order action.', 'skillmerc-sync-for-sage-accounting' ),
		);
		$fields[] = array(
			'title'   => __( 'Send orders when status is', 'skillmerc-sync-for-sage-accounting' ),
			'id'      => 'skm_ssa_statuses',
			'type'    => 'multiselect',
			'class'   => 'wc-enhanced-select',
			'options' => $statuses,
			'default' => array( 'processing', 'completed' ),
		);
		$fields[] = array(
			'title'   => __( 'Record the payment', 'skillmerc-sync-for-sage-accounting' ),
			'id'      => 'skm_ssa_record_payment',
			'type'    => 'checkbox',
			'desc'    => __( 'Create a customer receipt in the bank account above, so the invoice shows as paid.', 'skillmerc-sync-for-sage-accounting' ),
			'default' => 'yes',
		);
		$fields[] = array(
			'title'   => __( 'Debug log', 'skillmerc-sync-for-sage-accounting' ),
			'id'      => 'skm_ssa_debug',
			'type'    => 'checkbox',
			/* translators: %s: log source name */
			'desc'    => sprintf( __( 'Write extra detail to WooCommerce > Status > Logs (source: %s). Errors are always logged.', 'skillmerc-sync-for-sage-accounting' ), SKM_SSA_Logger::SOURCE ),
			'default' => 'no',
		);
		$fields[] = array(
			'type' => 'sectionend',
			'id'   => 'skm_ssa_sync',
		);
		return $fields;
	}

	/**
	 * Ledger, bank and VAT fields once connected.
	 *
	 * @param array $lists Sage lists.
	 * @return array
	 */
	public static function account_fields( $lists ) {
		$none   = array( '' => __( 'Choose...', 'skillmerc-sync-for-sage-accounting' ) );
		$fields = array(
			array(
				'title' => __( 'Accounts and VAT', 'skillmerc-sync-for-sage-accounting' ),
				'type'  => 'title',
				'id'    => 'skm_ssa_accounts',
			),
			array(
				'title'    => __( 'Sales ledger account', 'skillmerc-sync-for-sage-accounting' ),
				'id'       => 'skm_ssa_sales_ledger',
				'type'     => 'select',
				'class'    => 'wc-enhanced-select',
				'options'  => $none + $lists['ledgers'],
				'default'  => self::guess( $lists['ledgers'], '(4000)' ),
				'desc_tip' => __( 'Products and fees go to this account.', 'skillmerc-sync-for-sage-accounting' ),
			),
			array(
				'title'   => __( 'Shipping ledger account', 'skillmerc-sync-for-sage-accounting' ),
				'id'      => 'skm_ssa_shipping_ledger',
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'options' => $none + $lists['ledgers'],
				'default' => self::guess( $lists['ledgers'], '(4910)' ),
			),
			array(
				'title'    => __( 'Bank account for payments', 'skillmerc-sync-for-sage-accounting' ),
				'id'       => 'skm_ssa_bank_account',
				'type'     => 'select',
				'class'    => 'wc-enhanced-select',
				'options'  => $none + $lists['banks'],
				'default'  => self::guess( $lists['banks'], '(1200)' ),
				'desc_tip' => __( 'Where the customer receipt is recorded, for example the bank account your payment gateway pays out to.', 'skillmerc-sync-for-sage-accounting' ),
			),
			array(
				'title'   => __( 'VAT rate for taxed lines', 'skillmerc-sync-for-sage-accounting' ),
				'id'      => 'skm_ssa_default_tax_rate',
				'type'    => 'select',
				'options' => $lists['taxes'],
				'default' => isset( $lists['taxes']['GB_STANDARD'] ) ? 'GB_STANDARD' : (string) key( $lists['taxes'] ),
			),
			array(
				'title'   => __( 'VAT rate for untaxed lines', 'skillmerc-sync-for-sage-accounting' ),
				'id'      => 'skm_ssa_zero_tax_rate',
				'type'    => 'select',
				'options' => $lists['taxes'],
				'default' => isset( $lists['taxes']['GB_ZERO'] ) ? 'GB_ZERO' : (string) key( $lists['taxes'] ),
			),
		);
		foreach ( self::wc_tax_rates() as $rate_id => $label ) {
			$fields[] = array(
				/* translators: %s: WooCommerce tax rate label */
				'title'   => sprintf( __( 'Map WooCommerce rate: %s', 'skillmerc-sync-for-sage-accounting' ), $label ),
				'id'      => 'skm_ssa_tax_map[' . $rate_id . ']',
				'type'    => 'select',
				'options' => array( '' => __( 'Use the VAT rate for taxed lines', 'skillmerc-sync-for-sage-accounting' ) ) + $lists['taxes'],
				'default' => '',
			);
		}
		$fields[] = array(
			'type' => 'sectionend',
			'id'   => 'skm_ssa_accounts',
		);
		return $fields;
	}

	/**
	 * Secret field that never prints the stored value.
	 *
	 * @param array $field Field.
	 */
	public static function field_secret( $field ) {
		$has = '' !== (string) get_option( 'skm_ssa_client_secret', '' );
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><label for="skm_ssa_client_secret"><?php echo esc_html( $field['title'] ); ?></label></th>
			<td class="forminp">
				<input type="password" name="skm_ssa_client_secret" id="skm_ssa_client_secret" value="" autocomplete="new-password" class="regular-input" placeholder="<?php echo esc_attr( $has ? __( 'Saved. Leave empty to keep it.', 'skillmerc-sync-for-sage-accounting' ) : '' ); ?>" />
			</td>
		</tr>
		<?php
	}

	/**
	 * Connect or disconnect button with the connected business name.
	 *
	 * @param array $field Field.
	 */
	public static function field_connect( $field ) {
		$connected = SKM_SSA_OAuth::is_connected();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $field['title'] ); ?></th>
			<td class="forminp">
				<?php if ( $connected ) : ?>
					<p>
						<?php
						/* translators: %s: Sage business name */
						echo esc_html( sprintf( __( 'Connected to %s.', 'skillmerc-sync-for-sage-accounting' ), (string) get_option( 'skm_ssa_business_name', '' ) ) );
						?>
					</p>
					<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=skm_ssa_disconnect' ), 'skm_ssa_disconnect' ) ); ?>"><?php esc_html_e( 'Disconnect', 'skillmerc-sync-for-sage-accounting' ); ?></a></p>
				<?php elseif ( '' === (string) get_option( 'skm_ssa_client_id', '' ) || '' === (string) get_option( 'skm_ssa_client_secret', '' ) ) : ?>
					<p><?php esc_html_e( 'Save your Client ID and Client Secret first.', 'skillmerc-sync-for-sage-accounting' ); ?></p>
				<?php else : ?>
					<p><a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=skm_ssa_connect' ), 'skm_ssa_connect' ) ); ?>"><?php esc_html_e( 'Connect to Sage', 'skillmerc-sync-for-sage-accounting' ); ?></a></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Result message after connect or disconnect.
	 */
	public static function notices() {
		// Read-only display of a message key set by our own redirect.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg = isset( $_GET['skm_ssa_msg'] ) ? sanitize_key( wp_unslash( $_GET['skm_ssa_msg'] ) ) : '';
		if ( '' === $msg || ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			return;
		}
		$messages = array(
			'connected'      => array( 'success', __( 'Connected to Sage Accounting. Check the ledger accounts and VAT rates below, then save.', 'skillmerc-sync-for-sage-accounting' ) ),
			'disconnected'   => array( 'success', __( 'Disconnected from Sage Accounting.', 'skillmerc-sync-for-sage-accounting' ) ),
			'refused'        => array( 'warning', __( 'Sage did not authorise the connection.', 'skillmerc-sync-for-sage-accounting' ) ),
			'connect_failed' => array( 'error', __( 'Connecting to Sage failed. Check the Client ID, Client Secret and callback URL, then see WooCommerce > Status > Logs.', 'skillmerc-sync-for-sage-accounting' ) ),
			'no_client'      => array( 'error', __( 'Save your Client ID and Client Secret first.', 'skillmerc-sync-for-sage-accounting' ) ),
		);
		if ( ! isset( $messages[ $msg ] ) ) {
			return;
		}
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $msg ][0] ), esc_html( $messages[ $msg ][1] ) );
	}
}
