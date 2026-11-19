/**
 * "Assign to me" button next to a user column - Backlog's 私が担当.
 *
 * The button is rendered by SelectTable::setAdminOptions, which puts the logged
 * in user's id and name straight into the attributes. Nothing exposes the
 * current user to scripts globally, and reading it from the attribute keeps this
 * file free of any request to the server.
 */
(function ($) {
    'use strict';

    function assignMe(event) {
        var $button = $(event.currentTarget);
        var id = String($button.data('assign-me') || '');
        if (!id) {
            return;
        }

        var $select = $button.closest('.form-group').find('select').first();
        if (!$select.length) {
            return;
        }

        // A field that loads its choices over ajax has an empty option list until
        // somebody types, so the option has to be created before it can be picked.
        if (!$select.find('option[value="' + id + '"]').length) {
            var label = String($button.data('assign-me-label') || id);
            $select.append(new Option(label, id, true, true));
        }

        if ($select.prop('multiple')) {
            var current = $select.val() || [];
            if (current.indexOf(id) < 0) {
                current.push(id);
                $select.val(current);
            }
        } else {
            $select.val(id);
        }

        $select.trigger('change');
    }

    $(document)
        .off('click.exment_assign_me')
        .on('click.exment_assign_me', '[data-assign-me]', assignMe);
})(jQuery);
