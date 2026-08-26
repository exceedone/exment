/**
 * Quick add beside a select-table column - Backlog's + on カテゴリー and マイルストーン.
 *
 * Asks for the label, posts it, and drops the answer into the select. It does not
 * open a form: the moment somebody needs a master row that does not exist yet is
 * the moment they are half way through writing something else, and a second form
 * is what makes them give up and pick the wrong value instead.
 */
(function ($) {
    'use strict';

    function appendAndSelect($select, id, label) {
        id = String(id);

        if (!$select.find('option[value="' + id + '"]').length) {
            $select.append(new Option(label, id, false, false));
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

    function post($button, $select, label) {
        $.ajax({
            url: $button.data('quickadd-url'),
            type: 'POST',
            dataType: 'json',
            data: {
                label: label,
                _token: LA.token,
            },
        }).done(function (data) {
            if (!data || !data.id) {
                return;
            }
            appendAndSelect($select, data.id, data.label || label);
        }).fail(function (xhr) {
            var message = (xhr.responseJSON && xhr.responseJSON.errors)
                ? [].concat(xhr.responseJSON.errors).join('\n')
                : xhr.statusText;
            Exment.CommonEvent.ShowSwal(null, {
                type: 'error',
                title: $button.data('quickadd-title'),
                text: message,
                showCancelButton: false,
            });
        });
    }

    function quickAdd(event) {
        var $button = $(event.currentTarget);
        var $select = $button.closest('.form-group').find('select').first();
        if (!$select.length) {
            return;
        }

        swal({
            title: $button.data('quickadd-title'),
            input: 'text',
            showCancelButton: true,
            confirmButtonText: LA.trans ? LA.trans('admin.submit') : 'OK',
            inputValidator: function (value) {
                return (value && value.trim() !== '') ? null : ' ';
            },
        }).then(function (result) {
            // sweetalert2 resolves with {value}; sweetalert1 resolves with the value
            var label = (result && typeof result === 'object') ? result.value : result;
            if (!label) {
                return;
            }
            post($button, $select, String(label).trim());
        }).catch(function () {
            // dismissed - nothing to do
        });
    }

    $(document)
        .off('click.exment_quickadd')
        .on('click.exment_quickadd', '[data-quickadd-url]', quickAdd);
})(jQuery);
