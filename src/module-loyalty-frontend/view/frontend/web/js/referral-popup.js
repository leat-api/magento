
define([
    'jquery',
    'mage/url',
    'mage/translate',
    'Magento_Ui/js/modal/modal'
], function ($, urlBuilder, $t, modal) {
    'use strict';

    return function (config) {
        var modalInstance = null,
            accepted = false,
            STATE_SHOWN = 'shown',          // opened but never interacted with (e.g. covered by another overlay)
            STATE_DISMISSED = 'dismissed',  // user closed it
            STATE_ACCEPTED = 'accepted';    // user submitted the form

        /**
         * Get the stored popup state for a referral code (empty when unset or storage is unavailable)
         *
         * @param {String} code
         * @returns {String}
         */
        function getState(code) {
            try {
                return localStorage.getItem('leat_referral_' + code) || '';
            } catch (e) {
                return '';
            }
        }

        /**
         * Store the popup state for a referral code (no expiry)
         *
         * @param {String} code
         * @param {String} state
         */
        function setState(code, state) {
            try {
                localStorage.setItem('leat_referral_' + code, state);
            } catch (e) {
                // storage blocked: popup simply shows again next time
            }
        }

        /**
         * Get URL parameter
         *
         * @param {String} name
         * @returns {String}
         */
        function getUrlParameter(name) {
            name = name.replace(/[[]/, '\\[').replace(/[\]]/, '\\]');
            var regex = new RegExp('[\\?&]' + name + '=([^&#]*)');
            var results = regex.exec(location.search);
            return results === null ? '' : decodeURIComponent(results[1].replace(/\+/g, ' '));
        }

        /**
         * Check for referral code in URL and show popup if needed
         */
        function initReferralPopup() {
            var referralCode = getUrlParameter('___referral-code');

            if (referralCode) {
                // Only 'dismissed' / 'accepted' suppress the popup; no cookie or 'shown' re-shows it on reload
                var state = getState(referralCode);
                if (state !== STATE_DISMISSED && state !== STATE_ACCEPTED) {
                    // Fetch popup content
                    $.ajax({
                        url: urlBuilder.build('leat/referral/popup'),
                        type: 'GET',
                        data: {
                            referral_code: referralCode
                        },
                        success: function (response) {
                            if (response) {
                                // Create modal container if it doesn't exist
                                if (!$('#leat-referral-modal').length) {
                                    $('body').append('<div id="leat-referral-modal" class="leat-referral-modal"></div>');
                                }

                                // Add modal content
                                $('#leat-referral-modal').html(response);

                                // Initialize Magento modal
                                modalInstance = modal({
                                    type: 'popup',
                                    title: false,
                                    modalClass: 'leat-referral-modal-container',
                                    responsive: true,
                                    innerScroll: true,
                                    clickableOverlay: true,
                                    buttons: [],
                                    closed: function() {
                                        if (!accepted) {
                                            setState(referralCode, STATE_DISMISSED);
                                        }
                                    }
                                }, $('#leat-referral-modal'));

                                // Open modal
                                modalInstance.openModal();

                                // Mark as shown; becomes dismissed/accepted only on user action
                                setState(referralCode, STATE_SHOWN);

                                // Setup event handlers
                                setupPopupEvents(referralCode);
                            }
                        }
                    });
                }
            }
        }

        /**
         * Setup popup event handlers
         *
         * @param {String} referralCode
         */
        function setupPopupEvents(referralCode) {
            // Close button handler
            $(document).on('click', '.leat-referral-popup-close', function() {
                if (modalInstance) {
                    modalInstance.closeModal();
                }
            });

            // Form submission handler
            $(document).on('submit', '#leat-referral-form', function(e) {
                e.preventDefault();

                var form = $(this);
                var submitBtn = form.find('button[type="submit"]');
                var email = form.find('input[name="email"]').val();

                submitBtn.prop('disabled', true).addClass('loading');

                $.ajax({
                    url: urlBuilder.build('leat/referral/subscribe'),
                    type: 'POST',
                    data: form.serialize(),
                    success: function(response) {
                        submitBtn.prop('disabled', false).removeClass('loading');

                        if (response.success) {
                            accepted = true;
                            setState(referralCode, STATE_ACCEPTED);
                            form.hide();
                            $('.leat-referral-popup-success').html(response.message).show();

                            // Auto close after success
                            setTimeout(function() {
                                if (modalInstance) {
                                    modalInstance.closeModal();
                                }
                            }, 5000);
                        } else {
                            $('.leat-referral-popup-error').html(response.message).show();

                            setTimeout(function() {
                                $('.leat-referral-popup-error').hide();
                            }, 3000);
                        }
                    },
                    error: function() {
                        submitBtn.prop('disabled', false).removeClass('loading');
                        $('.leat-referral-popup-error').html(
                            $t('An error occurred. Please try again later.')
                        ).show();

                        setTimeout(function() {
                            $('.leat-referral-popup-error').hide();
                        }, 3000);
                    }
                });
            });
        }

        // Initialize popup on page load
        $(function() {
            initReferralPopup();
        });
    };
});
