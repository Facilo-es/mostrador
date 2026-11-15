<?php
/**
 * Revisión: a technical check of the shop's settings. Never says a shop "complies".
 *
 * @package Mostrador
 */

namespace Mostrador;

defined( 'ABSPATH' ) || exit;

/**
 * SiteCheck.
 */
class SiteCheck {

	/**
	 * Transient with the counts that need a query (1 hour).
	 */
	const CACHE = 'mostrador_check_counts';

	/**
	 * Known withdrawal-button plugins (folder slugs).
	 */
	const WITHDRAWAL = array(
		'eu-order-withdrawal-button-for-woocommerce',
		'wt-eu-withdrawal-button',
		'eu-withdrawal-compliance',
		'eu-withdrawal-button-for-woocommerce',
		'easy-return-eu-withdrawal-button-for-woocommerce',
		'buddypilot-withdrawal',
		'woocommerce-germanized',
	);

	/**
	 * ODR link the check looks for.
	 */
	const ODR = 'ec.europa.eu/consumers/odr';

	/**
	 * Hooks: drop the cached counts when products or pages change.
	 */
	public static function boot() {
		add_action( 'save_post', array( __CLASS__, 'flush' ) );
		add_action( 'deleted_post', array( __CLASS__, 'flush' ) );
		add_action( 'set_object_terms', array( __CLASS__, 'flush' ) );
		add_action( 'update_option_widget_block', array( __CLASS__, 'flush' ) );
		add_action( 'update_option_widget_text', array( __CLASS__, 'flush' ) );
		add_action( 'activated_plugin', array( __CLASS__, 'flush' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'flush' ) );
	}

	/**
	 * Clear cached counts.
	 */
	public static function flush() {
		delete_transient( self::CACHE );
		delete_transient( 'mostrador_badge' );
		Settings::flush();
	}

	/**
	 * Group labels, in display order.
	 *
	 * @return array
	 */
	public static function groups() {
		return array(
			'impuestos' => __( 'Taxes', 'mostrador' ),
			'pago'      => __( 'Checkout', 'mostrador' ),
			'emails'    => __( 'Emails', 'mostrador' ),
			'productos' => __( 'Products', 'mostrador' ),
		);
	}

	/**
	 * Status labels.
	 *
	 * @return array
	 */
	public static function status_labels() {
		return array(
			'ready'  => __( 'Ready', 'mostrador' ),
			'review' => __( 'Check this', 'mostrador' ),
			'na'     => __( 'Not applicable', 'mostrador' ),
		);
	}

	/**
	 * Admin URL of a settings tab.
	 *
	 * @param string $tab Tab.
	 * @return string
	 */
	private static function settings_url( $tab ) {
		return admin_url( 'admin.php?page=mostrador-ajustes&tab=' . $tab );
	}

	/**
	 * Admin URL of a wizard step.
	 *
	 * @param int $step Step.
	 * @return string
	 */
	private static function wizard_url( $step ) {
		return admin_url( 'admin.php?page=mostrador-asistente&step=' . (int) $step );
	}

	/**
	 * Whether a page is set and published.
	 *
	 * @param int $id Page ID.
	 * @return bool
	 */
	private static function page_ok( $id ) {
		return $id > 0 && 'publish' === get_post_status( $id );
	}

	/**
	 * Name of the shop's territory when it isn't in the IVA area.
	 *
	 * @return array [ territory, tax ] or [].
	 */
	public static function outside_vat() {
		switch ( (string) Settings::get( 'region' ) ) {
			case 'canarias':
				return array( __( 'the Canary Islands', 'mostrador' ), 'IGIC' );
			case 'ceuta':
				return array( 'Ceuta', 'IPSI' );
			case 'melilla':
				return array( 'Melilla', 'IPSI' );
		}
		return array();
	}

	/**
	 * Active withdrawal-button plugin name, or ''.
	 *
	 * @return string
	 */
	public static function withdrawal_plugin() {
		$slugs  = (array) apply_filters( 'mostrador_withdrawal_plugins', self::WITHDRAWAL );
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		foreach ( $active as $file ) {
			if ( in_array( dirname( (string) $file ), $slugs, true ) ) {
				if ( ! function_exists( 'get_plugin_data' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				$path = WP_PLUGIN_DIR . '/' . $file;
				$data = file_exists( $path ) ? get_plugin_data( $path, false, false ) : array();
				return ! empty( $data['Name'] ) ? (string) $data['Name'] : dirname( (string) $file );
			}
		}
		return '';
	}

	/**
	 * Counts that need a query, cached for an hour.
	 *
	 * @return array
	 */
	public static function counts() {
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$counts = array(
			'unit'      => self::count_products(
				array(
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one count, cached 1 hour.
						'relation' => 'AND',
						array(
							'key'   => '_mostrador_sold_by_qty',
							'value' => 'yes',
						),
						array(
							'relation' => 'OR',
							array(
								'key'     => '_mostrador_unit',
								'compare' => 'NOT EXISTS',
							),
							array(
								'key'     => '_mostrador_qty',
								'compare' => 'NOT EXISTS',
							),
						),
					),
				)
			),
			'maker'     => self::count_products(
				array(
					'tax_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one count, cached 1 hour.
						array(
							'taxonomy' => Catalog::MAKER,
							'operator' => 'NOT EXISTS',
						),
					),
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'     => '_virtual',
							'value'   => 'yes',
							'compare' => '!=',
						),
					),
				)
			),
			'allergens' => self::count_products(
				array(
					'tax_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						array(
							'taxonomy' => Catalog::ALLERGEN,
							'operator' => 'NOT EXISTS',
						),
					),
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'relation' => 'AND',
						array(
							'key'   => '_mostrador_food',
							'value' => 'yes',
						),
						array(
							'relation' => 'OR',
							array(
								'key'     => '_mostrador_food_data',
								'compare' => 'NOT EXISTS',
							),
							array(
								'key'     => '_mostrador_food_data',
								'value'   => '"none";i:1;',
								'compare' => 'NOT LIKE',
							),
						),
					),
				)
			),
			'odr'       => self::odr_hits(),
		);
		set_transient( self::CACHE, $counts, HOUR_IN_SECONDS );
		return $counts;
	}

	/**
	 * Count published products matching the args.
	 *
	 * @param array $args Query args.
	 * @return int
	 */
	private static function count_products( array $args ) {
		$q = new \WP_Query(
			array_merge(
				array(
					'post_type'              => 'product',
					'post_status'            => 'publish',
					'posts_per_page'         => 1,
					'fields'                 => 'ids',
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				),
				$args
			)
		);
		return (int) $q->found_posts;
	}

	/**
	 * Published content that still links the closed ODR platform: list of post IDs, plus 0 for a widget.
	 *
	 * @return int[]
	 */
	private static function odr_hits() {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( self::ODR ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cached for an hour by counts().
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ( 'page', 'post', 'wp_template', 'wp_template_part', 'wp_block' ) AND post_content LIKE %s LIMIT 20", $like ) );
		$ids = array_map( 'intval', (array) $ids );
		foreach ( array( 'widget_block', 'widget_text', 'widget_custom_html' ) as $option ) {
			$value = get_option( $option );
			if ( $value && false !== strpos( wp_json_encode( $value ), str_replace( '/', '\\/', self::ODR ) ) ) {
				$ids[] = 0;
				break;
			}
		}
		return $ids;
	}

	/**
	 * Every check.
	 *
	 * @return array
	 */
	public static function checks() {
		$counts  = self::counts();
		$checks  = array();
		$tax_url = admin_url( 'admin.php?page=wc-settings&tab=tax' );
		$outside = self::outside_vat();

		// Taxes.
		$on       = 'yes' === get_option( 'woocommerce_calc_taxes' );
		$checks[] = array(
			'id'     => 'taxes_on',
			'group'  => 'impuestos',
			'title'  => __( 'Taxes turned on', 'mostrador' ),
			'status' => $on ? 'ready' : 'review',
			'line'   => $on ? __( 'WooCommerce adds tax to every order.', 'mostrador' ) : __( 'Taxes are turned off, so no VAT is charged.', 'mostrador' ),
			'button' => __( 'Fix', 'mostrador' ),
			'url'    => self::wizard_url( 2 ),
		);
		if ( $outside ) {
			$checks[] = array(
				'id'     => 'vat_rates',
				'group'  => 'impuestos',
				'title'  => __( 'VAT rates', 'mostrador' ),
				'status' => 'na',
				/* translators: 1: territory, 2: IGIC or IPSI. */
				'line'   => sprintf( __( 'In %1$s, %2$s applies, not VAT. Mostrador doesn\'t create those rates, but everything else works the same.', 'mostrador' ), $outside[0], $outside[1] ),
				'button' => __( 'View', 'mostrador' ),
				'url'    => $tax_url,
			);
		} else {
			$ok       = TaxRates::has_standard();
			$checks[] = array(
				'id'     => 'vat_rates',
				'group'  => 'impuestos',
				'title'  => __( 'VAT rates', 'mostrador' ),
				'status' => $ok ? 'ready' : 'review',
				'line'   => $ok ? __( 'Spain has a 21 % rate.', 'mostrador' ) : __( 'There is no 21 % VAT rate for Spain.', 'mostrador' ),
				'button' => __( 'Fix', 'mostrador' ),
				'url'    => $ok ? $tax_url : self::wizard_url( 2 ),
			);
		}
		$incl     = 'incl' === get_option( 'woocommerce_tax_display_shop', 'excl' );
		$checks[] = array(
			'id'     => 'prices_incl',
			'group'  => 'impuestos',
			'title'  => __( 'Prices with VAT', 'mostrador' ),
			'status' => $incl ? 'ready' : 'review',
			'line'   => $incl ? __( 'Shoppers see the final price.', 'mostrador' ) : __( 'You show prices without VAT. Consumers must be shown the final price.', 'mostrador' ),
			'button' => __( 'Fix', 'mostrador' ),
			'url'    => self::wizard_url( 2 ),
		);

		// Checkout.
		$says     = Settings::says_pay( Settings::button_text() );
		$checks[] = array(
			'id'     => 'buy_button',
			'group'  => 'pago',
			'title'  => __( 'Order button', 'mostrador' ),
			'status' => $says ? 'ready' : 'review',
			/* translators: %s: button text. */
			'line'   => $says ? sprintf( __( 'The button says "%s".', 'mostrador' ), Settings::button_text() ) : __( 'The order button doesn\'t say that the order means paying.', 'mostrador' ),
			'button' => __( 'Fix', 'mostrador' ),
			'url'    => self::settings_url( 'pago' ),
		);
		$terms    = (int) wc_terms_and_conditions_page_id();
		$terms_ok = self::page_ok( $terms );
		$checks[] = array(
			'id'     => 'terms',
			'group'  => 'pago',
			'title'  => __( 'Conditions', 'mostrador' ),
			'status' => $terms_ok ? 'ready' : 'review',
			'line'   => $terms_ok ? __( 'Shoppers accept your purchase conditions at checkout.', 'mostrador' ) : __( 'You have no purchase conditions page at checkout.', 'mostrador' ),
			'button' => __( 'Fix', 'mostrador' ),
			'url'    => admin_url( 'admin.php?page=wc-settings&tab=advanced' ),
		);
		$missing  = array();
		if ( ! self::page_ok( (int) get_option( 'wp_page_for_privacy_policy' ) ) ) {
			$missing[] = __( 'Privacy policy', 'mostrador' );
		}
		if ( ! self::page_ok( (int) Settings::get( 'withdrawal_page' ) ) ) {
			$missing[] = __( 'Withdrawal information', 'mostrador' );
		}
		if ( ! self::page_ok( (int) Settings::get( 'shipping_page' ) ) ) {
			$missing[] = __( 'Shipping policy', 'mostrador' );
		}
		$checks[] = array(
			'id'     => 'legal_pages',
			'group'  => 'pago',
			'title'  => __( 'Legal pages', 'mostrador' ),
			'status' => $missing ? 'review' : 'ready',
			/* translators: %l: list of page names. */
			'line'   => $missing ? wp_sprintf( __( 'The %l page is missing.', 'mostrador' ), $missing ) : __( 'Privacy, withdrawal and shipping pages are published.', 'mostrador' ),
			'button' => $missing ? __( 'Fix', 'mostrador' ) : __( 'View', 'mostrador' ),
			'url'    => self::settings_url( 'emails' ),
		);

		// Emails.
		if ( ! $terms_ok ) {
			$email = array( 'na', __( 'Publish your purchase conditions page first, then it goes in the email.', 'mostrador' ) );
		} elseif ( Settings::get( 'emails' ) && in_array( $terms, Emails::pages(), true ) ) {
			$email = array( 'ready', __( 'The first order email carries your conditions.', 'mostrador' ) );
		} else {
			$email = array( 'review', __( 'Your customers don\'t get your conditions in writing.', 'mostrador' ) );
		}
		$checks[] = array(
			'id'     => 'order_email',
			'group'  => 'emails',
			'title'  => __( 'Order email', 'mostrador' ),
			'status' => $email[0],
			'line'   => $email[1],
			'button' => __( 'Fix', 'mostrador' ),
			'url'    => self::settings_url( 'emails' ),
		);
		$odr      = $counts['odr'];
		$first    = $odr ? (int) reset( $odr ) : -1;
		$checks[] = array(
			'id'     => 'odr',
			'group'  => 'emails',
			'title'  => __( 'Link to the ODR platform', 'mostrador' ),
			'status' => $odr ? 'review' : 'ready',
			'line'   => $odr ? __( 'Your site links the European online dispute resolution platform, which closed on 20 July 2025. Remove the link.', 'mostrador' ) : __( 'No page links the closed ODR platform.', 'mostrador' ),
			'button' => __( 'View', 'mostrador' ),
			'url'    => $first > 0 ? (string) get_edit_post_link( $first, 'raw' ) : ( 0 === $first ? admin_url( 'widgets.php' ) : admin_url( 'edit.php?post_type=page' ) ),
			'count'  => count( $odr ),
			'items'  => array_values( array_filter( $odr ) ),
		);
		$plugin   = self::withdrawal_plugin();
		$checks[] = array(
			'id'     => 'withdrawal_button',
			'group'  => 'emails',
			'title'  => __( 'Withdrawal button', 'mostrador' ),
			'status' => '' !== $plugin ? 'ready' : 'na',
			/* translators: %s: plugin name. */
			'line'   => '' !== $plugin ? sprintf( __( '%s adds the button.', 'mostrador' ), $plugin ) : __( 'The EU requires a withdrawal button from 19 June 2026. Spain hasn\'t yet written it into Spanish law.', 'mostrador' ),
			'button' => __( 'View', 'mostrador' ),
			'url'    => admin_url( 'plugins.php' ),
		);

		// Products.
		$products = admin_url( 'edit.php?post_type=product' );
		/* translators: %d: number of products. */
		$checks[] = self::product_check( 'unit_price', 'unit_price', __( 'Unit price', 'mostrador' ), $counts['unit'], __( 'Every product sold by quantity shows its unit price.', 'mostrador' ), _n( '%d product without a unit price.', '%d products without a unit price.', $counts['unit'], 'mostrador' ), $products );
		/* translators: %d: number of products. */
		$checks[] = self::product_check( 'manufacturer', 'safety', __( 'Manufacturer', 'mostrador' ), $counts['maker'], __( 'Every physical product has a manufacturer.', 'mostrador' ), _n( '%d product without a manufacturer.', '%d products without a manufacturer.', $counts['maker'], 'mostrador' ), $products );
		/* translators: %d: number of products. */
		$checks[] = self::product_check( 'allergens', 'food', __( 'Allergens', 'mostrador' ), $counts['allergens'], __( 'Every food product lists its allergens.', 'mostrador' ), _n( '%d food without allergens shown.', '%d foods without allergens shown.', $counts['allergens'], 'mostrador' ), $products );
		if ( Settings::get( 'delivery' ) ) {
			$default  = Settings::default_delivery();
			$checks[] = array(
				'id'     => 'delivery_default',
				'group'  => 'productos',
				'title'  => __( 'Delivery time', 'mostrador' ),
				'status' => '' !== $default ? 'ready' : 'review',
				/* translators: %s: delivery time. */
				'line'   => '' !== $default ? sprintf( __( 'Default: %s.', 'mostrador' ), $default ) : __( 'There is no default delivery time.', 'mostrador' ),
				'button' => __( 'Fix', 'mostrador' ),
				'url'    => self::settings_url( 'productos' ),
			);
		} else {
			$checks[] = self::off_check( 'delivery_default', __( 'Delivery time', 'mostrador' ) );
		}

		$out = array();
		foreach ( (array) apply_filters( 'mostrador_site_checks', $checks ) as $check ) {
			if ( is_array( $check ) && ! empty( $check['id'] ) ) {
				$out[] = self::normalise( $check );
			}
		}
		return $out;
	}

	/**
	 * Fills the keys a check from Free or Pro may leave out, so every check renders the same way.
	 *
	 * @param array $check Check.
	 * @return array
	 */
	public static function normalise( array $check ) {
		$check = array_merge(
			array(
				'group'  => 'productos',
				'title'  => '',
				'status' => 'na',
				'line'   => '',
				'button' => '',
				'url'    => '',
				'count'  => null,
				'items'  => array(),
			),
			$check
		);
		if ( ! in_array( $check['status'], array( 'ready', 'review', 'na' ), true ) ) {
			$check['status'] = 'na';
		}
		$check['items'] = array_values( array_filter( array_map( 'absint', (array) $check['items'] ) ) );
		if ( null !== $check['count'] ) {
			$check['count'] = (int) $check['count'];
		}
		return $check;
	}

	/**
	 * A product count check.
	 *
	 * @param string $id      Check ID.
	 * @param string $setting Module setting.
	 * @param string $title   Title.
	 * @param int    $count   Count.
	 * @param string $ok      Line when ready.
	 * @param string $bad     Line with %d.
	 * @param string $url     Products URL.
	 * @return array
	 */
	private static function product_check( $id, $setting, $title, $count, $ok, $bad, $url ) {
		if ( ! Settings::get( $setting ) ) {
			return self::off_check( $id, $title );
		}
		return array(
			'id'     => $id,
			'group'  => 'productos',
			'title'  => $title,
			'status' => $count ? 'review' : 'ready',
			'line'   => $count ? sprintf( $bad, number_format_i18n( $count ) ) : $ok,
			'button' => __( 'View', 'mostrador' ),
			'url'    => $url,
			'count'  => (int) $count,
		);
	}

	/**
	 * Check for a module that's turned off.
	 *
	 * @param string $id    ID.
	 * @param string $title Title.
	 * @return array
	 */
	private static function off_check( $id, $title ) {
		return array(
			'id'     => $id,
			'group'  => 'productos',
			'title'  => $title,
			'status' => 'na',
			'line'   => __( 'Turned off in Settings.', 'mostrador' ),
			'button' => __( 'View', 'mostrador' ),
			'url'    => self::settings_url( 'productos' ),
		);
	}

	/**
	 * Count of checks per status.
	 *
	 * @param array|null $checks Checks.
	 * @return array
	 */
	public static function summary( $checks = null ) {
		$out = array(
			'ready'  => 0,
			'review' => 0,
			'na'     => 0,
		);
		foreach ( null === $checks ? self::checks() : $checks as $check ) {
			if ( isset( $check['status'], $out[ $check['status'] ] ) ) {
				++$out[ $check['status'] ];
			}
		}
		return $out;
	}
}
