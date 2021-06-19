var Exment;
(function (Exment) {
    class SearchEvent {
        /**
         * Call only once. It's $(document).on event.
         */
        static AddEventOnce() {
            SearchEvent.searchHeaderEvent();
            $(document).on('click.exment_search', '[data-ajax-link]', [], SearchEvent.dataAjaxLinkEvent);
        }
        static AddEvent() {
        }
        static searchHeaderEvent() {
            if (!hasValue($('.search-form #query'))) {
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
                select: function (e, ui) {
                    if (ui.item) {
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
                    'tabindex': -1,
                    'class': 'ui-menu-item-wrapper',
                    'html': [p, $('<span/>', { 'text': item.text })]
                });
                return $('<li class="ui-menu-item-with-icon"></li>')
                    .data("item.autocomplete", item)
                    .append(div)
                    .appendTo(ul);
            };
        }
        static getNaviData(isList) {
            if (isList) {
                SearchEvent.getListNaviData();
            }
            else {
                SearchEvent.getRelationNaviData();
            }
        }
        /**
         * Get Search Navi data for List
         */
        static getListNaviData() {
            const tables = JSON.parse($('.tables').val());
            const search_execute_count = $('#search_execute_count');
            const url = admin_url('search/lists&' + $.param({ query: $('.base_query').val() }));
            // search target table names
            let searchTables = [];
            for (var i = 0; i < tables.length; i++) {
                let table = tables[i];
                if (!hasValue(table)) {
                    continue;
                }
                searchTables.push(table);
                // if searchTables.length >= SIZE, execute search
                if (searchTables.length >= 5) {
                    SearchEvent.getNaviDataItems(url, searchTables);
                    searchTables = [];
                }
            }
            // if searchTables.length > 0, execute last search
            if (searchTables.length > 0) {
                SearchEvent.getNaviDataItems(url, searchTables);
            }
        }
        static getRelationNaviData() {
            var tables = JSON.parse($('.tables').val());
            for (var i = 0; i < tables.length; i++) {
                var table = tables[i];
                if (!hasValue(table)) {
                    continue;
                }
                var url = admin_url('search/relation?search_table_name=' + table.table_name
                    + '&value_table_name=' + $('.table_name').val()
                    + '&value_id=' + $('.value_id').val()
                    + '&search_type=' + table.search_type);
                SearchEvent.getNaviDataItem(url, table.box_key);
            }
        }
        /**
         * Search navi data
         * @param url
         * @param box_key
         */
        static getNaviDataItem(url, box_key) {
            var box = $('[data-box_key="' + box_key + '"]');
            box.find('.overlay').show();
            // Get Data
            $.ajax({
                url: url,
                type: 'GET',
                context: { box: box },
            })
                // Execute when success Ajax Request
                .done(function (data) {
                var box = this.box;
                box.find('.box-body .box-body-inner-header').html(data.header);
                box.find('.box-body .box-body-inner-body').html(data.body);
                box.find('.box-body .box-body-inner-footer').html(data.footer);
                box.find('.overlay').hide();
                Exment.CommonEvent.tableHoverLink();
            })
                .always(function (data) {
            });
        }
        /**
         * Search navi data multiple
         * @param url
         * @param searchTables
         */
        static getNaviDataItems(url, searchTables) {
            let tableNames = [];
            // show overlay
            for (let i = 0; i < searchTables.length; i++) {
                let box = $('[data-box_key="' + searchTables[i].box_key + '"]');
                box.find('.overlay').show();
                tableNames.push(searchTables[i].table_name);
            }
            // Get Data
            $.ajax({
                url: url,
                data: {
                    table_names: tableNames.join(),
                },
                type: 'GET',
                context: { searchTables: searchTables },
            })
                // Execute when success Ajax Request
                .done(function (datalist) {
                let searchTables = this.searchTables;
                for (let i = 0; i < searchTables.length; i++) {
                    let box = $('[data-box_key="' + searchTables[i].box_key + '"]');
                    let data = datalist[searchTables[i].table_name];
                    if (!hasValue(data)) {
                        box.find('.overlay').hide();
                        continue;
                    }
                    box.find('.box-body .box-body-inner-header').html(data.header);
                    box.find('.box-body .box-body-inner-body').html(data.body);
                    box.find('.box-body .box-body-inner-footer').html(data.footer);
                    box.find('.overlay').hide();
                }
                Exment.CommonEvent.tableHoverLink();
            })
                .always(function (data) {
            });
        }
    }
    SearchEvent.dataAjaxLinkEvent = (ev) => {
        // get link
        const url = $(ev.target).closest('[data-ajax-link]').data('ajax-link');
        const box_key = $(ev.target).closest('[data-box_key]').data('box_key');
        SearchEvent.getNaviDataItem(url, box_key);
    };
    Exment.SearchEvent = SearchEvent;
})(Exment || (Exment = {}));
$(function () {
    Exment.SearchEvent.AddEvent();
    Exment.SearchEvent.AddEventOnce();
});
