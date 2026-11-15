<?php
/**
 * Notes under the price: unit price, VAT, shipping and delivery (TRLGDCU art. 60 and 97.1, RD 3423/2000).
 *
 * @package Mostrador
 */

namespace Mostrador;

defined( 'ABSPATH' ) || exit;

/**
 * PriceNotes.
 */
class PriceNotes {

	/**
	 * Hooks.
	 */
	public static function boot() {
		if ( ! Settings::get( 'price_notes' ) && ! Settings::get( 'unit_price' ) && ! Settings::get( 'delivery' ) ) {
			return;
		}
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'price_html' ), 50, 2 );
		if ( Settings::get( 'price_notes' ) ) {
			add_filter( 'woocommerce_cart_totals_order_total_html', array( __CLASS__, 'total_html' ), 50 );
		}
	}

	/**
	 * Whether this price is the main product on its own page.
	 *
	 * @param \WC_Product $product Product.
	 * @return bool
	 */
	private static function is_main( $product ) {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return false;
		}
		if ( $product->get_id() !== get_queried_object_id() ) {
			return false;
		}
		return ! in_the_loop() || ! wc_get_loop_prop( 'name' );
	}

	/**
	 * Add our pieces to the price.
	 *
	 * @param string      $html    Price HTML.
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public static function price_html( $html, $product ) {
		if ( '' === (string) $html || ( is_admin() && ! wp_doing_ajax() ) || ! $product instanceof \WC_Product || $product->is_type( 'variation' ) ) {
			return $html;
		}
		$main = self::is_main( $product );
		$list = ! $main && Settings::get( 'price_notes_lists' );
		if ( ! $main && ! $list ) {
			return Settings::get( 'unit_price' ) ? $html . self::unit_html( $product ) : $html;
		}
		$out = $html;
		if ( Settings::get( 'unit_price' ) ) {
			$out .= self::unit_html( $product );
		}
		if ( Settings::get( 'price_notes' ) ) {
			$out .= self::notes_html( $product, $main );
		}
		if ( $main && Settings::get( 'delivery' ) ) {
			$delivery = Delivery::text( $product );
			if ( '' !== $delivery ) {
				$out .= '<span class="mostrador-delivery" data-mostrador-delivery="' . esc_attr( $delivery ) . '">' . esc_html( $delivery ) . '</span>';
			}
		}
		return $out;
	}

	/**
	 * Unit price piece, with the original text kept for the variation script.
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	private static function unit_html( $product ) {
		$text = UnitPrice::text( $product );
		if ( '' === $text && ! $product->is_type( 'variable' ) ) {
			return '';
		}
		$hidden = '' === $text ? ' hidden' : '';
		return ' <span class="mostrador-unit" data-mostrador-unit="' . esc_attr( $text ) . '"' . $hidden . '>' . esc_html( $text ) . '</span>';
	}

	/**
	 * "VAT included" note for a product.
	 *
	 * @param \WC_Product|null $product Product.
	 * @return string
	 */
	public static function vat_text( $product = null ) {
		$incl = ! wc_tax_enabled() || 'incl' === get_option( 'woocommerce_tax_display_shop' );
		$text = $incl ? __( 'VAT included', 'mostrador' ) : __( 'VAT not included', 'mostrador' );
		return $text . (string) apply_filters( 'mostrador_price_note_suffix', '', $product );
	}

	/**
	 * Shipping part: '' for products that don't ship, "Free shipping" for the free classes, otherwise "Shipping not included".
	 *
	 * @param \WC_Product $product Product.
	 * @param bool        $link    Link to the shipping page.
	 * @return string HTML.
	 */
	public static function shipping_html( $product, $link ) {
		if ( ! $product->needs_shipping() && ! $product->is_type( 'variable' ) ) {
			return '';
		}
		$free = array_map( 'intval', (array) Settings::get( 'free_shipping_class', array() ) );
		if ( $free && in_array( (int) $product->get_shipping_class_id(), $free, true ) ) {
			return esc_html__( 'Free shipping', 'mostrador' );
		}
		$text = esc_html__( 'Shipping not included', 'mostrador' );
		$page = (int) Settings::get( 'shipping_page' );
		if ( $link && $page && 'publish' === get_post_status( $page ) ) {
			return '<a href="' . esc_url( get_permalink( $page ) ) . '">' . $text . '</a>';
		}
		return $text;
	}

	/**
	 * The notes line.
	 *
	 * @param \WC_Product $product Product.
	 * @param bool        $main    Main product (links allowed; list items sit inside a link).
	 * @return string
	 */
	public static function notes_html( $product, $main ) {
		$parts = array( esc_html( self::vat_text( $product ) ) );
		$ship  = self::shipping_html( $product, $main );
		if ( '' !== $ship ) {
			$parts[] = $ship;
		}
		return '<span class="mostrador-notes">' . implode( ' <span class="mostrador-sep" aria-hidden="true">·</span> ', $parts ) . '</span>';
	}

	/**
	 * Cart and classic checkout total: "(VAT included: 3,50 €)".
	 *
	 * @param string $html Total HTML.
	 * @return string
	 */
	public static function total_html( $html ) {
		if ( ! wc_tax_enabled() || 'incl' !== get_option( 'woocommerce_tax_display_cart' ) || ! WC()->cart ) {
			return $html;
		}
		$tax = (float) WC()->cart->get_taxes_total( true, true );
		if ( $tax <= 0 ) {
			return $html;
		}
		$strong = preg_match( '#^\s*<strong>.*?</strong>#s', $html, $m ) ? $m[0] : '<strong>' . WC()->cart->get_total() . '</strong>';
		/* translators: %s: tax amount. */
		return $strong . ' <small class="includes_tax mostrador-total-note">' . sprintf( esc_html__( '(VAT included: %s)', 'mostrador' ), wp_kses_post( wc_price( $tax ) ) ) . '</small>';
	}
}
