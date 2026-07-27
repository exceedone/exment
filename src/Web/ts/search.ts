
namespace Exment {
    export class SearchEvent {
        // Result count (permission-filtered) + load time, aggregated from the table boxes.
        private static meiliStart: number = 0;
        private static meiliTotal: number = 0;
        private static meiliPending: number = 0;
        private static meiliHasTotal: boolean = false;
        private static meiliCapped: boolean = false;

        /**
         * Call only once. It's $(document).on event.
         */
        public static AddEventOnce() {
            SearchEvent.searchHeaderEvent();

            $(document).on('click.exment_search', '[data-ajax-link]', [], SearchEvent.dataAjaxLinkEvent);
        }

        public static AddEvent() {

        }

        private static searchHeaderEvent(){
            if(!hasValue($('.search-form #query'))){
                return;
            }
            let $query = $('.search-form #query');
            let ajax_url = $query.data('ajax_url');
            let list_url = $query.data('list_url');
            let search_suggests = [];
            $('.search-form #query').autocomplete({
                source: function (req, res) {
                    $.ajax({
                        url: ajax_url,
                        data: {
                            _token: LA.token,
                            query: req.term
                        },
                        dataType: "json",
                        type: "GET",
                        success: function (data) {
                            search_suggests = data;
                            res(data);
                        },
                    });
                },
                // Search when seleting
                select : function(e, ui)
                    {
                        if(ui.item)
                        {
                            $.pjax({ container: '#pjax-container', url: list_url + '?table_name=' + ui.item.table_name + '&value_id=' + ui.item.value_id });
                        }
                    },
                autoFocus: false,
                delay: 500,
                minLength: 2,
            })
            .autocomplete("instance")._renderItem = function (ul, item) {
                let p = $('<p/>', {
                    'class': 'search-item-icon',
                    'html': [
                        $('<i/>', {
                            'class': 'fa ' + item.icon
                        }),
                        $('<span/>', {
                            'text': item.table_view_name,
                            'style': 'background-color:' + item.color,
                        }),
                    ]
                });
                let div = $('<div/>', {
                    'tabindex' : -1,
                    'class' : 'ui-menu-item-wrapper',
                    // item.text is server-escaped html with <mark> highlights
                    // (see HeaderSuggester::toHighlightedHtml).
                    'html' : [p, $('<span/>', {'html':item.text})]
                });
                return $('<li class="ui-menu-item-with-icon"></li>')
                    .data("item.autocomplete", item)
                    .append(div)
                    .appendTo(ul);
            };
        }


        private static dataAjaxLinkEvent = (ev) => {
            // get link
            const url = $(ev.target).closest('[data-ajax-link]').data('ajax-link');
            const box_key = $(ev.target).closest('[data-box_key]').data('box_key');
            SearchEvent.getNaviDataItem(url, box_key);
        }


        public static getNaviData(isList:boolean) {
            if(isList){
                SearchEvent.getListNaviData();
            }
            else{
                SearchEvent.getRelationNaviData();
            }
        }
        

        /**
         * Get Search Navi data for List
         */
        private static getListNaviData() {
            const tables = JSON.parse($('.tables').val() as string);
            const search_execute_count = $('#search_execute_count');

            // forward the filter (date + creator + status + range) from the current URL into the AJAX request.
            const params: any = {query : $('.base_query').val()};
            const cur = new URLSearchParams(window.location.search);
            ['date_from', 'date_to', 'sort'].forEach(function(k){ if(cur.get(k)){ params[k] = cur.get(k); } });
            let users = cur.getAll('users[]');
            if(!users.length && cur.get('users')){ users = cur.get('users').split(','); }
            if(users.length){ params.users = users.join(','); }
            // forward facets (status/classification) — keep the facets[] array form.
            let facets = cur.getAll('facets[]');
            if(facets.length){ params['facets'] = facets; }
            // forward range[n_col][from|to] (range filter).
            for(const pair of (cur as any).entries()){ if(pair[0].indexOf('range[') === 0 && pair[1]){ params[pair[0]] = pair[1]; } }
            const url = admin_url('search/lists&' + $.param(params));

            // measure the total result count (permission-filtered) + load time, shown on the header.
            SearchEvent.meiliStart = new Date().getTime();
            SearchEvent.meiliTotal = 0;
            SearchEvent.meiliPending = 0;
            SearchEvent.meiliHasTotal = false;
            SearchEvent.meiliCapped = false;

            // search target table names
            let searchTables = [];
            for (var i = 0; i < tables.length; i++) {
                let table = tables[i];
                if(!hasValue(table)){
                    continue;
                }
                searchTables.push(table);

                // if searchTables.length >= SIZE, execute search
                if(searchTables.length >= 5){
                    SearchEvent.getNaviDataItems(url, searchTables);

                    searchTables = [];
                }
            }
        
            // if searchTables.length > 0, execute last search
            if(searchTables.length > 0){
                SearchEvent.getNaviDataItems(url, searchTables);
            }
        }
        

        private static getRelationNaviData() {
            var tables = JSON.parse($('.tables').val() as string);
            for (var i = 0; i < tables.length; i++) {
                var table = tables[i];
                if(!hasValue(table)){
                    continue;
                }
                var url = admin_url('search/relation?search_table_name=' + table.table_name 
                    + '&value_table_name=' + $('.table_name').val() 
                    + '&value_id=' + $('.value_id').val()
                    + '&search_type=' + table.search_type
                );
                SearchEvent.getNaviDataItem(url, table.box_key);
            }
        }

        /**
         * Search navi data
         * @param url 
         * @param box_key 
         */
        private static getNaviDataItem(url, box_key){
            var box = $('[data-box_key="' + box_key + '"]');
            box.find('.overlay').show();
            // Get Data
            $.ajax({
                url: url,
                type: 'GET',
                context: {box: box},
            })
            // Execute when success Ajax Request
            .done(function(data){
                var box = this.box;
                box.find('.box-body .box-body-inner-header').html(data.header);
                box.find('.box-body .box-body-inner-body').html(data.body);
                box.find('.box-body .box-body-inner-footer').html(data.footer);
                box.find('.overlay').hide();
                Exment.CommonEvent.tableHoverLink();
            })
            .always(function(data){
            });
        }

        /**
         * Search navi data multiple
         * @param url 
         * @param searchTables 
         */
        private static getNaviDataItems(url, searchTables){
            let tableNames = [];
            // show overlay
            for(let i = 0; i < searchTables.length; i++){
                let box = $('[data-box_key="' + searchTables[i].box_key + '"]');
                box.find('.overlay').show();

                tableNames.push(searchTables[i].table_name);
            }

            SearchEvent.meiliPending++;
            // Get Data
            $.ajax({
                url: url,
                data: {
                    table_names: tableNames.join(),
                },
                type: 'GET',
                context: {searchTables: searchTables},
            })
            // Execute when success Ajax Request
            .done(function(datalist){
                let searchTables = this.searchTables;
                for(let i = 0; i < searchTables.length; i++){
                    let box = $('[data-box_key="' + searchTables[i].box_key + '"]');
                    
                    let data = datalist[searchTables[i].table_name];
                    if(!hasValue(data)){
                        box.find('.overlay').hide();
                        continue;
                    }

                    box.find('.box-body .box-body-inner-header').html(data.header);
                    box.find('.box-body .box-body-inner-body').html(data.body);
                    box.find('.box-body .box-body-inner-footer').html(data.footer);
                    box.find('.overlay').hide();

                    // total is only present when running through Meili; the MySQL fallback keeps the old behavior.
                    if(typeof data.total !== 'undefined'){
                        SearchEvent.meiliHasTotal = true;
                        SearchEvent.meiliTotal += data.total;
                        // capped = the over-fetch cap was reached -> the count is a
                        // floor, show "N+" so it never reads as an exact number.
                        if(data.total_capped){
                            SearchEvent.meiliCapped = true;
                        }
                        if(data.total === 0){
                            box.hide();
                        }
                        else{
                            box.find('.box-header .meili-box-count').text('(' + data.total.toLocaleString() + (data.total_capped ? '+' : '') + ')');
                        }
                    }
                }
                
                Exment.CommonEvent.tableHoverLink();
            })
            .always(function(data){
                SearchEvent.meiliPending--;
                SearchEvent.updateResultMeta();
            });
        }

        /**
         * Result header: "— N result(s) (X ms)". Only rendered when every box has finished loading.
         */
        private static updateResultMeta(){
            const meta = $('.meili-result-meta');
            if(!meta.length || !SearchEvent.meiliHasTotal || SearchEvent.meiliPending > 0){
                return;
            }
            const ms = new Date().getTime() - SearchEvent.meiliStart;
            const suffix = SearchEvent.meiliCapped ? '+' : '';
            meta.text('— ' + SearchEvent.meiliTotal.toLocaleString() + suffix + ' ' + meta.data('unit') + ' (' + ms + ' ms)');
            if(SearchEvent.meiliTotal === 0){
                $('.meili-empty').show();
            }
        }
    }
}

$(function () {
    Exment.SearchEvent.AddEvent();
    Exment.SearchEvent.AddEventOnce();
});


