/**
 * The comment box of a data: a comment is written in Markdown.
 *
 * - "Write / Preview" above the box. The preview is made by the server
 *   (data/{table}/{id}/previewcomment), by the same code that shows a saved
 *   comment, so what is previewed is what will be shown.
 * - An image pasted or dropped into the box is attached to the data
 *   (data/{table}/{id}/commentimage) and written into the comment as
 *   ![name](url).
 *
 * Only textareas marked data-comment-markdown take part.
 */
(function ($) {
    'use strict';

    var SELECTOR = 'textarea[data-comment-markdown]';
    var IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
    var COUNTER = 0;

    function token() {
        return (typeof LA !== 'undefined' && LA.token) ? LA.token : $('meta[name="csrf-token"]').attr('content');
    }

    // write / preview ----------------------------------------------------

    function setup($textarea) {
        if ($textarea.data('comment-markdown-ready')) {
            return;
        }
        $textarea.data('comment-markdown-ready', true);

        var $write = $('<a class="comment-markdown-tab-write active"></a>').text($textarea.data('comment-label-write') || 'Write');
        var $preview = $('<a class="comment-markdown-tab-preview"></a>').text($textarea.data('comment-label-preview') || 'Preview');
        var $tabs = $('<div class="comment-markdown-tabs"></div>').append($write).append($preview);
        var $box = $('<div class="comment-markdown-preview comment-markdown"></div>').hide();

        $textarea.before($tabs).after($box);

        $write.on('click', function () {
            $preview.removeClass('active');
            $write.addClass('active');
            $box.hide();
            $textarea.show().trigger('focus');
        });

        // a hidden box that is required and empty would stop the submit without a word
        $textarea.closest('form').find('[type="submit"]').on('click', function () {
            if ($preview.hasClass('active')) {
                $preview.removeClass('active');
                $write.addClass('active');
                $box.hide();
                $textarea.show();
            }
        });

        $preview.on('click', function () {
            var url = $textarea.data('comment-preview-url');
            if (!url || $preview.hasClass('active')) {
                return;
            }
            $write.removeClass('active');
            $preview.addClass('active');
            $box.css('min-height', $textarea.outerHeight() + 'px').empty().show();
            $textarea.hide();

            $.ajax({
                url: url,
                type: 'POST',
                data: { _token: token(), comment: $textarea.val() }
            }).done(function (res) {
                // the server answers html that is already cleaned
                $box.html(res && res.html ? res.html : '');
            }).fail(function () {
                $box.text($textarea.val());
            });
        });
    }

    function setupAll() {
        $(SELECTOR).each(function () {
            setup($(this));
        });
    }

    // images ----------------------------------------------------

    function imagesOf(list) {
        var images = [];
        if (!list) {
            return images;
        }
        for (var i = 0; i < list.length; i++) {
            var item = list[i];
            var file = item;
            if (typeof item.getAsFile === 'function') {
                if (item.kind !== 'file') {
                    continue;
                }
                file = item.getAsFile();
            }
            if (file && IMAGE_TYPES.indexOf(file.type) >= 0) {
                images.push(file);
            }
        }
        return images;
    }

    /**
     * A pasted image arrives named "image.png" every time, which would make
     * three screenshots on one data indistinguishable in the file list.
     */
    function named(file) {
        if (file.name && file.name !== 'image.png') {
            return file;
        }
        var pad = function (n) {
            return (n < 10 ? '0' : '') + n;
        };
        var now = new Date();
        var name = 'paste-' + now.getFullYear() + pad(now.getMonth() + 1) + pad(now.getDate())
            + '-' + pad(now.getHours()) + pad(now.getMinutes()) + pad(now.getSeconds())
            + '-' + (++COUNTER) + '.' + (file.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
        try {
            return new File([file], name, { type: file.type });
        } catch (e) {
            return file;
        }
    }

    function replaceText($textarea, from, to) {
        var el = $textarea.get(0);
        var value = el.value;
        var index = value.indexOf(from);
        if (index < 0) {
            el.value = value + to;
        } else {
            el.value = value.substring(0, index) + to + value.substring(index + from.length);
        }
        $textarea.trigger('change');
    }

    function insertAtCursor($textarea, text) {
        var el = $textarea.get(0);
        var start = typeof el.selectionStart === 'number' ? el.selectionStart : el.value.length;
        var end = typeof el.selectionEnd === 'number' ? el.selectionEnd : el.value.length;
        el.value = el.value.substring(0, start) + text + el.value.substring(end);
        el.selectionStart = el.selectionEnd = start + text.length;
        $textarea.trigger('change');
    }

    function upload($textarea, file) {
        var url = $textarea.data('comment-image-url');
        if (!url || typeof FormData === 'undefined') {
            return;
        }
        file = named(file);

        // a mark that is replaced by the image when the upload ends
        var mark = '![' + ($textarea.data('comment-label-uploading') || 'Uploading...') + ' ' + (++COUNTER) + ']()';
        insertAtCursor($textarea, mark + '\n');

        var data = new FormData();
        data.append('_token', token());
        data.append('file', file, file.name);

        $.ajax({
            url: url,
            type: 'POST',
            data: data,
            processData: false,
            contentType: false
        }).done(function (res) {
            if (res && res.result && res.markdown) {
                replaceText($textarea, mark, res.markdown);
            } else {
                failed($textarea, mark, res);
            }
        }).fail(function (xhr) {
            failed($textarea, mark, xhr ? xhr.responseJSON : null);
        });
    }

    function failed($textarea, mark, res) {
        replaceText($textarea, mark + '\n', '');
        replaceText($textarea, mark, '');
        var message = (res && res.message) ? res.message : ($textarea.data('comment-label-error') || 'Upload failed.');
        if (typeof toastr !== 'undefined') {
            toastr.error(message);
        } else {
            alert(message);
        }
    }

    $(document)
        .off('.exment_comment_markdown')
        .on('paste.exment_comment_markdown', SELECTOR, function (e) {
            var clipboard = e.originalEvent ? e.originalEvent.clipboardData : null;
            if (!clipboard) {
                return;
            }
            // Cells copied from Excel reach the clipboard with a picture of
            // themselves next to the text: that is text the user meant to paste.
            var types = clipboard.types ? Array.prototype.slice.call(clipboard.types) : [];
            if (types.indexOf('text/plain') >= 0) {
                return;
            }
            var images = imagesOf(clipboard.items);
            if (!images.length) {
                return;
            }
            e.preventDefault();
            // paste_attach.js listens on the document for the same paste
            e.stopPropagation();
            var $textarea = $(this);
            for (var i = 0; i < images.length; i++) {
                upload($textarea, images[i]);
            }
        })
        .on('dragover.exment_comment_markdown', SELECTOR, function (e) {
            e.preventDefault();
            $(this).addClass('comment-markdown-dragover');
        })
        .on('dragleave.exment_comment_markdown drop.exment_comment_markdown', SELECTOR, function () {
            $(this).removeClass('comment-markdown-dragover');
        })
        .on('drop.exment_comment_markdown', SELECTOR, function (e) {
            var transfer = e.originalEvent ? e.originalEvent.dataTransfer : null;
            var images = transfer ? imagesOf(transfer.files) : [];
            if (!images.length) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            var $textarea = $(this);
            for (var i = 0; i < images.length; i++) {
                upload($textarea, images[i]);
            }
        });

    // the data screen is also loaded by pjax
    $(setupAll);
    $(document)
        .off('pjax:complete.exment_comment_markdown')
        .on('pjax:complete.exment_comment_markdown', setupAll);
})(jQuery);
