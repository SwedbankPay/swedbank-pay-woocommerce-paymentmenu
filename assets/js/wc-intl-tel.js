/**
 * Enhances the checkout billing phone field with intl-tel-input.
 *
 * This file is loaded as an ES module (see Swedbank_Intl_Tel::script_loader_tag)
 * and pulls the library in with a dynamic import. That is deliberate: the
 * library's classic build declares a top-level `var intlTelInput`, which lands
 * on `window`, so two plugins shipping different versions would clobber each
 * other. An imported binding is module-scoped, so nothing here reads or writes
 * the global and we cannot collide with anyone else's copy.
 */
( function () {
	'use strict';

	var config = window.WC_Gateway_Swedbank_Pay_Intl_Tel;

	if ( ! config || ! config.lib_script ) {
		return;
	}

	/**
	 * Country name translation goes through Intl.DisplayNames, which throws on a
	 * malformed tag. The library catches that and then blanks every country
	 * name, so validate before handing the tag over.
	 *
	 * @param {string} tag Locale tag.
	 * @return {string} A usable locale tag.
	 */
	function safeLocale( tag ) {
		try {
			return Intl.getCanonicalLocales( tag ).length ? tag : 'en';
		} catch ( e ) {
			return 'en';
		}
	}

	/**
	 * Load the translated interface strings, if we ship any for this site's
	 * language. A failure here must not stop the field from initialising.
	 *
	 * @return {Promise<Object>} Translations, or an empty object.
	 */
	function loadTranslations() {
		if ( ! config.locale_script ) {
			return Promise.resolve( {} );
		}

		return import( config.locale_script )
			.then( function ( module ) {
				return module.default || {};
			} )
			.catch( function () {
				return {};
			} );
	}

	var enhanced = [];

	/**
	 * Tear down instances whose field has been removed from the page. The
	 * library holds every instance in a map of its own, so a checkout plugin
	 * that re-renders the address fields would otherwise pile them up.
	 */
	function dropDetached() {
		enhanced = enhanced.filter(
			function ( element ) {
				if ( document.contains( element ) ) {
					return true;
				}

				if ( element.iti ) {
					element.iti.destroy();
				}

				return false;
			}
		);
	}

	/**
	 * Initialise the field, unless it is already initialised. The library keeps
	 * its instance on the element itself, so that doubles as the guard.
	 *
	 * @param {Function} intlTelInput The library.
	 * @param {Object}   translations Interface translations.
	 */
	function init( intlTelInput, translations ) {
		dropDetached();

		var input = document.querySelector( '#billing_phone' );

		if ( ! input || input.iti ) {
			return;
		}

		var iti = intlTelInput(
			input,
			{
				initialCountry: config.country || '',
				countryOrder: config.country_order || null,
				countryNameLocale: safeLocale( config.name_locale || 'en' ),
				uiTranslations: translations,

				separateDialCode: false,
				numberDisplayFormat: 'INTERNATIONAL',

				// A scope class of our own, so our instances stay addressable if
				// another plugin's intl-tel-input stylesheet ever bleeds in. The
				// library applies it to the detached mobile selector too.
				containerClass: 'form-row-wide swedbank-pay-iti',

				loadUtils: function () {
					return import( config.utils_script );
				},
			}
		);

		enhanced.push( input );

		/**
		 * Normalise the phone number to E.164 format.
		 */
		function normalise() {
			if ( ! intlTelInput.utils ) {
				return;
			}

			var number = iti.getNumber( 'E164' );

			// Compared the way wc_sanitize_phone_number() will.
			if ( number && 0 === number.indexOf( '+' ) && number !== input.value.replace( /[^\d+]/g, '' ) ) {
				iti.setNumber( number );
			}
		}

		input.addEventListener( 'countrychange', normalise );
		input.addEventListener( 'change', normalise );
		input.addEventListener( 'blur', normalise );

		// Keep the selected country in step with the billing country, so the
		// number the customer types is interpreted the same way the server will
		// interpret it.
		var billingCountry = document.querySelector( '#billing_country' );
		if ( billingCountry ) {
			billingCountry.addEventListener(
				'change',
				function () {
					if ( ! input.value && billingCountry.value ) {
						iti.setSelectedCountry( billingCountry.value.toLowerCase() );
					}
				}
			);
		}
	}

	Promise.all( [ import( config.lib_script ), loadTranslations() ] )
		.then(
			function ( results ) {
				var intlTelInput = results[0].default;
				var translations = results[1];

				init( intlTelInput, translations );

				// Some checkout plugins re-render the address fields. The
				// `input.iti` guard above makes this idempotent. Note that
				// `updated_checkout` is synthesised by jQuery and so never
				// reaches addEventListener().
				if ( window.jQuery ) {
					window.jQuery( document.body ).on(
						'updated_checkout',
						function () {
							init( intlTelInput, translations );
						}
					);
				}
			}
		)
		.catch(
			function ( error ) {
				// A CDN plugin rewriting plugins_url() to a host without CORS
				// headers is the likely cause -- module imports are fetched in
				// CORS mode, unlike classic scripts. Leave the plain field in
				// place rather than failing silently.
				window.console.warn( 'Swedbank Pay: could not load intl-tel-input.', error );
			}
		);
}() );
