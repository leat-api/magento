/**
 * POST to a url (with the form key), optionally after the admin confirmed. Used on the form buttons that
 * change data. Config: {url: string, title?: string, message?: string}
 */
define([
    'jquery',
    'mage/utils/misc',
    'Magento_Ui/js/modal/confirm'
], function ($, utils, confirm) {
    'use strict';

    return function (config, element) {
        $(element).on('click', function (event) {
            event.preventDefault();

            if (!config.message) {
                utils.submit({url: config.url, data: {}});

                return;
            }

            confirm({
                title: config.title || '',
                content: config.message,
                actions: {
                    confirm: function () {
                        utils.submit({url: config.url, data: {}});
                    }
                }
            });
        });
    };
});
