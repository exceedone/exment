namespace Exment {
    export abstract class WebApi {
        protected abstract prefix;

        /**
         * Get object model
         */
        public static make() : WebApi{
            if(Exment.WebApiAdmin !== undefined){
                return new WebApiAdmin();
            }
            if(Exment.WebApiPublicForm !== undefined){
                return new WebApiPublicForm();
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

        /**
         * Get web api appends data
         */
        protected abstract getData() : {};
    }
}
