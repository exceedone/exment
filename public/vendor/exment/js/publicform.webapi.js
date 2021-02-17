var Exment;
(function (Exment) {
    class WebApiPublicForm extends Exment.WebApi {
        getPrefix() {
            return URLJoin('publicformapi', $('input.formkey').val());
        }
        ;
        getFullUrl(...args) {
            if (!args) {
                args = [];
            }
            args.unshift(this.getPrefix());
            args.unshift($('input.rooturi').val());
            return URLJoin(...args);
        }
    }
    Exment.WebApiPublicForm = WebApiPublicForm;
})(Exment || (Exment = {}));
