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

    // Cells copied from Excel, or a passage from Word, reach the clipboard
    // with a picture of themselves next to the text. Pasted into a text box
    // that is text the user meant to paste; taking it as a screenshot would
    // attach a picture nobody asked for and drop the text.
    function isTextPaste(event) {
        if (!$(event.target).is('textarea, input:not([type="file"])')) {
            return false;
        }
        var clipboard = event.originalEvent ? event.originalEvent.clipboardData : event.clipboardData;
        var types = clipboard && clipboard.types ? Array.prototype.slice.call(clipboard.types) : [];
        return types.indexOf('text/plain') >= 0;
    }

    function onPaste(event) {
        if (inEditor(event.target) || isTextPaste(event)) {
            return;
        }

        var $form = $(event.target).closest('form');
        if (!$form.length) {
            // Nothing on the form has focus - the usual case right after taking a
            // screenshot - so the paste lands on <body>. The first form on the page
            // is the header search box, so take the form that has an attachment
            // column instead.
            $form = $('input[type="file"][data-paste-attach]').first().closest('form');
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

        // A column taking several files keeps whatever was already chosen,
        // otherwise pasting a second screenshot would silently drop the first.
        // A single-file column holds one file, so the paste replaces it:
        // fileinput refuses a second file there with a "too many files" error.
        if (input.multiple) {
            if (input.files) {
                for (var i = 0; i < input.files.length; i++) {
                    transfer.items.add(input.files[i]);
                }
            }
        } else {
            images = images.slice(0, 1);
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
