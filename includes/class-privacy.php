<?php
/**
 * Personal data export and erase for the NIF and the digital-content consent.
 *
 * @package Mostrador
 */

namespace Mostrador;

defined( 'ABSPATH' ) || exit;

/**
 * Privacy.
 */
class Privacy {

	/**
	 * Hooks.
	 */
	public static function boot() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy_text' ) );
	}

	/**
	 * Suggested privacy policy text.
	 */
	public static function policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p>' . esc_html__( 'When you order, we store the tax ID (NIF, NIE or CIF) you give at checkout with your order and, if you have an account, in your account, so we can issue the invoice the law requires. When you buy digital content, we store that you agreed to receive it straight away and lose the right to withdraw, with the date and the text you accepted.', 'mostrador' ) . '</p>';
		wp_add_privacy_policy_content( 'Mostrador', wp_kses_post( $text ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function exporters( $exporters ) {
		$exporters['mostrador'] = array(
			'exporter_friendly_name' => __( 'Mostrador (tax ID and consent)', 'mostrador' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function erasers( $erasers ) {
		$erasers['mostrador'] = array(
			'eraser_friendly_name' => __( 'Mostrador (tax ID and consent)', 'mostrador' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Orders of an email address, 10 per page.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return \WC_Order[]
	 */
	private static function orders( $email, $page ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		return wc_get_orders(
			array(
				'billing_email' => $email,
				'limit'         => 10,
				'page'          => max( 1, (int) $page ),
				'type'          => 'shop_order',
			)
		);
	}

	/**
	 * Export.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		$data = array();
		$user = get_user_by( 'email', $email );
		if ( 1 === (int) $page && $user ) {
			$nif = Nif::get_for_user( $user->ID );
			if ( '' !== $nif ) {
				$data[] = array(
					'group_id'    => 'mostrador-customer',
					'group_label' => __( 'Tax ID', 'mostrador' ),
					'item_id'     => 'mostrador-user-' . $user->ID,
					'data'        => array(
						array(
							'name'  => Nif::label(),
							'value' => $nif,
						),
					),
				);
			}
		}
		$orders = self::orders( $email, $page );
		foreach ( $orders as $order ) {
			$items   = array();
			$nif     = Nif::get_for_order( $order );
			$consent = $order->get_meta( '_mostrador_digital_consent' );
			if ( '' !== $nif ) {
				$items[] = array(
					'name'  => Nif::label(),
					'value' => $nif,
				);
			}
			if ( is_array( $consent ) && ! empty( $consent['text'] ) ) {
				$items[] = array(
					'name'  => __( 'Digital content consent', 'mostrador' ),
					'value' => $consent['text'] . ( ! empty( $consent['time'] ) ? ' (' . wp_date( 'Y-m-d H:i', (int) $consent['time'] ) . ')' : '' ),
				);
			}
			if ( $items ) {
				$data[] = array(
					'group_id'    => 'mostrador-orders',
					'group_label' => __( 'Order tax ID and consent', 'mostrador' ),
					'item_id'     => 'mostrador-order-' . $order->get_id(),
					'data'        => $items,
				);
			}
		}
		return array(
			'data' => $data,
			'done' => count( $orders ) < 10,
		);
	}

	/**
	 * Erase. Order NIFs are kept when WooCommerce keeps the order for tax reasons.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		$removed  = false;
		$retained = false;
		$messages = array();
		$user     = get_user_by( 'email', $email );
		if ( 1 === (int) $page && $user ) {
			foreach ( array( 'billing_nif', Nif::BLOCK_KEY ) as $key ) {
				if ( '' !== (string) get_user_meta( $user->ID, $key, true ) ) {
					delete_user_meta( $user->ID, $key );
					$removed = true;
				}
			}
		}
		$erase_orders = 'yes' === get_option( 'woocommerce_erasure_request_removes_order_data', 'no' );
		$orders       = self::orders( $email, $page );
		foreach ( $orders as $order ) {
			$has = '' !== Nif::get_for_order( $order ) || $order->get_meta( '_mostrador_digital_consent' );
			if ( ! $has ) {
				continue;
			}
			if ( ! $erase_orders || ! apply_filters( 'woocommerce_privacy_erase_order_personal_data', true, $order ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter, applied the same way WooCommerce does.
				$retained = true;
				continue;
			}
			foreach ( array( '_billing_nif', Nif::BLOCK_KEY, '_mostrador_digital_consent' ) as $key ) {
				$order->delete_meta_data( $key );
			}
			$order->save();
			$removed = true;
		}
		if ( $retained ) {
			$messages[] = __( 'Tax IDs and consents on orders were kept, because WooCommerce is set to keep order data.', 'mostrador' );
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => count( $orders ) < 10,
		);
	}
}
