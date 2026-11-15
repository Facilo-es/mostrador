<?php
/**
 * Uninstall. Options and the tax rates Mostrador created go only when the owner ticked
 * "Delete all Mostrador data when uninstalling". Product and order data always stay,
 * since invoices and records may rely on them.
 *
 * @package Mostrador
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site.
 */
function mostrador_uninstall_site() {
	global $wpdb;
	delete_transient( 'mostrador_activation_redirect' );
	delete_transient( 'mostrador_badge' );

	$settings = get_option( 'mostrador_settings', array() );
	if ( ! is_array( $settings ) || empty( $settings['delete_on_uninstall'] ) ) {
		return;
	}
	if ( class_exists( '\WC_Tax' ) ) {
		require_once __DIR__ . '/includes/class-taxrates.php';
		\Mostrador\TaxRates::remove_created();
	}
	foreach ( array( 'mostrador_settings', 'mostrador_delivery_times', 'mostrador_allergen_terms', 'mostrador_digital_ids', 'mostrador_installed_at', 'mostrador_created_rates', 'mostrador_created_classes' ) as $option ) {
		delete_option( $option );
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_mostrador_' ) . '%', $wpdb->esc_like( '_transient_timeout_mostrador_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own transients, on uninstall.
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $mostrador_site ) {
		switch_to_blog( $mostrador_site );
		mostrador_uninstall_site();
		restore_current_blog();
	}
} else {
	mostrador_uninstall_site();
}
