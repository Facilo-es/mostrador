<?php
/**
 * Purchase conditions and the model withdrawal form in the first customer email (TRLGDCU art. 98.7, Annex B).
 *
 * @package Mostrador
 */

namespace Mostrador;

defined( 'ABSPATH' ) || exit;

/**
 * Emails.
 */
class Emails {

	/**
	 * Order meta set once the block has been added.
	 */
	const SENT = '_mostrador_conditions_sent';

	/**
	 * Hooks.
	 */
	public static function boot() {
		if ( ! Settings::get( 'emails' ) ) {
			return;
		}
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'block' ), 30, 4 );
	}

	/**
	 * Customer emails that carry the block.
	 *
	 * @return string[]
	 */
	public static function email_ids() {
		return (array) apply_filters( 'mostrador_email_ids', array( 'customer_on_hold_order', 'customer_processing_order', 'customer_completed_order' ) );
	}

	/**
	 * Pages added to the email, at most 4. Default: the terms page and the withdrawal page.
	 *
	 * @return int[]
	 */
	public static function pages() {
		$pages = array_filter( array_map( 'absint', (array) Settings::get( 'email_pages', array() ) ) );
		if ( ! $pages ) {
			$pages = array_filter( array( (int) wc_terms_and_conditions_page_id(), (int) Settings::get( 'withdrawal_page' ) ) );
		}
		$out = array();
		foreach ( array_unique( $pages ) as $id ) {
			if ( 'publish' === get_post_status( $id ) ) {
				$out[] = (int) $id;
			}
		}
		return array_slice( $out, 0, 4 );
	}

	/**
	 * The shop line for the form: name, full address, email.
	 *
	 * @return string
	 */
	public static function shop_line() {
		$parts = array( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		if ( function_exists( 'WC' ) && WC()->countries ) {
			$c        = WC()->countries;
			$postcode = trim( $c->get_base_postcode() . ' ' . $c->get_base_city() );
			$country  = $c->get_base_country();
			$address  = array_filter( array( $c->get_base_address(), $c->get_base_address_2(), $postcode, $country && isset( $c->countries[ $country ] ) ? $c->countries[ $country ] : '' ) );
			if ( $address ) {
				$parts[] = implode( ', ', $address );
			}
		}
		$email = sanitize_email( (string) get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) ) );
		if ( $email ) {
			$parts[] = $email;
		}
		return implode( ', ', $parts );
	}

	/**
	 * The form, as title and lines.
	 *
	 * @return array
	 */
	public static function annex() {
		$annex = include MOSTRADOR_DIR . 'data/annex-b.php';
		$shop  = self::shop_line();
		foreach ( $annex['lines'] as $i => $line ) {
			$annex['lines'][ $i ] = str_replace( '{shop}', $shop, $line );
		}
		return $annex;
	}

	/**
	 * Page content ready for an email: blocks rendered, shortcodes and images removed.
	 *
	 * @param int  $id    Page ID.
	 * @param bool $plain Plain text.
	 * @return string
	 */
	public static function page_content( $id, $plain ) {
		$post = get_post( $id );
		if ( ! $post ) {
			return '';
		}
		$content = (string) $post->post_content;
		$content = has_blocks( $content ) ? do_blocks( $content ) : wpautop( $content );
		$content = strip_shortcodes( $content );
		$content = preg_replace( '#<(script|style|figure|picture|svg|iframe|video|audio)\b[^>]*>.*?</\1>#is', '', $content );
		$allowed = array(
			'p'      => array(),
			'br'     => array(),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
			'h2'     => array(),
			'h3'     => array(),
			'h4'     => array(),
			'a'      => array( 'href' => true ),
		);
		$content = wp_kses( $content, $allowed );
		if ( $plain ) {
			$content = preg_replace( '#</(p|li|h2|h3|h4)>|<br\s*/?>#i', "\n", $content );
			$content = html_entity_decode( wp_strip_all_tags( $content ), ENT_QUOTES, 'UTF-8' );
			$content = preg_replace( "/[ \t]+\n/", "\n", $content );
			$content = preg_replace( "/\n{3,}/", "\n\n", $content );
		}
		return trim( $content );
	}

	/**
	 * Whether this email gets the block.
	 *
	 * @param \WC_Order $order Order.
	 * @param bool      $sent_to_admin Admin copy.
	 * @param object    $email Email.
	 * @return bool
	 */
	private static function applies( $order, $sent_to_admin, $email ) {
		if ( $sent_to_admin || ! $order instanceof \WC_Order || ! is_object( $email ) || empty( $email->id ) ) {
			return false;
		}
		if ( ! in_array( $email->id, self::email_ids(), true ) ) {
			return false;
		}
		if ( Settings::get( 'email_first_only' ) && $order->get_meta( self::SENT ) ) {
			return false;
		}
		return (bool) ( self::pages() || Settings::get( 'email_annex' ) || Checkout::order_consent( $order ) );
	}

	/**
	 * Print the block.
	 *
	 * @param \WC_Order $order         Order.
	 * @param bool      $sent_to_admin Admin copy.
	 * @param bool      $plain_text    Plain text.
	 * @param object    $email         Email.
	 */
	public static function block( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
		if ( ! self::applies( $order, $sent_to_admin, $email ) ) {
			return;
		}
		if ( $plain_text ) {
			self::plain( $order );
		} else {
			self::html( $order );
		}
		$order->update_meta_data( self::SENT, time() );
		$order->save_meta_data();
	}

	/**
	 * HTML version.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function html( $order ) {
		$font = 'margin:0 0 12px;';
		echo '<div class="mostrador-email" style="margin:32px 0 16px;">';
		$consent = Checkout::order_consent( $order );
		if ( $consent ) {
			echo '<p style="' . esc_attr( $font ) . '"><strong>' . esc_html__( 'Your consent', 'mostrador' ) . ':</strong> ' . esc_html( $consent ) . '</p>';
		}
		$pages = self::pages();
		if ( $pages ) {
			echo '<h2>' . esc_html__( 'Your purchase conditions', 'mostrador' ) . '</h2>';
			foreach ( $pages as $id ) {
				echo '<h3>' . esc_html( get_the_title( $id ) ) . '</h3>';
				echo '<div style="' . esc_attr( $font ) . '">' . wp_kses_post( self::page_content( $id, false ) ) . '</div>';
			}
		}
		if ( Settings::get( 'email_annex' ) ) {
			$annex = self::annex();
			echo '<div style="margin:24px 0 0;padding:16px;border:1px solid #e5e5e5;">';
			echo '<h3 style="margin-top:0;">' . esc_html( $annex['title'] ) . '</h3>';
			foreach ( $annex['lines'] as $line ) {
				echo '<p style="margin:0 0 8px;">' . esc_html( $line ) . '</p>';
			}
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Plain text version.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function plain( $order ) {
		$rule    = "\n" . str_repeat( '=', 40 ) . "\n";
		$consent = Checkout::order_consent( $order );
		if ( $consent ) {
			echo "\n" . esc_html( __( 'Your consent', 'mostrador' ) . ': ' . $consent ) . "\n";
		}
		$pages = self::pages();
		if ( $pages ) {
			echo $rule . esc_html( wc_strtoupper( __( 'Your purchase conditions', 'mostrador' ) ) ) . $rule; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $rule is a fixed string.
			foreach ( $pages as $id ) {
				echo "\n" . esc_html( wp_specialchars_decode( get_the_title( $id ), ENT_QUOTES ) ) . "\n\n";
				echo esc_html( self::page_content( $id, true ) ) . "\n";
			}
		}
		if ( Settings::get( 'email_annex' ) ) {
			$annex = self::annex();
			echo $rule . esc_html( $annex['title'] ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $rule is a fixed string.
			foreach ( $annex['lines'] as $line ) {
				echo esc_html( $line ) . "\n";
			}
		}
	}
}
