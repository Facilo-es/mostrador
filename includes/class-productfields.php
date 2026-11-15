<?php
/**
 * Mostrador panel on the product edit screen, and variation fields.
 *
 * @package Mostrador
 */

namespace Mostrador;

defined( 'ABSPATH' ) || exit;

/**
 * ProductFields.
 */
class ProductFields {

	/**
	 * Nutrition row keys, in display order. Safe to call at any time (no translation).
	 *
	 * @return string[]
	 */
	public static function nutrition_keys() {
		return array( 'energy', 'fat', 'saturates', 'carbs', 'sugars', 'protein', 'salt' );
	}

	/**
	 * Nutrition rows: key => label. Before init the labels are not translated yet,
	 * so an early caller gets the keys as labels instead of loading the text domain too early.
	 *
	 * @return array
	 */
	public static function nutrition_rows() {
		if ( ! did_action( 'init' ) ) {
			return array_combine( self::nutrition_keys(), self::nutrition_keys() );
		}
		return array(
			'energy'    => __( 'Energy', 'mostrador' ),
			'fat'       => __( 'Fat', 'mostrador' ),
			'saturates' => __( 'of which saturates', 'mostrador' ),
			'carbs'     => __( 'Carbohydrate', 'mostrador' ),
			'sugars'    => __( 'of which sugars', 'mostrador' ),
			'protein'   => __( 'Protein', 'mostrador' ),
			'salt'      => __( 'Salt', 'mostrador' ),
		);
	}

	/**
	 * Hooks.
	 */
	public static function boot() {
		if ( ! is_admin() ) {
			return;
		}
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save' ) );
		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'variation_fields' ), 20, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Admin assets on the product screen.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->id || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'mostrador-product', MOSTRADOR_URL . 'assets/css/product-admin.css', array(), MOSTRADOR_VERSION );
		wp_enqueue_script( 'mostrador-product', MOSTRADOR_URL . 'assets/js/product-admin.js', array( 'jquery' ), MOSTRADOR_VERSION, true );
		wp_localize_script(
			'mostrador-product',
			'mostradorProduct',
			array(
				'choose' => __( 'Choose the label image', 'mostrador' ),
				'use'    => __( 'Use this image', 'mostrador' ),
			)
		);
	}

	/**
	 * Product data tab.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public static function tab( $tabs ) {
		$tabs['mostrador'] = array(
			'label'    => __( 'Mostrador', 'mostrador' ),
			'target'   => 'mostrador_product_data',
			'class'    => array(),
			'priority' => 75,
		);
		return $tabs;
	}

	/**
	 * Select options for the delivery time.
	 *
	 * @return array
	 */
	private static function delivery_options() {
		$default = Settings::default_delivery();
		/* translators: %s: the shop's default delivery time. */
		$options = array( '' => '' !== $default ? sprintf( __( 'Shop default (%s)', 'mostrador' ), $default ) : __( 'Shop default', 'mostrador' ) );
		foreach ( Settings::delivery_times() as $i => $time ) {
			$options[ (string) $i ] = $time;
		}
		return $options;
	}

	/**
	 * Unit options.
	 *
	 * @param string $none Label for no unit.
	 * @return array
	 */
	private static function unit_options( $none ) {
		return array_merge( array( '' => $none ), UnitPrice::units() );
	}

	/**
	 * The panel.
	 */
	public static function panel() {
		global $post;
		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return;
		}
		$id = $product->get_id();
		echo '<div id="mostrador_product_data" class="panel woocommerce_options_panel hidden mostrador-product">';
		wp_nonce_field( 'mostrador_product', 'mostrador_product_nonce' );

		if ( Settings::get( 'unit_price' ) ) {
			echo '<div class="options_group"><h4 class="mostrador-h">' . esc_html__( 'Price per unit', 'mostrador' ) . '</h4>';
			woocommerce_wp_select(
				array(
					'id'      => '_mostrador_unit',
					'label'   => __( 'Unit', 'mostrador' ),
					'options' => self::unit_options( __( 'No unit price', 'mostrador' ) ),
					'value'   => (string) $product->get_meta( '_mostrador_unit' ),
				)
			);
			woocommerce_wp_text_input(
				array(
					'id'                => '_mostrador_qty',
					'label'             => __( 'Product quantity', 'mostrador' ),
					'description'       => __( 'How much the pack holds, in the unit above. For example 250 for 250 g.', 'mostrador' ),
					'desc_tip'          => true,
					'type'              => 'number',
					'custom_attributes' => array(
						'step' => 'any',
						'min'  => '0',
					),
					'value'             => (string) $product->get_meta( '_mostrador_qty' ),
				)
			);
			woocommerce_wp_text_input(
				array(
					'id'                => '_mostrador_ref_qty',
					'label'             => __( 'Reference quantity', 'mostrador' ),
					'description'       => __( 'Leave empty for 1 kg, 1 l, 1 m or 1 unit. Use 100 for 100 g or 100 ml on small packs.', 'mostrador' ),
					'desc_tip'          => true,
					'type'              => 'number',
					'placeholder'       => __( 'Default', 'mostrador' ),
					'custom_attributes' => array(
						'step' => 'any',
						'min'  => '0',
					),
					'value'             => (string) $product->get_meta( '_mostrador_ref_qty' ),
				)
			);
			woocommerce_wp_checkbox(
				array(
					'id'          => '_mostrador_sold_by_qty',
					'label'       => __( 'Sold by quantity', 'mostrador' ),
					'description' => __( 'This product is sold by weight, volume or length, so it needs a price per unit.', 'mostrador' ),
					'value'       => $product->get_meta( '_mostrador_sold_by_qty' ) ? 'yes' : 'no',
				)
			);
			echo '</div>';
		}

		echo '<div class="options_group"><h4 class="mostrador-h">' . esc_html__( 'Delivery and type', 'mostrador' ) . '</h4>';
		if ( Settings::get( 'delivery' ) ) {
			woocommerce_wp_select(
				array(
					'id'      => '_mostrador_delivery',
					'label'   => __( 'Delivery time', 'mostrador' ),
					'options' => self::delivery_options(),
					'value'   => (string) $product->get_meta( '_mostrador_delivery' ),
				)
			);
		}
		woocommerce_wp_select(
			array(
				'id'          => '_mostrador_digital',
				'label'       => __( 'Kind of product', 'mostrador' ),
				'options'     => array(
					''        => __( 'Goods', 'mostrador' ),
					'digital' => __( 'Digital content', 'mostrador' ),
					'service' => __( 'Service', 'mostrador' ),
				),
				'description' => __( 'Digital content and services can ask for the extra consent tick at checkout (Settings, Checkout).', 'mostrador' ),
				'desc_tip'    => true,
				'value'       => (string) $product->get_meta( '_mostrador_digital' ),
			)
		);
		echo '</div>';

		if ( Settings::get( 'safety' ) ) {
			self::safety_fields( $product );
		}
		if ( Settings::get( 'food' ) ) {
			self::food_fields( $id );
		}

		echo '<div class="options_group mostrador-extra">';
		/**
		 * Extra Mostrador product fields (Pro adds its own here).
		 *
		 * @param int      $product_id Product ID.
		 * @param int|null $loop       Variation loop index, null for the product.
		 */
		do_action( 'mostrador_product_fields', $id, null );
		echo '</div></div>';
	}

	/**
	 * Safety fields.
	 *
	 * @param \WC_Product $product Product.
	 */
	private static function safety_fields( $product ) {
		$id      = $product->get_id();
		$current = wp_get_object_terms( $id, Catalog::MAKER, array( 'fields' => 'ids' ) );
		$current = is_wp_error( $current ) || empty( $current ) ? 0 : (int) $current[0];
		$makers  = get_terms(
			array(
				'taxonomy'   => Catalog::MAKER,
				'hide_empty' => false,
			)
		);
		$options = array( '0' => __( 'None', 'mostrador' ) );
		if ( ! is_wp_error( $makers ) ) {
			foreach ( $makers as $term ) {
				$options[ (string) $term->term_id ] = $term->name;
			}
		}
		echo '<div class="options_group"><h4 class="mostrador-h">' . esc_html__( 'Product safety', 'mostrador' ) . '</h4>';
		woocommerce_wp_select(
			array(
				'id'          => 'mostrador_maker',
				'label'       => __( 'Manufacturer', 'mostrador' ),
				'options'     => $options,
				'value'       => (string) $current,
				'description' => '<a href="' . esc_url( admin_url( 'edit-tags.php?taxonomy=' . Catalog::MAKER . '&post_type=product' ) ) . '">' . esc_html__( 'Add or edit manufacturers', 'mostrador' ) . '</a>',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => '_mostrador_identifier',
				'label'       => __( 'Identifier', 'mostrador' ),
				'description' => __( 'Model, type, batch or serial number.', 'mostrador' ),
				'desc_tip'    => true,
				'value'       => (string) $product->get_meta( '_mostrador_identifier' ),
			)
		);
		woocommerce_wp_textarea_input(
			array(
				'id'    => '_mostrador_warnings',
				'label' => __( 'Warnings and safety information', 'mostrador' ),
				'rows'  => 4,
				'value' => (string) $product->get_meta( '_mostrador_warnings' ),
			)
		);
		$image = (int) $product->get_meta( '_mostrador_label_image' );
		$src   = $image ? wp_get_attachment_image_url( $image, 'thumbnail' ) : '';
		echo '<p class="form-field mostrador-image"><label for="_mostrador_label_image">' . esc_html__( 'Label image', 'mostrador' ) . '</label>';
		echo '<input type="hidden" id="_mostrador_label_image" name="_mostrador_label_image" value="' . esc_attr( $image ? (string) $image : '' ) . '">';
		echo '<img class="mostrador-image-preview" alt="" src="' . esc_url( $src ? $src : '' ) . '"' . ( $src ? '' : ' hidden' ) . '> ';
		echo '<button type="button" class="button mostrador-image-pick">' . esc_html__( 'Choose image', 'mostrador' ) . '</button> ';
		echo '<button type="button" class="button-link mostrador-image-clear"' . ( $src ? '' : ' hidden' ) . '>' . esc_html__( 'Remove', 'mostrador' ) . '</button></p>';
		echo '</div>';
	}

	/**
	 * Food fields.
	 *
	 * @param int $id Product ID.
	 */
	private static function food_fields( $id ) {
		$food = Catalog::food_data( $id );
		$on   = (bool) get_post_meta( $id, '_mostrador_food', true );
		echo '<div class="options_group"><h4 class="mostrador-h">' . esc_html__( 'Food information', 'mostrador' ) . '</h4>';
		woocommerce_wp_checkbox(
			array(
				'id'          => '_mostrador_food',
				'label'       => __( 'Food product', 'mostrador' ),
				'description' => __( 'Show the food information tab for this product.', 'mostrador' ),
				'value'       => $on ? 'yes' : 'no',
			)
		);
		echo '<div class="mostrador-food"' . ( $on ? '' : ' hidden' ) . '>';
		$text = array(
			'name'        => array( __( 'Name of the food', 'mostrador' ), 'text' ),
			'ingredients' => array( __( 'Ingredients', 'mostrador' ), 'textarea' ),
			'net'         => array( __( 'Net quantity', 'mostrador' ), 'text' ),
			'storage'     => array( __( 'Storage and use', 'mostrador' ), 'textarea' ),
			'origin'      => array( __( 'Country of origin', 'mostrador' ), 'text' ),
			'operator'    => array( __( 'Food business operator (name and address)', 'mostrador' ), 'textarea' ),
		);
		foreach ( $text as $key => $field ) {
			$args = array(
				'id'    => 'mostrador_food_' . $key,
				'name'  => 'mostrador_food[' . $key . ']',
				'label' => $field[0],
				'value' => (string) $food[ $key ],
			);
			if ( 'ingredients' === $key ) {
				$args['description'] = __( 'Write allergens in bold with <b>…</b>, for example <b>milk</b>.', 'mostrador' );
			}
			if ( 'textarea' === $field[1] ) {
				$args['rows'] = 3;
				woocommerce_wp_textarea_input( $args );
			} else {
				woocommerce_wp_text_input( $args );
			}
		}
		$allergens = Catalog::allergens();
		foreach ( array(
			'allergens' => __( 'Contains', 'mostrador' ),
			'traces'    => __( 'May contain traces of', 'mostrador' ),
		) as $group => $label ) {
			echo '<fieldset class="form-field mostrador-checks"><legend>' . esc_html( $label ) . '</legend><span>';
			foreach ( $allergens as $slug => $name ) {
				$checked = in_array( $slug, (array) $food[ $group ], true );
				echo '<label><input type="checkbox" name="mostrador_food[' . esc_attr( $group ) . '][]" value="' . esc_attr( $slug ) . '"' . checked( $checked, true, false ) . '> ' . esc_html( $name ) . '</label>';
			}
			echo '</span></fieldset>';
		}
		echo '<p class="form-field"><label for="mostrador_food_none">' . esc_html__( 'No allergens', 'mostrador' ) . '</label>';
		echo '<input type="checkbox" id="mostrador_food_none" name="mostrador_food[none]" value="1"' . checked( ! empty( $food['none'] ), true, false ) . '> ';
		echo '<span class="description">' . esc_html__( 'This food has none of the 14 allergens.', 'mostrador' ) . '</span></p>';

		woocommerce_wp_select(
			array(
				'id'      => 'mostrador_food_per',
				'name'    => 'mostrador_food[per]',
				'label'   => __( 'Nutrition values per', 'mostrador' ),
				'options' => array(
					'100g'  => '100 g',
					'100ml' => '100 ml',
				),
				'value'   => (string) $food['per'],
			)
		);
		echo '<div class="mostrador-nutrition">';
		$units = array(
			'energy' => 'kJ / kcal',
		);
		foreach ( self::nutrition_rows() as $key => $label ) {
			woocommerce_wp_text_input(
				array(
					'id'          => 'mostrador_food_n_' . $key,
					'name'        => 'mostrador_food[nutrition][' . $key . ']',
					'label'       => $label,
					'placeholder' => isset( $units[ $key ] ) ? $units[ $key ] : 'g',
					'value'       => isset( $food['nutrition'][ $key ] ) ? (string) $food['nutrition'][ $key ] : '',
				)
			);
		}
		echo '</div>';
		woocommerce_wp_text_input(
			array(
				'id'          => 'mostrador_food_abv',
				'name'        => 'mostrador_food[abv]',
				'label'       => __( 'Alcohol by volume (%)', 'mostrador' ),
				'description' => __( 'Only for drinks above 1.2%.', 'mostrador' ),
				'desc_tip'    => true,
				'value'       => (string) $food['abv'],
			)
		);
		echo '</div></div>';
	}

	/**
	 * Whether this request carries our product nonce.
	 *
	 * @return bool
	 */
	private static function nonce_ok() {
		return isset( $_POST['mostrador_product_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mostrador_product_nonce'] ) ), 'mostrador_product' );
	}

	/**
	 * Text value from $_POST.
	 *
	 * @param string $key Key.
	 * @return string|null Null when absent.
	 */
	private static function posted( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers check the nonce.
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : null;
	}

	/**
	 * Number or '' from a string.
	 *
	 * @param string|null $value Value.
	 * @return string
	 */
	private static function number( $value ) {
		$value = str_replace( ',', '.', (string) $value );
		return is_numeric( $value ) && (float) $value > 0 ? (string) (float) $value : '';
	}

	/**
	 * Set or delete one meta.
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $key     Key.
	 * @param mixed       $value   Value; '' deletes.
	 */
	private static function set( $product, $key, $value ) {
		if ( '' === $value || null === $value || array() === $value ) {
			$product->delete_meta_data( $key );
		} else {
			$product->update_meta_data( $key, $value );
		}
	}

	/**
	 * Save the panel.
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function save( $product ) {
		if ( ! self::nonce_ok() || ! current_user_can( 'edit_product', $product->get_id() ) ) {
			return;
		}
		if ( Settings::get( 'unit_price' ) ) {
			$unit = (string) self::posted( '_mostrador_unit' );
			self::set( $product, '_mostrador_unit', isset( UnitPrice::units()[ $unit ] ) ? $unit : '' );
			self::set( $product, '_mostrador_qty', self::number( self::posted( '_mostrador_qty' ) ) );
			self::set( $product, '_mostrador_ref_qty', self::number( self::posted( '_mostrador_ref_qty' ) ) );
			self::set( $product, '_mostrador_sold_by_qty', is_null( self::posted( '_mostrador_sold_by_qty' ) ) ? '' : 'yes' );
		}
		if ( Settings::get( 'delivery' ) ) {
			$delivery = (string) self::posted( '_mostrador_delivery' );
			self::set( $product, '_mostrador_delivery', isset( Settings::delivery_times()[ (int) $delivery ] ) && ctype_digit( $delivery ) ? $delivery : '' );
		}
		$kind = (string) self::posted( '_mostrador_digital' );
		self::set( $product, '_mostrador_digital', in_array( $kind, array( 'digital', 'service' ), true ) ? $kind : '' );

		if ( Settings::get( 'safety' ) ) {
			$maker = (int) self::posted( 'mostrador_maker' );
			$term  = $maker ? get_term( $maker, Catalog::MAKER ) : null;
			wp_set_object_terms( $product->get_id(), $term && ! is_wp_error( $term ) ? array( (int) $term->term_id ) : array(), Catalog::MAKER, false );
			self::set( $product, '_mostrador_identifier', (string) self::posted( '_mostrador_identifier' ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
			$warnings = isset( $_POST['_mostrador_warnings'] ) ? wp_kses_post( wp_unslash( $_POST['_mostrador_warnings'] ) ) : '';
			self::set( $product, '_mostrador_warnings', trim( $warnings ) );
			$image = absint( self::posted( '_mostrador_label_image' ) );
			self::set( $product, '_mostrador_label_image', $image && wp_attachment_is_image( $image ) ? $image : '' );
		}

		if ( Settings::get( 'food' ) ) {
			$on = null !== self::posted( '_mostrador_food' );
			self::set( $product, '_mostrador_food', $on ? 'yes' : '' );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked above, sanitised field by field in clean_food().
			$raw  = isset( $_POST['mostrador_food'] ) && is_array( $_POST['mostrador_food'] ) ? wp_unslash( $_POST['mostrador_food'] ) : array();
			$food = self::clean_food( $raw );
			self::set( $product, '_mostrador_food_data', $food );
			Catalog::sync_allergens( $product->get_id(), $on ? $food['allergens'] : array() );
		}
		SiteCheck::flush();
	}

	/**
	 * Sanitise the food array.
	 *
	 * @param array $raw Raw.
	 * @return array
	 */
	public static function clean_food( array $raw ) {
		$slugs = array_keys( Catalog::allergens() );
		$tags  = array(
			'b'      => array(),
			'strong' => array(),
			'em'     => array(),
			'i'      => array(),
			'br'     => array(),
		);
		$food  = array(
			'name'        => isset( $raw['name'] ) ? sanitize_text_field( $raw['name'] ) : '',
			'ingredients' => isset( $raw['ingredients'] ) ? trim( wp_kses( (string) $raw['ingredients'], $tags ) ) : '',
			'allergens'   => isset( $raw['allergens'] ) ? array_values( array_intersect( array_map( 'sanitize_key', (array) $raw['allergens'] ), $slugs ) ) : array(),
			'traces'      => isset( $raw['traces'] ) ? array_values( array_intersect( array_map( 'sanitize_key', (array) $raw['traces'] ), $slugs ) ) : array(),
			'none'        => empty( $raw['none'] ) ? 0 : 1,
			'net'         => isset( $raw['net'] ) ? sanitize_text_field( $raw['net'] ) : '',
			'storage'     => isset( $raw['storage'] ) ? sanitize_textarea_field( $raw['storage'] ) : '',
			'origin'      => isset( $raw['origin'] ) ? sanitize_text_field( $raw['origin'] ) : '',
			'operator'    => isset( $raw['operator'] ) ? sanitize_textarea_field( $raw['operator'] ) : '',
			'per'         => isset( $raw['per'] ) && '100ml' === $raw['per'] ? '100ml' : '100g',
			'nutrition'   => array(),
			'abv'         => isset( $raw['abv'] ) ? sanitize_text_field( $raw['abv'] ) : '',
		);
		foreach ( array_keys( self::nutrition_rows() ) as $key ) {
			if ( isset( $raw['nutrition'][ $key ] ) && '' !== trim( (string) $raw['nutrition'][ $key ] ) ) {
				$food['nutrition'][ $key ] = sanitize_text_field( $raw['nutrition'][ $key ] );
			}
		}
		if ( $food['allergens'] ) {
			$food['none'] = 0;
		}
		return $food;
	}

	/**
	 * Variation fields: unit and delivery, inheriting from the parent when empty.
	 *
	 * @param int      $loop      Loop index.
	 * @param array    $data      Variation data.
	 * @param \WP_Post $variation Variation post.
	 */
	public static function variation_fields( $loop, $data, $variation ) {
		$v = wc_get_product( $variation->ID );
		if ( ! $v ) {
			return;
		}
		echo '<div class="mostrador-variation">';
		if ( Settings::get( 'unit_price' ) ) {
			woocommerce_wp_select(
				array(
					'id'            => "mostrador_v_unit_{$loop}",
					'name'          => "mostrador_v[{$loop}][unit]",
					'label'         => __( 'Unit (Mostrador)', 'mostrador' ),
					'options'       => self::unit_options( __( 'Same as the product', 'mostrador' ) ),
					'value'         => (string) $v->get_meta( '_mostrador_unit' ),
					'wrapper_class' => 'form-row form-row-first',
				)
			);
			woocommerce_wp_text_input(
				array(
					'id'                => "mostrador_v_qty_{$loop}",
					'name'              => "mostrador_v[{$loop}][qty]",
					'label'             => __( 'Product quantity', 'mostrador' ),
					'type'              => 'number',
					'value'             => (string) $v->get_meta( '_mostrador_qty' ),
					'wrapper_class'     => 'form-row form-row-last',
					'custom_attributes' => array(
						'step' => 'any',
						'min'  => '0',
					),
				)
			);
			woocommerce_wp_text_input(
				array(
					'id'                => "mostrador_v_ref_{$loop}",
					'name'              => "mostrador_v[{$loop}][ref]",
					'label'             => __( 'Reference quantity', 'mostrador' ),
					'type'              => 'number',
					'placeholder'       => __( 'Same as the product', 'mostrador' ),
					'value'             => (string) $v->get_meta( '_mostrador_ref_qty' ),
					'wrapper_class'     => 'form-row form-row-first',
					'custom_attributes' => array(
						'step' => 'any',
						'min'  => '0',
					),
				)
			);
		}
		if ( Settings::get( 'delivery' ) ) {
			$options     = self::delivery_options();
			$options[''] = __( 'Same as the product', 'mostrador' );
			woocommerce_wp_select(
				array(
					'id'            => "mostrador_v_delivery_{$loop}",
					'name'          => "mostrador_v[{$loop}][delivery]",
					'label'         => __( 'Delivery time', 'mostrador' ),
					'options'       => $options,
					'value'         => (string) $v->get_meta( '_mostrador_delivery' ),
					'wrapper_class' => 'form-row form-row-last',
				)
			);
		}
		/** This action is documented in includes/class-product-fields.php */
		do_action( 'mostrador_product_fields', $variation->ID, $loop );
		echo '</div>';
	}

	/**
	 * Save variation fields. WooCommerce has checked its own nonce (ajax "save-variations" or the product form).
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $loop         Loop index.
	 */
	public static function save_variation( $variation_id, $loop ) {
		$ajax = isset( $_POST['security'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['security'] ) ), 'save-variations' );
		if ( ( ! $ajax && ! self::nonce_ok() ) || ! current_user_can( 'edit_product', $variation_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
		$row = isset( $_POST['mostrador_v'][ $loop ] ) && is_array( $_POST['mostrador_v'][ $loop ] ) ? map_deep( wp_unslash( $_POST['mostrador_v'][ $loop ] ), 'sanitize_text_field' ) : null;
		$v   = wc_get_product( $variation_id );
		if ( null === $row || ! $v ) {
			return;
		}
		if ( Settings::get( 'unit_price' ) ) {
			$unit = isset( $row['unit'] ) ? (string) $row['unit'] : '';
			self::set( $v, '_mostrador_unit', isset( UnitPrice::units()[ $unit ] ) ? $unit : '' );
			self::set( $v, '_mostrador_qty', self::number( isset( $row['qty'] ) ? $row['qty'] : '' ) );
			self::set( $v, '_mostrador_ref_qty', self::number( isset( $row['ref'] ) ? $row['ref'] : '' ) );
		}
		if ( Settings::get( 'delivery' ) ) {
			$d = isset( $row['delivery'] ) ? (string) $row['delivery'] : '';
			self::set( $v, '_mostrador_delivery', ctype_digit( $d ) && isset( Settings::delivery_times()[ (int) $d ] ) ? $d : '' );
		}
		$v->save();
	}
}
