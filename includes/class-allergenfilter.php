<?php
/**
 * "Without allergens" filter for the shop list: widget, block and the query change.
 *
 * @package Mostrador
 */

namespace Mostrador;

defined( 'ABSPATH' ) || exit;

/**
 * AllergenFilter.
 */
class AllergenFilter {

	/**
	 * Query string key.
	 */
	const PARAM = 'sin_alergeno';

	/**
	 * Hooks.
	 */
	public static function boot() {
		if ( ! Settings::get( 'food' ) ) {
			return;
		}
		add_action( 'widgets_init', array( __CLASS__, 'widget' ) );
		add_action( 'init', array( __CLASS__, 'block' ) );
		add_action( 'woocommerce_product_query', array( __CLASS__, 'product_query' ) );
		add_filter( 'query_loop_block_query_vars', array( __CLASS__, 'loop_query' ) );
	}

	/**
	 * Register the widget.
	 */
	public static function widget() {
		register_widget( __NAMESPACE__ . '\\AllergenWidget' );
	}

	/**
	 * Register the block.
	 */
	public static function block() {
		if ( function_exists( 'register_block_type' ) ) {
			register_block_type( MOSTRADOR_DIR . 'blocks/allergen-filter', array( 'render_callback' => array( __CLASS__, 'render_block' ) ) );
		}
	}

	/**
	 * Allergens chosen in the URL.
	 *
	 * @return string[]
	 */
	public static function chosen() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public, read-only shop filter.
		$raw = isset( $_GET[ self::PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ) : '';
		if ( '' === $raw ) {
			return array();
		}
		return array_values( array_intersect( array_map( 'sanitize_key', explode( ',', $raw ) ), array_keys( Catalog::allergens() ) ) );
	}

	/**
	 * Tax query for the chosen allergens.
	 *
	 * @return array|null
	 */
	private static function tax_clause() {
		$chosen = self::chosen();
		if ( ! $chosen ) {
			return null;
		}
		return array(
			'taxonomy' => Catalog::ALLERGEN,
			'field'    => 'slug',
			'terms'    => $chosen,
			'operator' => 'NOT IN',
		);
	}

	/**
	 * Classic shop query.
	 *
	 * @param \WP_Query $q Query.
	 */
	public static function product_query( $q ) {
		$clause = self::tax_clause();
		if ( ! $clause ) {
			return;
		}
		$tax   = (array) $q->get( 'tax_query' );
		$tax[] = $clause;
		$q->set( 'tax_query', $tax ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the filter is a taxonomy filter.
	}

	/**
	 * Block themes: product collection and query loop blocks.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function loop_query( $vars ) {
		$clause = self::tax_clause();
		if ( ! $clause ) {
			return $vars;
		}
		$type = isset( $vars['post_type'] ) ? (array) $vars['post_type'] : array();
		if ( ! in_array( 'product', $type, true ) ) {
			return $vars;
		}
		$vars['tax_query']   = isset( $vars['tax_query'] ) ? (array) $vars['tax_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		$vars['tax_query'][] = $clause;
		return $vars;
	}

	/**
	 * URL with one allergen added or removed.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	private static function toggle_url( $slug ) {
		$chosen = self::chosen();
		if ( in_array( $slug, $chosen, true ) ) {
			$chosen = array_diff( $chosen, array( $slug ) );
		} else {
			$chosen[] = $slug;
		}
		$url = remove_query_arg( array( self::PARAM, 'paged' ) );
		$url = preg_replace( '#/page/\d+/?#', '/', $url );
		return $chosen ? add_query_arg( self::PARAM, implode( ',', $chosen ), $url ) : $url;
	}

	/**
	 * The list of links.
	 *
	 * @param string $title Title, '' for none.
	 * @return string
	 */
	public static function html( $title = '' ) {
		$chosen = self::chosen();
		$html   = '<div class="mostrador-allergen-filter">';
		if ( '' !== $title ) {
			$html .= '<p class="mostrador-filter-title"><strong>' . esc_html( $title ) . '</strong></p>';
		}
		$html .= '<ul>';
		foreach ( Catalog::allergens() as $slug => $name ) {
			$on = in_array( $slug, $chosen, true );
			/* translators: %s: allergen name, like "gluten". */
			$label = sprintf( __( 'Without %s', 'mostrador' ), $name );
			$html .= '<li' . ( $on ? ' class="is-active"' : '' ) . '><a href="' . esc_url( self::toggle_url( $slug ) ) . '" rel="nofollow"' . ( $on ? ' aria-current="true"' : '' ) . '><span class="mostrador-box" aria-hidden="true">' . ( $on ? '&#10003;' : '' ) . '</span> ' . esc_html( $label ) . '</a></li>';
		}
		return $html . '</ul></div>';
	}

	/**
	 * Block render.
	 *
	 * @return string
	 */
	public static function render_block() {
		return '<div class="wp-block-mostrador-allergen-filter">' . self::html( __( 'Without allergens', 'mostrador' ) ) . '</div>';
	}
}
