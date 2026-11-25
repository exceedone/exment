/**
 * Copy a row of a child table while the form is being filled in (#1595).
 *
 * The row is added the way the "new" button adds one - so every input of it is
 * set up by the same script - and the values of the row it copies are then put
 * into it, field by field. The record id and the delete flag are not copied: the
 * copy is a new record. A file is not copied either: a browser cannot put a file
 * into a file input, so the copy starts without one.
 */
(function ($) {
    'use strict';

    // relation[key][value][column] -> key
    function keyOf($row, column) {
        var prefix = column + '[';
        var key = null;
        $row.find('[name]').each(function () {
            var name = String(this.getAttribute('name'));
            if (name.indexOf(prefix) !== 0) {
                return;
            }
            var end = name.indexOf(']', prefix.length);
            if (end > prefix.length) {
                key = name.substring(prefix.length, end);
                return false;
            }
        });
        return key;
    }

    // the part of the name below the row: [value][column], [id], [_remove_]
    function restOf(name, column, key) {
        var head = column + '[' + key + ']';
        return name.indexOf(head) === 0 ? name.substring(head.length) : null;
    }

    function skipped(rest, el) {
        if (rest === '[id]' || rest.indexOf('[_remove_]') === 0) {
            return true;
        }
        var type = String(el.getAttribute('type') || '').toLowerCase();
        return type === 'file';
    }

    function copyValues($src, $dst, column) {
        var srcKey = keyOf($src, column);
        var dstKey = keyOf($dst, column);
        if (srcKey === null || dstKey === null) {
            return;
        }

        // the inputs of the new row, by the part of their name below the row
        var targets = {};
        $dst.find('[name]').each(function () {
            var rest = restOf(String(this.getAttribute('name')), column, dstKey);
            if (rest === null || skipped(rest, this)) {
                return;
            }
            (targets[rest] = targets[rest] || []).push(this);
        });

        var changed = [];
        var done = {};
        $src.find('[name]').each(function () {
            var rest = restOf(String(this.getAttribute('name')), column, srcKey);
            if (rest === null || skipped(rest, this) || !targets[rest]) {
                return;
            }
            var type = String(this.getAttribute('type') || '').toLowerCase();

            // a group of checkboxes or radio buttons is copied by which ones are on
            if (type === 'checkbox' || type === 'radio') {
                var on = this.checked;
                var value = this.value;
                $.each(targets[rest], function (i, el) {
                    if (el.value === value && el.checked !== on) {
                        el.checked = on;
                        changed.push(el);
                    }
                });
                return;
            }

            // the same name once more (a hidden input that goes with a checkbox)
            if (done[rest]) {
                return;
            }
            done[rest] = true;

            $.each(targets[rest], function (i, el) {
                if (String(el.getAttribute('type') || '').toLowerCase() === 'checkbox' ||
                    String(el.getAttribute('type') || '').toLowerCase() === 'radio') {
                    return;
                }
                if (el.tagName === 'SELECT') {
                    copySelect(this, el);
                } else {
                    el.value = this.value;
                    // a rich text editor keeps its text outside the textarea
                    if (window.tinymce && el.id && window.tinymce.get(el.id)) {
                        var from = this.id ? window.tinymce.get(this.id) : null;
                        window.tinymce.get(el.id).setContent(from ? from.getContent() : this.value);
                    }
                }
                changed.push(el);
            }.bind(this));
        });

        // the plugins (select2, iCheck, switches, calculations) follow the inputs
        $.each(changed, function (i, el) {
            var $el = $(el);
            if ($el.iCheck && $el.closest('[class*="icheckbox"], [class*="iradio"]').length) {
                $el.iCheck('update');
            }
            $el.trigger('change');
        });
    }

    // A select that loads its choices as it is searched has only the chosen ones
    // in it: those are brought over as well, with the text they are shown with.
    function copySelect(src, dst) {
        var values = [];
        $.each(src.options, function (i, option) {
            if (!option.selected) {
                return;
            }
            values.push(option.value);
            var found = false;
            $.each(dst.options, function (j, own) {
                if (own.value === option.value) {
                    found = true;
                    return false;
                }
            });
            if (!found) {
                dst.appendChild(new Option(option.text, option.value, false, false));
            }
        });
        $(dst).val(dst.multiple ? values : (values.length ? values[0] : ''));
    }

    /**
     * Add a row after $src with the "new" button of the child table, and copy
     * the values of $src into it.
     *
     * @param {jQuery} $container the child table (#has-many-table-xxx or #has-many-xxx)
     * @param {jQuery} $src the row to copy
     * @param {string} column the relation name, the first part of the names of the row
     * @param {string} rowSelector the rows of this child table
     */
    function copyRow($container, $src, column, rowSelector) {
        var $add = $container.find('.add').first();
        if (!$add.length) {
            return;
        }
        var before = $container.find(rowSelector).toArray();
        $add.trigger('click');
        var $dst = $container.find(rowSelector).filter(function () {
            return before.indexOf(this) < 0;
        }).last();
        if (!$dst.length) {
            return;
        }
        $dst.insertAfter($src);
        copyValues($src, $dst, column);
        $dst.stop().css('background-color', '#FFFFCC').animate({ backgroundColor: 'rgba(0,0,0,0.0)' }, 1000);
        $dst.trigger('admin_hasmany_row_change');
    }

    window.ExmentHasManyCopy = {
        copyRow: copyRow,
        copyValues: copyValues
    };
})(jQuery);
