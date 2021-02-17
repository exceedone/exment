var Exment;
(function (Exment) {
    class WebApiPublicForm extends Exment.WebApi {
        getPrefix() {
            return URLJoin('publicformapi', $('input.formkey').val());
        }
        ;
        getFullUrl(...args) {
            return URLJoin($('input.rooturi').val(), 'publicformapi', $('input.formkey').val(), args);
        }
    }
    Exment.WebApiPublicForm = WebApiPublicForm;
})(Exment || (Exment = {}));
