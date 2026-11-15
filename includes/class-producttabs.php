<?php
/**
 * Product safety (GPSR art. 19) and food information (Reg. 1169/2011) on the product page.
 *
 * @package Mostrador
 */

namespace Mostrador;

defined( 'ABSPATH' ) || exit;

/**
 * ProductTabs.
 */
class ProductTabs {

	/**
	 * Whether the short safety block was already printed on this request.
	 *
	 * @var bool
	 */
	private static $short_done = false;

	/**
	 * Hooks.
	 */
	public static function boot() {
		add_filter( 'woocommerce_product_tabs', array( __CLASS__, 'tabs' ), 30 );
		add_action( 'init', array( __CLASS__, 'blocks' ) );
		if ( Settings::get( 'safety' ) && Settings::get( 'safety_short' ) ) {
			add_action( 'woocommerce_after_add_to_cart_form', array( __CLASS__, 'short_block' ) );
			add_filter( 'render_block_woocommerce/add-to-cart-form', array( __CLASS__, 'short_after_block' ) );
			add_filter( 'render_block_woocommerce/add-to-cart-with-options', array( __CLASS__, 'short_after_block' ) );
		}
	}

	/**
	 * Register the two dynamic blocks.
	 */
	public static function blocks() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script( 'mostrador-blocks', MOSTRADOR_URL . 'assets/js/blocks.js', array( 'wp-blocks', 'wp-element', 'wp-server-side-render', 'wp-i18n', 'wp-block-editor' ), MOSTRADOR_VERSION, true );
		wp_set_script_translations( 'mostrador-blocks', 'mostrador', MOSTRADOR_DIR . 'languages' );
		register_block_type(
			MOSTRADOR_DIR . 'blocks/product-safety',
			array( 'render_callback' => array( __CLASS__, 'render_safety_block' ) )
		);
		register_block_type(
			MOSTRADOR_DIR . 'blocks/food-info',
			array( 'render_callback' => array( __CLASS__, 'render_food_block' ) )
		);
	}

	/**
	 * The product shown on this request.
	 *
	 * @param array $attributes Block attributes.
	 * @return \WC_Product|null
	 */
	private static function current_product( $attributes = array() ) {
		$id = ! empty( $attributes['productId'] ) ? (int) $attributes['productId'] : (int) get_the_ID();
		$p  = $id ? wc_get_product( $id ) : null;
		if ( $p && $p->is_type( 'variation' ) ) {
			$p = wc_get_product( $p->get_parent_id() );
		}
		return $p ? $p : null;
	}

	/**
	 * Safety block render.
	 *
	 * @param array $attributes Attributes.
	 * @return string
	 */
	public static function render_safety_block( $attributes ) {
		$p = self::current_product( $attributes );
		if ( ! $p || ! Settings::get( 'safety' ) ) {
			return '';
		}
		$html = self::safety_html( $p );
		return '' === $html ? '' : '<div class="wp-block-mostrador-product-safety">' . $html . '</div>';
	}

	/**
	 * Food block render.
	 *
	 * @param array $attributes Attributes.
	 * @return string
	 */
	public static function render_food_block( $attributes ) {
		$p = self::current_product( $attributes );
		if ( ! $p || ! Settings::get( 'food' ) || ! self::is_food( $p ) ) {
			return '';
		}
		return '<div class="wp-block-mostrador-food-info">' . self::food_html( $p ) . '</div>';
	}

	/**
	 * Whether a product is marked as food.
	 *
	 * @param \WC_Product $product Product.
	 * @return bool
	 */
	public static function is_food( $product ) {
		return (bool) $product->get_meta( '_mostrador_food' );
	}

	/**
	 * Add our tabs.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public static function tabs( $tabs ) {
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			return $tabs;
		}
		if ( Settings::get( 'food' ) && self::is_food( $product ) ) {
			$tabs['mostrador_food'] = array(
				'title'    => __( 'Food information', 'mostrador' ),
				'priority' => 15,
				'callback' => array( __CLASS__, 'food_tab' ),
			);
		}
		if ( Settings::get( 'safety' ) && '' !== self::safety_html( $product ) ) {
			$tabs['mostrador_safety'] = array(
				'title'    => __( 'Product safety', 'mostrador' ),
				'priority' => 35,
				'callback' => array( __CLASS__, 'safety_tab' ),
			);
		}
		return $tabs;
	}

	/**
	 * Allowed tags in owner text.
	 *
	 * @return array
	 */
	private static function inline_tags() {
		return array(
			'b'      => array(),
			'strong' => array(),
			'em'     => array(),
			'i'      => array(),
			'br'     => array(),
		);
	}

	/**
	 * Lines joined with <br>.
	 *
	 * @param array $lines Lines, empty ones skipped.
	 * @return string Escaped HTML.
	 */
	private static function lines( array $lines ) {
		$out = array();
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' !== $line ) {
				$out[] = nl2br( esc_html( $line ) );
			}
		}
		return implode( '<br>', $out );
	}

	/**
	 * Contact as a link when it is an email or a web address.
	 *
	 * @param string $contact Contact.
	 * @return string Escaped HTML.
	 */
	private static function contact( $contact ) {
		$contact = trim( (string) $contact );
		if ( '' === $contact ) {
			return '';
		}
		if ( is_email( $contact ) ) {
			return '<a href="' . esc_url( 'mailto:' . $contact ) . '">' . esc_html( $contact ) . '</a>';
		}
		if ( preg_match( '#^https?://#i', $contact ) ) {
			return '<a href="' . esc_url( $contact ) . '" rel="nofollow noopener">' . esc_html( $contact ) . '</a>';
		}
		return esc_html( $contact );
	}

	/**
	 * Safety sections, '' when nothing is filled in.
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public static function safety_html( $product ) {
		$sections = array();
		$maker    = Catalog::maker_for( $product );
		if ( $maker ) {
			$body = self::lines( array( $maker['name'], $maker['address'] ) );
			$link = self::contact( $maker['contact'] );
			if ( $link ) {
				$body .= ( '' !== $body ? '<br>' : '' ) . $link;
			}
			$sections[] = array( __( 'Manufacturer', 'mostrador' ), $body );
			if ( '' !== $maker['eu_name'] ) {
				$body = self::lines( array( $maker['eu_name'], $maker['eu_address'] ) );
				$link = self::contact( $maker['eu_contact'] );
				if ( $link ) {
					$body .= '<br>' . $link;
				}
				$sections[] = array( __( 'Responsible person in the EU', 'mostrador' ), $body );
			}
		}
		$identifier = trim( (string) $product->get_meta( '_mostrador_identifier' ) );
		if ( '' !== $identifier ) {
			$sections[] = array( __( 'Product identification', 'mostrador' ), esc_html( $identifier ) );
		}
		$warnings = trim( (string) $product->get_meta( '_mostrador_warnings' ) );
		$image    = (int) $product->get_meta( '_mostrador_label_image' );
		if ( '' !== $warnings || $image ) {
			$body = '' !== $warnings ? wp_kses_post( wpautop( $warnings ) ) : '';
			if ( $image ) {
				$body .= wp_get_attachment_image( $image, 'medium', false, array( 'class' => 'mostrador-label-image' ) );
			}
			$sections[] = array( __( 'Warnings and safety', 'mostrador' ), $body );
		}
		if ( ! $sections ) {
			return '';
		}
		$html = '<div class="mostrador-safety">';
		foreach ( $sections as $section ) {
			$html .= '<h3 class="mostrador-h">' . esc_html( $section[0] ) . '</h3><div class="mostrador-p">' . $section[1] . '</div>';
		}
		return $html . '</div>';
	}

	/**
	 * Tab callback: safety.
	 */
	public static function safety_tab() {
		global $product;
		echo '<h2>' . esc_html__( 'Product safety', 'mostrador' ) . '</h2>';
		echo self::safety_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/**
	 * Short block under the add to cart button: maker and warnings only.
	 *
	 * @param \WC_Product|null $product Product.
	 * @return string
	 */
	public static function short_html( $product = null ) {
		if ( ! $product instanceof \WC_Product ) {
			return '';
		}
		$maker    = Catalog::maker_for( $product );
		$warnings = trim( wp_strip_all_tags( (string) $product->get_meta( '_mostrador_warnings' ) ) );
		if ( ! $maker && '' === $warnings ) {
			return '';
		}
		$html = '<div class="mostrador-safety-short">';
		if ( $maker ) {
			$html .= '<p><strong>' . esc_html__( 'Manufacturer', 'mostrador' ) . ':</strong> ' . esc_html( $maker['name'] ) . '</p>';
		}
		if ( '' !== $warnings ) {
			$html .= '<p><strong>' . esc_html__( 'Warnings', 'mostrador' ) . ':</strong> ' . esc_html( wp_trim_words( $warnings, 30 ) ) . '</p>';
		}
		return $html . '</div>';
	}

	/**
	 * Classic hook.
	 */
	public static function short_block() {
		global $product;
		if ( self::$short_done ) {
			return;
		}
		self::$short_done = true;
		echo self::short_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in short_html().
	}

	/**
	 * Block themes.
	 *
	 * @param string $html Block HTML.
	 * @return string
	 */
	public static function short_after_block( $html ) {
		if ( self::$short_done || ! is_product() ) {
			return $html;
		}
		self::$short_done = true;
		return $html . self::short_html( wc_get_product( get_queried_object_id() ) );
	}

	/**
	 * Allergen names, bold, with optional icon.
	 *
	 * @param array $slugs Slugs.
	 * @return string
	 */
	private static function allergen_list( array $slugs ) {
		$names = Catalog::allergens();
		$out   = array();
		foreach ( $slugs as $slug ) {
			if ( ! isset( $names[ $slug ] ) ) {
				continue;
			}
			$icon = '';
			if ( Settings::get( 'allergen_icons' ) && file_exists( MOSTRADOR_DIR . 'assets/icons/' . $slug . '.svg' ) ) {
				$icon = '<img class="mostrador-icon" src="' . esc_url( MOSTRADOR_URL . 'assets/icons/' . $slug . '.svg' ) . '" alt="" width="18" height="18" style="display:inline-block;vertical-align:-3px;margin:0 3px 0 0;height:18px;width:18px"> ';
			}
			$out[] = $icon . '<b>' . esc_html( $names[ $slug ] ) . '</b>';
		}
		return implode( ', ', $out );
	}

	/**
	 * Food information HTML.
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public static function food_html( $product ) {
		$f    = Catalog::food_data( $product->get_id() );
		$rows = array();
		if ( '' !== $f['name'] ) {
			$rows[] = array( __( 'Name of the food', 'mostrador' ), esc_html( $f['name'] ) );
		}
		if ( '' !== $f['ingredients'] ) {
			$rows[] = array( __( 'Ingredients', 'mostrador' ), wp_kses( $f['ingredients'], self::inline_tags() ) );
		}
		$allergens = array();
		if ( $f['allergens'] ) {
			/* translators: %s: list of allergens. */
			$allergens[] = sprintf( esc_html__( 'Contains: %s', 'mostrador' ), self::allergen_list( $f['allergens'] ) );
		} elseif ( ! empty( $f['none'] ) ) {
			$allergens[] = esc_html__( 'No allergens.', 'mostrador' );
		}
		if ( $f['traces'] ) {
			/* translators: %s: list of allergens. */
			$allergens[] = sprintf( esc_html__( 'May contain traces of: %s', 'mostrador' ), self::allergen_list( $f['traces'] ) );
		}
		if ( $allergens ) {
			$rows[] = array( __( 'Allergens', 'mostrador' ), implode( '<br>', $allergens ) );
		}
		foreach ( array(
			'net'      => __( 'Net quantity', 'mostrador' ),
			'storage'  => __( 'Storage and use', 'mostrador' ),
			'origin'   => __( 'Origin', 'mostrador' ),
			'operator' => __( 'Operator', 'mostrador' ),
		) as $key => $label ) {
			if ( '' !== trim( (string) $f[ $key ] ) ) {
				$rows[] = array( $label, nl2br( esc_html( $f[ $key ] ) ) );
			}
		}
		if ( '' !== trim( (string) $f['abv'] ) ) {
			/* translators: %s: alcohol strength, a number. */
			$rows[] = array( __( 'Alcohol', 'mostrador' ), esc_html( sprintf( __( '%s %% vol', 'mostrador' ), str_replace( '%', '', $f['abv'] ) ) ) );
		}
		$html = '<div class="mostrador-food">';
		foreach ( $rows as $row ) {
			$html .= '<h3 class="mostrador-h">' . esc_html( $row[0] ) . '</h3><div class="mostrador-p">' . $row[1] . '</div>';
		}
		$nutrition = array_filter( (array) $f['nutrition'], 'strlen' );
		if ( $nutrition ) {
			$per = '100ml' === $f['per'] ? '100 ml' : '100 g';
			/* translators: %s: 100 g or 100 ml. */
			$html  .= '<h3 class="mostrador-h">' . esc_html( sprintf( __( 'Nutrition information (per %s)', 'mostrador' ), $per ) ) . '</h3>';
			$html  .= '<table class="mostrador-nutrition shop_attributes"><tbody>';
			$labels = ProductFields::nutrition_rows();
			foreach ( $labels as $key => $label ) {
				if ( ! isset( $nutrition[ $key ] ) ) {
					continue;
				}
				$value = (string) $nutrition[ $key ];
				if ( 'energy' !== $key && is_numeric( str_replace( ',', '.', $value ) ) ) {
					$value .= ' g';
				}
				$sub   = in_array( $key, array( 'saturates', 'sugars' ), true ) ? ' class="mostrador-sub"' : '';
				$html .= '<tr' . $sub . '><th>' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
			}
			$html .= '</tbody></table>';
		}
		return $html . '</div>';
	}

	/**
	 * Tab callback: food.
	 */
	public static function food_tab() {
		global $product;
		echo '<h2>' . esc_html__( 'Food information', 'mostrador' ) . '</h2>';
		echo self::food_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}
}
