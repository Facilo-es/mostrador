/* Mostrador setup wizard: steps, live phone preview and small toggles. */
( function () {
	'use strict';
	var form = document.getElementById( 'mostrador-wizard' );
	if ( ! form ) {
		return;
	}
	var steps = Array.prototype.slice.call( form.querySelectorAll( '.paso' ) );
	var dots = Array.prototype.slice.call( document.querySelectorAll( '.pasos button' ) );
	var frame = document.getElementById( 'mostrador-pv' );
	var current = parseInt( form.getAttribute( 'data-start' ), 10 ) || 0;
	var timer = null;

	function show( i ) {
		if ( i < 0 || i >= steps.length ) {
			return;
		}
		current = i;
		steps.forEach( function ( s, n ) {
			s.hidden = n !== i;
		} );
		dots.forEach( function ( d, n ) {
			d.className = n === i ? 'on' : ( n < i ? 'hecho' : '' );
			if ( n === i ) {
				d.setAttribute( 'aria-current', 'step' );
			} else {
				d.removeAttribute( 'aria-current' );
			}
		} );
		var h = steps[ i ].querySelector( 'h1' );
		if ( h ) {
			h.setAttribute( 'tabindex', '-1' );
			h.focus( { preventScroll: true } );
		}
		window.scrollTo( 0, 0 );
	}

	function region() {
		var r = form.querySelector( 'input[name="m[region]"]:checked' );
		return r ? r.value : 'peninsula';
	}

	function syncRegion() {
		var reg = region();
		form.querySelectorAll( '.nota[data-region]' ).forEach( function ( n ) {
			n.hidden = n.getAttribute( 'data-region' ) !== reg;
		} );
		form.querySelectorAll( '[data-vat]' ).forEach( function ( n ) {
			n.hidden = 'peninsula' !== reg;
		} );
		form.querySelectorAll( '[data-outside]' ).forEach( function ( n ) {
			n.hidden = 'peninsula' === reg;
		} );
	}

	function syncCustom() {
		var b = form.querySelector( 'input[name="m[button]"]:checked' );
		var otro = form.querySelector( '.otro' );
		if ( otro ) {
			otro.hidden = ! b || 'custom' !== b.value;
		}
	}

	function previewUrl() {
		var base = frame.getAttribute( 'data-base' );
		var params = [];
		var seen = {};
		form.querySelectorAll( '[data-mp]' ).forEach( function ( el ) {
			var key = el.getAttribute( 'data-mp' );
			var val;
			if ( 'radio' === el.type ) {
				if ( ! el.checked ) {
					return;
				}
				val = el.value;
			} else if ( 'checkbox' === el.type ) {
				val = el.checked ? '1' : '0';
			} else {
				val = el.value;
			}
			if ( seen[ key ] ) {
				return;
			}
			seen[ key ] = true;
			params.push( 'mp%5B' + encodeURIComponent( key ) + '%5D=' + encodeURIComponent( val ) );
		} );
		return base + ( base.indexOf( '?' ) === -1 ? '?' : '&' ) + params.join( '&' );
	}

	function refresh() {
		if ( ! frame ) {
			return;
		}
		clearTimeout( timer );
		timer = setTimeout( function () {
			frame.src = previewUrl();
		}, 350 );
	}

	function scrollFrameTo( id ) {
		if ( ! frame ) {
			return;
		}
		frame.addEventListener( 'load', function once() {
			frame.removeEventListener( 'load', once );
			try {
				var el = frame.contentDocument.getElementById( id ) || frame.contentDocument.querySelector( '.woocommerce-tabs' );
				if ( el ) {
					el.scrollIntoView();
				}
			} catch ( e ) {}
		} );
	}

	form.addEventListener( 'click', function ( e ) {
		var go = e.target.closest( '[data-go]' );
		if ( ! go ) {
			return;
		}
		e.preventDefault();
		var what = go.getAttribute( 'data-go' );
		if ( 'back' === what ) {
			show( current - 1 );
			return;
		}
		if ( 'skip' === what ) {
			var s = steps[ current ].querySelector( 'input.skip' );
			if ( s ) {
				s.value = '1';
			}
		} else {
			var s2 = steps[ current ].querySelector( 'input.skip' );
			if ( s2 ) {
				s2.value = '0';
			}
		}
		if ( current === steps.length - 1 ) {
			form.submit();
		} else {
			show( current + 1 );
		}
	} );

	dots.forEach( function ( d, n ) {
		d.addEventListener( 'click', function () {
			show( n );
		} );
	} );

	form.addEventListener( 'change', function ( e ) {
		var t = e.target;
		if ( 'm[region]' === t.name ) {
			syncRegion();
		}
		if ( 'm[button]' === t.name ) {
			syncCustom();
		}
		if ( t.hasAttribute( 'data-mp' ) ) {
			if ( 'food' === t.getAttribute( 'data-mp' ) && t.checked ) {
				scrollFrameTo( 'tab-mostrador_food' );
			}
			refresh();
		}
	} );
	form.addEventListener( 'input', function ( e ) {
		if ( e.target.hasAttribute( 'data-mp' ) && 'text' === e.target.type ) {
			refresh();
		}
	} );

	syncRegion();
	syncCustom();
	show( current );
}() );
