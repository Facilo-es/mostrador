<?php
/**
 * Order button text, terms checkbox text and the digital content tick.
 *
 * @package Mostrador
 */

namespace Mostrador;

defined( 'ABSPATH' ) || exit;

/**
 * Checkout.
 */
class Checkout {

	const CONSENT_ID      = 'mostrador/digital-consent';
	const CONSENT_VERSION = '1';

	/**
	 * Hooks.
	 */
	public static function boot() {
		add_filter( 'woocommerce_order_button_text', array( __CLASS__, 'button_text' ), 20 );
		add_filter( 'woocommerce_pay_order_button_text', array( __CLASS__, 'button_text' ), 20 );
		add_filter( 'render_block_woocommerce/checkout-actions-block', array( __CLASS__, 'block_button' ), 10, 1 );
		if ( Settings::get( 'terms_text' ) ) {
			add_filter( 'woocommerce_get_terms_and_conditions_checkbox_text', array( __CLASS__, 'terms_text' ), 20 );
			add_filter( 'render_block_woocommerce/checkout-terms-block', array( __CLASS__, 'block_terms' ), 10, 1 );
		}
		if ( Settings::get( 'digital_tick' ) ) {
			add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'classic_tick' ) );
			add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'classic_validate' ), 10, 2 );
			add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'classic_save' ), 10, 2 );
			add_action( 'init', array( __CLASS__, 'register_block_tick' ), 20 );
			add_action( 'woocommerce_blocks_validate_location_order_fields', array( __CLASS__, 'block_validate' ), 10, 2 );
			add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'block_save' ), 20, 2 );
		}
		add_action( 'woocommerce_update_product', array( __CLASS__, 'refresh_digital_ids' ) );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'refresh_digital_ids' ) );
		do_action( 'mostrador_checkout_boot' );
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'extra_fields' ), 30 );
	}

	/**
	 * Classic checkout extras from Pro (same shape as billing fields).
	 *
	 * @param array $fields Checkout fields.
	 * @return array
	 */
	public static function extra_fields( $fields ) {
		$extra = apply_filters( 'mostrador_checkout_fields', array() );
		if ( is_array( $extra ) && $extra ) {
			$fields['billing'] = array_merge( $fields['billing'], $extra );
		}
		return $fields;
	}

	/**
	 * Button text.
	 *
	 * @return string
	 */
	public static function button_text() {
		return Settings::button_text();
	}

	/**
	 * Sets the Place Order label of the block checkout through the block's own attribute.
	 *
	 * @param string $html Block HTML.
	 * @return string
	 */
	public static function block_button( $html ) {
		return self::set_data_attr( $html, 'wp-block-woocommerce-checkout-actions-block', 'data-place-order-button-label', Settings::button_text() );
	}

	/**
	 * Adds or replaces a data attribute on the element with the given class.
	 *
	 * @param string $html  HTML.
	 * @param string $css   Class name.
	 * @param string $attr  Attribute.
	 * @param string $value Value.
	 * @return string
	 */
	public static function set_data_attr( $html, $css, $attr, $value ) {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $html;
		}
		$p = new \WP_HTML_Tag_Processor( $html );
		if ( $p->next_tag( array( 'class_name' => $css ) ) ) {
			$p->set_attribute( $attr, $value );
			return $p->get_updated_html();
		}
		return $html;
	}

	/**
	 * Terms text with links.
	 *
	 * @return string
	 */
	public static function terms_text() {
		$terms      = (int) wc_terms_and_conditions_page_id();
		$withdrawal = (int) Settings::get( 'withdrawal_page' );
		$a          = $terms ? '<a href="' . esc_url( get_permalink( $terms ) ) . '" class="woocommerce-terms-and-conditions-link" target="_blank">' . esc_html__( 'purchase conditions', 'mostrador' ) . '</a>' : esc_html__( 'purchase conditions', 'mostrador' );
		$b          = $withdrawal ? '<a href="' . esc_url( get_permalink( $withdrawal ) ) . '" target="_blank">' . esc_html__( 'withdrawal policy', 'mostrador' ) . '</a>' : esc_html__( 'withdrawal policy', 'mostrador' );
		/* translators: 1: link to the purchase conditions, 2: link to the withdrawal policy. */
		return sprintf( __( 'I have read and accept the %1$s and the %2$s.', 'mostrador' ), $a, $b );
	}

	/**
	 * Block checkout terms: WooCommerce's own checkbox with our text.
	 *
	 * @param string $html Block HTML.
	 * @return string
	 */
	public static function block_terms( $html ) {
		if ( ! wc_terms_and_conditions_page_id() ) {
			return $html;
		}
		$html = self::set_data_attr( $html, 'wp-block-woocommerce-checkout-terms-block', 'data-checkbox', 'true' );
		return self::set_data_attr( $html, 'wp-block-woocommerce-checkout-terms-block', 'data-text', self::terms_text() );
	}

	/**
	 * Tick text.
	 *
	 * @return string
	 */
	public static function consent_text() {
		return __( 'I want to receive the content or the service now, and I understand that once it starts I lose my right of withdrawal.', 'mostrador' );
	}

	/**
	 * The consent text stored on an order, '' when none.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	public static function order_consent( $order ) {
		$data = $order->get_meta( '_mostrador_digital_consent' );
		return is_array( $data ) && ! empty( $data['text'] ) ? (string) $data['text'] : '';
	}

	/**
	 * Product IDs marked as digital content or service.
	 *
	 * @return array
	 */
	public static function digital_ids() {
		$ids = get_option( 'mostrador_digital_ids', null );
		if ( ! is_array( $ids ) ) {
			$ids = self::refresh_digital_ids();
		}
		return array_map( 'intval', $ids );
	}

	/**
	 * Rebuilds the list of digital product IDs (on product save).
	 *
	 * @return array
	 */
	public static function refresh_digital_ids() {
		$ids = get_posts(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'meta_key'       => '_mostrador_digital', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => array( 'digital', 'service' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_compare'   => 'IN',
			)
		);
		$all = array_map( 'intval', $ids );
		foreach ( $ids as $id ) {
			if ( 'product' === get_post_type( $id ) ) {
				$all = array_merge( $all, array_map( 'intval', get_children( array( 'post_parent' => $id, 'post_type' => 'product_variation', 'fields' => 'ids' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			}
		}
		$all = array_values( array_unique( $all ) );
		update_option( 'mostrador_digital_ids', $all, false );
		return $all;
	}

	/**
	 * Whether the cart holds a digital product or service.
	 *
	 * @return bool
	 */
	public static function cart_has_digital() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}
		$ids = self::digital_ids();
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( in_array( (int) $item['product_id'], $ids, true ) || in_array( (int) $item['variation_id'], $ids, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Classic tick.
	 */
	public static function classic_tick() {
		if ( ! self::cart_has_digital() ) {
			return;
		}
		echo '<p class="form-row validate-required mostrador-consent"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox"><input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="mostrador_digital_consent" id="mostrador_digital_consent" value="1" /> <span>' . esc_html( self::consent_text() ) . '</span>&nbsp;<abbr class="required" aria-hidden="true">*</abbr></label></p>';
	}

	/**
	 * Classic validation.
	 *
	 * @param array     $data   Data.
	 * @param \WP_Error $errors Errors.
	 */
	public static function classic_validate( $data, $errors ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checks the checkout nonce before this action.
		if ( self::cart_has_digital() && empty( $_POST['mostrador_digital_consent'] ) ) {
			$errors->add( 'mostrador_digital_consent', __( 'Please tick the box about receiving the content or service now.', 'mostrador' ) );
		}
	}

	/**
	 * Saves the tick on classic checkout.
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $data  Data.
	 */
	public static function classic_save( $order, $data ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- action signature.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checks the checkout nonce before this action.
		if ( ! empty( $_POST['mostrador_digital_consent'] ) && self::cart_has_digital() ) {
			self::store( $order );
		}
	}

	/**
	 * Stores the consent.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function store( $order ) {
		$order->update_meta_data(
			'_mostrador_digital_consent',
			array(
				'time'    => time(),
				'version' => self::CONSENT_VERSION,
				'text'    => self::consent_text(),
			)
		);
	}

	/**
	 * Block checkout tick, shown only when the cart holds a marked product (WooCommerce 9.9+).
	 */
	public static function register_block_tick() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		$args = array(
			'id'                         => self::CONSENT_ID,
			'label'                      => self::consent_text(),
			'location'                   => 'order',
			'type'                       => 'checkbox',
			'required'                   => false,
			'show_in_order_confirmation' => false,
		);
		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '9.9', '>=' ) ) {
			$ids              = self::digital_ids();
			$ids              = $ids ? $ids : array( -1 );
			$args['required'] = true;
			$args['hidden']   = array(
				'type'       => 'object',
				'properties' => array(
					'cart' => array(
						'properties' => array(
							'items' => array(
								'not' => array( 'contains' => array( 'enum' => $ids ) ),
							),
						),
					),
				),
			);
		}
		try {
			woocommerce_register_additional_checkout_field( $args );
		} catch ( \Throwable $e ) {
			unset( $args['hidden'], $args['show_in_order_confirmation'] );
			$args['required'] = false;
			woocommerce_register_additional_checkout_field( $args );
		}
	}

	/**
	 * Block validation (also covers WooCommerce versions without conditional fields).
	 *
	 * @param \WP_Error $errors Errors.
	 * @param array     $fields Fields.
	 */
	public static function block_validate( $errors, $fields ) {
		if ( self::cart_has_digital() && empty( $fields[ self::CONSENT_ID ] ) && ! $errors->get_error_message( 'mostrador_digital_consent' ) ) {
			$errors->add( 'mostrador_digital_consent', __( 'Please tick the box about receiving the content or service now.', 'mostrador' ) );
		}
	}

	/**
	 * Saves the block tick.
	 *
	 * @param \WC_Order        $order   Order.
	 * @param \WP_REST_Request $request Request.
	 */
	public static function block_save( $order, $request ) {
		$extra = $request['additional_fields'];
		if ( is_array( $extra ) && ! empty( $extra[ self::CONSENT_ID ] ) && self::cart_has_digital() ) {
			self::store( $order );
			$order->save_meta_data();
		}
	}
}
