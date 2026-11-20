define([
    'jquery',
    'mage/translate',
    'Magento_Customer/js/customer-data',
    'mage/cookies'
], function ($, $t, customerData) {
    'use strict';

    /**
     * Earn points cards. Pressing a claim button lets the link open in its own tab (default action untouched)
     * and claims the points through XHR. The server decides; the button only mirrors its answer.
     *
     * @param {Object} config {claimUrl, completedLabel}
     * @param {HTMLElement} element
     */
    return function (config, element) {
        var $root = $(element),
            pending = 0, // Claim requests still in flight
            claimed = false, // A claim in the current burst succeeded
            before = ''; // Points and log length when the burst started

        /**
         * @returns {String}
         */
        function snapshot() {
            var data = customerData.get('leat')() || {};

            return data.points + '|' + (data.transactions || []).length;
        }

        /**
         * Reload the leat section; when nothing changed the credit may not be listed by Leat yet, so retry
         *
         * @param {Number} retries
         */
        function refresh(retries) {
            customerData.invalidate(['leat']);
            customerData.reload(['leat'], true).done(function () {
                if (retries > 0 && snapshot() === before) {
                    setTimeout(function () {
                        if (!pending) {
                            refresh(retries - 1);
                        }
                    }, 3000);
                }
            });
        }

        /**
         * Once the last claim has finished: one reload for the whole burst (the POSTs skip Magento's own)
         */
        function settle() {
            if (pending > 0 || !claimed) {
                return;
            }

            claimed = false;
            refresh(1);
        }

        /**
         * @param {jQuery} $card
         * @param {String} label
         */
        function complete($card, label) {
            $card.find('[data-earn-claim]').replaceWith(
                $('<span class="leat-earn-points-completed"></span>').text(label || config.completedLabel)
            );
            $card.find('.leat-earn-points-error').remove();
        }

        /**
         * @param {jQuery} $card
         * @param {String} message
         */
        function fail($card, message) {
            $card.data('busy', false).find('[data-earn-claim]').removeAttr('aria-disabled').removeClass('is-busy');
            $card.find('.leat-earn-points-error').remove();
            $('<span class="leat-earn-points-error" role="alert"></span>')
                .text(message || $t('Something went wrong, please try again later.'))
                .appendTo($card.find('.leat-earn-points-text'));
        }

        // auxclick: a middle-click also opens the link in a new tab (right-click, button 2, does not)
        $root.on('click auxclick', '[data-earn-claim]', function (event) {
            var $button = $(this),
                $card = $button.closest('[data-earning-id]');

            if (event.type === 'auxclick' && event.button !== 1) {
                return;
            }

            if ($card.data('busy') || $button.is('[data-earn-no-link]')) {
                // Already in flight: don't open the link a second time. No link configured: nothing to open
                event.preventDefault();
            }

            if ($card.data('busy')) {
                return;
            }

            $card.data('busy', true);
            $button.attr('aria-disabled', 'true').addClass('is-busy');
            $card.find('.leat-earn-points-error').remove();

            if (!pending) {
                before = snapshot();
            }
            pending++;

            $.ajax({
                url: config.claimUrl,
                type: 'POST',
                dataType: 'json',
                // Magento would reload the section after every POST, in arbitrary order; settle() does it once
                global: false,
                data: {
                    earning_id: $card.data('earning-id'),
                    form_key: $.mage.cookies.get('form_key')
                }
            }).done(function (response) {
                if (response.completed) {
                    claimed = claimed || response.success;
                    complete($card, response.label);

                    return;
                }

                fail($card, response.message);
            }).fail(function () {
                fail($card);
            }).always(function () {
                // Last, so done() has already recorded its success
                pending--;
                settle();
            });
        });
    };
});
