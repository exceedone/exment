var Exment;
(function (Exment) {
    class WebApiAdmin extends Exment.WebApi {
        constructor() {
            super(...arguments);
            this.prefix = 'webapi';
        }
        getData() {
            return {};
        }
        ;
    }
    Exment.WebApiAdmin = WebApiAdmin;
})(Exment || (Exment = {}));
