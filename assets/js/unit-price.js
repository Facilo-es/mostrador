/* Mostrador: swaps the unit price and delivery time when a variation is chosen. */
( function ( $ ) {
	'use strict';
	function scope( form ) {
		var p = form.closest( '.product, .wp-block-woocommerce-single-product, main' );
		return p.length ? p : $( document.body );
	}
	function set( els, attr, text ) {
		els.each( function () {
			var el = $( this ), t = text === null ? el.attr( attr ) || '' : text;
			el.text( t ).prop( 'hidden', t === '' );
		} );
	}
	$( document.body ).on( 'found_variation', 'form.variations_form', function ( e, v ) {
		var s = scope( $( this ) );
		set( s.find( '.mostrador-unit' ).first(), 'data-mostrador-unit', v && v.mostrador_unit ? v.mostrador_unit : '' );
		if ( v && v.mostrador_delivery ) {
			set( s.find( '.mostrador-delivery' ).first(), 'data-mostrador-delivery', v.mostrador_delivery );
		}
	} ).on( 'reset_data', 'form.variations_form', function () {
		var s = scope( $( this ) );
		set( s.find( '.mostrador-unit' ).first(), 'data-mostrador-unit', null );
		set( s.find( '.mostrador-delivery' ).first(), 'data-mostrador-delivery', null );
	} );
}( jQuery ) );
