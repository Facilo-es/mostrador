<?php
/**
 * The six-step setup, full screen, with a live preview of the shop on a phone.
 *
 * @package Mostrador
 */

namespace Mostrador;

defined( 'ABSPATH' ) || exit;

/**
 * Wizard.
 */
class Wizard {

	/**
	 * Page slug.
	 */
	const PAGE = 'mostrador-asistente';

	/**
	 * Hooks.
	 */
	public static function boot() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_render' ), 20 );
		add_action( 'admin_post_mostrador_wizard', array( __CLASS__, 'save' ) );
	}

	/**
	 * Preview of the shop in the wizard's phone: runs before the modules boot, so the overrides apply everywhere.
	 */
	public static function preview() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the nonce is checked right below.
		if ( empty( $_GET['mostrador_preview'] ) || ! isset( $_GET['_mpn'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_mpn'] ) ), 'mostrador_preview' ) || ! current_user_can( Admin::CAP ) ) {
			return;
		}
		$raw = isset( $_GET['mp'] ) && is_array( $_GET['mp'] ) ? wp_unslash( $_GET['mp'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned by Admin::sanitize().
		// phpcs:enable
		Settings::override( Admin::sanitize( (array) $raw ) );
		add_filter( 'show_admin_bar', '__return_false' );
		add_action( 'send_headers', array( __CLASS__, 'preview_headers' ) );
	}

	/**
	 * The preview is never cached.
	 */
	public static function preview_headers() {
		nocache_headers();
		header( 'X-Robots-Tag: noindex' );
	}

	/**
	 * URL of the shop page shown in the phone.
	 *
	 * @return string
	 */
	public static function preview_target() {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => 'total_sales', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one product, once per wizard view.
				'orderby'        => array(
					'meta_value_num' => 'DESC',
					'date'           => 'DESC',
				),
			)
		);
		if ( $ids ) {
			return get_permalink( $ids[0] );
		}
		$shop = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'shop' ) : 0;
		return $shop > 0 ? get_permalink( $shop ) : home_url( '/' );
	}

	/**
	 * Preview base URL, with the nonce.
	 *
	 * @return string
	 */
	public static function preview_url() {
		return add_query_arg(
			array(
				'mostrador_preview' => 1,
				'_mpn'              => wp_create_nonce( 'mostrador_preview' ),
			),
			self::preview_target()
		);
	}

	/**
	 * Draw the wizard as its own full screen page.
	 */
	public static function maybe_render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page routing only.
		if ( ! isset( $_GET['page'] ) || self::PAGE !== $_GET['page'] || wp_doing_ajax() ) {
			return;
		}
		if ( ! current_user_can( Admin::CAP ) ) {
			return;
		}
		self::render();
		exit;
	}

	/**
	 * Menu callback, in case another plugin stops the full screen page.
	 */
	public static function page() {
		echo '<div class="wrap"><p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Start', 'mostrador' ) . '</a></p></div>';
	}

	/**
	 * Pages that may go into the email: [ id => title ], with a guess for the withdrawal and shipping pages.
	 *
	 * @return array
	 */
	public static function candidate_pages() {
		$out   = array();
		$terms = (int) wc_terms_and_conditions_page_id();
		if ( $terms ) {
			$out[ $terms ] = get_the_title( $terms );
		}
		foreach ( array( 'withdrawal_page', 'shipping_page' ) as $key ) {
			$id = (int) Settings::get( $key );
			if ( ! $id ) {
				$id = self::guess_page( $key );
			}
			if ( $id ) {
				$out[ $id ] = get_the_title( $id );
			}
		}
		foreach ( Emails::pages() as $id ) {
			$out[ $id ] = get_the_title( $id );
		}
		return array_slice( $out, 0, 4, true );
	}

	/**
	 * Find a published page by slug words.
	 *
	 * @param string $key withdrawal_page or shipping_page.
	 * @return int
	 */
	public static function guess_page( $key ) {
		$words = 'withdrawal_page' === $key
			? array( 'desistimiento', 'devoluciones', 'devolucion', 'withdrawal', 'returns', 'refund' )
			: array( 'envios', 'envio', 'gastos-de-envio', 'shipping', 'delivery' );
		foreach ( $words as $word ) {
			$page = get_page_by_path( $word );
			if ( $page && 'publish' === $page->post_status ) {
				return (int) $page->ID;
			}
		}
		$query = new \WP_Query(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				's'              => $words[0],
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		return $query->posts ? (int) $query->posts[0] : 0;
	}

	/**
	 * A switch.
	 *
	 * @param string $name    Field name.
	 * @param string $label   Label.
	 * @param bool   $checked Checked.
	 * @param string $preview Preview key (data-mp).
	 */
	private static function sw( $name, $label, $checked, $preview = '' ) {
		echo '<label class="sw"><span>' . esc_html( $label ) . '</span><input type="hidden" name="' . esc_attr( $name ) . '" value="0"><input type="checkbox" role="switch" name="' . esc_attr( $name ) . '" value="1"' . checked( $checked, true, false ) . ( '' !== $preview ? ' data-mp="' . esc_attr( $preview ) . '"' : '' ) . '></label>';
	}

	/**
	 * Radio cards.
	 *
	 * @param string $name    Field name.
	 * @param array  $choices Value => label.
	 * @param string $current Current value.
	 * @param string $css     Extra class.
	 * @param string $preview Preview key.
	 */
	private static function radios( $name, array $choices, $current, $css = '', $preview = '' ) {
		echo '<div class="elige ' . esc_attr( $css ) . '">';
		foreach ( $choices as $value => $label ) {
			echo '<label><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . checked( (string) $current, (string) $value, false ) . ( '' !== $preview ? ' data-mp="' . esc_attr( $preview ) . '"' : '' ) . '>' . esc_html( $label ) . '</label>';
		}
		echo '</div>';
	}

	/**
	 * Footer buttons of a step.
	 *
	 * @param int $i     Step index.
	 * @param int $count Steps.
	 */
	private static function foot( $i, $count ) {
		echo '<div class="pie">';
		if ( $i ) {
			echo '<button type="button" class="btn sec" data-go="back">' . esc_html__( 'Back', 'mostrador' ) . '</button>';
		}
		if ( $i === $count - 1 ) {
			echo '<button type="submit" class="btn">' . esc_html__( 'Finish', 'mostrador' ) . '</button>';
		} else {
			echo '<button type="button" class="btn" data-go="next">' . esc_html__( 'Next', 'mostrador' ) . '</button>';
		}
		echo '<button type="button" class="saltar" data-go="skip">' . esc_html__( 'Skip this step', 'mostrador' ) . '</button></div>';
	}

	/**
	 * Step titles.
	 *
	 * @return array
	 */
	public static function steps() {
		return array(
			__( 'Where is your shop?', 'mostrador' ),
			__( 'Taxes', 'mostrador' ),
			__( 'Checkout and order', 'mostrador' ),
			__( 'Your products', 'mostrador' ),
			__( 'Order emails', 'mostrador' ),
			__( 'Look', 'mostrador' ),
		);
	}

	/**
	 * Full page.
	 */
	public static function render() {
		$steps = self::steps();
		$count = count( $steps );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$done = isset( $_GET['done'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$start = isset( $_GET['step'] ) ? max( 0, min( $count - 1, absint( $_GET['step'] ) - 1 ) ) : 0;

		wp_register_style( 'mostrador-wizard', MOSTRADOR_URL . 'assets/css/wizard.css', array(), MOSTRADOR_VERSION );
		wp_register_script( 'mostrador-wizard', MOSTRADOR_URL . 'assets/js/wizard.js', array(), MOSTRADOR_VERSION, true );
		$exit = admin_url( 'admin.php?page=mostrador' );

		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		echo '<!doctype html><html ' . get_language_attributes() . '><head><meta charset="' . esc_attr( get_option( 'blog_charset' ) ) . '"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core escapes language attributes.
		echo '<title>' . esc_html__( 'Get your shop ready to sell in Spain', 'mostrador' ) . ' &lsaquo; Mostrador</title>';
		wp_print_styles( array( 'mostrador-wizard' ) );
		echo '</head><body class="mostrador-wizard">';

		echo '<header class="bar"><span class="marca"><i>' . Admin::logo_svg() . '</i>Mostrador</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG.
		echo '<nav class="pasos" aria-label="' . esc_attr__( 'Steps', 'mostrador' ) . '">';
		foreach ( $steps as $i => $title ) {
			$class = $done ? 'hecho' : ( $i === $start ? 'on' : ( $i < $start ? 'hecho' : '' ) );
			echo '<button type="button" class="' . esc_attr( $class ) . '" data-step="' . esc_attr( (string) $i ) . '" title="' . esc_attr( $title ) . '"' . ( $done ? ' disabled' : '' ) . '><span class="screen-reader-text">' . esc_html( $title ) . '</span></button>';
		}
		echo '</nav><a class="salir" href="' . esc_url( $exit ) . '">' . esc_html__( 'Exit', 'mostrador' ) . '</a></header>';

		echo '<div class="lay"><section class="izq">';
		if ( $done ) {
			self::end_screen();
		} else {
			self::form( $steps, $start );
		}
		echo '</section><aside class="der"><div class="vivo">' . esc_html__( 'Your shop, right now', 'mostrador' ) . '</div><div class="tel"><iframe id="mostrador-pv" src="' . esc_url( self::preview_url() ) . '" data-base="' . esc_url( self::preview_url() ) . '" title="' . esc_attr__( 'Your shop on a phone', 'mostrador' ) . '"></iframe></div></aside></div>';
		wp_print_scripts( array( 'mostrador-wizard' ) );
		echo '</body></html>';
	}

	/**
	 * The six steps, one form.
	 *
	 * @param array $steps Titles.
	 * @param int   $start First step shown.
	 */
	private static function form( array $steps, $start ) {
		$count   = count( $steps );
		$region  = (string) Settings::get( 'region' );
		$regions = Admin::regions();
		$notes   = array(
			/* translators: 1: territory, 2: IGIC or IPSI. */
			'canarias' => sprintf( __( 'In %1$s, %2$s applies, not VAT. Mostrador doesn\'t create those rates, but everything else works the same.', 'mostrador' ), __( 'the Canary Islands', 'mostrador' ), 'IGIC' ),
			/* translators: 1: territory, 2: IGIC or IPSI. */
			'ceuta'    => sprintf( __( 'In %1$s, %2$s applies, not VAT. Mostrador doesn\'t create those rates, but everything else works the same.', 'mostrador' ), 'Ceuta', 'IPSI' ),
			/* translators: 1: territory, 2: IGIC or IPSI. */
			'melilla'  => sprintf( __( 'In %1$s, %2$s applies, not VAT. Mostrador doesn\'t create those rates, but everything else works the same.', 'mostrador' ), 'Melilla', 'IPSI' ),
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="mostrador-wizard" data-start="' . esc_attr( (string) $start ) . '">';
		echo '<input type="hidden" name="action" value="mostrador_wizard">';
		wp_nonce_field( 'mostrador_wizard' );

		foreach ( $steps as $i => $title ) {
			echo '<div class="paso" data-paso="' . esc_attr( (string) $i ) . '"' . ( $i === $start ? '' : ' hidden' ) . '>';
			echo '<input type="hidden" name="skip[' . esc_attr( (string) $i ) . ']" value="0" class="skip">';
			if ( 0 === $i ) {
				echo '<div class="num">' . esc_html__( 'Get your shop ready to sell in Spain', 'mostrador' ) . '</div><h1>' . esc_html( $title ) . '</h1>';
				echo '<p class="intro">' . esc_html__( 'In five minutes you set up taxes, checkout and your product information. You can skip any step and change it later.', 'mostrador' ) . '</p>';
			} else {
				/* translators: 1: step number, 2: number of steps. */
				echo '<div class="num">' . esc_html( sprintf( __( 'Step %1$d of %2$d', 'mostrador' ), $i + 1, $count ) ) . '</div><h1>' . esc_html( $title ) . '</h1>';
			}
			call_user_func( array( __CLASS__, 'step_' . ( $i + 1 ) ), $region, $regions, $notes );
			self::foot( $i, $count );
			echo '</div>';
		}
		echo '</form>';
	}

	/**
	 * Step 1: region.
	 *
	 * @param string $region  Current region.
	 * @param array  $regions Choices.
	 * @param array  $notes   Notes per region.
	 */
	private static function step_1( $region, $regions, $notes ) {
		echo '<div class="campo">';
		self::radios( 'm[region]', $regions, $region );
		foreach ( $notes as $key => $note ) {
			echo '<div class="nota" data-region="' . esc_attr( $key ) . '"' . ( $key === $region ? '' : ' hidden' ) . '>' . esc_html( $note ) . '</div>';
		}
		echo '</div>';
	}

	/**
	 * Step 2: taxes.
	 *
	 * @param string $region Current region.
	 */
	private static function step_2( $region ) {
		$existing = TaxRates::existing();
		$missing  = TaxRates::missing();
		echo '<div data-vat' . ( 'peninsula' === $region ? '' : ' hidden' ) . '>';
		echo '<p class="intro">' . esc_html__( 'We turn on WooCommerce taxes and create the VAT rates: standard 21 %, reduced 10 %, super-reduced 4 % and 0 %. Shipments to the Canary Islands, Ceuta and Melilla go without VAT.', 'mostrador' ) . '</p>';
		echo '<div class="campo">';
		self::sw( 'prices_incl', __( 'I enter prices with VAT included', 'mostrador' ), 'no' !== get_option( 'woocommerce_prices_include_tax', 'yes' ) || 'yes' !== get_option( 'woocommerce_calc_taxes' ) );
		self::sw( 'display_incl', __( 'Show prices with VAT in the shop and cart', 'mostrador' ), 'excl' !== get_option( 'woocommerce_tax_display_shop', 'incl' ) || 'yes' !== get_option( 'woocommerce_calc_taxes' ) );
		echo '</div>';
		if ( $existing ) {
			echo '<div class="campo"><b>';
			/* translators: %d: number of tax rates. */
			echo esc_html( sprintf( _n( 'You already have %d rate for Spain. We won\'t touch it. Shall we add the missing ones?', 'You already have %d rates for Spain. We won\'t touch them. Shall we add the missing ones?', count( $existing ), 'mostrador' ), count( $existing ) ) );
			echo '</b>';
			if ( $missing ) {
				self::radios(
					'rates',
					array(
						'add'  => __( 'Add the missing ones', 'mostrador' ),
						'keep' => __( 'Leave them as they are', 'mostrador' ),
					),
					''
				);
			} else {
				echo '<p class="ayuda">' . esc_html__( 'Nothing is missing.', 'mostrador' ) . '</p><input type="hidden" name="rates" value="keep">';
			}
			echo '</div>';
		} else {
			echo '<input type="hidden" name="rates" value="add">';
		}
		echo '</div><div data-outside' . ( 'peninsula' === $region ? ' hidden' : '' ) . '>';
		echo '<p class="intro">' . esc_html__( 'Your shop isn\'t in the VAT area, so this step doesn\'t change your taxes. Set your IGIC or IPSI rates in WooCommerce → Settings → Tax.', 'mostrador' ) . '</p></div>';
	}

	/**
	 * Step 3: checkout.
	 */
	private static function step_3() {
		echo '<div class="campo"><b>' . esc_html__( 'NIF at checkout', 'mostrador' ) . '</b>';
		if ( Nif::apg_active() ) {
			echo '<div class="nota">' . esc_html__( 'Another plugin already adds a NIF field, so Mostrador doesn\'t add a second one.', 'mostrador' ) . '</div>';
		} else {
			self::radios( 'm[nif_mode]', Admin::nif_modes(), (string) Settings::get( 'nif_mode' ), '', 'nif_mode' );
		}
		echo '</div><div class="campo"><b>' . esc_html__( 'Order button text', 'mostrador' ) . '</b>';
		$choices           = Settings::button_choices();
		$choices['custom'] = __( 'Other', 'mostrador' );
		self::radios( 'm[button]', $choices, (string) Settings::get( 'button' ), 'una', 'button' );
		echo '<p class="otro"' . ( 'custom' === Settings::get( 'button' ) ? '' : ' hidden' ) . '><label class="screen-reader-text" for="mostrador-w-custom">' . esc_html__( 'Your text', 'mostrador' ) . '</label><input type="text" class="sel" id="mostrador-w-custom" name="m[button_custom]" maxlength="60" value="' . esc_attr( (string) Settings::get( 'button_custom' ) ) . '" placeholder="' . esc_attr__( 'Your text', 'mostrador' ) . '"></p>';
		echo '</div><div class="campo">';
		self::sw( 'm[terms_text]', __( 'Terms tick names your purchase conditions and withdrawal policy', 'mostrador' ), (bool) Settings::get( 'terms_text' ) );
		echo '</div><div class="ayuda">' . esc_html__( 'The law asks for a button that makes clear the order means paying (art. 98.2 of the Spanish consumer protection law).', 'mostrador' ) . '</div>';
	}

	/**
	 * Step 4: products.
	 */
	private static function step_4() {
		echo '<div class="campo">';
		self::sw( 'm[unit_price]', __( 'Price per unit (per kilo, litre, metre…)', 'mostrador' ), (bool) Settings::get( 'unit_price' ), 'unit_price' );
		self::sw( 'm[delivery]', __( 'Delivery time', 'mostrador' ), (bool) Settings::get( 'delivery' ), 'delivery' );
		self::sw( 'm[safety]', __( 'Product safety (manufacturer and warnings)', 'mostrador' ), (bool) Settings::get( 'safety' ), 'safety' );
		self::sw( 'm[food]', __( 'Food information and allergens', 'mostrador' ), (bool) Settings::get( 'food' ), 'food' );
		echo '</div><div class="campo"><label for="mostrador-w-plazo"><b>' . esc_html__( 'Usual delivery time', 'mostrador' ) . '</b></label><select class="sel" id="mostrador-w-plazo" name="m[delivery_default]" data-mp="delivery_default">';
		foreach ( Settings::delivery_times() as $i => $time ) {
			echo '<option value="' . esc_attr( (string) $i ) . '"' . selected( (int) Settings::get( 'delivery_default' ), $i, false ) . '>' . esc_html( $time ) . '</option>';
		}
		echo '</select></div>';
	}

	/**
	 * Step 5: emails.
	 */
	private static function step_5() {
		$chosen = Emails::pages();
		$pages  = self::candidate_pages();
		echo '<div class="campo"><b>' . esc_html__( 'Add to the first email of each order:', 'mostrador' ) . '</b>';
		foreach ( $pages as $id => $title ) {
			echo '<label class="sw"><span>' . esc_html( $title ) . '</span><input type="checkbox" role="switch" name="pages[]" value="' . esc_attr( (string) $id ) . '"' . checked( ! $chosen || in_array( (int) $id, $chosen, true ), true, false ) . '></label>';
		}
		self::sw( 'm[email_annex]', __( 'Withdrawal form', 'mostrador' ), (bool) Settings::get( 'email_annex' ) );
		if ( ! $pages ) {
			echo '<div class="nota">' . esc_html__( 'You don\'t have purchase conditions or withdrawal pages yet. The shop check will remind you.', 'mostrador' ) . '</div>';
		}
		echo '</div><div class="ayuda">' . esc_html__( 'So your customer gets your conditions in writing, which is what the law asks for.', 'mostrador' ) . '</div>';
	}

	/**
	 * Step 6: look.
	 */
	private static function step_6() {
		$current = Styles::current();
		echo '<div class="est">';
		foreach ( Styles::all() as $id => $style ) {
			echo '<label><input type="radio" name="m[style]" value="' . esc_attr( $id ) . '" data-mp="style"' . checked( $current, $id, false ) . '><span class="mini mini-' . esc_attr( $id ) . '" aria-hidden="true"><i></i><i></i><i></i></span><b>' . esc_html( $style['label'] ) . '</b><small>' . esc_html( $style['preview'] ) . '</small></label>';
		}
		foreach ( Styles::pro_teasers() as $id => $style ) {
			if ( isset( Styles::all()[ $id ] ) ) {
				continue;
			}
			echo '<div class="is-pro" aria-disabled="true"><span class="mini mini-' . esc_attr( $id ) . '" aria-hidden="true"><i></i><i></i><i></i></span><b>' . esc_html( $style['label'] ) . '</b><small>' . esc_html( $style['preview'] ) . '</small><span class="pro">Pro</span></div>';
		}
		echo '</div>';
	}

	/**
	 * What is on now, for the end screen.
	 *
	 * @return string[]
	 */
	public static function done_list() {
		$list    = array();
		$outside = SiteCheck::outside_vat();
		if ( ! $outside && 'yes' === get_option( 'woocommerce_calc_taxes' ) && TaxRates::has_standard() ) {
			$list[] = __( 'VAT rates for Spain', 'mostrador' );
		}
		if ( Nif::active() ) {
			$list[] = __( 'NIF at checkout', 'mostrador' );
		}
		/* translators: %s: button text. */
		$list[] = sprintf( __( 'Button "%s"', 'mostrador' ), Settings::button_text() );
		if ( Settings::get( 'price_notes' ) ) {
			$list[] = __( 'VAT and shipping under the price', 'mostrador' );
		}
		if ( Settings::get( 'unit_price' ) ) {
			$list[] = __( 'Price per unit', 'mostrador' );
		}
		if ( Settings::get( 'delivery' ) ) {
			$time = Settings::default_delivery();
			/* translators: %s: delivery time. */
			$list[] = '' !== $time ? sprintf( __( 'Delivery time: %s', 'mostrador' ), $time ) : __( 'Delivery time', 'mostrador' );
		}
		if ( Settings::get( 'safety' ) ) {
			$list[] = __( 'Product safety', 'mostrador' );
		}
		if ( Settings::get( 'food' ) ) {
			$list[] = __( 'Food information and allergens', 'mostrador' );
		}
		if ( Settings::get( 'emails' ) && ( Emails::pages() || Settings::get( 'email_annex' ) ) ) {
			$list[] = __( 'Your conditions in the order email', 'mostrador' );
		}
		return $list;
	}

	/**
	 * End screen.
	 */
	private static function end_screen() {
		echo '<div class="num">' . esc_html__( 'Done', 'mostrador' ) . '</div><h1>' . esc_html__( 'Done. Your shop now shows all this.', 'mostrador' ) . '</h1><ul class="lista">';
		foreach ( self::done_list() as $item ) {
			echo '<li>' . esc_html( $item ) . '</li>';
		}
		echo '</ul><div class="pie"><a class="btn" href="' . esc_url( admin_url( 'admin.php?page=mostrador-revision' ) ) . '">' . esc_html__( 'See the check', 'mostrador' ) . '</a><a class="btn sec" href="' . esc_url( admin_url( 'edit.php?post_type=product' ) ) . '">' . esc_html__( 'Go to my products', 'mostrador' ) . '</a></div>';
	}

	/**
	 * Save the wizard. Skipped steps change nothing.
	 */
	public static function save() {
		if ( ! current_user_can( Admin::CAP ) ) {
			wp_die( esc_html__( 'You can\'t change these settings.', 'mostrador' ), 403 );
		}
		check_admin_referer( 'mostrador_wizard' );
		$skip    = isset( $_POST['skip'] ) && is_array( $_POST['skip'] ) ? array_map( 'absint', wp_unslash( $_POST['skip'] ) ) : array();
		$raw     = isset( $_POST['m'] ) && is_array( $_POST['m'] ) ? wp_unslash( $_POST['m'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned by Admin::sanitize().
		$m       = Admin::sanitize( (array) $raw );
		$keys    = array(
			0 => array( 'region' ),
			2 => array( 'nif_mode', 'button', 'button_custom', 'terms_text' ),
			3 => array( 'unit_price', 'delivery', 'safety', 'food', 'delivery_default' ),
			4 => array( 'email_annex' ),
			5 => array( 'style' ),
		);
		$changes = array( 'wizard_done' => 1 );
		foreach ( $keys as $step => $list ) {
			if ( ! empty( $skip[ $step ] ) ) {
				continue;
			}
			foreach ( $list as $key ) {
				if ( array_key_exists( $key, $m ) ) {
					$changes[ $key ] = $m[ $key ];
				}
			}
		}
		Settings::update( $changes );

		$region = (string) Settings::get( 'region' );
		if ( empty( $skip[1] ) && 'peninsula' === $region ) {
			TaxRates::configure( ! empty( $_POST['prices_incl'] ), ! empty( $_POST['display_incl'] ) );
			// With rates already there, adding needs an explicit "add"; nothing chosen keeps them as they are.
			$rates = isset( $_POST['rates'] ) ? sanitize_key( wp_unslash( $_POST['rates'] ) ) : ( TaxRates::existing() ? 'keep' : 'add' );
			if ( 'keep' !== $rates ) {
				TaxRates::add_missing();
			}
		}

		if ( empty( $skip[4] ) ) {
			$pages   = isset( $_POST['pages'] ) && is_array( $_POST['pages'] ) ? array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST['pages'] ) ) ) ) ) : array();
			$email   = array( 'email_pages' => array_slice( $pages, 0, 4 ) );
			$guesses = array(
				'withdrawal_page' => self::guess_page( 'withdrawal_page' ),
				'shipping_page'   => self::guess_page( 'shipping_page' ),
			);
			foreach ( $guesses as $key => $id ) {
				if ( ! Settings::get( $key ) && $id ) {
					$email[ $key ] = $id;
				}
			}
			if ( ! $pages && ! Settings::get( 'email_annex' ) ) {
				$email['emails'] = 0;
			}
			Settings::update( $email );
		}

		SiteCheck::flush();
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&done=1' ) );
		exit;
	}
}
