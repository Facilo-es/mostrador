<?php
/**
 * "Without allergens" widget for classic themes.
 *
 * @package Mostrador
 */

namespace Mostrador;

defined( 'ABSPATH' ) || exit;

/**
 * The widget.
 */
class AllergenWidget extends \WP_Widget {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'mostrador_allergens',
			__( 'Without allergens', 'mostrador' ),
			array( 'description' => __( 'Lets shoppers hide products that contain an allergen.', 'mostrador' ) )
		);
	}

	/**
	 * Output.
	 *
	 * @param array $args     Args.
	 * @param array $instance Instance.
	 */
	public function widget( $args, $instance ) {
		if ( ! function_exists( 'is_shop' ) || ! ( is_shop() || is_product_taxonomy() ) ) {
			return;
		}
		$title = isset( $instance['title'] ) && '' !== $instance['title'] ? $instance['title'] : __( 'Without allergens', 'mostrador' );
		echo wp_kses_post( $args['before_widget'] );
		echo wp_kses_post( $args['before_title'] ) . esc_html( apply_filters( 'widget_title', $title, $instance, $this->id_base ) ) . wp_kses_post( $args['after_title'] );
		echo AllergenFilter::html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in html().
		echo wp_kses_post( $args['after_widget'] );
	}

	/**
	 * Admin form.
	 *
	 * @param array $instance Instance.
	 * @return string
	 */
	public function form( $instance ) {
		$title = isset( $instance['title'] ) ? $instance['title'] : '';
		echo '<p><label for="' . esc_attr( $this->get_field_id( 'title' ) ) . '">' . esc_html__( 'Title', 'mostrador' ) . '</label> ';
		echo '<input class="widefat" id="' . esc_attr( $this->get_field_id( 'title' ) ) . '" name="' . esc_attr( $this->get_field_name( 'title' ) ) . '" type="text" value="' . esc_attr( $title ) . '" placeholder="' . esc_attr__( 'Without allergens', 'mostrador' ) . '"></p>';
		return '';
	}

	/**
	 * Save.
	 *
	 * @param array $new_instance New.
	 * @param array $old_instance Old.
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		return array( 'title' => isset( $new_instance['title'] ) ? sanitize_text_field( $new_instance['title'] ) : '' );
	}
}
