define([], function () {
    'use strict';

    /**
     * Gift quantity is fixed by the voucher: make the qty input of this cart row read-only
     */
    return function (config, element) {
        var row = element.closest('tbody.cart.item');

        (row ? row.querySelectorAll('input.qty') : []).forEach(function (input) {
            input.readOnly = true;
            input.classList.add('leat-gift-qty');
        });
    };
});
