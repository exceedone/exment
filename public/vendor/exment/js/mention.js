/**
 * "@" picker for comment boxes - Backlog's 「＠を入力してメンバーに通知」.
 *
 * Inserts the user's ユーザーコード, not the display name: the server has to read
 * the mention back out of free text later, and a name with a space in it cannot
 * be told apart from the sentence around it.
 */
(function ($) {
    'use strict';

    var URL = null;
    var $menu = null;
    var $active = null;
    var anchor = -1;
    var items = [];
    var cursor = 0;
    var timer = null;

    function endpoint() {
        if (URL === null) {
            var $holder = $('[data-mention-url]').first();
            URL = $holder.length ? $holder.data('mention-url') : '';
        }
        return URL;
    }

    function menu() {
        if ($menu === null) {
            $menu = $('<div class="exment-mention-menu"></div>').css({
                position: 'absolute',
                zIndex: 10000,
                display: 'none',
                background: '#fff',
                border: '1px solid #c8d2da',
                borderRadius: '3px',
                boxShadow: '0 2px 6px rgba(0,0,0,.15)',
                maxHeight: '220px',
                overflowY: 'auto',
                minWidth: '220px',
                fontSize: '13px',
            });
            $('body').append($menu);
        }
        return $menu;
    }

    function hide() {
        menu().hide().empty();
        items = [];
        cursor = 0;
        anchor = -1;
    }

    function draw() {
        var $m = menu().empty();

        if (!items.length) {
            hide();
            return;
        }

        $.each(items, function (index, item) {
            $('<div></div>')
                .addClass('exment-mention-item')
                .css({
                    padding: '5px 10px',
                    cursor: 'pointer',
                    background: index === cursor ? '#eef6fb' : '#fff',
                })
                .text(item.name ? (item.name + ' (' + item.code + ')') : item.code)
                .on('mousedown', function (e) {
                    // mousedown, not click: click fires after the textarea has
                    // already lost focus and the caret position with it
                    e.preventDefault();
                    choose(index);
                })
                .appendTo($m);
        });

        var offset = $active.offset();
        $m.css({
            top: offset.top + $active.outerHeight(),
            left: offset.left,
        }).show();
    }

    function choose(index) {
        var item = items[index];
        if (!item || !$active) {
            return;
        }

        var value = $active.val();
        var caret = $active.get(0).selectionStart;
        var before = value.substring(0, anchor);
        var after = value.substring(caret);

        $active.val(before + '@' + item.code + ' ' + after);
        var position = (before + '@' + item.code + ' ').length;
        $active.get(0).setSelectionRange(position, position);
        $active.focus();

        hide();
    }

    function search(term) {
        var url = endpoint();
        if (!url) {
            hide();
            return;
        }

        clearTimeout(timer);
        timer = setTimeout(function () {
            $.getJSON(url, { q: term }).done(function (response) {
                items = (response && response.data) ? response.data : [];
                cursor = 0;
                draw();
            }).fail(hide);
        }, 150);
    }

    /**
     * The token being typed, or null when the caret is not inside one.
     */
    function currentToken($el) {
        var value = $el.val();
        var caret = $el.get(0).selectionStart;

        for (var i = caret - 1; i >= 0 && caret - i <= 65; i--) {
            var ch = value.charAt(i);
            if (ch === '@' || ch === '＠') {
                // must start the line or follow whitespace, or every email address
                // in the comment would open the picker
                var prev = i > 0 ? value.charAt(i - 1) : ' ';
                if (!/\s/.test(prev) && i > 0) {
                    return null;
                }
                anchor = i;
                return value.substring(i + 1, caret);
            }
            if (/\s/.test(ch)) {
                return null;
            }
        }
        return null;
    }

    $(document)
        .off('.exment_mention')
        .on('keyup.exment_mention', 'textarea[data-mention]', function (e) {
            // arrows and enter are handled on keydown while the menu is open
            if ([13, 38, 40, 27].indexOf(e.which) >= 0 && menu().is(':visible')) {
                return;
            }
            $active = $(this);
            var token = currentToken($active);
            if (token === null) {
                hide();
                return;
            }
            search(token);
        })
        .on('keydown.exment_mention', 'textarea[data-mention]', function (e) {
            if (!menu().is(':visible') || !items.length) {
                return;
            }
            if (e.which === 40) {
                cursor = (cursor + 1) % items.length;
                draw();
                e.preventDefault();
            } else if (e.which === 38) {
                cursor = (cursor - 1 + items.length) % items.length;
                draw();
                e.preventDefault();
            } else if (e.which === 13 || e.which === 9) {
                choose(cursor);
                e.preventDefault();
            } else if (e.which === 27) {
                hide();
                e.preventDefault();
            }
        })
        .on('click.exment_mention', function (e) {
            if (!$(e.target).closest('.exment-mention-menu').length) {
                hide();
            }
        });
})(jQuery);
