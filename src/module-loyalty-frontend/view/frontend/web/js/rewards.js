define([
    'jquery',
    'uiComponent',
    'mage/translate',
    'Magento_Customer/js/customer-data',
    'Leat_LoyaltyFrontend/js/progress-button'
], function ($, Component, $t, customerData, progressButton) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Leat_LoyaltyFrontend/rewards',
            redeemUrl: null
        },

        /**
         * Initialize component
         */
        initialize: function () {
            this._super();

            // Get the URL from the global config
            if (window.LeatConfig && window.LeatConfig.redeemUrl) {
                this.redeemUrl = window.LeatConfig.redeemUrl;
            }

            this.pendingRedeems = 0; // Redeem requests still in flight
            this.needsReload = false; // A redeem in the current burst succeeded

            this.initEventHandlers();
            return this;
        },

        /**
         * Initialize event handlers
         */
        initEventHandlers: function () {
            $(document).on('click', '.leat-reward-redeem', this.onRedeemClick.bind(this));
        },

        /**
         * Handle redeem button click
         *
         * @param {Event} event
         */
        onRedeemClick: function (event) {
            const button = $(event.currentTarget);
            const rewardId = button.data('reward-id');

            if (!rewardId) {
                return;
            }

            // Saves a round trip to Leat; the API is still the real check
            const requiredCredits = parseInt(button.data('required-credits'), 10);
            const points = Number((customerData.get('leat')() || {}).points);
            if (!isNaN(requiredCredits) && !isNaN(points) && points < requiredCredits) {
                this.showErrorMessage($t('You don\'t have enough points to redeem this reward.'));
                return;
            }

            // Initialize progress button with all text states
            const progress = progressButton.init(button, {
                initialText: $t('Redeem'),
                loadingText: $t('Redeeming...'),
                successText: $t('Redeemed!')
            });

            this.pendingRedeems++;
            this.redeemReward(rewardId)
                .done(function (response) {
                    if (response.success) {
                        // Complete with success state
                        progress.complete(true, {
                            resetDelay: 3000
                        });

                        this.showSuccessMessage(response.message || $t('Reward successfully redeemed!'));

                        this.needsReload = true;
                    } else {
                        // Complete with error state
                        progress.complete(false);
                        this.showErrorMessage(response.message || $t('Failed to redeem reward.'));
                    }
                }.bind(this))
                .fail(function () {
                    // Reset on failure
                    progress.reset();
                    this.showErrorMessage($t('An error occurred while redeeming your reward.'));
                }.bind(this))
                // Last, so done() has already recorded its success
                .always(function () {
                    this.pendingRedeems--;
                    this.settleRedeems();
                }.bind(this));
        },

        /**
         * Once the last redeem has finished: one leat/cart reload for the whole burst
         *
         * The leat update also refreshes the voucher list (your-coupons.js subscribes to it)
         */
        settleRedeems: function () {
            if (this.pendingRedeems > 0 || !this.needsReload) {
                return;
            }

            this.needsReload = false;
            customerData.invalidate(['leat', 'cart']);
            customerData.reload(['leat', 'cart'], true);
        },

        /**
         * Redeem a reward
         *
         * @param {string} rewardId
         * @returns {jQuery.Deferred}
         */
        redeemReward: function (rewardId) {
            return $.ajax({
                url: this.redeemUrl,
                type: 'POST',
                dataType: 'json',
                // Keeps Magento from reloading the sections after every POST; settleRedeems() does it once
                global: false,
                data: {
                    reward_id: rewardId,
                    form_key: $.mage.cookies.get('form_key')
                }
            });
        },

        /**
         * Show success message
         *
         * @param {string} message
         */
        showSuccessMessage: function (message) {
            // Using Magento's standard messages UI component
            if (typeof window.messages !== 'undefined') {
                window.messages.addSuccessMessage({
                    message: message
                });
            } else {
                // Attempt to use Magento's message manager
                if (typeof requirejs !== 'undefined') {
                    requirejs(['Magento_Ui/js/model/messageList'], function(messageList) {
                        messageList.addSuccessMessage({
                            message: message
                        });
                    });
                } else {
                    // Fallback to alert for testing
                    alert(message);
                }
            }
        },

        /**
         * Show error message
         *
         * @param {string} message
         */
        showErrorMessage: function (message) {
            // Using Magento's standard messages UI component
            if (typeof window.messages !== 'undefined') {
                window.messages.addErrorMessage({
                    message: message
                });
            } else {
                // Attempt to use Magento's message manager
                if (typeof requirejs !== 'undefined') {
                    requirejs(['Magento_Ui/js/model/messageList'], function(messageList) {
                        messageList.addErrorMessage({
                            message: message
                        });
                    });
                } else {
                    // Fallback to alert for testing
                    alert(message);
                }
            }
        }
    });
});
