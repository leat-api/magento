/**
 * Drag/drop reorder for a dynamic-rows config table. Rows are posted in DOM order,
 * so the saved order is simply the order on screen.
 */
define(['jquery', 'jquery-ui-modules/widgets/sortable'], function ($) {
    'use strict';

    return function (config, element) {
        $(element).find('tbody').sortable({
            items: '> tr',
            axis: 'y',
            handle: '.draggable-handle',
            tolerance: 'pointer',
            // keep the cell widths while dragging
            helper: function (e, row) {
                row.children().each(function () {
                    $(this).width($(this).width());
                });

                return row;
            }
        });
    };
});
