namespace Exment {
    export class WebApiAdmin extends WebApi {
        protected getPrefix() : string
        {
            return 'webapi';  
        };

        protected getFullUrl(...args) : string
        {
            if(!args){
                args = [];
            }
            args.unshift(this.getPrefix());

            return admin_url(URLJoin(...args));
        }
    }
}
