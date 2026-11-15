/* Mostrador settings: show the custom button field and the payment-word warning as the owner types. */
( function () {
	'use strict';
	var form = document.querySelector( '.mostrador-form' );
	if ( ! form ) {
		return;
	}
	var custom = document.getElementById( 'mostrador-button-custom' );
	var words = [ 'pag', 'compr', 'pay', 'buy', 'kauf', 'zahl', 'pai', 'achet', 'acquist', 'betal', 'koop', 'kup', 'płac' ];
	function chosen() {
		var r = form.querySelector( 'input[name="m[button]"]:checked' );
		return r ? r.value : '';
	}
	function update() {
		if ( ! custom ) {
			return;
		}
		var isCustom = 'custom' === chosen();
		custom.parentNode.hidden = ! isCustom;
		var warn = form.querySelector( '[data-mostrador-button-warning]' );
		if ( warn ) {
			var text = isCustom ? custom.value.toLowerCase() : 'pag';
			warn.hidden = words.some( function ( w ) {
				return text.indexOf( w ) !== -1;
			} );
		}
	}
	form.addEventListener( 'change', update );
	if ( custom ) {
		custom.addEventListener( 'input', update );
	}
	update();
}() );
