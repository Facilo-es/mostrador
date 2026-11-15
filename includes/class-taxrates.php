<?php
/**
 * IVA tax classes and rates (LIVA art. 3, 90 and 91).
 *
 * @package Mostrador
 */

namespace Mostrador;

defined( 'ABSPATH' ) || exit;

/**
 * TaxRates.
 */
class TaxRates {

	/**
	 * Option holding the IDs of the rates Mostrador created (removed on uninstall when asked).
	 */
	const CREATED = 'mostrador_created_rates';

	/**
	 * Option holding the tax class slugs Mostrador created.
	 */
	const CREATED_CLASSES = 'mostrador_created_classes';

	/**
	 * Territories outside the IVA area (WooCommerce state codes).
	 *
	 * @var string[]
	 */
	const OUTSIDE = array( 'GC', 'TF', 'CE', 'ML' );

	/**
	 * Tax classes: slug ('' is Standard) => [ class name, rate, rate name ].
	 * The class names differ from WooCommerce's own "Reduced rate" and "Zero rate" classes,
	 * which would otherwise block creating ours (same name) and send the rates to Standard.
	 *
	 * @return array
	 */
	public static function plan() {
		return array(
			''                   => array( '', '21.0000', 'IVA 21 %' ),
			'tipo-reducido'      => array( 'IVA 10 %', '10.0000', 'IVA 10 %' ),
			'tipo-superreducido' => array( 'IVA 4 %', '4.0000', 'IVA 4 %' ),
			'tipo-cero'          => array( 'IVA 0 %', '0.0000', 'IVA 0 %' ),
		);
	}

	/**
	 * Existing rates for Spain.
	 *
	 * @return array Rows with tax_rate_id, tax_rate_state, tax_rate, tax_rate_name, tax_rate_class.
	 */
	public static function existing() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WooCommerce's own table, read once in the wizard and Revisión.
		$rows = $wpdb->get_results( "SELECT tax_rate_id, tax_rate_state, tax_rate, tax_rate_name, tax_rate_class FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_country = 'ES' ORDER BY tax_rate_class, tax_rate_order", ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Whether a Spanish 21 % rate exists in the standard class.
	 *
	 * @return bool
	 */
	public static function has_standard() {
		foreach ( self::existing() as $row ) {
			if ( '' === $row['tax_rate_class'] && '' === $row['tax_rate_state'] && abs( (float) $row['tax_rate'] - 21 ) < 0.001 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Rates still missing: list of [ class, state, rate, name ].
	 *
	 * @return array
	 */
	public static function missing() {
		$have = array();
		foreach ( self::existing() as $row ) {
			$have[ $row['tax_rate_class'] . '|' . strtoupper( (string) $row['tax_rate_state'] ) ] = true;
		}
		$missing = array();
		foreach ( self::plan() as $class => $info ) {
			foreach ( self::OUTSIDE as $state ) {
				if ( empty( $have[ $class . '|' . $state ] ) ) {
					$missing[] = array( $class, $state, '0.0000', 'IVA 0 %' );
				}
			}
			if ( empty( $have[ $class . '|' ] ) ) {
				$missing[] = array( $class, '', $info[1], $info[2] );
			}
		}
		return $missing;
	}

	/**
	 * Create the classes and the missing rates. Never deletes or changes an existing rate.
	 *
	 * @return int Number of rates added.
	 */
	public static function add_missing() {
		if ( ! class_exists( '\WC_Tax' ) ) {
			return 0;
		}
		$classes = (array) get_option( self::CREATED_CLASSES, array() );
		$slugs   = \WC_Tax::get_tax_class_slugs();
		foreach ( self::plan() as $slug => $info ) {
			if ( '' === $slug || in_array( $slug, $slugs, true ) ) {
				continue;
			}
			$made = \WC_Tax::create_tax_class( $info[0], $slug );
			if ( ! is_wp_error( $made ) ) {
				$classes[] = $slug;
			}
		}
		update_option( self::CREATED_CLASSES, array_values( array_unique( $classes ) ), false );

		$created = (array) get_option( self::CREATED, array() );
		$added   = 0;
		$slugs   = \WC_Tax::get_tax_class_slugs();
		foreach ( self::missing() as $rate ) {
			if ( '' !== $rate[0] && ! in_array( $rate[0], $slugs, true ) ) {
				continue; // Never let WooCommerce file a 10 % or 0 % rate under Standard.
			}
			// State rates go first, so they win over the country-wide rate of the same priority.
			$id = \WC_Tax::_insert_tax_rate(
				array(
					'tax_rate_country'  => 'ES',
					'tax_rate_state'    => $rate[1],
					'tax_rate'          => $rate[2],
					'tax_rate_name'     => $rate[3],
					'tax_rate_priority' => 1,
					'tax_rate_compound' => 0,
					'tax_rate_shipping' => 1,
					'tax_rate_order'    => '' === $rate[1] ? 10 : 0,
					'tax_rate_class'    => $rate[0],
				)
			);
			if ( $id ) {
				$created[] = (int) $id;
				++$added;
			}
		}
		update_option( self::CREATED, array_values( array_unique( array_map( 'intval', $created ) ) ), false );
		SiteCheck::flush();
		return $added;
	}

	/**
	 * Turn taxes on and set how prices are entered and shown.
	 *
	 * @param bool $prices_incl Prices entered with tax.
	 * @param bool $display_incl Show prices with tax in shop and cart.
	 */
	public static function configure( $prices_incl, $display_incl ) {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', $prices_incl ? 'yes' : 'no' );
		update_option( 'woocommerce_tax_display_shop', $display_incl ? 'incl' : 'excl' );
		update_option( 'woocommerce_tax_display_cart', $display_incl ? 'incl' : 'excl' );
		SiteCheck::flush();
	}

	/**
	 * Remove only what Mostrador created (uninstall with "delete data" ticked).
	 */
	public static function remove_created() {
		if ( ! class_exists( '\WC_Tax' ) ) {
			return;
		}
		foreach ( (array) get_option( self::CREATED, array() ) as $id ) {
			\WC_Tax::_delete_tax_rate( (int) $id );
		}
		foreach ( (array) get_option( self::CREATED_CLASSES, array() ) as $slug ) {
			$class = \WC_Tax::get_tax_class_by( 'slug', $slug );
			if ( $class && ! \WC_Tax::get_rates_for_tax_class( $slug ) ) {
				\WC_Tax::delete_tax_class_by( 'slug', $slug );
			}
		}
		delete_option( self::CREATED );
		delete_option( self::CREATED_CLASSES );
	}
}
