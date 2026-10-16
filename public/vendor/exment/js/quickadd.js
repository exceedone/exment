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

    // The server's own words when it sent some: "errors" holds validation
    // messages - {column: message} or lists of them - and an Exment error
    // carries {code, message}; both are translated. Anything else (an expired
    // session, a server error) only has the framework's English status text,
    // so the button's own message is shown instead.
    function errorText(xhr, fallback) {
        var json = xhr.responseJSON || {};
        var messages = [];
        (function collect(v) {
            if (typeof v === 'string') { messages.push(v); return; }
            if (v && typeof v === 'object') { $.each(v, function (k, x) { collect(x); }); }
        })(json.errors);
        if (messages.length) { return messages.join('\n'); }
        if (json.code != null && json.message) { return json.message; }
        return fallback;
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
            Exment.CommonEvent.ShowSwal(null, {
                type: 'error',
                title: $button.attr('data-quickadd-title'),
                text: errorText(xhr, $button.attr('data-quickadd-error')),
                confirm: $button.attr('data-quickadd-ok'),
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

        // titleText, not title: sweetalert2 renders a title as html, and this
        // one is a column name somebody typed
        swal({
            titleText: $button.attr('data-quickadd-title'),
            input: 'text',
            showCancelButton: true,
            confirmButtonText: $button.attr('data-quickadd-ok'),
            cancelButtonText: $button.attr('data-quickadd-cancel'),
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
