namespace Exment {
    export class WebApiPublicForm extends WebApi {
        
        protected getPrefix() : string
        {
            return URLJoin($('input.publicformapi').val(), $('input.formkey').val());  
        };

        protected getFullUrl(...args) : string{
            if(!args){
                args = [];
            }

            if(this.isAbsolute(...args)){
                return URLJoin(...args);
            }

            args.unshift(this.getPrefix());
            args.unshift($('input.rooturi').val());

            return URLJoin(...args); 
        }
    }
}
