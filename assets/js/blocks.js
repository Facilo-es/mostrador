/* Mostrador: editor side of the three dynamic blocks (no build step). */
( function ( wp ) {
	'use strict';
	var el = wp.element.createElement, SSR = wp.serverSideRender, useBlockProps = wp.blockEditor.useBlockProps, __ = wp.i18n.__;
	function edit( name, empty ) {
		return function ( props ) {
			var attrs = { productId: props.attributes.productId || ( props.context && props.context.postId ) || 0 };
			return el( 'div', useBlockProps(), el( SSR, {
				block: name,
				attributes: attrs,
				EmptyResponsePlaceholder: function () {
					return el( 'p', { className: 'mostrador-block-empty' }, empty );
				}
			} ) );
		};
	}
	wp.blocks.registerBlockType( 'mostrador/product-safety', { edit: edit( 'mostrador/product-safety', __( 'Product safety details show here once the product has a manufacturer or warnings.', 'mostrador' ) ), save: function () { return null; } } );
	wp.blocks.registerBlockType( 'mostrador/food-info', { edit: edit( 'mostrador/food-info', __( 'Food information shows here once the product is marked as food.', 'mostrador' ) ), save: function () { return null; } } );
	wp.blocks.registerBlockType( 'mostrador/allergen-filter', { edit: edit( 'mostrador/allergen-filter', __( 'Without allergens', 'mostrador' ) ), save: function () { return null; } } );
}( window.wp ) );
