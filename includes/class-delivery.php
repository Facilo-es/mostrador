<?php
/**
 * Delivery times (TRLGDCU art. 97.1).
 *
 * @package Mostrador
 */

namespace Mostrador;

defined( 'ABSPATH' ) || exit;

/**
 * Delivery.
 */
class Delivery {

	/**
	 * Hooks.
	 */
	public static function boot() {
		if ( ! Settings::get( 'delivery' ) ) {
			return;
		}
		add_filter( 'woocommerce_available_variation', array( __CLASS__, 'variation_data' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'cart_line' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_line' ), 10, 3 );
		add_filter( 'woocommerce_order_item_display_meta_key', array( __CLASS__, 'meta_key_label' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'script' ) );
	}

	/**
	 * The variation script also swaps the delivery line.
	 */
	public static function script() {
		if ( function_exists( 'is_product' ) && is_product() ) {
			wp_enqueue_script( 'mostrador-unit', MOSTRADOR_URL . 'assets/js/unit-price.js', array( 'jquery' ), MOSTRADOR_VERSION, true );
		}
	}

	/**
	 * Delivery time for a product or variation: own choice, then the parent's, then the shop default.
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public static function time_for( $product ) {
		$times = Settings::delivery_times();
		$key   = (string) $product->get_meta( '_mostrador_delivery' );
		if ( ( '' === $key || ! isset( $times[ $key ] ) ) && $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$key    = $parent ? (string) $parent->get_meta( '_mostrador_delivery' ) : '';
		}
		if ( '' !== $key && isset( $times[ $key ] ) ) {
			return $times[ $key ];
		}
		return Settings::default_delivery();
	}

	/**
	 * Line shown on the product page, '' when nothing applies.
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public static function text( $product ) {
		if ( ! $product->is_type( 'variable' ) && ! $product->needs_shipping() ) {
			return '';
		}
		if ( ! $product->is_type( 'variable' ) && $product->is_on_backorder( 1 ) ) {
			$back = (string) Settings::get( 'delivery_backorder', '' );
			$time = '' !== $back ? $back : self::time_for( $product );
			/* translators: %s: delivery time, like "1-2 weeks". */
			return '' === $time ? '' : sprintf( __( 'Available in %s', 'mostrador' ), $time );
		}
		if ( ! $product->is_type( 'variable' ) && ! $product->is_in_stock() ) {
			return '';
		}
		$time = self::time_for( $product );
		/* translators: %s: delivery time, like "24/48 hours". */
		return '' === $time ? '' : sprintf( __( 'Delivery in %s', 'mostrador' ), $time );
	}

	/**
	 * HTML piece for the product page.
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public static function html( $product ) {
		$text = self::text( $product );
		return '' === $text ? '' : '<span class="mostrador-delivery">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Variation data for the live update.
	 *
	 * @param array                 $data      Data.
	 * @param \WC_Product_Variable  $parent_product Parent.
	 * @param \WC_Product_Variation $variation Variation.
	 * @return array
	 */
	public static function variation_data( $data, $parent_product, $variation ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- filter signature.
		$data['mostrador_delivery'] = self::text( $variation );
		return $data;
	}

	/**
	 * Cart and checkout line.
	 *
	 * @param array $item_data Item data.
	 * @param array $item      Cart item.
	 * @return array
	 */
	public static function cart_line( $item_data, $item ) {
		if ( empty( $item['data'] ) || ! $item['data'] instanceof \WC_Product || ! $item['data']->needs_shipping() ) {
			return $item_data;
		}
		$time = self::time_for( $item['data'] );
		if ( '' !== $time ) {
			$item_data[] = array(
				'key'     => __( 'Delivery', 'mostrador' ),
				'value'   => $time,
				'display' => $time,
			);
		}
		return $item_data;
	}

	/**
	 * Keep the delivery time on the order line, so the email shows "Delivery: …".
	 *
	 * @param \WC_Order_Item_Product $item   Item.
	 * @param string                 $key    Cart key.
	 * @param array                  $values Cart values.
	 */
	public static function order_line( $item, $key, $values ) {
		if ( empty( $values['data'] ) || ! $values['data'] instanceof \WC_Product || ! $values['data']->needs_shipping() ) {
			return;
		}
		$time = self::time_for( $values['data'] );
		if ( '' !== $time ) {
			$item->add_meta_data( 'mostrador_delivery', $time, true );
		}
	}

	/**
	 * Show our line meta as "Delivery".
	 *
	 * @param string         $key  Display key.
	 * @param \WC_Meta_Data $meta Meta.
	 * @return string
	 */
	public static function meta_key_label( $key, $meta ) {
		return 'mostrador_delivery' === $meta->key ? __( 'Delivery', 'mostrador' ) : $key;
	}
}
