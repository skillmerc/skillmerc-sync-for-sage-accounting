<?php
/**
 * Order to Sage sync: contact, sales invoice, customer receipt.
 *
 * @package SKM_SSA
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends paid orders to Sage. Each step stores its Sage id on the order, so a retry never repeats a finished step.
 */
class SKM_SSA_Sync {

	const HOOK         = 'skm_ssa_sync_order';
	const GROUP        = 'skillmerc-sync-for-sage-accounting';
	const MAX_ATTEMPTS = 5;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 20, 4 );
		add_action( self::HOOK, array( __CLASS__, 'run_scheduled' ), 10, 2 );
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'order_actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_skm_ssa_sync_now', array( __CLASS__, 'order_action_sync_now' ) );
	}

	/**
	 * Order statuses that trigger a sync (without the wc- prefix).
	 *
	 * @return array
	 */
	public static function trigger_statuses() {
		$s = get_option( 'skm_ssa_statuses', array( 'processing', 'completed' ) );
		$s = is_array( $s ) ? $s : array();
		return array_map(
			function ( $v ) {
				return 0 === strpos( $v, 'wc-' ) ? substr( $v, 3 ) : $v;
			},
			$s
		);
	}

	/**
	 * Queue a sync when an order reaches a trigger status.
	 *
	 * @param int      $order_id Order id.
	 * @param string   $from     Old status.
	 * @param string   $to       New status.
	 * @param WC_Order $order    Order.
	 */
	public static function on_status_changed( $order_id, $from, $to, $order = null ) {
		if ( ! in_array( $to, self::trigger_statuses(), true ) || ! SKM_SSA_OAuth::is_connected() ) {
			return;
		}
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order || self::is_done( $order ) ) {
			return;
		}
		self::queue( $order_id, 1, 0 );
	}

	/**
	 * Whether every step is finished for this order.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function is_done( $order ) {
		if ( '' === (string) $order->get_meta( '_skm_ssa_invoice_id' ) ) {
			return false;
		}
		return ! self::wants_payment( $order ) || '' !== (string) $order->get_meta( '_skm_ssa_receipt_id' );
	}

	/**
	 * Whether to record the payment for this order.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function wants_payment( $order ) {
		return 'no' !== get_option( 'skm_ssa_record_payment', 'yes' ) && (float) $order->get_total() > 0;
	}

	/**
	 * Schedule a background sync.
	 *
	 * @param int $order_id Order id.
	 * @param int $attempt  Attempt number (1 based).
	 * @param int $delay    Delay in seconds.
	 */
	public static function queue( $order_id, $attempt, $delay ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$args = array(
			'order_id' => (int) $order_id,
			'attempt'  => (int) $attempt,
		);
		if ( as_next_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
			return;
		}
		as_schedule_single_action( time() + $delay, self::HOOK, $args, self::GROUP );
	}

	/**
	 * Backoff before attempt N (2..5): 5 min, 30 min, 2 h, 6 h.
	 *
	 * @param int $attempt Next attempt number.
	 * @return int Seconds.
	 */
	public static function backoff( $attempt ) {
		$table = array(
			2 => 5 * MINUTE_IN_SECONDS,
			3 => 30 * MINUTE_IN_SECONDS,
			4 => 2 * HOUR_IN_SECONDS,
			5 => 6 * HOUR_IN_SECONDS,
		);
		return isset( $table[ $attempt ] ) ? $table[ $attempt ] : 6 * HOUR_IN_SECONDS;
	}

	/**
	 * Action Scheduler callback.
	 *
	 * @param int $order_id Order id.
	 * @param int $attempt  Attempt number.
	 */
	public static function run_scheduled( $order_id, $attempt = 1 ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || self::is_done( $order ) ) {
			return;
		}
		$result = self::sync_order( $order );
		if ( is_wp_error( $result ) ) {
			self::handle_failure( $order, (int) $attempt, $result );
		}
	}

	/**
	 * Note the failure and schedule the next attempt with backoff, up to MAX_ATTEMPTS.
	 *
	 * @param WC_Order $order   Order.
	 * @param int      $attempt Attempt that failed.
	 * @param WP_Error $error   Error.
	 */
	public static function handle_failure( $order, $attempt, $error ) {
		$data  = $error->get_error_data();
		$retry = ! is_array( $data ) || ! isset( $data['retry'] ) || $data['retry'];
		$msg   = $error->get_error_message();
		$order->update_meta_data( '_skm_ssa_last_error', $msg );
		$order->update_meta_data( '_skm_ssa_attempts', $attempt );
		$order->save();
		if ( $retry && $attempt < self::MAX_ATTEMPTS ) {
			$next  = $attempt + 1;
			$delay = self::backoff( $next );
			self::queue( $order->get_id(), $next, $delay );
			/* translators: 1: attempt number, 2: error message, 3: minutes until the next try */
			$order->add_order_note( sprintf( __( 'Sage sync attempt %1$d failed: %2$s. Trying again in %3$d minutes.', 'skillmerc-sync-for-sage-accounting' ), $attempt, $msg, (int) round( $delay / 60 ) ) );
		} else {
			/* translators: 1: attempt number, 2: error message */
			$order->add_order_note( sprintf( __( 'Sage sync stopped after attempt %1$d: %2$s. Fix the cause, then use the order action "Sync to Sage now".', 'skillmerc-sync-for-sage-accounting' ), $attempt, $msg ) );
		}
	}

	/**
	 * Add the manual order action.
	 *
	 * @param array    $actions Actions.
	 * @param WC_Order $order   Order (WooCommerce 8.1+).
	 * @return array
	 */
	public static function order_actions( $actions, $order = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Filter signature.
		if ( SKM_SSA_OAuth::is_connected() ) {
			$actions['skm_ssa_sync_now'] = __( 'Sync to Sage now', 'skillmerc-sync-for-sage-accounting' );
		}
		return $actions;
	}

	/**
	 * Manual sync from the order screen.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function order_action_sync_now( $order ) {
		if ( self::is_done( $order ) ) {
			$order->add_order_note( __( 'Already in Sage, nothing to send.', 'skillmerc-sync-for-sage-accounting' ) );
			return;
		}
		$result = self::sync_order( $order );
		if ( is_wp_error( $result ) ) {
			/* translators: %s: error message */
			$order->add_order_note( sprintf( __( 'Sage sync failed: %s', 'skillmerc-sync-for-sage-accounting' ), $result->get_error_message() ) );
		}
	}

	/**
	 * Run all steps for an order.
	 *
	 * @param WC_Order $order Order.
	 * @return true|WP_Error
	 */
	public static function sync_order( $order ) {
		$base = (string) get_option( 'skm_ssa_base_currency', '' );
		if ( '' !== $base && $order->get_currency() !== $base ) {
			/* translators: 1: order currency, 2: Sage base currency */
			return new WP_Error( 'skm_ssa_currency', sprintf( __( 'Order currency %1$s differs from the Sage base currency %2$s. Version 0.1 only sends orders in the base currency.', 'skillmerc-sync-for-sage-accounting' ), $order->get_currency(), $base ), array( 'retry' => false ) );
		}
		foreach ( array( 'skm_ssa_sales_ledger', 'skm_ssa_shipping_ledger' ) as $opt ) {
			if ( '' === (string) get_option( $opt, '' ) ) {
				return new WP_Error( 'skm_ssa_settings', __( 'Choose the sales and shipping ledger accounts in the Sage Sync settings.', 'skillmerc-sync-for-sage-accounting' ), array( 'retry' => false ) );
			}
		}

		$contact_id = self::ensure_contact( $order );
		if ( is_wp_error( $contact_id ) ) {
			return $contact_id;
		}
		$invoice = self::ensure_invoice( $order, $contact_id );
		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}
		if ( self::wants_payment( $order ) ) {
			$receipt = self::ensure_receipt( $order, $contact_id );
			if ( is_wp_error( $receipt ) ) {
				return $receipt;
			}
		}
		$order->delete_meta_data( '_skm_ssa_last_error' );
		$order->save();
		return true;
	}

	/**
	 * Contact reference: WC-C<user id> for accounts, WC-G<hash of email> for guests.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function contact_reference( $order ) {
		$user_id = (int) $order->get_customer_id();
		if ( $user_id > 0 ) {
			return 'WC-C' . $user_id;
		}
		$email = strtolower( trim( (string) $order->get_billing_email() ) );
		if ( '' === $email ) {
			return 'WC-O' . $order->get_id();
		}
		return 'WC-G' . strtoupper( substr( md5( $email ), 0, 10 ) );
	}

	/**
	 * Address block from the order (billing, then shipping, then store country).
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function address( $order ) {
		$type    = '' !== (string) $order->get_billing_address_1() || '' === (string) $order->get_shipping_address_1() ? 'billing' : 'shipping';
		$get     = function ( $field ) use ( $order, $type ) {
			$m = 'get_' . $type . '_' . $field;
			return trim( (string) $order->$m() );
		};
		$country = $get( 'country' );
		if ( '' === $country ) {
			$country = WC()->countries ? WC()->countries->get_base_country() : 'GB';
		}
		$line1 = $get( 'address_1' );
		$addr  = array(
			'address_line_1' => '' !== $line1 ? $line1 : __( 'No address given', 'skillmerc-sync-for-sage-accounting' ),
			'address_line_2' => $get( 'address_2' ),
			'city'           => $get( 'city' ),
			'region'         => $get( 'state' ),
			'postal_code'    => $get( 'postcode' ),
			'country_id'     => $country,
		);
		return array_filter(
			$addr,
			function ( $v ) {
				return '' !== $v;
			}
		);
	}

	/**
	 * Contact name: company, else full name, else email.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function contact_name( $order ) {
		$name = trim( (string) $order->get_billing_company() );
		if ( '' === $name ) {
			$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		}
		if ( '' === $name ) {
			$name = (string) $order->get_billing_email();
		}
		return '' !== $name ? $name : __( 'WooCommerce customer', 'skillmerc-sync-for-sage-accounting' );
	}

	/**
	 * POST /contacts body.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function contact_payload( $order ) {
		$person  = array_filter(
			array(
				'name'      => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'email'     => (string) $order->get_billing_email(),
				'telephone' => (string) $order->get_billing_phone(),
			)
		);
		$contact = array(
			'name'             => self::contact_name( $order ),
			'contact_type_ids' => array( 'CUSTOMER' ),
			'reference'        => self::contact_reference( $order ),
			'main_address'     => self::address( $order ),
		);
		if ( $person ) {
			$contact['main_contact_person'] = $person;
		}
		return array( 'contact' => apply_filters( 'skm_ssa_contact_payload', $contact, $order ) );
	}

	/**
	 * Find the contact by reference, or create it.
	 *
	 * @param WC_Order $order Order.
	 * @return string|WP_Error Sage contact id.
	 */
	public static function ensure_contact( $order ) {
		$id = (string) $order->get_meta( '_skm_ssa_contact_id' );
		if ( '' !== $id ) {
			return $id;
		}
		$ref   = self::contact_reference( $order );
		$found = SKM_SSA_API::items(
			'/contacts',
			array(
				'search'     => $ref,
				'attributes' => 'reference',
			)
		);
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		foreach ( $found as $c ) {
			if ( isset( $c['reference'], $c['id'] ) && $c['reference'] === $ref ) {
				$id = (string) $c['id'];
				break;
			}
		}
		if ( '' === $id ) {
			$res = SKM_SSA_API::request( 'POST', '/contacts', self::contact_payload( $order ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			if ( empty( $res['id'] ) ) {
				return new WP_Error( 'skm_ssa_contact', 'Sage returned no contact id.' );
			}
			$id = (string) $res['id'];
		}
		$order->update_meta_data( '_skm_ssa_contact_id', $id );
		$order->save();
		return $id;
	}

	/**
	 * Sage tax rate id for a WooCommerce line.
	 *
	 * @param WC_Order_Item $item Item.
	 * @param float         $tax  Line tax.
	 * @return string
	 */
	public static function tax_rate_for( $item, $tax ) {
		if ( abs( $tax ) < 0.005 ) {
			return (string) get_option( 'skm_ssa_zero_tax_rate', 'GB_ZERO' );
		}
		$map   = get_option( 'skm_ssa_tax_map', array() );
		$map   = is_array( $map ) ? $map : array();
		$taxes = $item->get_taxes();
		if ( ! empty( $taxes['total'] ) ) {
			foreach ( $taxes['total'] as $rate_id => $amount ) {
				if ( '' !== (string) $amount && abs( (float) $amount ) >= 0.005 && ! empty( $map[ $rate_id ] ) ) {
					return (string) $map[ $rate_id ];
				}
			}
		}
		return (string) get_option( 'skm_ssa_default_tax_rate', 'GB_STANDARD' );
	}

	/**
	 * One invoice line. Uses the discounted line total; falls back to quantity 1 when total / qty is not a whole penny.
	 *
	 * @param string        $description Description.
	 * @param float         $qty         Quantity.
	 * @param float         $total       Net line total.
	 * @param float         $tax         Line tax.
	 * @param string        $ledger      Ledger account id.
	 * @param WC_Order_Item $item        Item.
	 * @return array
	 */
	public static function line( $description, $qty, $total, $tax, $ledger, $item ) {
		$total = round( (float) $total, 2 );
		$tax   = round( (float) $tax, 2 );
		$qty   = (float) $qty;
		$unit  = $qty > 0 ? round( $total / $qty, 2 ) : $total;
		if ( $qty <= 0 || abs( $unit * $qty - $total ) >= 0.005 ) {
			if ( $qty > 1 ) {
				$description .= ' x ' . wc_format_decimal( $qty );
			}
			$qty  = 1;
			$unit = $total;
		}
		return array(
			'description'       => $description,
			'ledger_account_id' => $ledger,
			'quantity'          => $qty,
			'unit_price'        => $unit,
			'tax_rate_id'       => self::tax_rate_for( $item, $tax ),
			'tax_amount'        => $tax,
		);
	}

	/**
	 * Invoice reference stored in Sage.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function invoice_reference( $order ) {
		return 'WC-' . $order->get_order_number();
	}

	/**
	 * Order date for Sage (paid date, else created), site timezone.
	 *
	 * @param WC_Order $order Order.
	 * @return string Y-m-d
	 */
	public static function order_date( $order ) {
		$d = $order->get_date_paid() ? $order->get_date_paid() : $order->get_date_created();
		return $d ? $d->date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
	}

	/**
	 * POST /sales_invoices body.
	 *
	 * @param WC_Order $order      Order.
	 * @param string   $contact_id Sage contact id.
	 * @return array
	 */
	public static function invoice_payload( $order, $contact_id ) {
		$sales = (string) get_option( 'skm_ssa_sales_ledger', '' );
		$ship  = (string) get_option( 'skm_ssa_shipping_ledger', '' );
		$lines = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$name    = $item->get_name();
			$product = $item->get_product();
			if ( $product && '' !== (string) $product->get_sku() ) {
				$name .= ' (' . $product->get_sku() . ')';
			}
			$lines[] = self::line( $name, $item->get_quantity(), $item->get_total(), $item->get_total_tax(), $sales, $item );
		}
		foreach ( $order->get_items( 'fee' ) as $item ) {
			$lines[] = self::line( $item->get_name(), 1, $item->get_total(), $item->get_total_tax(), $sales, $item );
		}
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			/* translators: %s: shipping method name */
			$lines[] = self::line( sprintf( __( 'Shipping: %s', 'skillmerc-sync-for-sage-accounting' ), $item->get_name() ), 1, $item->get_total(), $item->get_total_tax(), $ship, $item );
		}
		$invoice = array(
			'contact_id'    => $contact_id,
			'date'          => self::order_date( $order ),
			'reference'     => self::invoice_reference( $order ),
			'main_address'  => self::address( $order ),
			'invoice_lines' => $lines,
		);
		/* translators: %s: order number */
		$invoice['notes'] = sprintf( __( 'WooCommerce order %s', 'skillmerc-sync-for-sage-accounting' ), $order->get_order_number() );
		return array( 'sales_invoice' => apply_filters( 'skm_ssa_invoice_payload', $invoice, $order ) );
	}

	/**
	 * Find an invoice already created for this order (covers a lost response), or create it.
	 *
	 * @param WC_Order $order      Order.
	 * @param string   $contact_id Sage contact id.
	 * @return array|WP_Error {id, number, total}
	 */
	public static function ensure_invoice( $order, $contact_id ) {
		$id = (string) $order->get_meta( '_skm_ssa_invoice_id' );
		if ( '' !== $id ) {
			return array(
				'id'     => $id,
				'number' => (string) $order->get_meta( '_skm_ssa_invoice_number' ),
				'total'  => (string) $order->get_meta( '_skm_ssa_invoice_total' ),
			);
		}
		$ref = self::invoice_reference( $order );
		$inv = null;
		if ( (int) $order->get_meta( '_skm_ssa_attempts' ) > 0 ) {
			$found = SKM_SSA_API::items(
				'/sales_invoices',
				array(
					'contact_id' => $contact_id,
					'search'     => $ref,
					'attributes' => 'reference,total_amount,invoice_number',
				)
			);
			if ( is_wp_error( $found ) ) {
				return $found;
			}
			foreach ( $found as $f ) {
				if ( isset( $f['reference'] ) && $f['reference'] === $ref ) {
					$inv = $f;
					break;
				}
			}
		}
		if ( null === $inv ) {
			$inv = SKM_SSA_API::request( 'POST', '/sales_invoices', self::invoice_payload( $order, $contact_id ) );
			if ( is_wp_error( $inv ) ) {
				return $inv;
			}
		}
		if ( empty( $inv['id'] ) ) {
			return new WP_Error( 'skm_ssa_invoice', 'Sage returned no invoice id.' );
		}
		$out = array(
			'id'     => (string) $inv['id'],
			'number' => isset( $inv['invoice_number'] ) ? (string) $inv['invoice_number'] : '',
			'total'  => isset( $inv['total_amount'] ) ? (string) $inv['total_amount'] : '',
		);
		$order->update_meta_data( '_skm_ssa_invoice_id', $out['id'] );
		$order->update_meta_data( '_skm_ssa_invoice_number', $out['number'] );
		$order->update_meta_data( '_skm_ssa_invoice_total', $out['total'] );
		$order->save();
		/* translators: 1: Sage invoice number, 2: total */
		$note = sprintf( __( 'Sage sales invoice %1$s created (total %2$s).', 'skillmerc-sync-for-sage-accounting' ), $out['number'], $out['total'] );
		if ( '' !== $out['total'] && abs( (float) $out['total'] - (float) $order->get_total() ) >= 0.01 ) {
			/* translators: %s: order total */
			$note .= ' ' . sprintf( __( 'Warning: the WooCommerce order total is %s. Check the tax mapping.', 'skillmerc-sync-for-sage-accounting' ), wc_format_decimal( $order->get_total(), 2 ) );
		}
		$order->add_order_note( $note );
		return $out;
	}

	/**
	 * POST /contact_payments body.
	 *
	 * @param WC_Order $order      Order.
	 * @param string   $contact_id Contact id.
	 * @param string   $invoice_id Invoice id.
	 * @param float    $amount     Amount.
	 * @return array
	 */
	public static function receipt_payload( $order, $contact_id, $invoice_id, $amount ) {
		$ref = self::invoice_reference( $order );
		if ( '' !== (string) $order->get_payment_method_title() ) {
			$ref .= ' ' . $order->get_payment_method_title();
		}
		$amount = round( (float) $amount, 2 );
		return array(
			'contact_payment' => apply_filters(
				'skm_ssa_receipt_payload',
				array(
					'transaction_type_id' => 'CUSTOMER_RECEIPT',
					'contact_id'          => $contact_id,
					'bank_account_id'     => (string) get_option( 'skm_ssa_bank_account', '' ),
					'date'                => self::order_date( $order ),
					'total_amount'        => $amount,
					'reference'           => function_exists( 'mb_substr' ) ? mb_substr( $ref, 0, 50 ) : substr( $ref, 0, 50 ),
					'allocated_artefacts' => array(
						array(
							'artefact_id' => $invoice_id,
							'amount'      => $amount,
						),
					),
				),
				$order
			),
		);
	}

	/**
	 * Record the payment against the invoice.
	 *
	 * @param WC_Order $order      Order.
	 * @param string   $contact_id Contact id.
	 * @return string|WP_Error Receipt id.
	 */
	public static function ensure_receipt( $order, $contact_id ) {
		$id = (string) $order->get_meta( '_skm_ssa_receipt_id' );
		if ( '' !== $id ) {
			return $id;
		}
		if ( '' === (string) get_option( 'skm_ssa_bank_account', '' ) ) {
			return new WP_Error( 'skm_ssa_settings', __( 'Choose the Sage bank account for payments in the Sage Sync settings.', 'skillmerc-sync-for-sage-accounting' ), array( 'retry' => false ) );
		}
		$invoice_id = (string) $order->get_meta( '_skm_ssa_invoice_id' );
		$amount     = (string) $order->get_meta( '_skm_ssa_invoice_total' );
		$amount     = '' !== $amount ? (float) $amount : (float) $order->get_total();
		$res        = SKM_SSA_API::request( 'POST', '/contact_payments', self::receipt_payload( $order, $contact_id, $invoice_id, $amount ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( empty( $res['id'] ) ) {
			return new WP_Error( 'skm_ssa_receipt', 'Sage returned no payment id.' );
		}
		$order->update_meta_data( '_skm_ssa_receipt_id', (string) $res['id'] );
		$order->save();
		/* translators: 1: amount, 2: Sage invoice number */
		$order->add_order_note( sprintf( __( 'Payment of %1$s recorded in Sage against invoice %2$s.', 'skillmerc-sync-for-sage-accounting' ), wc_format_decimal( $amount, 2 ), (string) $order->get_meta( '_skm_ssa_invoice_number' ) ) );
		return (string) $res['id'];
	}
}
