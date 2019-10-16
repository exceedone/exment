declare var toastr: any;
declare var math: any;
declare var LA: any;
declare var BigNumber: any;
declare function swal(...x:any): any;

namespace Exment {
    export class ChangeFieldEvent {
        /**
         * toggle right-top help link and color
         */
        public static ChangeFieldEvent(ajax, eventTriggerSelector, eventTargetSelector){
            if(!hasValue(ajax)){
                return;
            }
            $('.has-many-table').off('change').on('change', eventTriggerSelector, function (ev) {
                var changeTd = $(ev.target).closest('tr').find('td:nth-child(3)>div>div');
                if(!hasValue($(ev.target).val())){
                    changeTd.html('');
                    return;
                }
                $.ajax({
                    url: ajax,
                    type: "GET",
                    data: {
                        'target': $(this).closest('tr').find(eventTargetSelector).val(),
                        'cond_name': $(this).attr('name'),
                        'cond_key': $(this).val(),
                    },
                    context: this,
                    success: function (data) {
                        var json = JSON.parse(data);
                        $(this).closest('tr').find('td:nth-child(3)>div>div').html(json.html);
                        if (json.script) {
                            eval(json.script);
                        }
                    },
                });
            });
        }
    }
}