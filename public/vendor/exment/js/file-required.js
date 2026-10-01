/**
 * File Required Handler
 * When the last file of a REQUIRED file column (data-file-required="1") has really been
 * deleted on the server, set "required" again so the browser asks for a new file.
 */
(function($) {
    'use strict';

    // bootstrap-fileinput raises "filedeleted" after the server deleted the file,
    // then removes the thumbnail. Count the remaining thumbnails after that.
    $(document).on('filedeleted', 'input[type="file"][data-file-required="1"]', function() {
        var input = this;
        setTimeout(function() {
            var remaining = $(input).closest('.file-input').find('.file-preview-thumbnails .file-preview-frame').length;
            if (remaining === 0) {
                input.required = true;
            }
        }, 0);
    });
})(jQuery);
