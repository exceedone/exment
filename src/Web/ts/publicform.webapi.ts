namespace Exment {
    export class WebApiPublicForm extends WebApi {
        protected prefix = 'publicformapi';  

        protected getData() : {}{
            return {formkey: $('input.formkey').val()};
        };
    }
}
