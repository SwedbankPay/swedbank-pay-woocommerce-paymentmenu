( function () {
	'use strict';

	var config = window.WC_Gateway_Swedbank_Pay_Intl_Tel || {};
	var initialized = new WeakMap();

	function findPhoneInput() {
		return document.querySelector( '#billing_phone, #phone, #shipping-phone, #billing-phone' );
	}

	function commitValueToReact( input, value ) {
		var setter = Object.getOwnPropertyDescriptor( window.HTMLInputElement.prototype, 'value' );
		if ( setter && setter.set ) {
			setter.set.call( input, value );
		} else {
			input.value = value;
		}
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	function initOnInput( input ) {
		if ( ! input || initialized.has( input ) || ! window.intlTelInput ) {
			return;
		}

		var options = {
			initialCountry: config.country || 'auto',
			countryOrder: config.country_order || [ 'SE', 'NO', 'FI', 'DK' ],
			strictMode: true,
			separateDialCode: true,
			formatAsYouType: true,
			nationalMode: false,
			customContainer: 'form-row-wide',
			i18n: config.i18n || {},
		};

		if ( config.utils_script ) {
			options.loadUtils = function () {
				return import( /* webpackIgnore: true */ config.utils_script );
			};
		}

		var iti = window.intlTelInput( input, options );

		initialized.set( input, iti );

		var reformat = function () {
			var utils = window.intlTelInput && window.intlTelInput.utils;
			if ( ! utils ) {
				return;
			}
			var number = iti.getNumber( utils.numberFormat.E164 );
			if ( typeof number === 'string' && number.length > 0 ) {
				commitValueToReact( input, number );
			}
		};

		input.addEventListener( 'countrychange', reformat );
		input.addEventListener( 'blur', reformat );
	}

	function tryInit() {
		initOnInput( findPhoneInput() );
	}

	function observe() {
		if ( ! ( 'MutationObserver' in window ) ) {
			return;
		}
		var observer = new MutationObserver(
			function () {
				tryInit();
			}
		);
		observer.observe( document.body, { childList: true, subtree: true } );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', tryInit );
	} else {
		tryInit();
	}

	observe();

	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'updated_checkout update_checkout', tryInit );
	}
}() );
