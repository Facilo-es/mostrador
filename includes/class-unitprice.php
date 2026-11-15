<?php
/**
 * Price per unit (RD 3423/2000).
 *
 * @package Mostrador
 */

namespace Mostrador;

defined( 'ABSPATH' ) || exit;

/**
 * UnitPrice.
 */
class UnitPrice {

	/**
	 * Hooks.
	 */
	public static function boot() {
		if ( ! Settings::get( 'unit_price' ) ) {
			return;
		}
		add_filter( 'woocommerce_available_variation', array( __CLASS__, 'variation_data' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'cart_line' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'script' ) );
	}

	/**
	 * Units.
	 *
	 * @return array unit => label.
	 */
	public static function units() {
		return array(
			'kg'     => 'kg',
			'g'      => 'g',
			'l'      => 'l',
			'ml'     => 'ml',
			'm'      => 'm',
			'm2'     => 'm²',
			'm3'     => 'm³',
			'unidad' => __( 'unit', 'mostrador' ),
		);
	}

	/**
	 * Default reference quantity, in the product's own unit.
	 *
	 * @param string $unit Unit.
	 * @return float
	 */
	public static function default_ref( $unit ) {
		return in_array( $unit, array( 'g', 'ml' ), true ) ? 1000.0 : 1.0;
	}

	/**
	 * Unit price, rounded half up to the shop's decimals, or null when it equals the price.
	 *
	 * @param float  $price   Displayed price.
	 * @param string $unit    Unit of $qty and $ref_qty.
	 * @param float  $qty     Product quantity.
	 * @param float  $ref_qty Reference quantity, in the same unit.
	 * @return float|null
	 */
	public static function calc( $price, $unit, $qty, $ref_qty ) {
		$value = self::raw( $price, $unit, $qty, $ref_qty );
		if ( null === $value ) {
			return null;
		}
		$dec = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		return self::round( $value, $dec ) === self::round( (float) $price, $dec ) ? null : $value;
	}

	/**
	 * Unit price even when it equals the price (for "from" in lists), or null when data is missing.
	 *
	 * @param float  $price   Price.
	 * @param string $unit    Unit.
	 * @param float  $qty     Quantity.
	 * @param float  $ref_qty Reference quantity.
	 * @return float|null
	 */
	public static function raw( $price, $unit, $qty, $ref_qty ) {
		$qty     = (float) $qty;
		$ref_qty = (float) $ref_qty;
		if ( $qty <= 0 || $ref_qty <= 0 || ! isset( self::units()[ $unit ] ) || '' === (string) $price ) {
			return null;
		}
		$dec = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		return self::round( (float) $price * $ref_qty / $qty, $dec );
	}

	/**
	 * Half-up rounding that ignores float noise.
	 *
	 * @param float $value Value.
	 * @param int   $dec   Decimals.
	 * @return float
	 */
	public static function round( $value, $dec ) {
		return round( round( $value, $dec + 4 ), $dec, PHP_ROUND_HALF_UP );
	}

	/**
	 * Reference label, like "kg", "100 g", "l", "unit".
	 *
	 * @param string $unit Unit.
	 * @param float  $ref  Reference quantity in that unit.
	 * @return string
	 */
	public static function ref_label( $unit, $ref ) {
		$ref = (float) $ref;
		if ( 'g' === $unit && $ref >= 1000 && 0.0 === fmod( $ref, 1000 ) ) {
			list( $unit, $ref ) = array( 'kg', $ref / 1000 );
		} elseif ( 'ml' === $unit && $ref >= 1000 && 0.0 === fmod( $ref, 1000 ) ) {
			list( $unit, $ref ) = array( 'l', $ref / 1000 );
		} elseif ( 'kg' === $unit && $ref < 1 ) {
			list( $unit, $ref ) = array( 'g', $ref * 1000 );
		} elseif ( 'l' === $unit && $ref < 1 ) {
			list( $unit, $ref ) = array( 'ml', $ref * 1000 );
		}
		$label = self::units()[ $unit ];
		return 1.0 === round( $ref, 6 ) ? $label : wc_format_localized_decimal( (string) round( $ref, 3 ) ) . ' ' . $label;
	}

	/**
	 * Unit data for a product or variation, with variations inheriting from the parent.
	 *
	 * @param \WC_Product $product Product.
	 * @return array|null [ unit, qty, ref ] or null.
	 */
	public static function data( $product ) {
		$unit = (string) $product->get_meta( '_mostrador_unit' );
		$qty  = (string) $product->get_meta( '_mostrador_qty' );
		$ref  = (string) $product->get_meta( '_mostrador_ref_qty' );
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent ) {
				$unit = '' !== $unit ? $unit : (string) $parent->get_meta( '_mostrador_unit' );
				$qty  = '' !== $qty ? $qty : (string) $parent->get_meta( '_mostrador_qty' );
				$ref  = '' !== $ref ? $ref : (string) $parent->get_meta( '_mostrador_ref_qty' );
			}
		}
		if ( '' === $unit || ! isset( self::units()[ $unit ] ) || (float) $qty <= 0 ) {
			return null;
		}
		return array( $unit, (float) $qty, (float) $ref > 0 ? (float) $ref : self::default_ref( $unit ) );
	}

	/**
	 * "13,96 € / kg" for a product, '' when not shown.
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public static function text( $product ) {
		if ( $product->is_type( 'variable' ) ) {
			return self::from_text( $product );
		}
		$data = self::data( $product );
		if ( ! $data || '' === (string) $product->get_price() ) {
			return '';
		}
		$value = self::calc( (float) wc_get_price_to_display( $product ), $data[0], $data[1], $data[2] );
		if ( null === $value ) {
			return '';
		}
		// Plain text (no &nbsp; or &euro;), so the variation JSON shows right when the script swaps it in.
		return html_entity_decode( wp_strip_all_tags( wc_price( $value ) ), ENT_QUOTES, 'UTF-8' ) . ' / ' . self::ref_label( $data[0], $data[2] );
	}

	/**
	 * "desde 8,90 € / kg" for a variable product.
	 *
	 * @param \WC_Product_Variable $product Product.
	 * @return string
	 */
	public static function from_text( $product ) {
		$prices = $product->get_variation_prices( true );
		if ( empty( $prices['price'] ) ) {
			return '';
		}
		update_meta_cache( 'post', array_keys( $prices['price'] ) );
		$best  = null;
		$label = '';
		foreach ( $prices['price'] as $id => $price ) {
			$variation = wc_get_product( $id );
			$data      = $variation ? self::data( $variation ) : null;
			if ( ! $data ) {
				continue;
			}
			$value = self::raw( (float) $price, $data[0], $data[1], $data[2] );
			if ( null !== $value && ( null === $best || $value < $best ) ) {
				$best  = $value;
				$label = self::ref_label( $data[0], $data[2] );
			}
		}
		if ( null === $best ) {
			return '';
		}
		/* translators: %s: unit price, like "8,90 € / kg". */
		return sprintf( __( 'from %s', 'mostrador' ), html_entity_decode( wp_strip_all_tags( wc_price( $best ) ), ENT_QUOTES, 'UTF-8' ) . ' / ' . $label );
	}

	/**
	 * HTML piece for next to the price.
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public static function html( $product ) {
		$text = self::text( $product );
		return '' === $text ? '' : '<span class="mostrador-unit">' . esc_html( $text ) . '</span>';
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
		$data['mostrador_unit'] = self::text( $variation );
		return $data;
	}

	/**
	 * Small unit price under the name in cart lines.
	 *
	 * @param array $item_data Item data.
	 * @param array $item      Cart item.
	 * @return array
	 */
	public static function cart_line( $item_data, $item ) {
		if ( empty( $item['data'] ) || ! $item['data'] instanceof \WC_Product ) {
			return $item_data;
		}
		$text = self::text( $item['data'] );
		if ( '' !== $text ) {
			$item_data[] = array(
				'key'     => __( 'Price per unit', 'mostrador' ),
				'value'   => $text,
				'display' => $text,
			);
		}
		return $item_data;
	}

	/**
	 * Live update script, single product pages only.
	 */
	public static function script() {
		if ( function_exists( 'is_product' ) && is_product() ) {
			wp_enqueue_script( 'mostrador-unit', MOSTRADOR_URL . 'assets/js/unit-price.js', array( 'jquery' ), MOSTRADOR_VERSION, true );
		}
	}
}
