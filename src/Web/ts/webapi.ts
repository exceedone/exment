namespace Exment {
    export abstract class WebApi {

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
                    url: this.getFullUrl('data', table_name, value),
                    type: 'GET',
                    context: context,
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


        public select2Option(uri: string, $target:JQuery<HTMLElement>){
            // get url, join prefix
            let url = this.getFullUrl(uri);

            $.ajax({
                type: 'GET',
                url: url,
                data: {'label': 1},
                async: false,
                success: function (repsonse) {
                    let newOption = new Option(repsonse.label, repsonse.id, true, false);
                    $target.append(newOption);
                }
            });
        }

        
        /**
         * Execute linkage.
         * @param $target 
         * @param url 
         * @param val 
         * @param expand 
         * @param linkage_text 
         */
        public linkage($target: JQuery<Element>, uri: string, val: any, expand?: any, linkage_text?: string) {
            var $d = $.Deferred();

            // create querystring
            if (!hasValue(expand)) { expand = {}; }
            if (!hasValue(linkage_text)) { linkage_text = 'text'; }

            expand['q'] = val;
            let query = $.param(expand);

            // get url, join prefix
            let url = this.getFullUrl(uri);

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
         * get select2 ajax option. if input, call ajax.
         * @param $elem 
         */
        public getSelect2AjaxOption($elem : JQuery<Element>) : {} {
            let uri = $elem.data('add-select2-ajax');
            
            // get url, join prefix
            let url = this.getFullUrl(uri);
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


        protected isAbsolute(...args) : boolean{
            let uri = URLJoin(...args);
            return uri.indexOf('http://') === 0 || uri.indexOf('https://') === 0;
        }

        
        /**
         * Get url's prefix
         */
        protected abstract getPrefix() : string;
        
        protected abstract getFullUrl(...args) : string;
    }
}
