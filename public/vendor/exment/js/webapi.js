var Exment;
(function (Exment) {
    class WebApiBase {
        /**
         * Get object model
         */
        static make() {
            if (Exment.WebApi === undefined) {
                return new Exment.WebApi;
            }
            if (Exment.WebApiPublicForm === undefined) {
                return new Exment.WebApiPublicForm;
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
                    context: context
                })
                    .done(function (modeldata) {
                    $d.resolve(modeldata);
                })
                    .fail(function (errordata) {
                    console.log(errordata);
                    $d.reject();
                });
            }
            return $d.promise();
        }
    }
    Exment.WebApiBase = WebApiBase;
})(Exment || (Exment = {}));
