/**
 * Cell style presets on the column and view setting screens.
 *
 * Three jobs, all of them in the browser because they all react to typing:
 *
 *   - draw every entry of the preset dropdown in its own style, the way a
 *     word processor shows a font in that font. A preset is a look, and a
 *     list of names tells you nothing about a look.
 *   - keep a live sample next to the settings, so nobody has to save a
 *     column and open a list to find out what they built.
 *   - run the preset editor modal, including its two save buttons.
 *
 * The sample is a reimplementation of GridCellStyle::wrap() and it is meant
 * to stay one: a round trip per keystroke would make the screen unusable.
 * The grid remains the authority - anywhere the two disagree, the grid wins,
 * and the shapes below are the ones the stylesheet already defines.
 */
var Exment;
(function (Exment) {
    'use strict';

    var PALETTE = [
        '#c0392b', '#e67e22', '#f1c40f', '#27ae60', '#95a5a6',
        '#3c8dbc', '#8e44ad', '#16a085', '#d35400', '#2980b9'
    ];

    var AVATAR_PALETTE = [
        '#16a085', '#3c8dbc', '#e67e22', '#8e44ad', '#2980b9',
        '#c0392b', '#27ae60', '#d35400', '#7f8c8d', '#1abc9c'
    ];

    var FILLED_STYLES = ['tag', 'pill'];
    var MARKED_STYLES = ['dot', 'lvl'];

    var STYLE_KEYS = [
        'grid_style', 'grid_color', 'grid_bg_color', 'grid_border_color',
        'grid_font_weight', 'grid_icon', 'grid_nowrap', 'grid_value_colors'
    ];

    /**
     * What the icon picker offers.
     *
     * A curated list, not the whole font: the icon ends up in a data list
     * cell where it has to be recognised at a glance, and scrolling a
     * thousand glyphs to find "phone" helps nobody. The field stays a text
     * field underneath, so any other class can still be typed in.
     */
    var ICON_CHOICES = [
        'fa-check', 'fa-check-circle', 'fa-times', 'fa-times-circle', 'fa-ban',
        'fa-exclamation-triangle', 'fa-exclamation-circle', 'fa-question-circle', 'fa-info-circle', 'fa-bell',
        'fa-star', 'fa-heart', 'fa-thumbs-up', 'fa-thumbs-down', 'fa-flag',
        'fa-circle', 'fa-square', 'fa-bookmark', 'fa-tag', 'fa-tags',
        'fa-clock-o', 'fa-calendar', 'fa-hourglass-half', 'fa-history', 'fa-refresh',
        'fa-user', 'fa-users', 'fa-user-circle', 'fa-building', 'fa-home',
        'fa-phone', 'fa-envelope', 'fa-comment', 'fa-comments', 'fa-paper-plane',
        'fa-file-text-o', 'fa-files-o', 'fa-folder', 'fa-paperclip', 'fa-print',
        'fa-bolt', 'fa-fire', 'fa-bug', 'fa-wrench', 'fa-cog',
        'fa-database', 'fa-server', 'fa-desktop', 'fa-laptop', 'fa-mobile',
        'fa-key', 'fa-lock', 'fa-unlock', 'fa-shield', 'fa-eye',
        'fa-truck', 'fa-shopping-cart', 'fa-money', 'fa-credit-card', 'fa-barcode',
        'fa-line-chart', 'fa-bar-chart', 'fa-pie-chart', 'fa-tachometer', 'fa-list',
        'fa-map-marker', 'fa-globe', 'fa-link', 'fa-play', 'fa-pause'
    ];

    /** Preset definitions by endpoint, so one screen asks the server once. */
    var cache = {};

    class CellStylePresetEvent {
        /**
         * Called once per page load. Everything else hangs off delegated
         * events or the observer below, so a pjax swap or a new row in the
         * view column table is picked up without re-registering anything.
         */
        static AddEvent() {
            CellStylePresetEvent.bindAll();
            CellStylePresetEvent.watch();
        }

        /**
         * Delegated handlers. Registered once for the life of the page.
         */
        static AddEventOnce() {
            $(document).off('click.cellstyle', '.exm-preset-open')
                .on('click.cellstyle', '.exm-preset-open', function (ev) {
                    ev.preventDefault();
                    var $select = $(this).data('presetSelect');
                    CellStylePresetEvent.openEditor($(this), $select, $select ? String($select.val() || '') : '');
                });

            // The column type is picked on the same screen, and half the
            // library does not apply to a given type. Bound to the document
            // so the field of a form drawn later is covered too.
            $(document).off('change.cellstyletype', '[name="column_type"]')
                .on('change.cellstyletype', '[name="column_type"]', function () {
                    CellStylePresetEvent.refreshPickerOptions($(this).closest('form'));
                });

            // The same question on a view form, where the column is whatever
            // the row points at - so only that row's picker is concerned.
            $(document).off('change.cellstyletarget', 'select[name$="[view_column_target]"]')
                .on('change.cellstyletarget', 'select[name$="[view_column_target]"]', function () {
                    var $row = $(this).closest('tr');
                    CellStylePresetEvent.refreshPickerOptions($row.length ? $row : $(this).closest('form'));
                });

            // select2 selects an entry on mouseup, so the pencil has to be
            // taken out of the event before that handler ever sees it. A
            // capture listener on the document is the only place early
            // enough; a bubbling one would arrive after the selection.
            // Unlike the delegated handlers here it cannot be replaced by
            // re-registering, so it goes on exactly once.
            if (!CellStylePresetEvent._captureBound) {
                CellStylePresetEvent._captureBound = true;
                CellStylePresetEvent.watchPencil();
            }

            CellStylePresetEvent.bindEditorControls();
        }

        /**
         * Let the pencil inside a dropdown entry win over select2.
         */
        static watchPencil() {
            document.addEventListener('mouseup', function (ev) {
                var pencil = ev.target && ev.target.closest ? ev.target.closest('.exm-preset-edit') : null;
                if (!pencil) {
                    return;
                }
                ev.stopPropagation();
                ev.preventDefault();

                var $picker = $('select.exm-preset-picker').filter(function () {
                    return $(this).data('select2') && $(this).data('select2').isOpen();
                }).first();
                if ($picker.length) {
                    $picker.select2('close');
                }

                CellStylePresetEvent.openEditor($picker, $picker, String($(pencil).data('preset-key') || ''));
            }, true);
        }

        /**
         * The editor's own buttons and colour inputs.
         */
        static bindEditorControls() {
            $(document).off('click.cellstyle', '[data-preset-save]')
                .on('click.cellstyle', '[data-preset-save]', function (ev) {
                    ev.preventDefault();
                    CellStylePresetEvent.save($(this));
                });

            $(document).off('click.cellstyle', '.exm-preset-delete')
                .on('click.cellstyle', '.exm-preset-delete', function (ev) {
                    ev.preventDefault();
                    CellStylePresetEvent.remove($(this));
                });

            // Colour inputs: the text field is the value, the swatch is only
            // a way of typing into it. Keeping the text authoritative is what
            // lets "no colour at all" stay expressible - a native colour
            // input always holds one.
            $(document).off('input.cellstyle', '.exm-preset-color-swatch')
                .on('input.cellstyle change.cellstyle', '.exm-preset-color-swatch', function () {
                    $(this).siblings('.exm-preset-field').val($(this).val()).trigger('input');
                });

            $(document).off('input.cellstyle', '.exm-preset-color .exm-preset-field')
                .on('input.cellstyle', '.exm-preset-color .exm-preset-field', function () {
                    var color = CellStylePresetEvent.color($(this).val());
                    if (color) {
                        $(this).siblings('.exm-preset-color-swatch').val(color);
                    }
                });

            $(document).off('click.cellstyle', '.exm-preset-color-clear')
                .on('click.cellstyle', '.exm-preset-color-clear', function (ev) {
                    ev.preventDefault();
                    $(this).siblings('.exm-preset-field').val('').trigger('input');
                });
        }

        // --------------------------------------------------------- editor ---

        /**
         * Open the preset editor for one key, or for a brand new preset.
         */
        static openEditor($target, $select, key) {
            var endpoint = CellStylePresetEvent.endpoint();
            if (!hasValue(endpoint)) {
                return;
            }

            CellStylePresetEvent._opener = $select && $select.length ? $select : null;
            // A new preset starts from whatever is already on the screen; an
            // existing one is loaded from the server and must not be touched.
            CellStylePresetEvent._prefill = hasValue(key) ? null : CellStylePresetEvent.prefillFrom($select);

            Exment.ModalEvent.ShowModal($target && $target.length ? $target : $('body'), endpoint + '/modal', { key: key });
        }

        /**
         * Send the editor's fields to the server.
         *
         * Both buttons read the same fields; only the url differs, which is
         * the whole difference between "save a copy" and "rewrite this one".
         */
        static save($button) {
            var $modal = $button.closest('[data-preset-modal]');
            var endpoint = String($modal.data('preset-endpoint') || '');
            var key = String($modal.data('preset-key') || '');
            var update = $button.data('preset-save') === 'update';

            if (!endpoint.length || (update && !key.length)) {
                return;
            }

            var data = CellStylePresetEvent.collect($modal);
            data._token = LA.token;
            if (update) {
                data._method = 'PUT';
            }

            $button.prop('disabled', true);
            $.ajax({
                url: update ? endpoint + '/' + key : endpoint,
                method: 'POST',
                data: data
            }).done(function (res) {
                CellStylePresetEvent.afterWrite(res, res && res.preset ? res.preset.key : null);
            }).fail(function (res) {
                $button.prop('disabled', false);
                Exment.CommonEvent.CallbackExmentAjax(res);
            });
        }

        /**
         * Delete, confirmed by pressing the same button twice.
         *
         * A browser confirm would freeze the page behind a dialog this code
         * cannot dismiss; asking in the button itself keeps everything on
         * screen and reversible by simply not clicking again.
         */
        static remove($button) {
            var $modal = $button.closest('[data-preset-modal]');
            var endpoint = String($modal.data('preset-endpoint') || '');
            var key = String($modal.data('preset-key') || '');

            if (!endpoint.length || !key.length) {
                return;
            }

            if (!$button.hasClass('exm-preset-confirming')) {
                $button.addClass('exm-preset-confirming').data('presetLabel', $button.text());
                $button.text(String($button.data('confirm-label') || $button.text()));
                setTimeout(function () {
                    if ($button.hasClass('exm-preset-confirming')) {
                        $button.removeClass('exm-preset-confirming').text($button.data('presetLabel'));
                    }
                }, 4000);
                return;
            }

            $button.prop('disabled', true);
            $.ajax({
                url: endpoint + '/' + key,
                method: 'POST',
                data: { _token: LA.token, _method: 'DELETE' }
            }).done(function (res) {
                CellStylePresetEvent.afterWrite(res, null);
            }).fail(function (res) {
                $button.prop('disabled', false);
                Exment.CommonEvent.CallbackExmentAjax(res);
            });
        }

        /**
         * Close the editor and let every picker on the screen catch up.
         */
        static afterWrite(res, selectKey) {
            $('#modal-showmodal').modal('hide');
            CellStylePresetEvent.invalidate();

            var $opener = CellStylePresetEvent._opener;
            CellStylePresetEvent._opener = null;

            if ($opener && $opener.length && hasValue(selectKey)) {
                // Set before rebinding: syncOptions keeps whatever is
                // selected, so the new preset is there when the list is
                // rebuilt even though its option does not exist yet.
                $opener.append($('<option>').val(selectKey)).val(selectKey);
            }

            CellStylePresetEvent.rebindAll();

            if (res && res.toastr && typeof toastr !== 'undefined') {
                toastr.success(res.toastr);
            }
        }

        /**
         * Narrow every picker of a form to the column type now chosen.
         *
         * Cheaper than rebindAll and it keeps the select2 instances alive:
         * select2 reads the options out of the element each time the list is
         * opened, so replacing them is enough.
         */
        static refreshPickerOptions($form) {
            if (!$form || !$form.length) {
                return;
            }

            $form.find('select.exm-preset-picker').each(function () {
                var $select = $(this);
                var map = $select.data('presetMap') || {};
                var presets = [];
                for (var key in map) {
                    presets.push(map[key]);
                }
                if (!presets.length) {
                    return;
                }

                // Dropped before the list is rebuilt, not after: syncOptions
                // deliberately keeps whatever is selected, so that a value
                // saved earlier is never thrown away behind the user's back.
                // Here the user is choosing the type themselves, and a preset
                // that cannot paint it has to go.
                var type = CellStylePresetEvent.columnType($select);
                var current = map[String($select.val() || '')];
                var types = current ? (current.column_types || []) : [];

                if (current) {
                    // something is picked, so nothing is being held for later
                    $select.removeData('presetDropped');
                }
                if (current && hasValue(type) && types.length && types.indexOf(type) < 0) {
                    // Put aside rather than forgotten. Pointing a row at the
                    // wrong column and correcting it a second later should
                    // not cost the preset that was chosen before the slip.
                    $select.data('presetDropped', String($select.val() || ''));
                    $select.val('');
                }

                // The one put aside earlier, if the column it could not
                // paint has been pointed somewhere it can. Chosen here but
                // selected after the list is rebuilt: a value no option
                // carries yet does not stick.
                var dropped = map[String($select.data('presetDropped') || '')];
                var back = dropped ? (dropped.column_types || []) : [];
                var restore = (dropped && !hasValue($select.val())
                    && (!hasValue(type) || !back.length || back.indexOf(type) >= 0)) ? dropped.key : null;

                CellStylePresetEvent.syncOptions($select, presets);

                if (hasValue(restore) && !hasValue($select.val())) {
                    $select.val(restore);
                    $select.removeData('presetDropped');
                }

                $select.trigger('change');
            });
        }

        /**
         * Rebuild every picker from a fresh copy of the library.
         */
        static rebindAll() {
            $('select.exm-preset-picker').each(function () {
                var $select = $(this);
                if ($select.data('select2')) {
                    $select.select2('destroy');
                }
                $select.removeClass('exm-preset-bound exm-preset-picker admin-added-select2');

                // Unwrap so the next binding does not nest a wrapper in a
                // wrapper and leave a second pencil behind. Siblings rather
                // than next(): destroying select2 moves elements around, and
                // the wrapper is not always the very next node afterwards.
                $select.siblings('.exm-preset-picker-wrap').remove();
            });

            $('[data-cellstyle-preview]').removeClass('exm-preset-bound');
            CellStylePresetEvent.bindAll();
        }

        /**
         * @return every field of the editor, ready to post
         */
        static collect($modal) {
            var data = { column_types: [] };

            $modal.find('.exm-preset-field').each(function () {
                var $field = $(this);
                var name = $field.attr('name');
                if (!hasValue(name)) {
                    return;
                }
                data[name] = $field.attr('type') === 'checkbox'
                    ? ($field.is(':checked') ? '1' : '')
                    : $field.val();
            });

            $modal.find('input[name="column_types"]:checked').each(function () {
                data.column_types.push($(this).val());
            });

            return data;
        }

        // ---------------------------------------------------------- boot ---

        /**
         * Wire every picker and preview that is not wired yet.
         */
        static bindAll() {
            var $pickers = $('select[data-cellstyle-preset]').not('.exm-preset-bound');
            var $previews = $('[data-cellstyle-preview]').not('.exm-preset-bound');
            var $vcolors = $('[data-cellstyle-valuecolors]').not('.exm-preset-bound');
            var $icons = $('[data-cellstyle-iconpicker]').not('.exm-preset-bound');
            var $editors = $('[data-preset-modal]').not('.exm-preset-bound');

            if (!$pickers.length && !$previews.length && !$vcolors.length && !$icons.length && !$editors.length) {
                return;
            }

            // The editor needs no library, and it has to be filled in before
            // the preview inside it is first drawn.
            $editors.addClass('exm-preset-bound').each(function () {
                CellStylePresetEvent.applyPrefill($(this));
            });

            // Before the fetch, and without needing it: a preset editor has
            // no picker to ask, so the style its table follows is a field of
            // the editor itself. The refresh after the fetch below catches
            // the column screen, where the picker does decide.
            $vcolors.addClass('exm-preset-bound').each(function () {
                CellStylePresetEvent.bindValueColors($(this));
            });

            $icons.addClass('exm-preset-bound').each(function () {
                CellStylePresetEvent.bindIconPicker($(this));
            });

            var endpoint = CellStylePresetEvent.endpoint();
            if (!hasValue(endpoint)) {
                return;
            }

            // Claim them now, not in the callback. The definitions arrive
            // over the network, and inserting the picker's own markup wakes
            // the observer below - a second scan would otherwise find these
            // same elements still unclaimed and bind every one of them twice.
            $pickers.addClass('exm-preset-bound');
            $previews.addClass('exm-preset-bound');

            CellStylePresetEvent.load(endpoint, function (presets) {
                $pickers.each(function () {
                    CellStylePresetEvent.bindPicker($(this), presets);
                });
                $previews.each(function () {
                    CellStylePresetEvent.bindPreview($(this), presets);
                });

                // A picker only now bound can change the style in effect, so
                // every table takes a fresh look once they all are.
                $('.exm-vc-src').each(function () {
                    CellStylePresetEvent.refreshValueColors($(this));
                });
            });
        }

        /**
         * New rows of the view column table arrive from a template, long
         * after this file ran. Watching the document is simpler than racing
         * the handler that appends them, and costs nothing on the pages that
         * have no picker at all.
         */
        static watch() {
            if (CellStylePresetEvent._observer || typeof MutationObserver === 'undefined') {
                return;
            }

            var pending = null;
            CellStylePresetEvent._observer = new MutationObserver(function () {
                if (pending) {
                    return;
                }
                // One pass per burst: appending a row fires several mutations
                // and each one would otherwise start its own scan.
                pending = setTimeout(function () {
                    pending = null;
                    CellStylePresetEvent.bindAll();
                }, 60);
            });

            CellStylePresetEvent._observer.observe(document.body, { childList: true, subtree: true });
        }

        /**
         * The api base url, taken from any picker on the page.
         */
        static endpoint() {
            var table = $('[data-cellstyle-preset]').first().data('cellstyle-preset');
            if (!hasValue(table)) {
                return null;
            }

            return admin_url(URLJoin('webapi', String(table), 'cellstylepreset'));
        }

        /**
         * Fetch the preset definitions once per endpoint.
         */
        static load(endpoint, callback) {
            if (cache[endpoint]) {
                callback(cache[endpoint]);
                return;
            }
            if (CellStylePresetEvent._waiting) {
                CellStylePresetEvent._waiting.push(callback);
                return;
            }

            CellStylePresetEvent._waiting = [callback];
            $.get(endpoint).done(function (res) {
                cache[endpoint] = (res && res.presets) ? res.presets : [];
                var waiting = CellStylePresetEvent._waiting || [];
                CellStylePresetEvent._waiting = null;
                for (var i = 0; i < waiting.length; i++) {
                    waiting[i](cache[endpoint]);
                }
            }).fail(function () {
                CellStylePresetEvent._waiting = null;
            });
        }

        /**
         * Drop the cached definitions after an edit, so every picker on the
         * screen sees the new list the next time it is rebuilt.
         */
        static invalidate() {
            cache = {};
        }

        // -------------------------------------------------------- picker ---

        /**
         * Turn one preset select into the styled dropdown.
         */
        static bindPicker($select, presets) {
            var byKey = {};
            for (var i = 0; i < presets.length; i++) {
                byKey[presets[i].key] = presets[i];
            }
            $select.data('presetMap', byKey);

            // The column screen knows which column is being edited, so it can
            // hide the presets that make no sense on it. A view column row
            // does not - each row is a different column - so there the whole
            // library stays visible.
            CellStylePresetEvent.syncOptions($select, presets);

            // Ask the element, not its class list. laravel-admin tags a
            // select with admin-added-select2 and something else may have
            // torn the instance down since; destroying a select2 that is not
            // there throws and would take the rest of the pickers with it.
            if ($select.data('select2')) {
                $select.select2('destroy');
            }

            $select.select2({
                width: '100%',
                allowClear: true,
                placeholder: { id: '', text: String($select.data('cellstyle-preset-placeholder') || ' ') },
                dropdownParent: $select.closest('#modal-showmodal').length
                    ? $('#modal-showmodal .modal-dialog') : null,
                // the kanban card table gives this picker a fifth of the row,
                // which cuts the chips the list is read by in half
                dropdownCssClass: 'exm-preset-dropdown',
                templateResult: function (state) {
                    return CellStylePresetEvent.optionHtml(state, byKey, true);
                },
                templateSelection: function (state) {
                    return CellStylePresetEvent.optionHtml(state, byKey, false);
                }
            }).addClass('admin-added-select2 exm-preset-picker');

            $select.off('change.cellstyle').on('change.cellstyle', function () {
                CellStylePresetEvent.refreshPreviews();
                $('.exm-vc-src').each(function () {
                    CellStylePresetEvent.refreshValueColors($(this));
                });
            });

            CellStylePresetEvent.addOpenButton($select);
        }

        /**
         * The button that opens the editor.
         *
         * It edits whatever is selected, and starts an empty preset when
         * nothing is - one control for both, because a row of the view column
         * table has room for exactly one.
         */
        static addOpenButton($select) {
            var $container = $select.next('.select2-container');
            if (!$container.length || $select.siblings('.exm-preset-picker-wrap').length) {
                return;
            }

            var label = String($select.data('cellstyle-preset-label') || '');
            var $button = $('<button>', {
                'type': 'button',
                'class': 'btn btn-default exm-preset-open',
                'title': label,
                'aria-label': label
            }).append($('<i>', { 'class': 'fa fa-pencil' }));

            // The select2 container fills its cell, so the two only sit side
            // by side inside a wrapper of their own.
            var $wrap = $('<div>', { 'class': 'exm-preset-picker-wrap' });
            $container.before($wrap);
            $wrap.append($container).append($button);

            $button.data('presetSelect', $select);
        }

        /**
         * Keep the option list in step with the definitions.
         *
         * Rebuilding rather than patching: after an edit the names, the order
         * and the membership can all have changed, and the selected value has
         * to survive all three.
         */
        static syncOptions($select, presets) {
            var current = $select.val();
            var column_type = CellStylePresetEvent.columnType($select);

            $select.empty().append($('<option>').val(''));

            for (var i = 0; i < presets.length; i++) {
                var preset = presets[i];
                var types = preset.column_types || [];
                var fits = !column_type || !types.length || types.indexOf(column_type) >= 0;

                // A preset already chosen stays listed even when it does not
                // fit, or saving the form would silently throw it away.
                if (!fits && preset.key !== current) {
                    continue;
                }

                $select.append($('<option>').val(preset.key).text(preset.name));
            }

            if (hasValue(current)) {
                $select.val(current);
            }
        }

        /**
         * One dropdown entry: the preset drawn in its own style, its name,
         * and - for a preset this user owns - a pencil that opens the editor.
         */
        static optionHtml(state, byKey, withPencil) {
            if (!state || !state.id) {
                return state ? state.text : '';
            }

            var preset = byKey[state.id];
            if (!preset) {
                return state.text;
            }

            var $row = $('<span>', { 'class': 'exm-preset-option' });
            $row.append(CellStylePresetEvent.sample(preset.options, CellStylePresetEvent.sampleValueFor(preset.options), preset.name));

            // A progress bar has nowhere to put a name - it draws a number.
            // Every other shape carries the name as its own text, so only
            // this one needs a label beside it.
            if (String(preset.options.grid_style || '') === 'bar') {
                $row.append($('<span>', { 'class': 'exm-preset-option-name', text: preset.name }));
            }

            if (withPencil) {
                // Built-ins open the editor too - as a starting point for a
                // copy - so the pencil is always offered in the list.
                $row.append($('<i>', {
                    'class': 'fa fa-pencil exm-preset-edit',
                    'data-preset-key': preset.key
                }));
            }

            return $row;
        }

        /**
         * A value that makes one preset show what it is for.
         *
         * A preset carrying its own per-value colours is about those colours,
         * so the chip is drawn as its first value rather than as an unset one
         * - otherwise every priority and threshold preset would appear in the
         * same neutral grey and the list would say nothing.
         */
        static sampleValueFor(options) {
            if (String(options.grid_style || '') === 'bar') {
                return 70;
            }

            var keys = Object.keys(CellStylePresetEvent.valueColors(options));

            return keys.length ? keys[0] : null;
        }

        /**
         * The column type being edited, when the screen knows it.
         */
        static columnType($select) {
            // A view form has no column on it: every row points at one, and
            // the picker carries a map saying what type each target is. A
            // target the map says nothing about - a system column, the
            // workflow status, the parent record - answers "no type", which
            // offers the whole library rather than none of it.
            var types = $select.data('cellstyleTypes');
            if (types) {
                var $row = $select.closest('tr');
                var $target = ($row.length ? $row : $select.closest('form'))
                    .find('select[name$="[view_column_target]"]').first();

                return String(types[String($target.val() || '')] || '');
            }

            var $form = $select.closest('form');
            var $fields = $form.find('[name="column_type"]');
            if (!$fields.length) {
                return null;
            }

            // Not the first: laravel-admin renders a hidden companion ahead
            // of a select and never writes the choice into it, so reading
            // that one answered "no type" for every column being created -
            // and the whole library stayed on offer whatever was picked.
            var type = '';
            $fields.each(function () {
                var value = String($(this).val() || '');
                if (value.length) {
                    type = value;
                }
            });

            return type;
        }

        // ------------------------------------------------- value colors ---

        /**
         * Turn the per-value color textarea into a small table.
         *
         * The rows come from the column's own choices, so coloring a value
         * means picking a color next to its name instead of typing
         * "key,#hex" lines from memory. The textarea stays the stored value
         * - the table only reads and rewrites it - so a column saved from
         * the old screen loses nothing, and the setting is still editable
         * by hand should this script never run.
         */
        static bindValueColors($src) {
            if ($src.hasClass('exm-vc-src')) {
                return;
            }

            // The preset editor is not a form - it never posts - so a table
            // takes whichever of the two encloses it as its scope, and the
            // editor is asked first: it can itself sit inside a form.
            var $scope = $src.closest('.exm-preset-modal');
            if (!$scope.length) {
                $scope = $src.closest('form');
            }
            if (!$scope.length) {
                return;
            }

            $src.addClass('exm-vc-src').data('vcScope', $scope).hide();

            var labels = $src.data('cellstyle-labels') || {};
            var $box = $('<div>', { 'class': 'exm-vc' });
            var $table = $('<table>', { 'class': 'exm-vc-table' }).appendTo($box);
            var $add = $('<button>', { 'type': 'button', 'class': 'btn btn-sm btn-default exm-vc-add' })
                .append($('<i>', { 'class': 'fa fa-plus' }))
                .append(document.createTextNode(' ' + String(labels.add || '')));
            $box.append($add);
            $src.after($box);

            // Edits inside the table only serialize - rebuilding the rows on
            // the same keystroke would throw the cursor away.
            $box.on('input.cellstyle change.cellstyle', 'input', function () {
                var $input = $(this);
                if ($input.hasClass('exm-vc-swatch')) {
                    $input.siblings('.exm-vc-hex').val($input.val());
                } else if ($input.hasClass('exm-vc-hex')) {
                    var color = CellStylePresetEvent.color($input.val());
                    if (color) {
                        $input.siblings('.exm-vc-swatch').val(color);
                    }
                } else if ($input.hasClass('exm-vc-key')) {
                    CellStylePresetEvent.refreshRowLabel($input.closest('tr'), $scope, labels);
                }
                CellStylePresetEvent.serializeValueColors($src);
            });
            $box.on('click.cellstyle', '.exm-vc-clear', function (ev) {
                ev.preventDefault();
                $(this).closest('tr').find('.exm-vc-hex').val('');
                CellStylePresetEvent.serializeValueColors($src);
            });
            $box.on('click.cellstyle', '.exm-vc-remove', function (ev) {
                ev.preventDefault();
                $(this).closest('tr').remove();
                CellStylePresetEvent.serializeValueColors($src);
            });
            $add.on('click.cellstyle', function (ev) {
                ev.preventDefault();
                var labeled = $table.find('th').length > 3;
                var $row = CellStylePresetEvent.valueColorRow('', labeled ? '' : null, {}, labels, true, labeled);
                $table.append($row);
                CellStylePresetEvent.refreshRowLabel($row, $scope, labels);
                $row.find('.exm-vc-key').trigger('focus');
            });

            // The sources of the rows - the choices, the type, the preset -
            // can all change while the screen is open. Not `.cellstyle`:
            // bindPreview clears that namespace on this same scope.
            $scope.off('input.csvc change.csvc').on('input.csvc change.csvc', function (ev) {
                if ($(ev.target).closest('.exm-vc').length) {
                    return;
                }
                clearTimeout($src.data('vcTimer'));
                $src.data('vcTimer', setTimeout(function () {
                    CellStylePresetEvent.refreshValueColors($src);
                }, 200));
            });

            CellStylePresetEvent.refreshValueColors($src);
        }

        /**
         * Turn the icon field into a picker.
         *
         * The field stores a font-awesome class and nothing else knows which
         * classes exist, so typing one from memory was the only way in. The
         * text field stays underneath - it is still what gets posted, and an
         * icon outside the list below can still be written by hand.
         */
        static bindIconPicker($input) {
            var labels = $input.data('cellstyle-iconlabels') || {};

            var $box = $('<div>', { 'class': 'exm-iconpick' });
            var $toggle = $('<button>', {
                'type': 'button', 'class': 'btn btn-default exm-iconpick-toggle', 'title': String(labels.pick || '')
            }).append($('<i>'));
            var $clear = $('<button>', {
                'type': 'button', 'class': 'btn btn-default exm-iconpick-clear',
                'title': String(labels.clear || ''), 'html': '&times;'
            });

            // A block, not a floating menu: inside a modal a positioned panel
            // has to win a z-index argument it cannot see, and pushing the
            // fields below it down for a moment costs nothing here.
            var $panel = $('<div>', { 'class': 'exm-iconpick-panel' }).hide();
            for (var i = 0; i < ICON_CHOICES.length; i++) {
                $panel.append($('<button>', {
                    'type': 'button', 'class': 'btn btn-default exm-iconpick-item',
                    'data-icon': ICON_CHOICES[i], 'title': ICON_CHOICES[i]
                }).append($('<i>', { 'class': 'fa ' + ICON_CHOICES[i] })));
            }

            $input.after($box);
            $box.append($toggle).append($input).append($clear).append($panel);

            var show = function () {
                var current = $.trim(String($input.val() || ''));
                var $i = $toggle.find('i');
                $i.attr('class', current.length ? 'fa ' + current : 'fa fa-ellipsis-h exm-iconpick-none');
                $panel.find('.exm-iconpick-item').removeClass('active')
                    .filter('[data-icon="' + current + '"]').addClass('active');
            };

            $toggle.on('click.cellstyle', function (ev) {
                ev.preventDefault();
                $panel.toggle();
            });
            $panel.on('click.cellstyle', '.exm-iconpick-item', function (ev) {
                ev.preventDefault();
                $input.val(String($(this).data('icon'))).trigger('input').trigger('change');
                $panel.hide();
                show();
            });
            $clear.on('click.cellstyle', function (ev) {
                ev.preventDefault();
                $input.val('').trigger('input').trigger('change');
                $panel.hide();
                show();
            });
            $input.on('input.cellstyle change.cellstyle', show);

            show();
        }

        /**
         * Rebuild the rows of one table from the current choices, and show
         * or hide the whole setting: it exists only while the column's type
         * has listable values and the style in effect colors by value.
         */
        static refreshValueColors($src) {
            var $scope = $src.data('vcScope');
            var $box = $src.siblings('.exm-vc').first();
            if (!$scope || !$scope.length || !$box.length) {
                return;
            }

            var labels = $src.data('cellstyle-labels') || {};
            var $table = $box.find('.exm-vc-table').first();
            var $group = $src.closest('.form-group, .exm-preset-row');
            var type = String($scope.find('[name="column_type"]').first().val() || '');
            var options = CellStylePresetEvent.resolvedOptions($scope);
            var style = String(options.grid_style || 'plain');

            // No column type in reach means a preset is being edited, not a
            // column: the values it colors belong to whichever column picks
            // the preset up, so they are typed here instead of listed.
            var mode = type.length
                ? CellStylePresetEvent.valueColorsMode(type, style)
                : CellStylePresetEvent.presetValueColorsMode(style);

            if (!mode) {
                $group.hide();
                return;
            }
            $group.show();

            var existing = CellStylePresetEvent.valueLines($src.val());
            $table.empty();

            var labeled = mode === 'labeled';
            var $head = $('<tr>');
            $head.append($('<th>', { text: String(mode === 'bar' ? (labels.threshold || '') : (labels.value || '')) }));
            if (labeled) {
                $head.append($('<th>', { text: String(labels.label || '') }));
            }
            $head.append($('<th>', { text: String(labels.color || '') }));
            $head.append($('<th>'));
            $table.append($head);

            var used = {};
            var choices = (mode === 'plain' || mode === 'labeled') ? CellStylePresetEvent.choiceList($scope) : [];
            for (var i = 0; i < choices.length; i++) {
                var key = String(choices[i].value);
                $table.append(CellStylePresetEvent.valueColorRow(
                    key, labeled ? String(choices[i].text) : null, existing[key] || {}, labels, false, labeled,
                    // what this value is painted with while no colour is
                    // named here - the same answer the grid would reach
                    CellStylePresetEvent.colorFor(options, key, i)));
                used[key] = true;
            }

            // Lines stored under no choice above - thresholds, select_table
            // ids, values removed from the list since - stay editable here
            // instead of silently vanishing on the next save.
            for (var stored in existing) {
                if (!used[stored]) {
                    $table.append(CellStylePresetEvent.valueColorRow(stored, labeled ? '' : null, existing[stored], labels, true, labeled));
                }
            }

            if (!$table.find('tr.exm-vc-row').length) {
                $table.append(CellStylePresetEvent.valueColorRow('', labeled ? '' : null, {}, labels, true, labeled));
            }

            $table.find('tr.exm-vc-row').each(function () {
                CellStylePresetEvent.refreshRowLabel($(this), $scope, labels);
            });
        }

        /**
         * Keep the name cell of a hand-typed row in step with what is typed.
         *
         * The name of a value belongs to the column's choices and is not
         * stored with the colors, so this cell is never an input: it mirrors
         * the choice the typed value matches, and says so when it matches
         * none - which is the answer to "why can I not type a name here".
         *
         * @param $tr    the row
         * @param $form  the column setting form, holding the choices
         * @param labels texts handed over by the server
         */
        static refreshRowLabel($tr, $form, labels) {
            var $cell = $tr.find('td.exm-vc-label[data-vc-auto]').first();
            if (!$cell.length) {
                return;
            }

            var key = $.trim(String($tr.find('.exm-vc-key').val() || ''));
            var choices = CellStylePresetEvent.choiceList($form);
            for (var i = 0; i < choices.length; i++) {
                if (String(choices[i].value) === key) {
                    $cell.removeClass('exm-vc-label-missing').text(String(choices[i].text));
                    return;
                }
            }

            $cell.addClass('exm-vc-label-missing').text(key.length ? String(labels.unknown || '') : '');
        }

        /**
         * How the table works for this combination, or null to hide it.
         *
         *   labeled - one row per choice, the value next to its display name
         *   plain   - one row per choice, the value is the name
         *   manual  - rows typed by hand (a select over a table stores ids
         *             this screen cannot enumerate)
         *   bar     - rows are the thresholds of the progress bar
         */
        static valueColorsMode(type, style) {
            if (['integer', 'decimal', 'currency'].indexOf(type) >= 0) {
                return style === 'bar' ? 'bar' : null;
            }
            if (['text', 'tag', 'pill', 'badge', 'dot', 'lvl', 'mono'].indexOf(style) < 0) {
                return null;
            }
            if (type === 'select_valtext' || type === 'yesno' || type === 'boolean') {
                return 'labeled';
            }
            if (type === 'select') {
                return 'plain';
            }
            if (type === 'select_table') {
                return 'manual';
            }

            return null;
        }

        /**
         * The same question for a preset editor, which has no column type.
         *
         * A preset is shared, so its values are typed by hand - but only
         * where they change anything: the plain style paints nothing and
         * the cell style paints the whole cell, one color for every value.
         */
        static presetValueColorsMode(style) {
            if (style === 'bar') {
                return 'bar';
            }
            if (['text', 'tag', 'pill', 'badge', 'dot', 'lvl', 'mono', 'avatar'].indexOf(style) < 0) {
                return null;
            }

            return 'manual';
        }

        /**
         * One row of the table.
         *
         * @param key     stored value
         * @param label   display name, or null when the table has no name column
         * @param row     {color, tail} parsed from the stored line
         * @param labels  texts handed over by the server
         * @param manual  the key is typed by hand and the row can be removed
         * @param labeled the table carries a name column
         */
        static valueColorRow(key, label, row, labels, manual, labeled, auto) {
            var $tr = $('<tr>', { 'class': 'exm-vc-row' });
            $tr.data('vcTail', row.tail || []);

            var $keycell = $('<td>');
            if (manual) {
                $keycell.append($('<input>', {
                    'type': 'text', 'class': 'form-control input-sm exm-vc-key', 'value': key
                }));
            } else {
                $keycell.attr('data-key', key).append($('<span>', { 'class': 'exm-vc-keytext', text: key }));
            }
            $tr.append($keycell);

            if (labeled) {
                var $labelcell = $('<td>', { 'class': 'exm-vc-label', text: label === null ? '' : String(label) });
                if (manual) {
                    // Not an input: the name lives in the choice list, and a
                    // name typed here would have nowhere to be stored.
                    $labelcell.attr('data-vc-auto', '1');
                }
                $tr.append($labelcell);
            }

            // The swatch of a value nobody has coloured shows the colour it
            // is drawn with anyway - the palette hands one out by position.
            // A fixed blue there said the opposite of the preview right below.
            var color = row.color || '';
            $tr.append($('<td>', { 'class': 'exm-vc-colorcell' })
                .append($('<input>', {
                    'type': 'color', 'class': 'exm-vc-swatch', 'tabindex': -1,
                    'value': color || auto || '#3c8dbc'
                }))
                .append($('<input>', {
                    'type': 'text', 'class': 'form-control input-sm exm-vc-hex', 'maxlength': 7,
                    'value': color, 'placeholder': String(labels.auto || '')
                })));

            var $actions = $('<td>', { 'class': 'exm-vc-actions' });
            if (manual) {
                $actions.append($('<button>', {
                    'type': 'button', 'class': 'btn btn-xs btn-default exm-vc-remove', 'title': String(labels.remove || '')
                }).append($('<i>', { 'class': 'fa fa-trash-o' })));
            } else {
                $actions.append($('<button>', {
                    'type': 'button', 'class': 'btn btn-xs btn-default exm-vc-clear', 'title': String(labels.clear || ''), 'html': '&times;'
                }));
            }
            $tr.append($actions);

            return $tr;
        }

        /**
         * Write the rows back into the textarea, in the stored format.
         *
         * A row with no usable color is left out - an uncolored value is the
         * palette's job - unless its old line carried a background or border
         * in the tail, which opening this screen must not lose.
         */
        static serializeValueColors($src) {
            var $table = $src.siblings('.exm-vc').find('.exm-vc-table').first();
            var lines = [];

            $table.find('tr.exm-vc-row').each(function () {
                var $tr = $(this);
                var $key = $tr.find('.exm-vc-key');
                var key = $key.length
                    ? $.trim(String($key.val() || ''))
                    : String($tr.children('td').first().data('key') || '');
                if (!key.length) {
                    return;
                }

                var color = CellStylePresetEvent.color($tr.find('.exm-vc-hex').val());
                var tail = $tr.data('vcTail') || [];
                if (!color && !tail.length) {
                    return;
                }

                lines.push([key, color || ''].concat(tail).join(','));
            });

            $src.val(lines.join('\n'));
            CellStylePresetEvent.refreshPreviews();
        }

        /**
         * Parse the stored lines into {key: {color, tail}}.
         *
         * The tail keeps whatever followed the first color - the background
         * and border entries the old screen accepted - so a rewrite by this
         * table never drops a part it does not edit.
         */
        static valueLines(text) {
            var rows = {};
            var lines = String(text || '').split(/\r\n|\r|\n/);

            for (var i = 0; i < lines.length; i++) {
                var parts = lines[i].split(',');
                if (parts.length < 2) {
                    continue;
                }
                var key = $.trim(parts.shift());
                if (!key.length) {
                    continue;
                }
                var tail = [];
                for (var t = 1; t < parts.length; t++) {
                    var part = $.trim(parts[t]);
                    if (part.length) {
                        tail.push(part);
                    }
                }
                rows[key] = { color: CellStylePresetEvent.color(parts[0]) || '', tail: tail };
            }

            return rows;
        }

        /**
         * The style the grid would use right now: the picked preset's, or
         * what the column saved before presets existed.
         */
        static resolvedStyle($scope) {
            return String(CellStylePresetEvent.resolvedOptions($scope).grid_style || 'plain');
        }

        /**
         * Every setting in effect on this screen, in the order the renderer
         * reads them: the preset a picker names, then the raw fields a column
         * styled before presets still carries, then - in the preset editor,
         * which has no picker - the fields being edited.
         */
        static resolvedOptions($scope) {
            var $picker = $scope.find('select[data-cellstyle-preset]').first();
            if ($picker.length) {
                var map = $picker.data('presetMap') || {};
                var preset = map[String($picker.val() || '')];
                if (preset) {
                    // The colours stay on the column whichever preset gives
                    // the shape, so they are read from the column either way.
                    var merged = $.extend({}, preset.options);
                    var colors = CellStylePresetEvent.fieldValue($scope, 'grid_value_colors');
                    if (hasValue(colors)) {
                        merged.grid_value_colors = colors;
                    }
                    return merged;
                }
            }

            var legacy = CellStylePresetEvent.legacySource($scope);
            if (hasValue(legacy.grid_style)) {
                return legacy;
            }

            var flat = {};
            for (var i = 0; i < STYLE_KEYS.length; i++) {
                var value = CellStylePresetEvent.fieldValue($scope, STYLE_KEYS[i]);
                if (hasValue(value)) {
                    flat[STYLE_KEYS[i]] = value;
                }
            }

            return flat;
        }

        /**
         * The raw settings a column saved before presets existed, read from
         * the hidden fields that carry them through the form unseen.
         */
        static legacySource($scope) {
            var keys = ['grid_style', 'grid_color', 'grid_bg_color', 'grid_border_color', 'grid_font_weight', 'grid_icon', 'grid_nowrap'];
            var source = {};

            for (var i = 0; i < keys.length; i++) {
                var $field = $scope.find('[name="options[' + keys[i] + ']"]').first();
                if ($field.length && hasValue($field.val())) {
                    source[keys[i]] = $field.val();
                }
            }

            return source;
        }

        /**
         * What a brand new preset should start from.
         *
         * The column screen no longer edits raw fields, but a column styled
         * by hand before presets existed still carries them - and promoting
         * that look into a preset is exactly how such a column joins the
         * preset world without anybody retyping it.
         */
        static prefillFrom($select) {
            if (!$select || !$select.length) {
                return null;
            }

            var $form = $select.closest('form');
            if (!$form.length) {
                return null;
            }

            var source = CellStylePresetEvent.legacySource($form);
            var value_colors = CellStylePresetEvent.fieldValue($form, 'grid_value_colors');
            if (hasValue(value_colors)) {
                source.grid_value_colors = value_colors;
            }

            var data = { options: {}, column_types: [] };
            var found = false;
            for (var key in source) {
                if (!CellStylePresetEvent.isFilledIn(key, source[key])) {
                    continue;
                }
                data.options[key] = source[key];
                found = true;
            }

            // Nothing to carry over: the view screen has no such fields at
            // all, and an untouched column has nothing worth keeping.
            if (!found) {
                return null;
            }

            var type = CellStylePresetEvent.columnType($select);
            if (hasValue(type)) {
                data.column_types.push(String(type));
            }

            var name = CellStylePresetEvent.fieldValue($form, 'column_view_name');
            if (hasValue(name)) {
                data.name = String(name);
            }

            return data;
        }

        /**
         * Put those settings into the editor, once.
         */
        static applyPrefill($modal) {
            var data = CellStylePresetEvent._prefill;
            CellStylePresetEvent._prefill = null;

            if (!data || hasValue($modal.data('preset-key'))) {
                return;
            }

            for (var key in data.options) {
                var $field = $modal.find('.exm-preset-field[name="' + key + '"]').first();
                if (!$field.length) {
                    continue;
                }
                if ($field.attr('type') === 'checkbox') {
                    $field.prop('checked', String(data.options[key]) === '1');
                    continue;
                }

                $field.val(data.options[key]);

                var color = CellStylePresetEvent.color($field.val());
                if (color) {
                    $field.siblings('.exm-preset-color-swatch').val(color);
                }
            }

            for (var i = 0; i < data.column_types.length; i++) {
                $modal.find('input[name="column_types"][value="' + data.column_types[i] + '"]')
                    .prop('checked', true);
            }

            if (hasValue(data.name)) {
                $modal.find('.exm-preset-field[name="preset_name"]').val(data.name);
            }
        }

        // ------------------------------------------------------- previews ---

        /**
         * Wire one preview panel to the settings around it.
         */
        static bindPreview($panel, presets) {
            var $scope = $panel.closest('.exm-preset-modal');
            if (!$scope.length) {
                $scope = $panel.closest('form');
            }
            $panel.data('presetScope', $scope);

            $scope.off('input.cellstyle change.cellstyle').on('input.cellstyle change.cellstyle', function () {
                CellStylePresetEvent.drawPreview($panel);
            });

            CellStylePresetEvent.drawPreview($panel);
        }

        static refreshPreviews() {
            $('[data-cellstyle-preview]').each(function () {
                CellStylePresetEvent.drawPreview($(this));
            });
        }

        /**
         * Paint the samples of one preview panel.
         */
        static drawPreview($panel) {
            var $scope = $panel.data('presetScope');
            if (!$scope || !$scope.length) {
                return;
            }

            var options = CellStylePresetEvent.readSettings($scope);
            var values = CellStylePresetEvent.sampleValues($scope, options);
            var $body = $panel.find('.exm-cellstyle-preview-body').empty();

            for (var i = 0; i < values.length; i++) {
                $body.append(CellStylePresetEvent.sample(options, values[i].value, values[i].text, i));
            }
        }

        /**
         * Read the styling currently on screen.
         *
         * The preset editor's own fields are everything it has. The column
         * screen follows the server's rule instead: the picked preset is
         * the look (with no preset, whatever the column saved before
         * presets existed), and the column adds only its per-value colors
         * on top - exactly GridCellStyle::resolveSource, or the sample
         * would contradict the saved list.
         */
        static readSettings($scope) {
            var $picker = $scope.find('select[data-cellstyle-preset]').first();
            if (!$picker.length) {
                var own = {};
                for (var i = 0; i < STYLE_KEYS.length; i++) {
                    own[STYLE_KEYS[i]] = CellStylePresetEvent.fieldValue($scope, STYLE_KEYS[i]);
                }
                return own;
            }

            var map = $picker.data('presetMap') || {};
            var preset = map[String($picker.val() || '')];
            var base = preset ? $.extend({}, preset.options) : CellStylePresetEvent.legacySource($scope);

            var value_colors = CellStylePresetEvent.fieldValue($scope, 'grid_value_colors');
            if (CellStylePresetEvent.isFilledIn('grid_value_colors', value_colors)) {
                base.grid_value_colors = value_colors;
            }

            return base;
        }

        /**
         * Mirrors GridCellStyle::isFilledIn - a style left at "plain" and a
         * switch left off are defaults, not decisions.
         */
        static isFilledIn(key, value) {
            if (!hasValue(value)) {
                return false;
            }
            if (key === 'grid_style') {
                return String(value) !== 'plain';
            }
            if (key === 'grid_nowrap') {
                return String(value) === '1' || value === true;
            }

            return true;
        }

        /**
         * Read one setting, wherever the screen happens to keep it.
         *
         * The column screen nests its settings under `options[...]` while the
         * preset editor posts them flat, and both feed the same preview. The
         * hidden twin laravel-admin renders beside every select is skipped:
         * it is always empty and would hide the real answer.
         */
        static fieldValue($scope, name) {
            var $field = $scope.find('[name="' + name + '"], [name="options[' + name + ']"]')
                .filter(':not([type="hidden"])').first();
            if (!$field.length) {
                return null;
            }
            if ($field.attr('type') === 'checkbox') {
                return $field.is(':checked') ? '1' : '';
            }

            return $field.val();
        }

        /**
         * What to show in the sample.
         *
         * The real option list of the column when there is one: a status
         * column is being styled for its own statuses, and generic words
         * would hide exactly the case the setting exists for.
         */
        static sampleValues($scope, options) {
            var style = String(options.grid_style || 'plain');

            if (style === 'bar') {
                return [{ value: 25, text: '25' }, { value: 60, text: '60' }, { value: 95, text: '95' }];
            }

            var listed = CellStylePresetEvent.listedValues($scope);
            if (listed.length) {
                return listed;
            }

            // A preset editor has no choice list, so a preset that colors
            // values by name would show none of those colors. Its own keys
            // are the only values it knows - sample those before inventing.
            var keyed = CellStylePresetEvent.valueColorKeys(options);
            if (keyed.length) {
                return keyed;
            }

            if (style === 'avatar') {
                return [{ value: null, text: 'Sato' }, { value: null, text: 'Tanaka' }];
            }

            return [
                { value: 'ABC-1024', text: 'ABC-1024' },
                { value: 'ABC-1025', text: 'ABC-1025' }
            ];
        }

        /**
         * The values a per-value setting names, as sample entries.
         */
        static valueColorKeys(options) {
            var rows = CellStylePresetEvent.valueLines(options.grid_value_colors);
            var list = [];

            for (var key in rows) {
                list.push({ value: key, text: key });
                if (list.length >= 4) {
                    break;
                }
            }

            return list;
        }

        /**
         * The first few choices, as the sample shows them.
         */
        static listedValues($scope) {
            return CellStylePresetEvent.choiceList($scope).slice(0, 4);
        }

        /**
         * Every choice typed on the column screen: the option lines of a
         * select, the fixed pair of a yesno, the two values of a boolean.
         */
        static choiceList($scope) {
            var type = String($scope.find('[name="column_type"]').first().val() || '');

            if (type === 'yesno') {
                return [{ value: '1', text: 'YES' }, { value: '0', text: 'NO' }];
            }
            if (type === 'boolean') {
                var pair = [];
                var names = [['true_value', 'true_label'], ['false_value', 'false_label']];
                for (var n = 0; n < names.length; n++) {
                    var value = CellStylePresetEvent.fieldValue($scope, names[n][0]);
                    if (!hasValue(value)) {
                        continue;
                    }
                    var label = CellStylePresetEvent.fieldValue($scope, names[n][1]);
                    pair.push({ value: String(value), text: String(hasValue(label) ? label : value) });
                }
                return pair;
            }

            var raw = CellStylePresetEvent.fieldValue($scope, 'select_item_valtext');
            var paired = hasValue(raw);
            if (!paired) {
                raw = CellStylePresetEvent.fieldValue($scope, 'select_item');
            }
            if (!hasValue(raw)) {
                return [];
            }

            var list = [];
            var lines = String(raw).split(/\r\n|\r|\n/);
            for (var i = 0; i < lines.length; i++) {
                var line = $.trim(lines[i]);
                if (!line.length) {
                    continue;
                }
                if (paired) {
                    var at = line.indexOf(',');
                    if (at < 0) {
                        list.push({ value: line, text: line });
                        continue;
                    }
                    list.push({ value: $.trim(line.substr(0, at)), text: $.trim(line.substr(at + 1)) });
                } else {
                    list.push({ value: line, text: line });
                }
            }

            return list;
        }

        // --------------------------------------------------------- shapes ---

        /**
         * One styled value, as the grid would draw it.
         *
         * @param options styling, already merged
         * @param value   raw value, for the per-value color lookup
         * @param text    what to print
         * @param index   position in the sample, standing in for the option
         *                order the palette uses on a real select column
         */
        static sample(options, value, text, index) {
            var style = String(options.grid_style || 'plain');
            var label = String(hasValue(text) ? text : '');

            if (style === 'avatar') {
                return CellStylePresetEvent.avatar(options, label);
            }
            if (style === 'bar') {
                return CellStylePresetEvent.bar(options, value);
            }
            if (style === 'cell') {
                return CellStylePresetEvent.cell(options, label);
            }

            var $icon = CellStylePresetEvent.icon(options);
            if (style === 'plain') {
                var $plain = $('<span>', { 'class': 'exm-cell-text', text: label });
                if ($icon) {
                    $plain.prepend($icon);
                }
                return $plain;
            }

            var color = CellStylePresetEvent.colorFor(options, value, index);
            var $span = $('<span>', { 'class': 'exm-cell-' + style });

            CellStylePresetEvent.applyCss($span, options, color, value, style, index);

            if (MARKED_STYLES.indexOf(style) >= 0) {
                $span.append($('<span>', { 'class': 'exm-cell-mark' }).css('background-color', color || '#95a5a6'));
            }
            if ($icon) {
                $span.append($icon);
            }

            return $span.append(document.createTextNode(label));
        }

        static applyCss($span, options, color, value, style, index) {
            var row = CellStylePresetEvent.valueRow(options, value);
            var weight = CellStylePresetEvent.weight(options);

            if (style === 'badge') {
                if (CellStylePresetEvent.color(options.grid_color)) {
                    $span.css('color', CellStylePresetEvent.color(options.grid_color));
                }
                if (weight) {
                    $span.css('font-weight', weight);
                }

                var fill = row.background || row.color
                    || CellStylePresetEvent.color(options.grid_bg_color)
                    || CellStylePresetEvent.autoColor(options, index);
                if (fill) {
                    $span.css('background-color', fill);
                }
                var edge = row.border || CellStylePresetEvent.color(options.grid_border_color);
                if (edge) {
                    $span.css('border-color', edge);
                }

                return;
            }

            if (color) {
                $span.css('color', color);
            }
            if (weight) {
                $span.css('font-weight', weight);
            }
            if (FILLED_STYLES.indexOf(style) < 0) {
                return;
            }

            var background = row.background || CellStylePresetEvent.color(options.grid_bg_color)
                || (color ? CellStylePresetEvent.rgba(color, 0.12) : null);
            var border = row.border || CellStylePresetEvent.color(options.grid_border_color)
                || (color ? CellStylePresetEvent.rgba(color, 0.32) : null);

            if (background) {
                $span.css('background-color', background);
            }
            if (border) {
                $span.css('border-color', border);
            }
        }

        /**
         * The whole cell painted, which is what this style does in the grid -
         * the value is not wrapped in anything, the <td> itself is coloured.
         */
        static cell(options, text) {
            var $box = $('<span>', { 'class': 'exm-cellstyle-sample-cell', text: text });
            var color = CellStylePresetEvent.color(options.grid_color);
            var background = CellStylePresetEvent.color(options.grid_bg_color);
            var border = CellStylePresetEvent.color(options.grid_border_color);
            var weight = CellStylePresetEvent.weight(options);

            if (color) {
                $box.css('color', color);
            }
            if (background) {
                $box.css('background-color', background);
            }
            if (border) {
                $box.css('border-color', border);
            }
            if (weight) {
                $box.css('font-weight', weight);
            }

            return $box;
        }

        static avatar(options, text) {
            if (!text.length) {
                return $('<span>');
            }

            var color = CellStylePresetEvent.color(options.grid_color) || CellStylePresetEvent.hashColor(text);

            return $('<span>', { 'class': 'exm-cell-avatar' })
                .append($('<span>', { 'class': 'exm-cell-av', text: text.substr(0, 1) }).css('background-color', color))
                .append(document.createTextNode(text));
        }

        static bar(options, value) {
            var number = isNaN(parseFloat(value)) ? null : parseFloat(value);
            var width = number === null ? 0 : Math.max(0, Math.min(100, number));
            var color = (number === null ? null : CellStylePresetEvent.barColor(options, number))
                || CellStylePresetEvent.color(options.grid_color) || '#3c8dbc';
            var weight = CellStylePresetEvent.weight(options);

            var $text = $('<span>', { 'class': 'exm-cell-bar-txt', text: (number === null ? '' : number) + '%' })
                .css('color', color);
            if (weight) {
                $text.css('font-weight', weight);
            }

            return $('<span>', { 'class': 'exm-cell-bar' })
                .append($('<span>', { 'class': 'exm-cell-bar-track' })
                    .append($('<span>', { 'class': 'exm-cell-bar-fill' })
                        .css({ 'width': width + '%', 'background-color': color })))
                .append($text);
        }

        static barColor(options, number) {
            var rows = CellStylePresetEvent.valueColors(options);
            var picked = null;
            var pickedAt = null;

            for (var key in rows) {
                var threshold = parseFloat(key);
                if (isNaN(threshold) || !rows[key].color) {
                    continue;
                }
                if (threshold <= number && (pickedAt === null || threshold >= pickedAt)) {
                    picked = rows[key].color;
                    pickedAt = threshold;
                }
            }

            return picked;
        }

        static icon(options) {
            var icon = String(options.grid_icon || '');
            if (!/^fa-[0-9a-z-]+$/i.test(icon)) {
                return null;
            }

            return $('<i>', { 'class': 'fa ' + icon.toLowerCase() });
        }

        static colorFor(options, value, index) {
            var row = CellStylePresetEvent.valueRow(options, value);
            if (row.color) {
                return row.color;
            }

            return CellStylePresetEvent.color(options.grid_color) || CellStylePresetEvent.autoColor(options, index);
        }

        /**
         * The palette color a select value would get from its position.
         *
         * Only meaningful when the sample is drawn from a real option list,
         * which is the only case the server applies it in either.
         */
        static autoColor(options, index) {
            if (typeof index !== 'number') {
                return null;
            }

            return PALETTE[index % PALETTE.length];
        }

        static valueRow(options, value) {
            var rows = CellStylePresetEvent.valueColors(options);

            return rows[value === null || value === undefined ? '' : String(value)] || {};
        }

        static valueColors(options) {
            var rows = {};
            var raw = String(options.grid_value_colors || '');
            if (!raw.length) {
                return rows;
            }

            var lines = raw.split(/\r\n|\r|\n/);
            for (var i = 0; i < lines.length; i++) {
                var parts = lines[i].split(',');
                if (parts.length < 2) {
                    continue;
                }
                var key = $.trim(parts.shift());
                var row = {};
                var names = ['color', 'background', 'border'];
                for (var n = 0; n < names.length; n++) {
                    var color = CellStylePresetEvent.color(parts[n]);
                    if (color) {
                        row[names[n]] = color;
                    }
                }
                if (Object.keys(row).length) {
                    rows[key] = row;
                }
            }

            return rows;
        }

        /**
         * Same rule as GridCellStyle::normalizeColor - anything else is a
         * half-typed value and must not reach the sample.
         */
        static color(value) {
            if (!hasValue(value)) {
                return null;
            }

            var hex = String(value).trim().replace(/^#/, '');
            if (!/^([0-9a-f]{3}|[0-9a-f]{6})$/i.test(hex)) {
                return null;
            }

            return '#' + hex.toLowerCase();
        }

        static weight(options) {
            var weight = String(options.grid_font_weight || '');

            return ['400', '600', '700'].indexOf(weight) >= 0 ? weight : null;
        }

        static hashColor(text) {
            var number = 0;
            for (var i = 0; i < text.length; i++) {
                number = (number * 31 + text.charCodeAt(i)) % 100000;
            }

            return AVATAR_PALETTE[number % AVATAR_PALETTE.length];
        }

        static rgba(hex, alpha) {
            var value = hex.replace(/^#/, '');
            if (value.length === 3) {
                value = value[0] + value[0] + value[1] + value[1] + value[2] + value[2];
            }
            var number = parseInt(value, 16);

            return 'rgba(' + ((number >> 16) & 255) + ',' + ((number >> 8) & 255) + ',' + (number & 255) + ',' + alpha + ')';
        }
    }

    CellStylePresetEvent._observer = null;
    CellStylePresetEvent._waiting = null;
    CellStylePresetEvent._opener = null;
    CellStylePresetEvent._captureBound = false;

    Exment.CellStylePresetEvent = CellStylePresetEvent;
})(Exment || (Exment = {}));

$(function () {
    Exment.CellStylePresetEvent.AddEventOnce();
    Exment.CellStylePresetEvent.AddEvent();
});

