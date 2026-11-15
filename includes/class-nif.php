<?php
/**
 * NIF / NIE / CIF field in classic and block checkout.
 *
 * @package Mostrador
 */

namespace Mostrador;

defined( 'ABSPATH' ) || exit;

/**
 * Nif.
 */
class Nif {

	const BLOCK_ID  = 'mostrador/nif';
	const BLOCK_KEY = '_wc_billing/mostrador/nif';

	/**
	 * Contact and order fields of the block checkout request being validated (field id => value).
	 *
	 * @var array
	 */
	private static $block_extra = array();

	/**
	 * Hooks.
	 */
	public static function boot() {
		// Display and storage work whether or not our field is on, so orders keep showing their NIF.
		add_filter( 'woocommerce_order_formatted_billing_address', array( __CLASS__, 'order_address' ), 10, 2 );
		add_filter( 'woocommerce_my_account_my_address_formatted_address', array( __CLASS__, 'account_address' ), 10, 3 );
		add_filter( 'woocommerce_formatted_address_replacements', array( __CLASS__, 'replacements' ), 10, 2 );
		add_filter( 'woocommerce_localisation_address_formats', array( __CLASS__, 'formats' ), 20 );
		add_filter( 'woocommerce_admin_billing_fields', array( __CLASS__, 'admin_field' ) );
		add_filter( 'woocommerce_customer_meta_fields', array( __CLASS__, 'user_profile_field' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'admin_save' ), 60 );
		add_action( 'admin_notices', array( __CLASS__, 'admin_warning' ) );

		if ( ! self::active() ) {
			return;
		}
		add_filter( 'woocommerce_billing_fields', array( __CLASS__, 'classic_field' ), 20 );
		add_filter( 'woocommerce_process_checkout_field_billing_nif', array( NifValidator::class, 'normalise' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'classic_validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_update_customer', array( __CLASS__, 'classic_customer' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'classic_script' ) );
		add_action( 'init', array( __CLASS__, 'register_block_field' ), 20 );
		add_filter( 'woocommerce_sanitize_additional_field', array( __CLASS__, 'block_sanitize' ), 10, 2 );
		add_action( 'woocommerce_blocks_validate_location_address_fields', array( __CLASS__, 'block_validate' ), 10, 3 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'block_request' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'block_mirror' ), 20, 2 );
		add_filter( 'woocommerce_filter_fields_for_order_confirmation', array( __CLASS__, 'hide_duplicate' ), 10, 2 );
	}

	/**
	 * Whether our field is on: mode isn't "hidden" and WC APG NIF isn't active.
	 *
	 * @return bool
	 */
	public static function active() {
		return 'off' !== Settings::get( 'nif_mode' ) && ! self::apg_active();
	}

	/**
	 * Whether WC APG NIF is active (it uses the same meta key).
	 *
	 * @return bool
	 */
	public static function apg_active() {
		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
			if ( 0 === strpos( (string) $plugin, 'wc-apg-nifcifnie-field/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Field label.
	 *
	 * @return string
	 */
	public static function label() {
		return Settings::get( 'nif_eu' ) ? __( 'NIF / VAT', 'mostrador' ) : __( 'NIF / NIE / CIF', 'mostrador' );
	}

	/**
	 * Countries where the field shows.
	 *
	 * @return array
	 */
	public static function countries() {
		if ( Settings::get( 'nif_eu' ) && function_exists( 'WC' ) && WC()->countries ) {
			return array_values( array_unique( array_merge( array( 'ES' ), (array) WC()->countries->get_european_union_countries() ) ) );
		}
		return array( 'ES' );
	}

	/**
	 * Error for a value, or '' when fine.
	 *
	 * @param string $value   Value.
	 * @param string $country Billing country.
	 * @param string $company Billing company.
	 * @param array  $data    Checkout data: the classic posted fields, or on block checkout the billing address
	 *                        fields plus the contact and order fields (field id => value).
	 * @param string $context classic|block.
	 * @return string
	 */
	public static function error_for( $value, $country, $company = '', $data = array(), $context = '' ) {
		$value = NifValidator::normalise( $value );
		if ( ! in_array( $country, self::countries(), true ) ) {
			return '';
		}
		$mode     = (string) Settings::get( 'nif_mode' );
		$required = 'required' === $mode || ( 'company' === $mode && '' !== trim( (string) $company ) );
		$required = (bool) apply_filters(
			'mostrador_nif_required',
			$required,
			array_merge(
				(array) $data,
				array(
					'country' => $country,
					'company' => $company,
					'nif'     => $value,
					'mode'    => $mode,
					'context' => $context,
				)
			)
		);
		if ( '' === $value ) {
			return $required ? __( 'We need your NIF, NIE or CIF to place the order.', 'mostrador' ) : '';
		}
		$ok = 'ES' === $country ? NifValidator::is_valid( $value ) : NifValidator::eu_format_ok( $value );
		return $ok ? '' : __( 'The NIF, NIE or CIF isn\'t right. Check that the letter or the last digit is correct.', 'mostrador' );
	}

	/**
	 * Classic checkout field after Company.
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	public static function classic_field( $fields ) {
		$priority = isset( $fields['billing_company']['priority'] ) ? (int) $fields['billing_company']['priority'] + 1 : 35;
		$label    = self::label();
		if ( 'required' === Settings::get( 'nif_mode' ) ) {
			$label .= '&nbsp;<span class="required" aria-hidden="true">*</span>';
		}
		$fields['billing_nif'] = array(
			'label'             => $label,
			'placeholder'       => __( 'For your invoice', 'mostrador' ),
			'required'          => false,
			'class'             => array( 'form-row-wide', 'mostrador-nif' ),
			'priority'          => $priority,
			'autocomplete'      => 'off',
			'custom_attributes' => array( 'data-countries' => implode( ',', self::countries() ) ),
		);
		return $fields;
	}

	/**
	 * Classic validation.
	 *
	 * @param array     $data   Posted data.
	 * @param \WP_Error $errors Errors.
	 */
	public static function classic_validate( $data, $errors ) {
		$country = isset( $data['billing_country'] ) ? (string) $data['billing_country'] : '';
		$company = isset( $data['billing_company'] ) ? (string) $data['billing_company'] : '';
		$value   = isset( $data['billing_nif'] ) ? (string) $data['billing_nif'] : '';
		$error   = self::error_for( $value, $country, $company, (array) $data, 'classic' );
		if ( '' !== $error ) {
			$errors->add( 'billing_nif_validation', $error, array( 'id' => 'billing_nif' ) );
		}
	}

	/**
	 * Keeps the user meta in sync on classic checkout.
	 *
	 * @param \WC_Customer $customer Customer.
	 * @param array        $data     Posted data.
	 */
	public static function classic_customer( $customer, $data ) {
		if ( isset( $data['billing_nif'] ) && $customer->get_id() ) {
			update_user_meta( $customer->get_id(), 'billing_nif', NifValidator::normalise( $data['billing_nif'] ) );
		}
	}

	/**
	 * Small script that shows the field only for the right countries (classic checkout).
	 */
	public static function classic_script() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || has_block( 'woocommerce/checkout' ) ) {
			return;
		}
		wp_enqueue_script( 'mostrador-nif', MOSTRADOR_URL . 'assets/js/nif.js', array( 'jquery' ), MOSTRADOR_VERSION, true );
	}

	/**
	 * Registers the block checkout field.
	 */
	public static function register_block_field() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		$args = array(
			'id'                         => self::BLOCK_ID,
			'label'                      => self::label(),
			'optionalLabel'              => self::label() . ' ' . __( '(optional)', 'mostrador' ),
			'location'                   => 'address',
			'type'                       => 'text',
			'required'                   => false,
			'show_in_order_confirmation' => false,
			'attributes'                 => array(
				'autocomplete' => 'off',
				'title'        => __( 'For your invoice', 'mostrador' ),
			),
		);
		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '9.9', '>=' ) ) {
			$country = array( 'enum' => self::countries() );
			if ( 'required' === Settings::get( 'nif_mode' ) ) {
				$args['required'] = self::rule( $country );
			}
			$args['hidden'] = self::rule( array( 'not' => $country ) );
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
	 * JSON schema rule on the address country.
	 *
	 * @param array $country Country schema.
	 * @return array
	 */
	private static function rule( $country ) {
		return array(
			'type'       => 'object',
			'properties' => array(
				'customer' => array(
					'properties' => array(
						'address' => array(
							'properties' => array( 'country' => $country ),
						),
					),
				),
			),
		);
	}

	/**
	 * Normalises the block field value before it's stored.
	 *
	 * @param mixed  $value Value.
	 * @param string $key   Field id.
	 * @return mixed
	 */
	public static function block_sanitize( $value, $key ) {
		return self::BLOCK_ID === $key ? NifValidator::normalise( (string) $value ) : $value;
	}

	/**
	 * Block checkout validation, with the billing country and company in view.
	 *
	 * @param \WP_Error $errors Errors.
	 * @param array     $fields Fields of the address.
	 * @param string    $group  billing|shipping.
	 */
	public static function block_validate( $errors, $fields, $group ) {
		if ( 'billing' !== $group ) {
			return;
		}
		$error = self::error_for(
			isset( $fields[ self::BLOCK_ID ] ) ? (string) $fields[ self::BLOCK_ID ] : '',
			isset( $fields['country'] ) ? (string) $fields['country'] : '',
			isset( $fields['company'] ) ? (string) $fields['company'] : '',
			array_merge( self::$block_extra, (array) $fields ),
			'block'
		);
		if ( '' !== $error ) {
			$errors->add( 'mostrador_nif', $error );
		}
	}

	/**
	 * Keeps the contact and order fields of a Store API checkout request, which the address
	 * validation hook doesn't see, so mostrador_nif_required gets them on block checkout too.
	 *
	 * @param mixed            $result  Response so far (returned unchanged).
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function block_request( $result, $server, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- filter signature.
		self::$block_extra = array();
		if ( $request instanceof \WP_REST_Request && preg_match( '#^/wc/store(/v\d+)?/checkout#', (string) $request->get_route() ) ) {
			$extra = $request->get_param( 'additional_fields' );
			if ( is_array( $extra ) ) {
				foreach ( $extra as $key => $val ) {
					self::$block_extra[ sanitize_text_field( (string) $key ) ] = is_scalar( $val ) ? sanitize_text_field( (string) $val ) : '';
				}
			}
		}
		return $result;
	}

	/**
	 * Mirrors the block field to _billing_nif on the order and billing_nif on the user.
	 *
	 * @param \WC_Order        $order   Order.
	 * @param \WP_REST_Request $request Request.
	 */
	public static function block_mirror( $order, $request ) {
		$billing = $request['billing_address'];
		$value   = is_array( $billing ) && isset( $billing[ self::BLOCK_ID ] ) ? (string) $billing[ self::BLOCK_ID ] : (string) $order->get_meta( self::BLOCK_KEY );
		$value   = NifValidator::normalise( $value );
		$order->update_meta_data( '_billing_nif', $value );
		$order->save_meta_data();
		if ( $order->get_customer_id() ) {
			update_user_meta( $order->get_customer_id(), 'billing_nif', $value );
		}
	}

	/**
	 * Our address line already shows the NIF, so WooCommerce's own line would repeat it.
	 *
	 * @param bool  $show  Show.
	 * @param array $field Field.
	 * @return bool
	 */
	public static function hide_duplicate( $show, $field ) {
		return isset( $field['id'] ) && self::BLOCK_ID === $field['id'] ? false : $show;
	}

	/**
	 * NIF for an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	public static function get_for_order( $order ) {
		if ( ! $order instanceof \WC_Abstract_Order ) {
			return '';
		}
		$value = (string) $order->get_meta( '_billing_nif' );
		if ( '' === $value ) {
			$value = (string) $order->get_meta( self::BLOCK_KEY );
		}
		return NifValidator::normalise( $value );
	}

	/**
	 * NIF for a user.
	 *
	 * @param int $user_id User.
	 * @return string
	 */
	public static function get_for_user( $user_id ) {
		$value = (string) get_user_meta( (int) $user_id, 'billing_nif', true );
		if ( '' === $value ) {
			$value = (string) get_user_meta( (int) $user_id, self::BLOCK_KEY, true );
		}
		return NifValidator::normalise( $value );
	}

	/**
	 * Adds the NIF to the order billing address.
	 *
	 * @param array     $address Address.
	 * @param \WC_Order $order   Order.
	 * @return array
	 */
	public static function order_address( $address, $order ) {
		if ( is_array( $address ) ) {
			$address['nif'] = self::get_for_order( $order );
		}
		return $address;
	}

	/**
	 * Adds the NIF to My Account's billing address.
	 *
	 * @param array  $address  Address.
	 * @param int    $user_id  User.
	 * @param string $type     billing|shipping.
	 * @return array
	 */
	public static function account_address( $address, $user_id, $type ) {
		if ( 'billing' === $type && is_array( $address ) ) {
			$address['nif'] = self::get_for_user( $user_id );
		}
		return $address;
	}

	/**
	 * The {nif} placeholder.
	 *
	 * @param array $replace Replacements.
	 * @param array $args    Address.
	 * @return array
	 */
	public static function replacements( $replace, $args ) {
		$nif              = isset( $args['nif'] ) ? (string) $args['nif'] : '';
		$replace['{nif}'] = '' === $nif ? '' : self::label() . ': ' . $nif;
		return $replace;
	}

	/**
	 * Appends {nif} to every address format.
	 *
	 * @param array $formats Formats.
	 * @return array
	 */
	public static function formats( $formats ) {
		foreach ( $formats as $country => $format ) {
			if ( false === strpos( $format, '{nif}' ) ) {
				$formats[ $country ] = $format . "\n{nif}";
			}
		}
		return $formats;
	}

	/**
	 * Editable NIF in the admin order screen.
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	public static function admin_field( $fields ) {
		$out = array();
		foreach ( $fields as $key => $field ) {
			$out[ $key ] = $field;
			if ( 'company' === $key ) {
				$out['nif'] = array(
					'label' => __( 'NIF / NIE / CIF', 'mostrador' ),
					'show'  => false,
				);
			}
		}
		if ( ! isset( $out['nif'] ) ) {
			$out['nif'] = array(
				'label' => __( 'NIF / NIE / CIF', 'mostrador' ),
				'show'  => false,
			);
		}
		return $out;
	}

	/**
	 * NIF on the user profile's customer section.
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	public static function user_profile_field( $fields ) {
		if ( isset( $fields['billing']['fields'] ) ) {
			$fields['billing']['fields']['billing_nif'] = array(
				'label'       => __( 'NIF / NIE / CIF', 'mostrador' ),
				'description' => '',
			);
		}
		return $fields;
	}

	/**
	 * Normalises an NIF edited in the admin, and warns (never blocks) when it looks wrong.
	 *
	 * @param int $order_id Order.
	 */
	public static function admin_save( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$value = NifValidator::normalise( (string) $order->get_meta( '_billing_nif' ) );
		$order->update_meta_data( '_billing_nif', $value );
		$order->save_meta_data();
		if ( '' !== $value && '' !== self::error_for( $value, (string) $order->get_billing_country(), (string) $order->get_billing_company() ) ) {
			set_transient( 'mostrador_nif_warning_' . get_current_user_id(), 1, 60 );
		}
	}

	/**
	 * The warning after saving an order with a doubtful NIF.
	 */
	public static function admin_warning() {
		$key    = 'mostrador_nif_warning_' . get_current_user_id();
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ( false === strpos( (string) $screen->id, 'shop_order' ) && false === strpos( (string) $screen->id, 'wc-orders' ) ) || ! get_transient( $key ) ) {
			return;
		}
		delete_transient( $key );
		echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'The NIF, NIE or CIF isn\'t right. Check that the letter or the last digit is correct.', 'mostrador' ) . '</p></div>';
	}
}
