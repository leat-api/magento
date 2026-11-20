define([
    'Magento_Checkout/js/model/quote',
    'mage/translate'
], function (quote, $t) {
    'use strict';

    /**
     * Leat vouchers get their own totals rows (leat-vouchers.js), so the core Discount row only shows the rest.
     *
     * @return {Array}
     */
    function leatLines() {
        var totals = quote.getTotals()();

        return totals && totals.extension_attributes && totals.extension_attributes.leat_voucher_breakdown || [];
    }

    return function (Component) {
        return Component.extend({
            /** @inheritdoc */
            getPureValue: function () {
                var value = this._super(),
                    leat = leatLines().reduce(function (sum, line) {
                        return sum + parseFloat(line.amount);
                    }, 0);

                // discount_amount is negative, voucher amounts are positive
                return leat ? Math.round((value + leat) * 100) / 100 : value;
            },

            /** @inheritdoc */
            getTitle: function () {
                // The core title lists every rule label, including the Leat ones shown separately
                return leatLines().length ? $t('Discount') : this._super();
            }
        });
    };
});
