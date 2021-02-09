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
         * Execute linkage.
         * @param $target 
         * @param url 
         * @param val 
         * @param expand 
         * @param linkage_text 
         */
        public linkage($target: JQuery<Element>, url: string, val: any, expand?: any, linkage_text?: string) {
            var $d = $.Deferred();

            // create querystring
            if (!hasValue(expand)) { expand = {}; }
            if (!hasValue(linkage_text)) { linkage_text = 'text'; }

            expand['q'] = val;
            expand = Object.assign(expand, this.getData());
            let query = $.param(expand);

            $.get(url + '?' + query, function (json) {
                $target.find("option").remove();
                var options = [];
                options.push({id: '', text: ''});

                $.each(json, function(index, d){
                    options.push({id: hasValue(d.id) ? d.id : '', text: d[linkage_text]});
                })

                $target.select2({
                    data: options,
                    "allowClear": true,
                    "placeholder": $target.next().find('.select2-selection__placeholder').text(),
                }).trigger('change');

                $d.resolve();
            });
            return $d.promise();
        }

        
        /**
         * Execute linkage.
         * @param $target 
         * @param url 
         * @param val 
         * @param expand 
         * @param linkage_text 
         */
        public getSelect2AjaxOption($elem : JQuery<Element>) : {} {
            let url = $elem.data('add-select2-ajax');
            let params = this.getUrlParameter(url);
            params = Object.assign(params, this.getData());

            url = this.getPureUrl(url) + '?' + $.param(params);
            return {
                url: url,
                dataType: 'json',
                delay: 250,
                data: function (params) {
                    return {
                        q: params.term,
                        page: params.page,
                        expand: $elem.data('add-select2-expand'),
                    };
                },
                processResults: function (data, params) {
                    if (!hasValue(data) || !hasValue(data.data)) { return { results: [] }; }
                    params.page = params.page || 1;

                    return {
                        results: $.map(data.data, function (d) {
                            d.id = d.id;
                            d.text = hasValue(d.text) ? d.text : d.label; // label is custom value label appended.
                            return d;
                        }),
                        pagination: {
                            more: data.next_page_url
                        }
                    };
                },
                cache: true
            };
        }


        /**
         * get url without parameters
         * @param fullUrl 
         */
        protected getPureUrl(fullUrl:string){
            if(!fullUrl){
                return null;
            }
            return fullUrl.split('?')[0];
        }

        /**
         * get parameters
         * @param fullUrl 
         */
        protected getUrlParameter(fullUrl:string) : {}{
            if(!fullUrl){
                return {};
            }
            let url = new URL(fullUrl);

            let result = {};
            for(let pair of url.searchParams.entries()){
                result[pair[0]] = pair[1];
            }
            return result;
        }


        /**
         * Get web api appends data
         */
        protected abstract getData() : {};
    }
}
