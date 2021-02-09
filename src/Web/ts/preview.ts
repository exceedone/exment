
namespace Exment {
    /**
     * Preview showing model
     */
    export class Preview {
        public static AddEvent() {
        }

        public static AddEventOnce() {
            $(document).on('click.exment_preview', '[data-preview]', {}, Preview.appendPreviewEvent);

            $(document).on('pjax:complete', function (event) {
                Preview.AddEvent();
            });
        }


        private url:string;
        private $form:JQuery<HTMLElement>;
        private validateErrorTitle:string;
        private validateErrorText:string;
        private validateSubmitEvent;

        constructor(url: string, $form:JQuery<HTMLElement>, options:{} = {}){
            this.url = url;
            this.$form = $form;

            this.validateErrorTitle = options['validateErrorTitle'];
            this.validateErrorText = options['validateErrorText'];
            this.validateSubmitEvent = options['validateSubmitEvent'];
        }

        /**
         * Showing preview
         */
        public openPreview()
        {
            if(this.validateSubmitEvent !== undefined && !this.validateSubmitEvent()){
                CommonEvent.ShowSwal(null, {
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


        public static appendPreviewEvent = (ev:any) => {
            const $target = $(ev.target).closest('[data-preview]');
            const preview = new Preview(
                $target.data('preview-url'),
                $('section.content').find('form').eq(0),
                {
                    validateErrorTitle: $target.data('preview-error-title'),
                    validateErrorText: $target.data('preview-error-text'),
                }
            );
            preview.openPreview();
        }
    }
}

$(function () {
    Exment.Preview.AddEvent();
    Exment.Preview.AddEventOnce();
});
