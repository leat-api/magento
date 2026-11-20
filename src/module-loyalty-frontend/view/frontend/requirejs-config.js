
var config = {
    map: {
        '*': {
            leatReferCopy: 'Leat_LoyaltyFrontend/js/refer-copy',
            leatReferralPopup: 'Leat_LoyaltyFrontend/js/referral-popup',
            setLeatBalance: 'Leat_LoyaltyFrontend/js/action/set-leat-balance',
            leatBalance: 'Leat_LoyaltyFrontend/js/view/payment/leat-balance',
            leatGiftcard: 'Leat_LoyaltyFrontend/js/view/payment/leat-giftcard',
            leatProgressButton: 'Leat_LoyaltyFrontend/js/progress-button',
            leatActivityLog: 'Leat_LoyaltyFrontend/js/view/leat-activity-log',
            leatYourCoupons: 'Leat_LoyaltyFrontend/js/view/leat-your-coupons',
            leatEarnPoints: 'Leat_LoyaltyFrontend/js/view/leat-earn-points',
            giftcardOptionsForm: 'Leat_LoyaltyFrontend/js/product/giftcard-recipient-fields',
            giftcardBalanceCheck: 'Leat_LoyaltyFrontend/js/widget/giftcard-balance',
            'Magento_Checkout/template/minicart/item/default.html':
                'Leat_LoyaltyFrontend/template/minicart/item/default.html',
            'Magento_Checkout/template/minicart/subtotal/totals.html':
                'Leat_LoyaltyFrontend/template/minicart/subtotal/totals.html'
        }
    },
    config: {
        mixins: {
            'Magento_SalesRule/js/view/summary/discount': {
                'Leat_LoyaltyFrontend/js/mixin/discount-summary-mixin': true
            }
        }
    },
    paths: {
        'Leat_LoyaltyFrontend/js/progress-button': 'Leat_LoyaltyFrontend/js/progress-button',
        'Leat_LoyaltyFrontend/js/product/giftcard-recipient-fields': 'Leat_LoyaltyFrontend/js/product/giftcard-recipient-fields'
    }
};
