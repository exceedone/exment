/**
 * File Required Handler
 * Handle adding required attribute when file is removed from Bootstrap Fileinput
 */
(function($) {
    'use strict';
    
    $(document).ready(function() {
        // Handle click on kv-file-remove button
        $(document).on('click', '.kv-file-remove', function(event) {
            var $removeBtn = $(this);         
            // Find the closest file input (for both file and image types)
            var $fileInputContainer = $removeBtn.closest('.file-input');
            if ($fileInputContainer.length > 0) {
                var $fileInput = $fileInputContainer.find('input[type="file"][data-column_type="file"], input[type="file"][data-column_type="image"]');
                if ($fileInput.length > 0) {
                    // Wait a bit for the file to be actually removed, then check if all files are removed
                    setTimeout(function() {
                        // Check if there are any remaining file previews
                        var hasFiles = $fileInputContainer.find('.file-preview-frame:not(.file-preview-initial)').length > 0;                        
                        if (!hasFiles) {                            
                            // No files left, add required attribute
                            $fileInput.attr('required', '1');
                            $fileInput.prop('required', true);
                        }
                    }, 100);
                }
            }
        });
    });
    
})(jQuery);
