/**
 * Paste a screenshot straight into a file column - Backlog's
 * 「クリップボードから画像を貼り付け」.
 *
 * Only file inputs marked data-paste-attach take part, so pasting on a form that
 * has no attachment column does nothing. Pastes that land inside the rich text
 * editor are left alone: TinyMCE already uploads those itself, and stealing the
 * event would put the same screenshot in two places.
 */
(function ($) {
    'use strict';

    var COUNTER = 0;

    function imagesFromClipboard(event) {
        var clipboard = event.originalEvent ? event.originalEvent.clipboardData : event.clipboardData;
        if (!clipboard || !clipboard.items) {
            return [];
        }

        var images = [];
        for (var i = 0; i < clipboard.items.length; i++) {
            var item = clipboard.items[i];
            if (item.kind !== 'file' || item.type.indexOf('image/') !== 0) {
                continue;
            }
            var file = item.getAsFile();
            if (file) {
                images.push(file);
            }
        }
        return images;
    }

    /**
     * A pasted image arrives named "image.png" every time, which would make three
     * screenshots on one issue indistinguishable in the file list.
     */
    function named(file) {
        var extension = (file.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
        var stamp = new Date();
        var pad = function (n) {
            return (n < 10 ? '0' : '') + n;
        };
        var name = 'paste-'
            + stamp.getFullYear()
            + pad(stamp.getMonth() + 1)
            + pad(stamp.getDate())
            + '-'
            + pad(stamp.getHours())
            + pad(stamp.getMinutes())
            + pad(stamp.getSeconds())
            + '-'
            + (++COUNTER)
            + '.'
            + extension;

        try {
            return new File([file], name, { type: file.type });
        } catch (e) {
            // older engines cannot rename a File; the generic name is better than
            // dropping the paste
            return file;
        }
    }

    function targetInput($form) {
        var $inputs = $form.find('input[type="file"][data-paste-attach]:visible');
        if (!$inputs.length) {
            $inputs = $form.find('input[type="file"][data-paste-attach]');
        }
        return $inputs.first();
    }

    function inEditor(target) {
        var $target = $(target);
        return $target.closest('.tox, .mce-content-body, [contenteditable="true"]').length > 0;
    }

    function onPaste(event) {
        if (inEditor(event.target)) {
            return;
        }

        var $form = $(event.target).closest('form');
        if (!$form.length) {
            $form = $('form').first();
        }

        var $input = targetInput($form);
        if (!$input.length) {
            return;
        }

        var images = imagesFromClipboard(event);
        if (!images.length) {
            return;
        }

        if (typeof DataTransfer === 'undefined') {
            return;
        }

        var input = $input.get(0);
        var transfer = new DataTransfer();

        // keep whatever was already chosen, otherwise pasting a second screenshot
        // would silently drop the first
        if (input.files) {
            for (var i = 0; i < input.files.length; i++) {
                transfer.items.add(input.files[i]);
            }
        }
        for (var j = 0; j < images.length; j++) {
            transfer.items.add(named(images[j]));
        }

        input.files = transfer.files;
        $input.trigger('change');

        event.preventDefault();
    }

    $(document)
        .off('paste.exment_paste_attach')
        .on('paste.exment_paste_attach', onPaste);
})(jQuery);
