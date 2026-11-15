/* Mostrador: product screen helpers (label image picker, food section toggle). */
( function ( $ ) {
	'use strict';
	var t = window.mostradorProduct || { choose: 'Choose image', use: 'Use this image' };
	$( document ).on( 'click', '.mostrador-image-pick', function ( e ) {
		e.preventDefault();
		var wrap = $( this ).closest( '.mostrador-image' ), frame = wp.media( { title: t.choose, button: { text: t.use }, library: { type: 'image' }, multiple: false } );
		frame.on( 'select', function () {
			var a = frame.state().get( 'selection' ).first().toJSON(), url = a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url;
			wrap.find( 'input[type=hidden]' ).val( a.id );
			wrap.find( '.mostrador-image-preview' ).attr( 'src', url ).prop( 'hidden', false );
			wrap.find( '.mostrador-image-clear' ).prop( 'hidden', false );
		} );
		frame.open();
	} ).on( 'click', '.mostrador-image-clear', function ( e ) {
		e.preventDefault();
		var wrap = $( this ).closest( '.mostrador-image' );
		wrap.find( 'input[type=hidden]' ).val( '' );
		wrap.find( '.mostrador-image-preview' ).attr( 'src', '' ).prop( 'hidden', true );
		$( this ).prop( 'hidden', true );
	} ).on( 'change', '#_mostrador_food', function () {
		$( '.mostrador-food' ).prop( 'hidden', ! this.checked );
	} );
}( jQuery ) );
