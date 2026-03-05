jQuery(document).ready(function ($) {
    const swedbankPaySettings = {
        params: {},
        $instrumentSettingsSectionTitle: $('#woocommerce_payex_checkout_separate_instruments'),
        $flowSelect: $('#woocommerce_payex_checkout_checkout_flow'),
        $enableSeparateInstruments: $('#woocommerce_payex_checkout_enable_separate_instruments'),
        $instrumentSettings: $('.instrument-setting'),

        /**
         * Initialize the settings page by binding events.
         * Also toggles the instrument settings based on the initial state of the flow and separate instruments options.
         *
         * @returns {void}
         */
        init: function () {
            this.params = typeof swedbank_pay_admin_settings_params !== 'undefined' ? swedbank_pay_admin_settings_params : {};
            this.bindEvents();
            this.toggleInstrumentSettings();
        },

        /**
         * Bind the events needed for the settings page.
         *
         * @returns {void}
         */
        bindEvents: function () {
            const self = this;

            self.$flowSelect.on('change', function () {
                self.toggleInstrumentSettings();
            });

            self.$enableSeparateInstruments.on('change', function () {
                self.toggleInstrumentSettings();
            });
        },

        /**
         * Toggle the visibility of the instrument settings based on the selected flow,
         * and if the separate instruments option is enabled.
         *
         * @returns {void}
         */
        toggleInstrumentSettings: function () {
            const self = this;

            // Get the current flow, and whether separate instruments is enabled
            const currentFlow = self.$flowSelect.val();
            const separateInstrumentsEnabled = self.$enableSeparateInstruments.is(':checked');

            // If the flow is redirect, show the separate instruments option, otherwise hide it
            if (currentFlow === 'redirect') {
                self.$enableSeparateInstruments.closest('table').show();
                self.$instrumentSettingsSectionTitle.show();
            } else {
                self.$enableSeparateInstruments.closest('table').hide();
                self.$instrumentSettingsSectionTitle.hide();
            }

            // If the flow is redirect, and separate instruments is enabled, show the individual instrument settings, otherwise hide them
            if (currentFlow === 'redirect' && separateInstrumentsEnabled) {
                self.$instrumentSettings.closest('tr').show();
            } else {
                self.$instrumentSettings.closest('tr').hide();
            }
        },
    }

    swedbankPaySettings.init();
});
