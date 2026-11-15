/* Mostrador: shows the NIF field for the right countries and checks it as the shopper types (classic checkout). */
( function ( $ ) {
	'use strict';
	var L = 'TRWAGMYFPDXBNJZSQVHLCKE';
	function norm( v ) {
		v = ( v || '' ).toUpperCase().replace( /[\s.\-\/]+/g, '' );
		return v.indexOf( 'ES' ) === 0 && v.length > 9 ? v.slice( 2 ) : v;
	}
	function cif( v ) {
		var s = 0, i, d, c, l;
		for ( i = 0; i < 7; i++ ) {
			d = +v[ i + 1 ];
			if ( i % 2 === 0 ) { d *= 2; s += Math.floor( d / 10 ) + d % 10; } else { s += d; }
		}
		c = ( 10 - s % 10 ) % 10;
		l = 'JABCDEFGHI'[ c ];
		if ( 'PQRSNW'.indexOf( v[ 0 ] ) > -1 ) { return v[ 8 ] === l; }
		if ( 'ABEH'.indexOf( v[ 0 ] ) > -1 ) { return v[ 8 ] === String( c ); }
		return v[ 8 ] === String( c ) || v[ 8 ] === l;
	}
	function ok( v, country ) {
		v = norm( v );
		if ( country !== 'ES' ) {
			return /^([A-Z]{2})?[0-9A-Z+*]{2,13}$/.test( v ) && /\d/.test( v );
		}
		if ( /^\d{8}[A-Z]$/.test( v ) ) { return L[ +v.slice( 0, 8 ) % 23 ] === v[ 8 ]; }
		if ( /^[XYZ]\d{7}[A-Z]$/.test( v ) ) { return L[ +( 'XYZ'.indexOf( v[ 0 ] ) + v.slice( 1, 8 ) ) % 23 ] === v[ 8 ]; }
		if ( /^[KLM]\d{7}[A-Z]$/.test( v ) ) { return L[ +v.slice( 1, 8 ) % 23 ] === v[ 8 ]; }
		if ( /^[ABCDEFGHJNPQRSUVW]\d{7}[0-9A-J]$/.test( v ) ) { return cif( v ); }
		return false;
	}
	function country() {
		return $( '#billing_country' ).val() || '';
	}
	function toggle() {
		var row = $( '#billing_nif_field' ), list = ( $( '#billing_nif' ).data( 'countries' ) || '' ).split( ',' );
		row.toggle( list.indexOf( country() ) > -1 );
	}
	function check() {
		var input = $( '#billing_nif' ), row = $( '#billing_nif_field' ), v = input.val();
		if ( ! v || ! row.is( ':visible' ) ) {
			row.removeClass( 'woocommerce-invalid woocommerce-validated' );
			input.removeAttr( 'aria-invalid' );
			return;
		}
		var good = ok( v, country() );
		row.toggleClass( 'woocommerce-invalid', ! good ).toggleClass( 'woocommerce-validated', good );
		input.attr( 'aria-invalid', good ? 'false' : 'true' );
	}
	$( document.body ).on( 'change', '#billing_country', function () { toggle(); check(); } )
		.on( 'blur change', '#billing_nif', check )
		.on( 'updated_checkout', toggle );
	$( function () { toggle(); } );
}( jQuery ) );
