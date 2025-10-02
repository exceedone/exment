var Exment;
(function (Exment) {
    const EVENT_LOADED = 'exment:loaded';
    const EVENT_FIRST_LOADED = 'exment:first_loaded';
    const EVENT_FORM_LOADED = 'exment:form_loaded';
    const EVENT_LIST_LOADED = 'exment:list_loaded';
    const EVENT_SHOW_LOADED = 'exment:show_loaded';
    //const EVENT_CALENDAR_BIND = 'exment:calendar_bind'; // Used by calendar.blade.php
    /**
    * Column Event Script.
    */
    class CustomScriptEvent {
        static AddEvent() {
            CustomScriptEvent.fireEvent();
            CustomScriptEvent.fireListEvent();
            CustomScriptEvent.fireFormEvent();
            CustomScriptEvent.fireShowEvent();
        }
        static AddEventOnce() {
            $(document).on('pjax:complete', function (event) {
                CustomScriptEvent.AddEvent();
            });
            $(window).trigger(EVENT_FIRST_LOADED);
        }
        static fireEvent() {
            $(window).trigger(EVENT_LOADED);
        }
        static fireFormEvent() {
            if (!hasValue($('.block_custom_value_form'))) {
                return;
            }
            $(window).trigger(EVENT_FORM_LOADED);
        }
        static fireListEvent() {
            if (!hasValue($('.block_custom_value_grid'))) {
                return;
            }
            $(window).trigger(EVENT_LIST_LOADED);
        }
        static fireShowEvent() {
            if (!hasValue($('.block_custom_value_show'))) {
                return;
            }
            $(window).trigger(EVENT_SHOW_LOADED);
        }
    }
    Exment.CustomScriptEvent = CustomScriptEvent;
})(Exment || (Exment = {}));
$(function () {
    Exment.CustomScriptEvent.AddEvent();
    Exment.CustomScriptEvent.AddEventOnce();
});
(function (Exment) {
    class PluginUpdater {
        static init() {
            if (this._bound) return;
            this._bound = true;

            document.addEventListener('click', function (e) {
                const btn = e.target.closest('.plugin-update');
                if (!btn) return;


                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();

                const $btn = $(btn);
                const downloadUrl = $btn.data('url');
                const pluginId = $btn.data('plugin');


                if (!downloadUrl) {
                    return;
                }

                $btn.prop('disabled', true).text('更新中...');

                $.ajax({
                    url: `/admin/plugin/${pluginId}/update-remote`,
                    type: 'POST',
                    data: {
                        _token: LA.token,
                        download_url: downloadUrl
                    }
                })
                    .done(resp => {
                        location.reload();
                    })
                    .fail(xhr => {
                        alert('Lỗi server: ' + xhr.status);
                    })
                    .always(() => {
                        $btn.prop('disabled', false).text('Update');
                    });

            }, true);
        }
    }

    $(function () {
        console.log('[PluginUpdater] Init');
        PluginUpdater.init();
    });

    Exment.PluginUpdater = PluginUpdater;
})(Exment || (Exment = {}));

