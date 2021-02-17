namespace Exment {
    export class WebApiPublicForm extends WebApi {
        
        protected getPrefix() : string
        {
            return URLJoin('publicformapi', $('input.formkey').val());  
        };

        protected getFullUrl(...args) : string{
            return URLJoin($('input.rooturi').val(), 'publicformapi', $('input.formkey').val(), args); 
        }
    }
}
