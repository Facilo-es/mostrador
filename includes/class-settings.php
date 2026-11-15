<?php
/**
 * The single settings option.
 *
 * @package Mostrador
 */

namespace Mostrador;

defined( 'ABSPATH' ) || exit;

/**
 * Settings.
 */
class Settings {

	const OPTION = 'mostrador_settings';

	/**
	 * Request cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Preview overrides (wizard preview iframe only).
	 *
	 * @var array
	 */
	private static $override = array();

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'region'              => 'peninsula',
			'nif_mode'            => 'optional',
			'nif_eu'              => 0,
			'button'              => 'obligacion',
			'button_custom'       => '',
			'terms_text'          => 1,
			'digital_tick'        => 0,
			'price_notes'         => 1,
			'price_notes_lists'   => 0,
			'shipping_page'       => 0,
			'free_shipping_class' => array(),
			'unit_price'          => 1,
			'delivery'            => 1,
			'delivery_default'    => 0,
			'delivery_backorder'  => '',
			'safety'              => 1,
			'safety_short'        => 0,
			'food'                => 1,
			'allergen_icons'      => 1,
			'emails'              => 1,
			'email_pages'         => array(),
			'email_annex'         => 1,
			'email_first_only'    => 1,
			'withdrawal_page'     => 0,
			'style'               => 'sobrio',
			'delete_on_uninstall' => 0,
			'installed_at'        => 0,
			'notice_dismissed'    => 0,
			'wizard_done'         => 0,
		);
	}

	/**
	 * All settings.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		return array_merge( self::$cache, self::$override );
	}

	/**
	 * One setting.
	 *
	 * @param string $key     Key.
	 * @param mixed  $fallback Default when missing.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Saves changes.
	 *
	 * @param array $changes Key => value.
	 * @return void
	 */
	public static function update( array $changes ) {
		$saved = get_option( self::OPTION, array() );
		$saved = array_merge( is_array( $saved ) ? $saved : array(), $changes );
		update_option( self::OPTION, $saved, true );
		self::$cache = null;
	}

	/**
	 * Sets preview overrides for this request only.
	 *
	 * @param array $values Values.
	 */
	public static function override( array $values ) {
		self::$override = array_intersect_key( $values, self::defaults() );
	}

	/**
	 * Drops the request cache.
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Order button choices.
	 *
	 * @return array
	 */
	public static function button_choices() {
		return array(
			'obligacion' => __( 'Order with obligation to pay', 'mostrador' ),
			'comprar'    => __( 'Buy and pay', 'mostrador' ),
			'confirmar'  => __( 'Confirm order and pay', 'mostrador' ),
		);
	}

	/**
	 * Current order button text.
	 *
	 * @return string
	 */
	public static function button_text() {
		$choice = (string) self::get( 'button' );
		if ( 'custom' === $choice && '' !== trim( (string) self::get( 'button_custom' ) ) ) {
			return (string) self::get( 'button_custom' );
		}
		$choices = self::button_choices();
		return isset( $choices[ $choice ] ) ? $choices[ $choice ] : $choices['obligacion'];
	}

	/**
	 * Whether a button text says the order means paying (art. 98.2).
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function says_pay( $text ) {
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
		foreach ( array( 'pag', 'compr', 'pay', 'buy', 'kauf', 'zahl', 'pai', 'achet', 'acquist', 'compra', 'betal', 'koop', 'kup', 'płac', 'zapłac' ) as $word ) {
			if ( false !== strpos( $text, $word ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Delivery time list.
	 *
	 * @return array
	 */
	public static function delivery_times() {
		$list = get_option( 'mostrador_delivery_times', null );
		if ( ! is_array( $list ) ) {
			$list = array(
				__( '24/48 hours', 'mostrador' ),
				__( '2-4 working days', 'mostrador' ),
				__( '1-2 weeks', 'mostrador' ),
				__( 'Made to order: 3-4 weeks', 'mostrador' ),
			);
		}
		return array_values( array_filter( array_map( 'strval', $list ), 'strlen' ) );
	}

	/**
	 * Default delivery time text, or ''.
	 *
	 * @return string
	 */
	public static function default_delivery() {
		$list = self::delivery_times();
		$i    = (int) self::get( 'delivery_default' );
		return isset( $list[ $i ] ) ? $list[ $i ] : '';
	}
}
