<?php
/**
 * Plugin Name:          Mostrador for WooCommerce – NIF, precio por unidad y recargo de equivalencia en España
 * Plugin URI:           https://facilo.es/mostrador/
 * Description:          Get your WooCommerce shop ready to sell in Spain: NIF/NIE/CIF at checkout, IVA rates, price per unit, delivery times, product safety, allergens and your conditions in the order email.
 * Version:              1.0.0
 * Requires at least:    6.6
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               Facilo
 * Author URI:           https://facilo.es
 * License:              GPLv2 or later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          mostrador
 * Domain Path:          /languages
 * WC requires at least: 9.0
 * WC tested up to:      11.2
 *
 * @package Mostrador
 */

defined( 'ABSPATH' ) || exit;

define( 'MOSTRADOR_VERSION', '1.0.0' );
define( 'MOSTRADOR_FILE', __FILE__ );
define( 'MOSTRADOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'MOSTRADOR_URL', plugin_dir_url( __FILE__ ) );

foreach ( array( 'i18n', 'settings', 'nifvalidator', 'nif', 'checkout', 'unitprice', 'delivery', 'pricenotes', 'catalog', 'productfields', 'producttabs', 'allergenfilter', 'allergenwidget', 'emails', 'taxrates', 'styles', 'sitecheck', 'privacy', 'admin', 'wizard' ) as $mostrador_file ) {
	require_once MOSTRADOR_DIR . 'includes/class-' . $mostrador_file . '.php';
}
unset( $mostrador_file );

register_activation_hook( __FILE__, array( 'Mostrador\\Admin', 'activate' ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

Mostrador\I18n::boot();

add_action( 'plugins_loaded', array( 'Mostrador\\Wizard', 'preview' ), 4 );

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		Mostrador\Catalog::boot();
		Mostrador\Nif::boot();
		Mostrador\Checkout::boot();
		Mostrador\UnitPrice::boot();
		Mostrador\Delivery::boot();
		Mostrador\PriceNotes::boot();
		Mostrador\ProductFields::boot();
		Mostrador\ProductTabs::boot();
		Mostrador\AllergenFilter::boot();
		Mostrador\Emails::boot();
		Mostrador\Styles::boot();
		Mostrador\SiteCheck::boot();
		Mostrador\Privacy::boot();
		if ( is_admin() ) {
			Mostrador\Admin::boot();
			Mostrador\Wizard::boot();
		}
	},
	5
);
