var Exment;
(function (Exment) {
    class WebApiAdmin extends Exment.WebApi {
        getPrefix() {
            return 'webapi';
        }
        ;
        getFullUrl(...args) {
            return admin_url(URLJoin(this.getPrefix(), args));
        }
    }
    Exment.WebApiAdmin = WebApiAdmin;
})(Exment || (Exment = {}));
