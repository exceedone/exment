var Exment;
(function (Exment) {
    class WebApi {
        /**
         * Get object model
         */
        static make() {
            if (Exment.WebApiAdmin !== undefined) {
                return new Exment.WebApiAdmin();
            }
            if (Exment.WebApiPublicForm !== undefined) {
                return new Exment.WebApiPublicForm();
            }
            return null;
        }
        /**
         * find table data
         * @param table_name
         * @param value
         * @param context
         */
        findValue(table_name, value, context = null) {
            let $d = $.Deferred();
            if (!hasValue(value)) {
                $d.resolve(null);
            }
            else {
                $.ajax({
                    url: admin_url(URLJoin(this.prefix, 'data', table_name, value)),
                    type: 'GET',
                    context: context,
                    data: this.getData(),
                })
                    .done(function (modeldata) {
                    $d.resolve(modeldata, this);
                })
                    .fail(function (errordata) {
                    $d.reject();
                });
            }
            return $d.promise();
        }
    }
    Exment.WebApi = WebApi;
})(Exment || (Exment = {}));
