<?php
/**
 * Manufacturers (GPSR) and allergens taxonomies.
 *
 * @package Mostrador
 */

namespace Mostrador;

defined( 'ABSPATH' ) || exit;

/**
 * Catalog.
 */
class Catalog {

	const MAKER    = 'mostrador_fabricante';
	const ALLERGEN = 'mostrador_alergeno';

	/**
	 * Term meta keys for manufacturers.
	 *
	 * @var string[]
	 */
	const MAKER_META = array( 'address', 'contact', 'eu_name', 'eu_address', 'eu_contact' );

	/**
	 * Hooks.
	 */
	public static function boot() {
		add_action( 'init', array( __CLASS__, 'register' ), 5 );
		add_action( self::MAKER . '_add_form_fields', array( __CLASS__, 'add_fields' ) );
		add_action( self::MAKER . '_edit_form_fields', array( __CLASS__, 'edit_fields' ) );
		add_action( 'created_' . self::MAKER, array( __CLASS__, 'save_fields' ) );
		add_action( 'edited_' . self::MAKER, array( __CLASS__, 'save_fields' ) );
	}

	/**
	 * The 14 allergens.
	 *
	 * @return array slug => name.
	 */
	public static function allergens() {
		return include MOSTRADOR_DIR . 'data/allergens.php';
	}

	/**
	 * Register both taxonomies and make sure the allergen terms exist.
	 */
	public static function register() {
		register_taxonomy(
			self::MAKER,
			'product',
			array(
				'labels'            => array(
					'name'          => __( 'Manufacturers', 'mostrador' ),
					'singular_name' => __( 'Manufacturer', 'mostrador' ),
					'menu_name'     => __( 'Manufacturers', 'mostrador' ),
					'add_new_item'  => __( 'Add manufacturer', 'mostrador' ),
					'edit_item'     => __( 'Edit manufacturer', 'mostrador' ),
					'search_items'  => __( 'Search manufacturers', 'mostrador' ),
					'not_found'     => __( 'No manufacturers yet.', 'mostrador' ),
				),
				'public'            => false,
				'show_ui'           => true,
				'show_in_menu'      => true,
				'show_admin_column' => false,
				'show_in_rest'      => false,
				'meta_box_cb'       => false,
				'hierarchical'      => false,
				'rewrite'           => false,
				'capabilities'      => array(
					'manage_terms' => 'manage_product_terms',
					'edit_terms'   => 'edit_product_terms',
					'delete_terms' => 'delete_product_terms',
					'assign_terms' => 'assign_product_terms',
				),
			)
		);
		register_taxonomy(
			self::ALLERGEN,
			'product',
			array(
				'labels'       => array(
					'name'          => __( 'Allergens', 'mostrador' ),
					'singular_name' => __( 'Allergen', 'mostrador' ),
				),
				'public'       => false,
				'show_ui'      => false,
				'show_in_rest' => false,
				'hierarchical' => false,
				'rewrite'      => false,
				'query_var'    => false,
			)
		);
		if ( get_option( 'mostrador_allergen_terms' ) !== MOSTRADOR_VERSION ) {
			foreach ( array_keys( self::allergens() ) as $slug ) {
				if ( ! term_exists( $slug, self::ALLERGEN ) ) {
					wp_insert_term( $slug, self::ALLERGEN, array( 'slug' => $slug ) );
				}
			}
			update_option( 'mostrador_allergen_terms', MOSTRADOR_VERSION, false );
		}
	}

	/**
	 * Labels for the manufacturer fields.
	 *
	 * @return array
	 */
	private static function maker_labels() {
		return array(
			'address'    => array( __( 'Postal address', 'mostrador' ), 'textarea', '' ),
			'contact'    => array( __( 'Email or contact web', 'mostrador' ), 'text', '' ),
			'eu_name'    => array( __( 'Responsible person in the EU: name', 'mostrador' ), 'text', __( 'Only when the manufacturer is outside the EU.', 'mostrador' ) ),
			'eu_address' => array( __( 'Responsible person in the EU: address', 'mostrador' ), 'textarea', '' ),
			'eu_contact' => array( __( 'Responsible person in the EU: email or web', 'mostrador' ), 'text', '' ),
		);
	}

	/**
	 * Fields on the "add manufacturer" form.
	 */
	public static function add_fields() {
		wp_nonce_field( 'mostrador_maker', 'mostrador_maker_nonce' );
		foreach ( self::maker_labels() as $key => $field ) {
			echo '<div class="form-field"><label for="mostrador-' . esc_attr( $key ) . '">' . esc_html( $field[0] ) . '</label>';
			self::input( $key, $field[1], '' );
			if ( $field[2] ) {
				echo '<p>' . esc_html( $field[2] ) . '</p>';
			}
			echo '</div>';
		}
	}

	/**
	 * Fields on the "edit manufacturer" form.
	 *
	 * @param \WP_Term $term Term.
	 */
	public static function edit_fields( $term ) {
		wp_nonce_field( 'mostrador_maker', 'mostrador_maker_nonce' );
		foreach ( self::maker_labels() as $key => $field ) {
			echo '<tr class="form-field"><th scope="row"><label for="mostrador-' . esc_attr( $key ) . '">' . esc_html( $field[0] ) . '</label></th><td>';
			self::input( $key, $field[1], (string) get_term_meta( $term->term_id, $key, true ) );
			if ( $field[2] ) {
				echo '<p class="description">' . esc_html( $field[2] ) . '</p>';
			}
			echo '</td></tr>';
		}
	}

	/**
	 * One input.
	 *
	 * @param string $key   Key.
	 * @param string $type  text|textarea.
	 * @param string $value Value.
	 */
	private static function input( $key, $type, $value ) {
		if ( 'textarea' === $type ) {
			echo '<textarea id="mostrador-' . esc_attr( $key ) . '" name="mostrador_maker[' . esc_attr( $key ) . ']" rows="3">' . esc_textarea( $value ) . '</textarea>';
		} else {
			echo '<input type="text" id="mostrador-' . esc_attr( $key ) . '" name="mostrador_maker[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '">';
		}
	}

	/**
	 * Save term meta.
	 *
	 * @param int $term_id Term ID.
	 */
	public static function save_fields( $term_id ) {
		if ( ! isset( $_POST['mostrador_maker_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mostrador_maker_nonce'] ) ), 'mostrador_maker' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_product_terms' ) || ! isset( $_POST['mostrador_maker'] ) || ! is_array( $_POST['mostrador_maker'] ) ) {
			return;
		}
		$data = map_deep( wp_unslash( $_POST['mostrador_maker'] ), 'sanitize_textarea_field' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		foreach ( self::MAKER_META as $key ) {
			$value = isset( $data[ $key ] ) ? trim( (string) $data[ $key ] ) : '';
			if ( '' === $value ) {
				delete_term_meta( $term_id, $key );
			} else {
				update_term_meta( $term_id, $key, $value );
			}
		}
		SiteCheck::flush();
	}

	/**
	 * Manufacturer data for a product.
	 *
	 * @param \WC_Product $product Product (variations use the parent).
	 * @return array|null
	 */
	public static function maker_for( $product ) {
		$id    = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$terms = get_the_terms( $id, self::MAKER );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return null;
		}
		$term = $terms[0];
		$data = array( 'name' => $term->name );
		foreach ( self::MAKER_META as $key ) {
			$data[ $key ] = (string) get_term_meta( $term->term_id, $key, true );
		}
		return $data;
	}

	/**
	 * Food data for a product, with defaults.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	public static function food_data( $product_id ) {
		$data = get_post_meta( $product_id, '_mostrador_food_data', true );
		$data = is_array( $data ) ? $data : array();
		return array_merge(
			array(
				'name'        => '',
				'ingredients' => '',
				'allergens'   => array(),
				'traces'      => array(),
				'none'        => 0,
				'net'         => '',
				'storage'     => '',
				'origin'      => '',
				'operator'    => '',
				'per'         => '100g',
				'nutrition'   => array(),
				'abv'         => '',
			),
			$data
		);
	}

	/**
	 * Keep the allergen taxonomy in step with the food meta.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $slugs      Allergen slugs.
	 */
	public static function sync_allergens( $product_id, array $slugs ) {
		$slugs = array_values( array_intersect( $slugs, array_keys( self::allergens() ) ) );
		wp_set_object_terms( $product_id, $slugs, self::ALLERGEN, false );
	}
}
