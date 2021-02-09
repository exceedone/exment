var Exment;
(function (Exment) {
    class WebApiPublicForm extends Exment.WebApi {
        constructor() {
            super(...arguments);
            this.prefix = 'publicformapi';
        }
        getData() {
            return { formkey: $('input.formkey').val() };
        }
        ;
    }
    Exment.WebApiPublicForm = WebApiPublicForm;
})(Exment || (Exment = {}));
