var Exment;
(function (Exment) {
    class WebApiPublicForm extends Exment.WebApiBase {
        constructor() {
            super(...arguments);
            this.prefix = 'publicformapi';
        }
    }
    Exment.WebApiPublicForm = WebApiPublicForm;
})(Exment || (Exment = {}));
