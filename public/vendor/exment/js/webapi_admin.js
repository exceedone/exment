var Exment;
(function (Exment) {
    class WebApi extends Exment.WebApiBase {
        constructor() {
            super(...arguments);
            this.prefix = 'webapi';
        }
    }
    Exment.WebApi = WebApi;
})(Exment || (Exment = {}));
