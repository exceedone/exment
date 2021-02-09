var Exment;
(function (Exment) {
    class WebApiPublicForm extends Exment.WebApi {
        constructor() {
            super(...arguments);
            this.prefix = 'publicformapi';
        }
        getData() {
            return { key: $('input.key').val() };
        }
        ;
    }
    Exment.WebApiPublicForm = WebApiPublicForm;
})(Exment || (Exment = {}));
