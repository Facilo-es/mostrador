<?php
/**
 * Admin screens: Inicio, Revisión, Ajustes and Pro, plus the one notice after 30 days.
 *
 * @package Mostrador
 */

namespace Mostrador;

defined( 'ABSPATH' ) || exit;

/**
 * Admin.
 */
class Admin {

	/**
	 * Capability for every Mostrador screen.
	 */
	const CAP = 'manage_woocommerce';

	/**
	 * Transient holding the menu badge count.
	 */
	const BADGE = 'mostrador_badge';

	/**
	 * Days before the single Pro notice.
	 */
	const NOTICE_DAYS = 30;

	/**
	 * Hooks.
	 */
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_head', array( __CLASS__, 'hide_wizard_item' ) );
		add_action( 'admin_init', array( __CLASS__, 'activation_redirect' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_mostrador_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_mostrador_dismiss', array( __CLASS__, 'dismiss' ) );
		add_action( 'admin_notices', array( __CLASS__, 'pro_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MOSTRADOR_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Activation: remember the date and send the owner to the wizard once.
	 */
	public static function activate() {
		$changes = array();
		if ( ! Settings::get( 'installed_at' ) ) {
			$changes['installed_at'] = time();
		}
		if ( $changes ) {
			Settings::update( $changes );
		}
		if ( ! Settings::get( 'wizard_done' ) ) {
			set_transient( 'mostrador_activation_redirect', 1, 60 );
		}
	}

	/**
	 * Redirect to the wizard right after activation.
	 */
	public static function activation_redirect() {
		if ( ! get_transient( 'mostrador_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'mostrador_activation_redirect' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of WordPress's own bulk-activation flag.
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) || ! current_user_can( self::CAP ) || Settings::get( 'wizard_done' ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=mostrador-asistente' ) );
		exit;
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=mostrador-ajustes' ) ) . '">' . esc_html__( 'Settings', 'mostrador' ) . '</a>' );
		return $links;
	}

	/**
	 * Number of checks to review, cached for the menu badge.
	 *
	 * @return int
	 */
	public static function badge() {
		$count = get_transient( self::BADGE );
		if ( false === $count ) {
			$count = SiteCheck::summary()['review'];
			set_transient( self::BADGE, (int) $count, HOUR_IN_SECONDS );
		}
		return (int) $count;
	}

	/**
	 * Menu.
	 */
	public static function menu() {
		$badge = self::badge();
		$count = $badge ? ' <span class="awaiting-mod count-' . $badge . '"><span class="pending-count">' . number_format_i18n( $badge ) . '</span></span>' : '';
		add_menu_page( 'Mostrador', 'Mostrador' . $count, self::CAP, 'mostrador', array( __CLASS__, 'page_home' ), self::icon(), 56 );
		add_submenu_page( 'mostrador', __( 'Home', 'mostrador' ), __( 'Home', 'mostrador' ), self::CAP, 'mostrador', array( __CLASS__, 'page_home' ) );
		add_submenu_page( 'mostrador', __( 'Shop check', 'mostrador' ), __( 'Shop check', 'mostrador' ) . $count, self::CAP, 'mostrador-revision', array( __CLASS__, 'page_review' ) );
		add_submenu_page( 'mostrador', __( 'Settings', 'mostrador' ), __( 'Settings', 'mostrador' ), self::CAP, 'mostrador-ajustes', array( __CLASS__, 'page_settings' ) );
		if ( self::show_pro() ) {
			add_submenu_page( 'mostrador', 'Pro', 'Pro', self::CAP, 'mostrador-pro', array( __CLASS__, 'page_pro' ) );
		}
		add_submenu_page( 'mostrador', __( 'Setup', 'mostrador' ), __( 'Setup', 'mostrador' ), self::CAP, 'mostrador-asistente', array( 'Mostrador\\Wizard', 'page' ) );
	}

	/**
	 * The wizard has a page but no menu item.
	 */
	public static function hide_wizard_item() {
		remove_submenu_page( 'mostrador', 'mostrador-asistente' );
	}

	/**
	 * Whether Free's Pro tab shows.
	 *
	 * @return bool
	 */
	public static function show_pro() {
		return (bool) apply_filters( 'mostrador_show_pro_tab', true );
	}

	/**
	 * Where "Buy Pro" goes.
	 *
	 * @return string
	 */
	public static function pro_url() {
		return (string) apply_filters( 'mostrador_pro_url', 'https://checkout.freemius.com/plugin/44922/plan/79190/' );
	}

	/**
	 * Menu icon (the shop counter) as a data URI.
	 *
	 * @return string
	 */
	private static function icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="black" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10h18M5 10v10h14V10M3 10l2-6h14l2 6"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- menu icon data URI.
	}

	/**
	 * Whether the current screen is one of ours.
	 *
	 * @param string $hook Hook suffix.
	 * @return bool
	 */
	private static function ours( $hook ) {
		return false !== strpos( (string) $hook, 'mostrador' );
	}

	/**
	 * Styles and scripts for our screens.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ) {
		if ( ! self::ours( $hook ) ) {
			return;
		}
		wp_enqueue_style( 'mostrador-admin', MOSTRADOR_URL . 'assets/css/admin.css', array(), MOSTRADOR_VERSION );
		wp_enqueue_script( 'mostrador-admin', MOSTRADOR_URL . 'assets/js/admin.js', array(), MOSTRADOR_VERSION, true );
	}

	/**
	 * Shared header for Mostrador screens (Pro uses it too).
	 *
	 * @param string $title    Title.
	 * @param string $subtitle Optional line under the title.
	 */
	public static function page_header( $title, $subtitle = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the highlighted link.
		$current = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'mostrador';
		$links   = array(
			'mostrador'          => __( 'Home', 'mostrador' ),
			'mostrador-revision' => __( 'Shop check', 'mostrador' ),
			'mostrador-ajustes'  => __( 'Settings', 'mostrador' ),
		);
		$links   = (array) apply_filters( 'mostrador_admin_links', $links );
		if ( self::show_pro() ) {
			$links['mostrador-pro'] = 'Pro';
		}
		echo '<div class="mostrador-top"><span class="mostrador-brand"><i aria-hidden="true">' . self::logo_svg() . '</i>Mostrador</span><nav class="mostrador-nav" aria-label="Mostrador">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG.
		foreach ( $links as $slug => $label ) {
			$on = $slug === $current;
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '"' . ( $on ? ' class="is-current" aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav></div>';
		echo '<div class="mostrador-head"><h1>' . esc_html( $title ) . '</h1>';
		if ( '' !== $subtitle ) {
			echo '<p>' . esc_html( $subtitle ) . '</p>';
		}
		echo '</div>';
		// The heading WordPress moves notices under.
		echo '<hr class="wp-header-end">';
	}

	/**
	 * The logo mark.
	 *
	 * @return string
	 */
	public static function logo_svg() {
		return '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 10h18M5 10v10h14V10M3 10l2-6h14l2 6"/></svg>';
	}

	/**
	 * Saved / error message from the query string.
	 */
	private static function flash() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'mostrador' ) . '</p></div>';
		}
		// phpcs:enable
	}

	/**
	 * Main modules, for Inicio: key => [ label, outcome ].
	 *
	 * @return array
	 */
	public static function modules() {
		return array(
			'nif'         => array( __( 'NIF at checkout', 'mostrador' ), __( 'Business customers get the invoice they need, so they buy from you.', 'mostrador' ) ),
			'price_notes' => array( __( 'VAT and shipping under the price', 'mostrador' ), __( 'Shoppers see the final price, with no surprises at checkout.', 'mostrador' ) ),
			'unit_price'  => array( __( 'Price per unit', 'mostrador' ), __( 'Shoppers compare per kilo or litre and see your good value.', 'mostrador' ) ),
			'delivery'    => array( __( 'Delivery time', 'mostrador' ), __( 'Shoppers know when it arrives, and fewer ask you.', 'mostrador' ) ),
			'safety'      => array( __( 'Product safety', 'mostrador' ), __( 'Manufacturer and warnings on every product, as marketplaces ask.', 'mostrador' ) ),
			'food'        => array( __( 'Food information and allergens', 'mostrador' ), __( 'Shoppers with allergies buy with confidence.', 'mostrador' ) ),
			'emails'      => array( __( 'Conditions in the order email', 'mostrador' ), __( 'Your customer gets your conditions in writing, with no extra work.', 'mostrador' ) ),
		);
	}

	/**
	 * Whether a module is on.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	private static function module_on( $key ) {
		if ( 'nif' === $key ) {
			return 'off' !== Settings::get( 'nif_mode' );
		}
		return (bool) Settings::get( $key );
	}

	/**
	 * Inicio.
	 */
	public static function page_home() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$summary = SiteCheck::summary();
		echo '<div class="wrap mostrador-wrap">';
		self::page_header( __( 'Your shop, ready to sell in Spain', 'mostrador' ) );
		self::flash();

		echo '<div class="mostrador-hero">';
		if ( ! Settings::get( 'wizard_done' ) ) {
			echo '<div><h2>' . esc_html__( 'Get your shop ready to sell in Spain', 'mostrador' ) . '</h2>';
			echo '<p>' . esc_html__( 'In five minutes you set up taxes, checkout and your product information. You can skip any step and change it later.', 'mostrador' ) . '</p></div>';
			echo '<a class="mostrador-btn" href="' . esc_url( admin_url( 'admin.php?page=mostrador-asistente' ) ) . '">' . esc_html__( 'Start', 'mostrador' ) . '</a>';
		} else {
			echo '<div><h2>';
			if ( $summary['review'] ) {
				/* translators: %d: number of checks. */
				echo esc_html( sprintf( _n( '%d thing to check', '%d things to check', $summary['review'], 'mostrador' ), $summary['review'] ) );
			} else {
				echo esc_html__( 'Everything checked', 'mostrador' );
			}
			echo '</h2><p>';
			/* translators: 1: checks ready, 2: total checks that apply. */
			echo esc_html( sprintf( __( '%1$d of %2$d ready.', 'mostrador' ), $summary['ready'], $summary['ready'] + $summary['review'] ) );
			echo '</p>';
			$total = max( 1, $summary['ready'] + $summary['review'] );
			echo '<div class="mostrador-meter" role="img" aria-label="' . esc_attr( sprintf( '%d / %d', $summary['ready'], $total ) ) . '"><span style="width:' . esc_attr( (string) round( 100 * $summary['ready'] / $total ) ) . '%"></span></div></div>';
			echo '<a class="mostrador-btn" href="' . esc_url( admin_url( 'admin.php?page=mostrador-revision' ) ) . '">' . esc_html__( 'See the check', 'mostrador' ) . '</a>';
		}
		echo '</div>';

		echo '<h2 class="mostrador-h2">' . esc_html__( 'What your shop shows', 'mostrador' ) . '</h2><div class="mostrador-cards">';
		foreach ( self::modules() as $key => $module ) {
			$on = self::module_on( $key );
			echo '<div class="mostrador-card"><div class="mostrador-card-top"><b>' . esc_html( $module[0] ) . '</b>';
			echo '<span class="mostrador-pill ' . ( $on ? 'is-on' : 'is-off' ) . '">' . ( $on ? esc_html__( 'On', 'mostrador' ) : esc_html__( 'Off', 'mostrador' ) ) . '</span></div>';
			echo '<p>' . esc_html( $module[1] ) . '</p></div>';
		}
		echo '</div>';

		do_action( 'mostrador_home_panels' );

		echo '<p class="mostrador-foot"><a href="' . esc_url( admin_url( 'admin.php?page=mostrador-ajustes' ) ) . '">' . esc_html__( 'Change settings', 'mostrador' ) . '</a> · <a href="' . esc_url( admin_url( 'admin.php?page=mostrador-asistente' ) ) . '">' . esc_html__( 'Run the setup again', 'mostrador' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Revisión.
	 */
	public static function page_review() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$checks  = SiteCheck::checks();
		$summary = SiteCheck::summary( $checks );
		$labels  = SiteCheck::status_labels();
		set_transient( self::BADGE, (int) $summary['review'], HOUR_IN_SECONDS );

		echo '<div class="wrap mostrador-wrap">';
		self::page_header( __( 'Your shop check', 'mostrador' ) );
		echo '<p class="mostrador-disclaimer">' . esc_html__( 'This is a technical check, not legal advice. It helps you see what\'s missing.', 'mostrador' ) . '</p>';

		echo '<div class="mostrador-summary">';
		foreach ( $summary as $status => $count ) {
			echo '<span class="mostrador-status is-' . esc_attr( $status ) . '"><i aria-hidden="true"></i>' . esc_html( $labels[ $status ] ) . ' <b>' . esc_html( number_format_i18n( $count ) ) . '</b></span>';
		}
		echo '</div>';

		$by_group = array();
		foreach ( $checks as $check ) {
			$group                = isset( $check['group'] ) ? $check['group'] : 'productos';
			$by_group[ $group ][] = $check;
		}
		foreach ( SiteCheck::groups() as $group => $label ) {
			if ( empty( $by_group[ $group ] ) ) {
				continue;
			}
			echo '<section class="mostrador-group"><h2>' . esc_html( $label ) . '</h2><ul class="mostrador-checks">';
			foreach ( $by_group[ $group ] as $check ) {
				$status = isset( $labels[ $check['status'] ] ) ? $check['status'] : 'na';
				echo '<li class="mostrador-check is-' . esc_attr( $status ) . '" data-check="' . esc_attr( $check['id'] ) . '">';
				echo '<span class="mostrador-dot" aria-hidden="true"></span>';
				echo '<div class="mostrador-check-text"><b>' . esc_html( $check['title'] );
				if ( 'review' === $status && $check['count'] ) {
					echo ' <span class="mostrador-count">' . esc_html( number_format_i18n( $check['count'] ) ) . '</span>';
				}
				echo '</b><span>' . esc_html( $check['line'] ) . '</span>';
				self::check_items( $check );
				echo '</div>';
				echo '<span class="mostrador-status is-' . esc_attr( $status ) . '">' . esc_html( $labels[ $status ] ) . '</span>';
				if ( '' !== (string) $check['url'] ) {
					$button = ( 'review' === $status && '' !== (string) $check['button'] ) ? $check['button'] : __( 'View', 'mostrador' );
					echo '<a class="button' . ( 'review' === $status ? ' button-primary' : '' ) . '" href="' . esc_url( $check['url'] ) . '">' . esc_html( $button ) . '</a>';
				}
				echo '</li>';
			}
			echo '</ul></section>';
		}
		echo '</div>';
	}

	/**
	 * Lists the products or pages a check names (first 10, each linking to its edit screen).
	 *
	 * @param array $check Normalised check.
	 */
	private static function check_items( array $check ) {
		if ( empty( $check['items'] ) ) {
			return;
		}
		$shown = array_slice( $check['items'], 0, 10 );
		echo '<ul class="mostrador-check-items">';
		foreach ( $shown as $id ) {
			$title = get_the_title( $id );
			$link  = get_edit_post_link( $id, 'raw' );
			$title = '' !== $title ? $title : '#' . $id;
			echo '<li>' . ( $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) ) . '</li>';
		}
		$more = max( count( $check['items'] ), (int) $check['count'] ) - count( $shown );
		if ( $more > 0 ) {
			/* translators: %s: number of other products or pages. */
			echo '<li class="mostrador-more">' . esc_html( sprintf( _n( 'and %s more', 'and %s more', $more, 'mostrador' ), number_format_i18n( $more ) ) ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Settings tabs.
	 *
	 * @return array
	 */
	public static function tabs() {
		$tabs = array(
			'tienda'    => __( 'Shop', 'mostrador' ),
			'pago'      => __( 'Checkout', 'mostrador' ),
			'productos' => __( 'Products', 'mostrador' ),
			'emails'    => __( 'Emails', 'mostrador' ),
			'aspecto'   => __( 'Look', 'mostrador' ),
		);
		return (array) apply_filters( 'mostrador_settings_tabs', $tabs );
	}

	/**
	 * Ajustes.
	 */
	public static function page_settings() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$tabs = self::tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab choice only.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'tienda';
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'tienda';
		}
		echo '<div class="wrap mostrador-wrap">';
		self::page_header( __( 'Settings', 'mostrador' ), __( 'Every part can be turned off. A part that\'s off adds nothing to your shop\'s pages.', 'mostrador' ) );
		self::flash();
		echo '<nav class="nav-tab-wrapper mostrador-tabs">';
		foreach ( $tabs as $slug => $label ) {
			echo '<a class="nav-tab' . ( $slug === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=mostrador-ajustes&tab=' . $slug ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';

		if ( ! in_array( $tab, array( 'tienda', 'pago', 'productos', 'emails', 'aspecto' ), true ) ) {
			do_action( "mostrador_settings_tab_{$tab}" );
			echo '</div>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mostrador-form">';
		echo '<input type="hidden" name="action" value="mostrador_save"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		wp_nonce_field( 'mostrador_save' );
		call_user_func( array( __CLASS__, 'tab_' . $tab ) );
		echo '<p class="submit"><button type="submit" class="mostrador-btn">' . esc_html__( 'Save changes', 'mostrador' ) . '</button></p></form></div>';
	}

	/**
	 * A toggle row.
	 *
	 * @param string $key   Setting.
	 * @param string $label Label.
	 * @param string $help  Help line.
	 */
	private static function toggle( $key, $label, $help = '' ) {
		echo '<label class="mostrador-sw"><span>' . esc_html( $label );
		if ( '' !== $help ) {
			echo '<small>' . esc_html( $help ) . '</small>';
		}
		echo '</span><input type="hidden" name="m[' . esc_attr( $key ) . ']" value="0"><input type="checkbox" role="switch" name="m[' . esc_attr( $key ) . ']" value="1"' . checked( (bool) Settings::get( $key ), true, false ) . '></label>';
	}

	/**
	 * Radio cards.
	 *
	 * @param string $key     Setting.
	 * @param array  $choices Value => label.
	 * @param string $css     Extra class.
	 */
	private static function radios( $key, array $choices, $css = '' ) {
		$current = (string) Settings::get( $key );
		echo '<div class="mostrador-choose ' . esc_attr( $css ) . '">';
		foreach ( $choices as $value => $label ) {
			echo '<label><input type="radio" name="m[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '"' . checked( $current, (string) $value, false ) . '>' . esc_html( $label ) . '</label>';
		}
		echo '</div>';
	}

	/**
	 * Field wrapper start.
	 *
	 * @param string $label Label.
	 * @param string $input_id Input ID.
	 */
	private static function field( $label, $input_id = '' ) {
		echo '<div class="mostrador-field">';
		if ( '' !== $input_id ) {
			echo '<label class="mostrador-label" for="' . esc_attr( $input_id ) . '">' . esc_html( $label ) . '</label>';
		} else {
			echo '<b class="mostrador-label">' . esc_html( $label ) . '</b>';
		}
	}

	/**
	 * Help box.
	 *
	 * @param string $text Text.
	 */
	private static function help( $text ) {
		echo '<p class="mostrador-help">' . esc_html( $text ) . '</p>';
	}

	/**
	 * Region choices.
	 *
	 * @return array
	 */
	public static function regions() {
		return array(
			'peninsula' => __( 'Mainland Spain and the Balearic Islands', 'mostrador' ),
			'canarias'  => __( 'Canary Islands', 'mostrador' ),
			'ceuta'     => 'Ceuta',
			'melilla'   => 'Melilla',
		);
	}

	/**
	 * NIF modes.
	 *
	 * @return array
	 */
	public static function nif_modes() {
		return array(
			'optional' => __( 'Optional', 'mostrador' ),
			'required' => __( 'Required', 'mostrador' ),
			'company'  => __( 'Required if a company is given', 'mostrador' ),
			'off'      => __( 'Don\'t ask', 'mostrador' ),
		);
	}

	/**
	 * Page select.
	 *
	 * @param string $name     Field name.
	 * @param int    $selected Page ID.
	 * @param string $id       Input ID.
	 */
	private static function page_select( $name, $selected, $id ) {
		wp_dropdown_pages(
			array(
				'name'              => esc_attr( $name ),
				'id'                => esc_attr( $id ),
				'selected'          => (int) $selected,
				'show_option_none'  => esc_html__( '— None —', 'mostrador' ),
				'option_none_value' => '0',
				'class'             => 'mostrador-select',
			)
		);
	}

	/**
	 * Tab: shop.
	 */
	private static function tab_tienda() {
		self::field( __( 'Where is your shop?', 'mostrador' ) );
		self::radios( 'region', self::regions() );
		$outside = SiteCheck::outside_vat();
		if ( $outside ) {
			/* translators: 1: territory, 2: IGIC or IPSI. */
			echo '<p class="mostrador-note">' . esc_html( sprintf( __( 'In %1$s, %2$s applies, not VAT. Mostrador doesn\'t create those rates, but everything else works the same.', 'mostrador' ), $outside[0], $outside[1] ) ) . '</p>';
		}
		echo '</div>';
		self::field( __( 'Setup', 'mostrador' ) );
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=mostrador-asistente' ) ) . '">' . esc_html__( 'Run the setup again', 'mostrador' ) . '</a></p></div>';
		self::field( __( 'When you delete the plugin', 'mostrador' ) );
		self::toggle( 'delete_on_uninstall', __( 'Delete all Mostrador data when uninstalling', 'mostrador' ), __( 'Removes the settings and the tax rates Mostrador created. Tax IDs on orders and product details are always kept.', 'mostrador' ) );
		echo '</div>';
	}

	/**
	 * Tab: checkout.
	 */
	private static function tab_pago() {
		self::field( __( 'NIF at checkout', 'mostrador' ) );
		self::radios( 'nif_mode', self::nif_modes() );
		self::toggle( 'nif_eu', __( 'Also ask customers in other EU countries', 'mostrador' ), __( 'The field becomes "NIF / VAT". Numbers from other countries get a format check.', 'mostrador' ) );
		if ( Nif::apg_active() ) {
			echo '<p class="mostrador-note">' . esc_html__( 'Another plugin already adds a NIF field, so Mostrador doesn\'t add a second one.', 'mostrador' ) . '</p>';
		}
		echo '</div>';

		self::field( __( 'Order button text', 'mostrador' ) );
		$choices           = Settings::button_choices();
		$choices['custom'] = __( 'Other', 'mostrador' );
		self::radios( 'button', $choices, 'is-one' );
		echo '<p class="mostrador-custom"><label class="screen-reader-text" for="mostrador-button-custom">' . esc_html__( 'Your text', 'mostrador' ) . '</label><input type="text" id="mostrador-button-custom" class="regular-text" name="m[button_custom]" value="' . esc_attr( (string) Settings::get( 'button_custom' ) ) . '" placeholder="' . esc_attr__( 'Your text', 'mostrador' ) . '" maxlength="60"></p>';
		if ( ! Settings::says_pay( Settings::button_text() ) ) {
			echo '<p class="mostrador-note" data-mostrador-button-warning>' . esc_html__( 'The order button doesn\'t say that the order means paying.', 'mostrador' ) . '</p>';
		}
		self::help( __( 'The law asks for a button that makes clear the order means paying (art. 98.2 of the Spanish consumer protection law).', 'mostrador' ) );
		echo '</div>';

		self::field( __( 'Checkout ticks', 'mostrador' ) );
		self::toggle( 'terms_text', __( 'Terms tick names your purchase conditions and withdrawal policy', 'mostrador' ) );
		self::toggle( 'digital_tick', __( 'Digital content tick', 'mostrador' ), __( 'Shown only when the cart has a product you marked as digital content or a service.', 'mostrador' ) );
		echo '</div>';
	}

	/**
	 * Tab: products.
	 */
	private static function tab_productos() {
		self::field( __( 'Under the price', 'mostrador' ) );
		self::toggle( 'price_notes', __( 'VAT and shipping note', 'mostrador' ), __( '"VAT included · Shipping not included", or "Free shipping".', 'mostrador' ) );
		self::toggle( 'price_notes_lists', __( 'Also in product lists', 'mostrador' ) );
		echo '<p><label class="mostrador-label" for="mostrador-shipping-page">' . esc_html__( 'Shipping policy page (linked from the note)', 'mostrador' ) . '</label>';
		self::page_select( 'm[shipping_page]', (int) Settings::get( 'shipping_page' ), 'mostrador-shipping-page' );
		echo '</p>';
		$classes = function_exists( 'WC' ) && WC()->shipping() ? WC()->shipping()->get_shipping_classes() : array();
		if ( $classes ) {
			$free = array_map( 'intval', (array) Settings::get( 'free_shipping_class', array() ) );
			echo '<fieldset><legend class="mostrador-label">' . esc_html__( 'Shipping classes that ship free', 'mostrador' ) . '</legend><input type="hidden" name="m[free_shipping_class][]" value="0">';
			foreach ( $classes as $class ) {
				echo '<label class="mostrador-check-line"><input type="checkbox" name="m[free_shipping_class][]" value="' . esc_attr( (string) $class->term_id ) . '"' . checked( in_array( (int) $class->term_id, $free, true ), true, false ) . '> ' . esc_html( $class->name ) . '</label>';
			}
			echo '</fieldset>';
		}
		echo '</div>';

		self::field( __( 'Price per unit', 'mostrador' ) );
		self::toggle( 'unit_price', __( 'Price per unit (per kilo, litre, metre…)', 'mostrador' ), __( 'Fill in the quantity in each product\'s Mostrador panel.', 'mostrador' ) );
		echo '</div>';

		self::field( __( 'Delivery time', 'mostrador' ) );
		self::toggle( 'delivery', __( 'Delivery time', 'mostrador' ) );
		echo '<p><label class="mostrador-label" for="mostrador-delivery-times">' . esc_html__( 'Your delivery times, one per line', 'mostrador' ) . '</label>';
		echo '<textarea id="mostrador-delivery-times" name="delivery_times" rows="4" class="large-text">' . esc_textarea( implode( "\n", Settings::delivery_times() ) ) . '</textarea></p>';
		echo '<p><label class="mostrador-label" for="mostrador-delivery-default">' . esc_html__( 'Usual delivery time', 'mostrador' ) . '</label><select id="mostrador-delivery-default" name="m[delivery_default]" class="mostrador-select">';
		foreach ( Settings::delivery_times() as $i => $time ) {
			echo '<option value="' . esc_attr( (string) $i ) . '"' . selected( (int) Settings::get( 'delivery_default' ), $i, false ) . '>' . esc_html( $time ) . '</option>';
		}
		echo '<option value="-1"' . selected( (int) Settings::get( 'delivery_default' ), -1, false ) . '>' . esc_html__( '— None —', 'mostrador' ) . '</option></select></p>';
		echo '<p><label class="mostrador-label" for="mostrador-delivery-backorder">' . esc_html__( 'Time for products on backorder', 'mostrador' ) . '</label><input type="text" id="mostrador-delivery-backorder" class="regular-text" name="m[delivery_backorder]" value="' . esc_attr( (string) Settings::get( 'delivery_backorder' ) ) . '" placeholder="' . esc_attr__( '2-3 weeks', 'mostrador' ) . '"></p>';
		echo '</div>';

		self::field( __( 'Product safety', 'mostrador' ) );
		self::toggle( 'safety', __( 'Product safety (manufacturer and warnings)', 'mostrador' ) );
		self::toggle( 'safety_short', __( 'Short manufacturer line under the add to cart button', 'mostrador' ) );
		echo '</div>';

		self::field( __( 'Food', 'mostrador' ) );
		self::toggle( 'food', __( 'Food information and allergens', 'mostrador' ) );
		self::toggle( 'allergen_icons', __( 'Allergen icons', 'mostrador' ) );
		echo '</div>';
	}

	/**
	 * Tab: emails.
	 */
	private static function tab_emails() {
		self::field( __( 'Order emails', 'mostrador' ) );
		self::toggle( 'emails', __( 'Add your conditions to the order email', 'mostrador' ) );
		self::help( __( 'So your customer gets your conditions in writing, which is what the law asks for.', 'mostrador' ) );
		echo '</div>';

		self::field( __( 'Your pages', 'mostrador' ) );
		echo '<p><label class="mostrador-label" for="mostrador-withdrawal-page">' . esc_html__( 'Withdrawal information page', 'mostrador' ) . '</label>';
		self::page_select( 'm[withdrawal_page]', (int) Settings::get( 'withdrawal_page' ), 'mostrador-withdrawal-page' );
		echo '</p>';
		$terms = (int) wc_terms_and_conditions_page_id();
		/* translators: %s: page title or "none". */
		echo '<p class="description">' . esc_html( sprintf( __( 'Purchase conditions page: %s (set in WooCommerce → Settings → Advanced).', 'mostrador' ), $terms ? get_the_title( $terms ) : __( 'none', 'mostrador' ) ) ) . '</p>';
		echo '</div>';

		self::field( __( 'Add to the first email of each order:', 'mostrador' ) );
		$pages = array_values( array_map( 'absint', (array) Settings::get( 'email_pages', array() ) ) );
		if ( ! $pages ) {
			$pages = Emails::pages();
		}
		for ( $i = 0; $i < 4; $i++ ) {
			echo '<p><label class="screen-reader-text" for="mostrador-email-page-' . esc_attr( (string) $i ) . '">' . esc_html( sprintf( /* translators: %d: 1 to 4. */ __( 'Page %d', 'mostrador' ), $i + 1 ) ) . '</label>';
			self::page_select( 'm[email_pages][]', isset( $pages[ $i ] ) ? $pages[ $i ] : 0, 'mostrador-email-page-' . $i );
			echo '</p>';
		}
		self::toggle( 'email_annex', __( 'Withdrawal form', 'mostrador' ), __( 'The model form from the law, with your shop\'s name, address and email.', 'mostrador' ) );
		self::toggle( 'email_first_only', __( 'Only in the first email of each order', 'mostrador' ) );
		echo '</div>';
	}

	/**
	 * Tab: look.
	 */
	private static function tab_aspecto() {
		self::field( __( 'Style of the notes and tabs', 'mostrador' ) );
		self::style_cards( 'm[style]', (string) Settings::get( 'style' ) );
		echo '</div>';
	}

	/**
	 * Style cards, shared with the wizard.
	 *
	 * @param string $name    Field name.
	 * @param string $current Current style.
	 */
	public static function style_cards( $name, $current ) {
		$styles = Styles::all();
		echo '<div class="mostrador-styles">';
		foreach ( $styles as $id => $style ) {
			echo '<label class="mostrador-style mostrador-style-' . esc_attr( $id ) . '"><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $id ) . '"' . checked( $current, $id, false ) . '>';
			echo '<span class="mostrador-mini" aria-hidden="true"><i></i><i></i><i></i></span><b>' . esc_html( $style['label'] ) . '</b><small>' . esc_html( $style['preview'] ) . '</small></label>';
		}
		foreach ( Styles::pro_teasers() as $id => $style ) {
			if ( isset( $styles[ $id ] ) ) {
				continue;
			}
			echo '<div class="mostrador-style mostrador-style-' . esc_attr( $id ) . ' is-pro" aria-disabled="true"><span class="mostrador-mini" aria-hidden="true"><i></i><i></i><i></i></span><b>' . esc_html( $style['label'] ) . '</b><small>' . esc_html( $style['preview'] ) . '</small><span class="mostrador-pro-tag">Pro</span></div>';
		}
		echo '</div>';
	}

	/**
	 * Save Ajustes.
	 */
	public static function save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You can\'t change these settings.', 'mostrador' ), 403 );
		}
		check_admin_referer( 'mostrador_save' );
		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'tienda';
		$raw = isset( $_POST['m'] ) && is_array( $_POST['m'] ) ? wp_unslash( $_POST['m'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value goes through sanitize() below.
		if ( 'productos' === $tab && isset( $_POST['delivery_times'] ) ) {
			$lines = explode( "\n", sanitize_textarea_field( wp_unslash( $_POST['delivery_times'] ) ) );
			$lines = array_values( array_filter( array_map( 'trim', $lines ), 'strlen' ) );
			update_option( 'mostrador_delivery_times', array_slice( $lines, 0, 20 ), false );
		}
		Settings::update( self::sanitize( (array) $raw ) );
		SiteCheck::flush();
		delete_transient( self::BADGE );
		wp_safe_redirect( admin_url( 'admin.php?page=mostrador-ajustes&tab=' . $tab . '&saved=1' ) );
		exit;
	}

	/**
	 * Clean posted settings: only known keys, each to its type.
	 *
	 * @param array $raw Posted values.
	 * @return array
	 */
	public static function sanitize( array $raw ) {
		$out   = array();
		$bools = array( 'nif_eu', 'terms_text', 'digital_tick', 'price_notes', 'price_notes_lists', 'unit_price', 'delivery', 'safety', 'safety_short', 'food', 'allergen_icons', 'emails', 'email_annex', 'email_first_only', 'delete_on_uninstall' );
		foreach ( $raw as $key => $value ) {
			$key = sanitize_key( $key );
			if ( in_array( $key, $bools, true ) ) {
				$out[ $key ] = empty( $value ) ? 0 : 1;
				continue;
			}
			switch ( $key ) {
				case 'region':
					$out[ $key ] = isset( self::regions()[ $value ] ) ? $value : 'peninsula';
					break;
				case 'nif_mode':
					$out[ $key ] = isset( self::nif_modes()[ $value ] ) ? $value : 'optional';
					break;
				case 'button':
					$out[ $key ] = in_array( $value, array( 'obligacion', 'comprar', 'confirmar', 'custom' ), true ) ? $value : 'obligacion';
					break;
				case 'button_custom':
				case 'delivery_backorder':
					$out[ $key ] = mb_substr( sanitize_text_field( (string) $value ), 0, 60 );
					break;
				case 'delivery_default':
					$out[ $key ] = max( -1, (int) $value );
					break;
				case 'shipping_page':
				case 'withdrawal_page':
					$out[ $key ] = absint( $value );
					break;
				case 'free_shipping_class':
				case 'email_pages':
					$out[ $key ] = array_values( array_unique( array_filter( array_map( 'absint', (array) $value ) ) ) );
					if ( 'email_pages' === $key ) {
						$out[ $key ] = array_slice( $out[ $key ], 0, 4 );
					}
					break;
				case 'style':
					$out[ $key ] = isset( Styles::all()[ $value ] ) ? $value : 'sobrio';
					break;
			}
		}
		return $out;
	}

	/**
	 * Pro tab.
	 */
	public static function page_pro() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		echo '<div class="wrap mostrador-wrap">';
		self::page_header( 'Mostrador Pro', __( 'For shops that sell to other businesses or have many products.', 'mostrador' ) );
		$bullets = array(
			__( 'Automatic equivalence surcharge for your retail customers', 'mostrador' ),
			__( 'Income tax withholding on sales to companies and professionals', 'mostrador' ),
			__( 'Checks EU VAT numbers and sells without VAT to EU businesses', 'mostrador' ),
			__( 'Fill in hundreds of products at once, or with a CSV', 'mostrador' ),
			__( 'A quarterly summary ready for your accountant', 'mostrador' ),
			__( 'Metrics on what\'s missing in your products', 'mostrador' ),
			__( '6 styles and your colours', 'mostrador' ),
		);
		echo '<div class="mostrador-pro"><ul class="mostrador-ticks">';
		foreach ( $bullets as $bullet ) {
			echo '<li>' . esc_html( $bullet ) . '</li>';
		}
		echo '</ul><div class="mostrador-prices">';
		echo '<div class="mostrador-price"><b>' . esc_html__( '€49 a year, one shop', 'mostrador' ) . '</b></div>';
		echo '<div class="mostrador-price"><b>' . esc_html__( '€99 a year, up to 10 shops', 'mostrador' ) . '</b></div>';
		echo '<p>' . esc_html__( 'Taxes extra where they apply. 14 days to ask for a refund.', 'mostrador' ) . '</p>';
		echo '<a class="mostrador-btn" href="' . esc_url( self::pro_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Buy Pro', 'mostrador' ) . '</a>';
		echo '</div></div></div>';
	}

	/**
	 * The one notice, after 30 days, on the Dashboard, Plugins and Mostrador screens only.
	 */
	public static function pro_notice() {
		if ( ! self::show_pro() || ! current_user_can( self::CAP ) || Settings::get( 'notice_dismissed' ) ) {
			return;
		}
		$installed = (int) Settings::get( 'installed_at' );
		if ( ! $installed || time() - $installed < self::NOTICE_DAYS * DAY_IN_SECONDS ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! ( in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) || self::ours( $screen->id ) ) ) {
			return;
		}
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=mostrador_dismiss' ), 'mostrador_dismiss' );
		echo '<div class="notice notice-info mostrador-notice"><p>';
		echo esc_html__( 'You have used Mostrador for a month. If you sell to other businesses, Mostrador Pro adds the equivalence surcharge, income tax withholding and a quarterly summary for your accountant.', 'mostrador' );
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=mostrador-pro' ) ) . '">' . esc_html__( 'See Pro', 'mostrador' ) . '</a> · <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Don\'t show again', 'mostrador' ) . '</a></p></div>';
	}

	/**
	 * Dismiss the notice forever.
	 */
	public static function dismiss() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( '', 403 );
		}
		check_admin_referer( 'mostrador_dismiss' );
		Settings::update( array( 'notice_dismissed' => 1 ) );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
