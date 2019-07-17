<?php

namespace Exceedone\Exment\Form\Navbar;

use Exceedone\Exment\Model\Define;
use Illuminate\Contracts\Support\Renderable;

class NotifyNav implements Renderable
{
    public function render()
    {
        $ajax_url = admin_urls('webapi', 'notifyPage');

        $script = <<<SCRIPT
        $(function(){
            $.ajax({
                url: "$ajax_url",
                dataType: "json",
                type: "GET",
                success: function (data) {
                    $('.navbar-notify ul.menu').html();
                    if(data.count > 0){
                        $('.container-notify .label-danger').remove();
                        $('.container-notify').append('<span class="label label-danger">' + data.count + '</span>');
                    }
                    for(let i = 0; i < data.items.length; i++){
                        let d = data.items[i];
                        let li = $('<li/>', {
                            html: $('<a/>', {
                                href: hasValue(d.href) ? d.href : 'javascript:void(0);',
                                html: [
                                    $('<p/>', {
                                        html:[
                                            $('<i/>', {
                                                'class': 'fa ' + d.icon,
                                                //'style': hasValue(d.color) ? 'color:' + d.color : null
                                            }),
                                            $('<span></span>', {
                                                'text': d.table_view_name,
                                                'style': hasValue(d.color) ? 'background-color:' + d.color : null
                                            }),
                                        ],
                                        'class': 'search-item-icon'
                                    }),
                                    $('<span/>', {
                                        'text': d.label,
                                    }),
                                ],
                            }),
                        });

                        $('.navbar-notify ul.menu').append(li);
                    }
                },
            });
        });
SCRIPT;

        \Admin::script($script);

        $list = trans('admin.list');
        return <<<EOT
<li class="navbar-notify dropdown notifications-menu">
    <a href="javascript:void(0);" class="container-notify hidden-xs dropdown-toggle" data-toggle="dropdown" aria-expanded="true">
      <i class="fa fa-bell"></i>
      
    </a>

    <ul class="dropdown-menu">
        <li>
        <!-- inner menu: contains the actual data -->
        <ul class="menu">
           
        </ul>
        </li>
        <li class="footer"><a href="#">$list</a></li>
    </ul>
</li>
EOT;
    }
}
