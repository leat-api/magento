define([
    'Magento_Checkout/js/view/summary/abstract-total',
    'Magento_Checkout/js/model/quote'
], function (Component, quote) {
    'use strict';

    /**
     * One totals row per applied Leat voucher, from totals.extension_attributes.leat_voucher_breakdown
     */
    return Component.extend({
        defaults: {
            template: 'Leat_LoyaltyFrontend/checkout/summary/leat-vouchers'
        },

        /**
         * @return {Array}
         */
        getLines: function () {
            var totals = quote.getTotals()();

            return totals && totals.extension_attributes && totals.extension_attributes.leat_voucher_breakdown || [];
        },

        /**
         * @param {Object} line
         * @return {String}
         */
        getFormattedAmount: function (line) {
            return '-' + this.getFormattedPrice(line.amount);
        }
    });
});
