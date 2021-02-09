var Exment;
(function (Exment) {
    /**
     * Preview showing model
     */
    class Preview {
        constructor(url, $form, options = {}) {
            this.url = url;
            this.$form = $form;
            this.validateErrorTitle = options['validateErrorTitle'];
            this.validateErrorText = options['validateErrorText'];
            this.validateSubmitEvent = options['validateSubmitEvent'];
        }
        static AddEvent() {
        }
        static AddEventOnce() {
            $(document).on('click.exment_preview', '[data-preview]', {}, Preview.appendPreviewEvent);
            $(document).on('pjax:complete', function (event) {
                Preview.AddEvent();
            });
        }
        /**
         * Showing preview
         */
        openPreview() {
            if (this.validateSubmitEvent !== undefined && !this.validateSubmitEvent()) {
                Exment.CommonEvent.ShowSwal(null, {
                    type: 'error',
                    title: this.validateErrorTitle,
                    text: this.validateErrorText,
                    showCancelButton: false,
                });
                return;
            }
            window.open('', 'exment_preview');
            const form = this.$form;
            const action = form.attr('action');
            const method = form.attr('method');
            // update form info
            form.attr('action', this.url)
                .attr('method', 'post')
                .attr('target', 'exment_preview')
                .removeAttr('pjax-container')
                .data('preview', 1);
            form.submit();
            form.attr('action', action).attr('method', method).attr('target', '').attr('pjax-container', '');
        }
    }
    Preview.appendPreviewEvent = (ev) => {
        const $target = $(ev.target).closest('[data-preview]');
        const preview = new Preview($target.data('preview-url'), $('section.content').find('form').eq(0), {
            validateErrorTitle: $target.data('preview-error-title'),
            validateErrorText: $target.data('preview-error-text'),
        });
        preview.openPreview();
    };
    Exment.Preview = Preview;
})(Exment || (Exment = {}));
$(function () {
    Exment.Preview.AddEvent();
    Exment.Preview.AddEventOnce();
});
