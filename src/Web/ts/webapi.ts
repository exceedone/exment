namespace Exment {
    export abstract class WebApiBase {
        protected abstract prefix;

        /**
         * Get object model
         */
        public static make() : WebApiBase{
            if(Exment.WebApi === undefined){
                return new WebApi;
            }
            if(Exment.WebApiPublicForm === undefined){
                return new WebApiPublicForm;
            }
            return null;
        }

        /**
         * find table data
         * @param table_name 
         * @param value 
         * @param context 
         */
        public findValue(table_name, value, context = null) {
            let $d = $.Deferred();
            if (!hasValue(value)) {
                $d.resolve(null);
            } else {
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
}
