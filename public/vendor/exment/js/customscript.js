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
                const marketplaceId = $btn.data('marketplace-id');
                const pluginId = $btn.data('plugin');
                const latestVersion = $btn.data('latest-version');


                if (!marketplaceId) {
                    console.error('[PluginUpdater] No marketplace ID found');
                    return;
                }

                // Show confirmation dialog with SweetAlert
                swal({
                    title: 'Update Plugin',
                    text: `Do you want to update this plugin to version ${latestVersion}?`,
                    type: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#d33',
                    confirmButtonText: 'Yes, update it!',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (!result.value) {
                        return;
                    }

                    // Show loading state
                    $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Updating...');
                    
                    swal({
                        title: 'Updating...',
                        text: 'Please wait while the plugin is being updated',
                        type: 'info',
                        showConfirmButton: false,
                        allowOutsideClick: false
                    });

                    // Fetch versions from marketplace
                    const marketplaceUrl = $('meta[name="marketplace-url"]').attr('content') || 'http://marketplace.local';
                    
                    $.ajax({
                        url: `${marketplaceUrl}/api/plugins/${marketplaceId}/versions`,
                        type: 'GET',
                    })
                        .done(data => {
                            // Get latest version ID
                            const versions = data.versions || [];
                            const latestVersionData = versions.find(v => v.is_latest);
                            
                            if (!latestVersionData || !latestVersionData.id) {
                                swal({
                                    title: 'Error',
                                    text: 'Cannot find latest version',
                                    type: 'error'
                                });
                                $btn.prop('disabled', false).html('<i class="fa fa-arrow-up"></i> Update');
                                return;
                            }

                            // Call install API (it will handle update automatically)
                            $.ajax({
                                url: `/admin/plugin-market/${marketplaceId}/install`,
                                type: 'POST',
                                data: {
                                    _token: LA.token,
                                    version: latestVersionData.id
                                }
                            })
                                .done(resp => {
                                    console.log('[PluginUpdater] Install response:', resp);
                                    if (resp.success) {
                                        swal({
                                            title: 'Success!',
                                            text: resp.message || 'Plugin updated successfully!',
                                            type: 'success',
                                            timer: 2000,
                                            showConfirmButton: false
                                        }).then(() => {
                                            location.reload();
                                        });
                                    } else {
                                        console.error('[PluginUpdater] Install failed payload:', resp);
                                        swal({
                                            title: 'Update Failed',
                                            text: resp.error || 'Unknown error',
                                            type: 'error'
                                        });
                                        $btn.prop('disabled', false).html('<i class="fa fa-arrow-up"></i> Update');
                                    }
                                })
                                .fail(xhr => {
                                        console.error('[PluginUpdater] Install AJAX failed:', xhr.status, xhr.responseText);
                                        let error = `Server error: ${xhr.status}`;
                                        if (xhr.responseJSON && xhr.responseJSON.error) {
                                            error = xhr.responseJSON.error;
                                        }
                                    swal({
                                        title: 'Update Failed',
                                        text: error,
                                        type: 'error'
                                    });
                                    $btn.prop('disabled', false).html('<i class="fa fa-arrow-up"></i> Update');
                                });
                        })
                        .fail(xhr => {
                            swal({
                                title: 'Error',
                                text: `Failed to fetch versions: ${xhr.status}`,
                                type: 'error'
                            });
                            $btn.prop('disabled', false).html('<i class="fa fa-arrow-up"></i> Update');
                        });
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

