<?php
/**
 * Looks for the notes, tabs and blocks on the shop side.
 *
 * @package Mostrador
 */

namespace Mostrador;

defined( 'ABSPATH' ) || exit;

/**
 * Styles.
 */
class Styles {

	/**
	 * Hooks.
	 */
	public static function boot() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
	}

	/**
	 * Every style: id => [ label, css (URL), pro, preview (one line) ].
	 * Free registers two. Pro adds its own through `mostrador_styles`.
	 *
	 * @return array
	 */
	public static function all() {
		$styles = array(
			'sobrio'   => array(
				'label'   => __( 'Plain', 'mostrador' ),
				'css'     => MOSTRADOR_URL . 'assets/styles/sobrio.css',
				'pro'     => false,
				'preview' => __( 'Small grey text that takes the theme\'s fonts.', 'mostrador' ),
			),
			'contorno' => array(
				'label'   => __( 'Outline', 'mostrador' ),
				'css'     => MOSTRADOR_URL . 'assets/styles/contorno.css',
				'pro'     => false,
				'preview' => __( 'Thin borders around the notes and tabs.', 'mostrador' ),
			),
		);
		return (array) apply_filters( 'mostrador_styles', $styles );
	}

	/**
	 * Pro styles shown as preview-only cards when Pro isn't active.
	 *
	 * @return array id => [ label, preview ].
	 */
	public static function pro_teasers() {
		return array(
			'pildoras' => array(
				'label'   => __( 'Pills', 'mostrador' ),
				'preview' => __( 'Rounded badges for unit price, delivery and VAT.', 'mostrador' ),
			),
			'ficha'    => array(
				'label'   => __( 'Sheet', 'mostrador' ),
				'preview' => __( 'A tidy data sheet with icon allergens.', 'mostrador' ),
			),
			'tarjeta'  => array(
				'label'   => __( 'Card', 'mostrador' ),
				'preview' => __( 'Soft cards with shadow for the tabs.', 'mostrador' ),
			),
			'etiqueta' => array(
				'label'   => __( 'Label', 'mostrador' ),
				'preview' => __( 'Looks like a printed product label.', 'mostrador' ),
			),
		);
	}

	/**
	 * Chosen style, falling back to Plain when it isn't registered (for example after Pro is removed).
	 *
	 * @return string
	 */
	public static function current() {
		$id  = (string) Settings::get( 'style', 'sobrio' );
		$all = self::all();
		return isset( $all[ $id ] ) ? $id : 'sobrio';
	}

	/**
	 * Whether this page shows anything of ours.
	 *
	 * @return bool
	 */
	private static function needed() {
		if ( ! function_exists( 'is_woocommerce' ) ) {
			return false;
		}
		if ( is_woocommerce() || is_cart() || is_checkout() || is_account_page() ) {
			return true;
		}
		if ( is_active_widget( false, false, 'mostrador_allergens', true ) ) {
			return true;
		}
		$post = get_post();
		if ( ! $post ) {
			return false;
		}
		$content = (string) $post->post_content;
		return false !== strpos( $content, '<!-- wp:mostrador/' ) || false !== strpos( $content, '<!-- wp:woocommerce/' );
	}

	/**
	 * Front styles: one small base file and the chosen look.
	 */
	public static function enqueue() {
		if ( ! self::needed() ) {
			return;
		}
		wp_enqueue_style( 'mostrador', MOSTRADOR_URL . 'assets/styles/base.css', array(), MOSTRADOR_VERSION );
		$all   = self::all();
		$style = $all[ self::current() ];
		if ( ! empty( $style['css'] ) ) {
			wp_enqueue_style( 'mostrador-style', esc_url_raw( $style['css'] ), array( 'mostrador' ), MOSTRADOR_VERSION );
		}
		$vars = self::vars_css();
		if ( '' !== $vars ) {
			wp_add_inline_style( 'mostrador', $vars );
		}
	}

	/**
	 * CSS custom properties from `mostrador_style_vars` (Pro sets colours).
	 *
	 * @return string
	 */
	public static function vars_css() {
		$vars = (array) apply_filters( 'mostrador_style_vars', array() );
		$out  = '';
		foreach ( $vars as $name => $value ) {
			$name  = (string) $name;
			$value = (string) $value;
			if ( preg_match( '/^--mostrador-[a-z0-9-]+$/', $name ) && preg_match( '/^[#a-zA-Z0-9(),.%\s-]+$/', $value ) ) {
				$out .= $name . ':' . $value . ';';
			}
		}
		return '' === $out ? '' : ':root{' . $out . '}';
	}
}
