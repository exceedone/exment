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

        // the inputs of the row that is copied, the same way
        var sources = {};
        $src.find('[name]').each(function () {
            var rest = restOf(String(this.getAttribute('name')), column, srcKey);
            if (rest === null || skipped(rest, this) || !targets[rest]) {
                return;
            }
            (sources[rest] = sources[rest] || []).push(this);
        });

        var changed = [];
        $.each(sources, function (rest, srcEls) {
            var dstEls = targets[rest];

            // a group of checkboxes or radio buttons is copied by which ones are on
            $.each(srcEls, function (i, from) {
                if (!isCheck(from)) {
                    return;
                }
                $.each(dstEls, function (j, el) {
                    if (isCheck(el) && el.value === from.value && el.checked !== from.checked) {
                        el.checked = from.checked;
                        changed.push(el);
                    }
                });
            });

            // The other inputs of one name go in pairs of the same kind: a select
            // has a hidden input of its name before it (sent when nothing is
            // chosen), and a select must get its value from the select.
            var srcOthers = $.grep(srcEls, function (el) { return !isCheck(el); });
            var used = [];
            $.each(dstEls, function (i, el) {
                if (isCheck(el)) {
                    return;
                }
                var from = null;
                $.each(srcOthers, function (j, cand) {
                    if (used.indexOf(cand) < 0 && kindOf(cand) === kindOf(el)) {
                        from = cand;
                        return false;
                    }
                });
                if (from === null) {
                    return;
                }
                used.push(from);
                if (el.tagName === 'SELECT') {
                    copySelect(from, el);
                } else {
                    el.value = from.value;
                    // a rich text editor keeps its text outside the textarea
                    if (window.tinymce && el.id && window.tinymce.get(el.id)) {
                        var editor = from.id ? window.tinymce.get(from.id) : null;
                        window.tinymce.get(el.id).setContent(editor ? editor.getContent() : from.value);
                    }
                }
                changed.push(el);
            });
        });

        // the plugins (select2, iCheck, switches, calculations) follow the inputs
        $.each(changed, function (i, el) {
            var $el = $(el);
            if ($el.iCheck && $el.closest('[class*="icheckbox"], [class*="iradio"]').length) {
                $el.iCheck('update');
            }
            syncSwitch($el);
            $el.trigger('change');
        });
    }

    function isCheck(el) {
        var type = String(el.getAttribute('type') || '').toLowerCase();
        return type === 'checkbox' || type === 'radio';
    }

    function kindOf(el) {
        return el.tagName + ':' + String(el.getAttribute('type') || '').toLowerCase();
    }

    // A YES/NO switch posts the hidden input after it; the checkbox it is drawn
    // from has no name, so it is put in step with the hidden input here.
    function syncSwitch($hidden) {
        if (String($hidden.attr('type') || '').toLowerCase() !== 'hidden') {
            return;
        }
        var $wrapper = $hidden.prev('.bootstrap-switch');
        var $checkbox = $wrapper.length
            ? $wrapper.find('input.la_checkbox[data-onvalue]').first()
            : $hidden.prevAll('input.la_checkbox[data-onvalue]').first();
        if (!$checkbox.length) {
            return;
        }
        var on = String($hidden.val()) === String($checkbox.data('onvalue'));
        if ($wrapper.length && $checkbox.bootstrapSwitch) {
            // true: do not run onSwitchChange, the hidden input has the value already
            $checkbox.bootstrapSwitch('state', on, true);
        } else {
            $checkbox.prop('checked', on);
        }
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
