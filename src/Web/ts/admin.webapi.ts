namespace Exment {
    export class WebApiAdmin extends WebApi {
        protected getPrefix() : string
        {
            return 'webapi';  
        };

        protected getFullUrl(...args) : string
        {
            return admin_url(URLJoin(this.getPrefix(), args));
        }
    }
}
