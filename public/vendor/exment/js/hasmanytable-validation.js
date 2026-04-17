/**
 * Has-Many Table Validation
 */
(function($) {
    'use strict';

    function keyFromName(name) {
        if (!name) return null;
        var m = name.match(/\[value\]\[([^\]]+)\]|\[([^\]]+)\]$/);
        return m ? (m[1] || m[2]) : null;
    }

    function isHidden($el) {
        if (!$el || !$el.length) return false;
        if ($el.is(':hidden')) return true;
        return $el.parents().addBack().filter(function() {
            return $(this).css('visibility') === 'hidden';
        }).length > 0;
    }

    function outerCell($field) {
        var $row = $field.closest('tr.has-many-table-row');
        if ($row.length) {
            var $td = $row.children('td').filter(function() {
                return this.contains($field[0]);
            }).first();
            if ($td.length) return $td;
        }
        return $field.closest('td');
    }

    function colPos($td) {
        var pos = 0;
        $td.prevAll('td').each(function() {
            var span = parseInt($(this).attr('colspan'), 10);
            pos += isNaN(span) ? 1 : span;
        });
        return pos;
    }

    function headerAt($table, pos) {
        var $hit = $();
        var cur = 0;
        $table.find('thead tr').first().children('th').each(function() {
            var span = parseInt($(this).attr('colspan'), 10);
            var w = isNaN(span) ? 1 : span;
            if (pos >= cur && pos < cur + w) {
                $hit = $(this);
                return false;
            }
            cur += w;
        });
        return $hit;
    }

    function headerText($th) {
        return $th.clone().find('i.fa-info-circle, i.fa, .fa').remove().end().text().trim();
    }

    function fieldLabel($field) {
        var nameKey = keyFromName($field.attr('name'));
        var $table = $field.closest('table.has-many-table');

        var label = '';
        if ($table.length) {
            var $td = outerCell($field);
            if ($td.length) label = headerText(headerAt($table, colPos($td)));
        }

        if (label && nameKey && (/^(Action|操作)$/i).test(label)) return nameKey;
        if (label) return label;

        var $lbl = $field.closest('.form-group').find('label');
        if ($lbl.length) return $lbl.text().trim();

        return $field.attr('placeholder') || nameKey || $field.attr('name') || 'Unknown field';
    }

    function tableName($table) {
        var $h = $table.closest('.has-many-table-div').find('.field-header');
        if ($h.length) return $h.text().trim();

        var cls = ($table.attr('class') || '').match(/\bhas-many-table-([^\s]+?)-table\b/);
        if (cls && cls[1]) {
            return cls[1].replace(/_/g, ' ').replace(/\b\w/g, function(c) { return c.toUpperCase(); });
        }
        return 'Table';
    }

    function isEmptyRequiredValue($f) {
        if (!$f || !$f.length) return true;
        if ($f.is(':disabled')) return false;

        if ($f.is('input[type="checkbox"], input[type="radio"]')) {
            return !$f.is(':checked');
        }

        if ($f.is('select')) {
            var v = $f.val();
            if (Array.isArray(v)) return v.length === 0;
            return $.trim(v) === '';
        }

        var val = $f.val();
        return val == null || $.trim(val) === '';
    }

    function findHiddenRequired() {
        var bad = [];

        $('.has-many-table').each(function() {
            var $table = $(this);
            var tname = tableName($table);

            $table.find('tbody tr.has-many-table-row').not('.template').each(function(i) {
                var rowNo = i + 1;

                $(this).find('input[required], select[required], textarea[required]').each(function() {
                    var $f = $(this);
                    if ($f.is('input[type="hidden"]')) {
                        var n = $f.attr('name') || '';
                        if (n.indexOf('[value][') === -1) return;
                    }

                    // Only block when required field is hidden AND empty
                    if (!isEmptyRequiredValue($f)) return;

                    if (isHidden($f) || isHidden($f.closest('td'))) {
                        bad.push({ table: tname, row: rowNo, field: fieldLabel($f), element: $f });
                    }
                });
            });
        });

        return bad;
    }

    function showAlert(fields) {
        if (!fields.length) return;

        function escHtml(s) {
            return String(s).replace(/[&<>"']/g, function(c) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
            });
        }

        function hval(id, fallback) {
            var $el = $('#' + id);
            return $el.length ? $el.val() : fallback;
        }

        var TITLE = hval('exment_hm_validation_title', 'バリデーションエラー');
        var PLAIN_PREFIX = hval('exment_hm_validation_plain_prefix', '以下の必須項目が非表示になっています：');
        var HTML_PREFIX = hval('exment_hm_validation_html_prefix', '以下の必須項目が非表示になっており、表示する必要があります：');
        var OK_TEXT = hval('exment_hm_validation_ok', 'OK');
        var ROW_TEXT = hval('exment_common_row', '行');

        // Group: table -> row -> [field...]
        var byTableRow = {};
        fields.forEach(function(x) {
            byTableRow[x.table] = byTableRow[x.table] || {};
            byTableRow[x.table][x.row] = byTableRow[x.table][x.row] || [];
            byTableRow[x.table][x.row].push(x.field);
        });

        var lines = [];
        Object.keys(byTableRow).forEach(function(t) {
            lines.push(t + ':');

            Object.keys(byTableRow[t]).sort(function(a, b) {
                return parseInt(a, 10) - parseInt(b, 10);
            }).forEach(function(r) {
                var parts = byTableRow[t][r].map(function(f) {
                    return '• ' + ROW_TEXT + ' ' + r + ': ' + f;
                });
                lines.push(parts.join(' '));
            });
        });

        var plain = PLAIN_PREFIX + '\n\n' + lines.join('\n');

        if (typeof toastr !== 'undefined') {
            var toastHtml = escHtml(plain).replace(/\n/g, '<br>');
            toastr.error(toastHtml, TITLE, {
                timeOut: 10000,
                extendedTimeOut: 5000,
                closeButton: true,
                progressBar: true,
                positionClass: 'toast-top-right',
                escapeHtml: false
            });
            return;
        }

        var html = '<div style="text-align: left;"><strong>' + escHtml(HTML_PREFIX) + '</strong><br><br>';
        Object.keys(byTableRow).forEach(function(t) {
            html += '<strong>' + t + ':</strong><ul style="margin: 5px 0 15px 20px;">';
            Object.keys(byTableRow[t]).sort(function(a, b) {
                return parseInt(a, 10) - parseInt(b, 10);
            }).forEach(function(r) {
                var parts = byTableRow[t][r].map(function(f) {
                    return escHtml(ROW_TEXT) + ' ' + r + ': <em>' + escHtml(f) + '</em>';
                });
                html += '<li>' + parts.join(' / ') + '</li>';
            });
            html += '</ul>';
        });
        html += '</div>';

        if (typeof swal !== 'undefined') {
            swal({ title: TITLE, text: html, html: true, type: 'error', confirmButtonText: OK_TEXT });
        } else if (typeof Swal !== 'undefined') {
            Swal.fire({ title: TITLE, html: html, icon: 'error', confirmButtonText: OK_TEXT });
        } else {
            alert(plain);
        }
    }

    function init() {
        var $form = $('form').has('.has-many-table');
        if (!$form.length) return;

        $form.on('submit', function(e) {
            var fields = findHiddenRequired();
            if (!fields.length) return;

            e.preventDefault();
            e.stopImmediatePropagation();

            showAlert(fields);

            if (fields[0].element) {
                $('html, body').animate({
                    scrollTop: fields[0].element.closest('.has-many-table-div').offset().top - 100
                }, 500);
            }

            return false;
        });

        $(document).on('pjax:beforeSend', function(e) {
            var $targetForm = $(e.relatedTarget).closest('form');
            if (!$targetForm.has('.has-many-table').length) return;

            var fields = findHiddenRequired();
            if (!fields.length) return;

            e.preventDefault();
            showAlert(fields);
            return false;
        });
    }

    $(init);
})(jQuery);
