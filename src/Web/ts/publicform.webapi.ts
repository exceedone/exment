namespace Exment {
    export class WebApiPublicForm extends WebApi {
        protected prefix = 'publicformapi';  

        protected getData() : {}{
            return {key: $('input.key').val()};
        };
    }
}
