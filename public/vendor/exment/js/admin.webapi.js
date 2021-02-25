var Exment;
(function (Exment) {
    class WebApiAdmin extends Exment.WebApi {
        getPrefix() {
            return 'webapi';
        }
        ;
        getFullUrl(...args) {
            if (!args) {
                args = [];
            }
            if (this.isAbsolute(...args)) {
                return URLJoin(...args);
            }
            args.unshift(this.getPrefix());
            return admin_url(URLJoin(...args));
        }
    }
    Exment.WebApiAdmin = WebApiAdmin;
})(Exment || (Exment = {}));
