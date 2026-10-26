( function () {
	'use strict';

	const i18n = window.launaAdminSettings || {};

	function onReady( fn ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}

	function nextTable( el ) {
		let node = el.nextElementSibling;
		while ( node ) {
			if ( node.tagName === 'TABLE' ) {
				return node;
			}
			node = node.nextElementSibling;
		}
		return null;
	}

	function initAdvancedToggle() {
		const heading =
			document.getElementById( 'woocommerce_launa_advanced_title' ) ||
			document.querySelector( '.launa-advanced-heading' );
		if ( ! heading ) {
			return;
		}

		const table = nextTable( heading );
		if ( ! table ) {
			return;
		}

		heading.classList.add( 'launa-advanced-heading' );
		table.id = table.id || 'launa-advanced-fields';

		const button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'button-link launa-advanced-toggle';
		button.setAttribute( 'aria-controls', table.id );

		heading.appendChild( document.createTextNode( ' ' ) );
		heading.appendChild( button );

		let collapsed = true;

		function sync() {
			if ( collapsed ) {
				table.setAttribute( 'hidden', 'hidden' );
			} else {
				table.removeAttribute( 'hidden' );
			}
			button.textContent = collapsed
				? i18n.showAdvanced || 'Show'
				: i18n.hideAdvanced || 'Hide';
			button.setAttribute(
				'aria-expanded',
				collapsed ? 'false' : 'true'
			);
			heading.classList.toggle( 'is-collapsed', collapsed );
		}

		button.addEventListener( 'click', function () {
			collapsed = ! collapsed;
			sync();
		} );

		sync();
	}

	function fallbackCopy( text ) {
		const input = document.createElement( 'textarea' );
		input.value = text;
		input.setAttribute( 'readonly', 'readonly' );
		input.style.position = 'absolute';
		input.style.left = '-9999px';
		document.body.appendChild( input );
		input.select();
		let ok = false;
		try {
			ok = document.execCommand( 'copy' );
		} catch {
			ok = false;
		}
		document.body.removeChild( input );
		return ok;
	}

	function markCopied( button ) {
		const label = button.getAttribute( 'aria-label' ) || '';
		button.classList.add( 'is-copied' );
		button.setAttribute( 'aria-label', i18n.copied || 'Copied' );
		window.setTimeout( function () {
			button.classList.remove( 'is-copied' );
			if ( label ) {
				button.setAttribute( 'aria-label', label );
			}
		}, 1500 );
	}

	function copyText( text, button ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then(
				function () {
					markCopied( button );
				},
				function () {
					if ( fallbackCopy( text ) ) {
						markCopied( button );
					}
				}
			);
			return;
		}
		if ( fallbackCopy( text ) ) {
			markCopied( button );
		}
	}

	function initCopyButtons() {
		document.addEventListener( 'click', function ( event ) {
			const target = event.target;
			if ( ! ( target instanceof Element ) ) {
				return;
			}
			const button = target.closest( '[data-launa-copy]' );
			if ( ! button ) {
				return;
			}
			event.preventDefault();
			const text = button.getAttribute( 'data-launa-copy' ) || '';
			if ( text === '' ) {
				return;
			}
			copyText( text, button );
		} );
	}

	function syncHederaFields() {
		let hederaOn = false;
		document
			.querySelectorAll(
				'input.launa-settlement-token[data-hedera="1"]:checked:not(:disabled)'
			)
			.forEach( function () {
				hederaOn = true;
			} );
		document
			.querySelectorAll( '[data-launa-hedera-payto="1"]' )
			.forEach( function ( el ) {
				if ( ! ( el instanceof HTMLInputElement ) ) {
					return;
				}
				el.readOnly = ! hederaOn;
				if ( ! hederaOn ) {
					el.setAttribute( 'aria-disabled', 'true' );
				} else {
					el.removeAttribute( 'aria-disabled' );
				}
				const row = el.closest( 'tr' );
				if ( row ) {
					row.style.opacity = hederaOn ? '' : '0.55';
				}
			} );
	}

	function initHederaFields() {
		document.addEventListener( 'change', function ( event ) {
			const target = event.target;
			if ( ! ( target instanceof Element ) ) {
				return;
			}
			if ( target.classList.contains( 'launa-settlement-token' ) ) {
				syncHederaFields();
			}
		} );
		syncHederaFields();
	}

	function initRefreshTokens() {
		document.addEventListener( 'click', function ( event ) {
			const target = event.target;
			if ( ! ( target instanceof Element ) ) {
				return;
			}
			const button = target.closest( '[data-launa-refresh-tokens]' );
			if ( ! button ) {
				return;
			}
			const flag = document.querySelector( '[data-launa-refresh-flag]' );
			if ( flag instanceof HTMLInputElement ) {
				flag.value = '1';
			}
		} );
	}

	onReady( function () {
		initAdvancedToggle();
		initCopyButtons();
		initHederaFields();
		initRefreshTokens();
	} );
} )();
