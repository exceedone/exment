
function appendKanbanItemEvent(){
  $('.kanban-container .fa-external-link').off('click').on('click', function(ev){
    let target = $(ev.target).closest('a');
    let url = getBoardUrl(target, false) + '?modal=1';
    
    Exment.ModalEvent.ShowModal(target, url);
  });
}

function getBoardUrl($target, isWebApi){
  let kanban = $target.closest('.kanban-item');
  let url = admin_url(URLJoin('data', kanban.data('table_name'), kanban.data('dataid')));
  return url;
}

function getUpdateUrl($target){
  let kanban = $target.closest('.kanban-item');
  let url = kanban.data('update_url');
  return url;
}

function mergeIdAndTable($target, value){
  let kanban = $target.closest('.kanban-item');
  value['id'] = kanban.data('dataid');
  value['table_name'] = kanban.data('table_name');
}

function callJkanban(){
    new jKanban({
      element          : '#kanban',                                           
      gutter           : '15px',                                       
      widthBoard       : '250px',                                      
      responsivePercentage: false,                                    
      dragItems        : true,                                         
      boards           : JSON.parse($('#kanban_value').val()),                                           
      dragBoards       : false,                                         
      itemAddOptions: {
          enabled: false,                                              
          content: '+',                                                
          class: 'kanban-title-button btn btn-default btn-xs',         
          footer: false                                                
      },    
      itemHandleOptions: {
          enabled             : true, 
          handleClass         : "item_handle",
          customCssHandler    : "drag_handler",
          customCssIconHandler: "drag_handler_icon",
          customHandler       : "<div><span class='kanban-item-text'>%s</span><span class='kanban-item-icon'><a href='javascript::void(0);'><i class='fa fa-external-link'></i></a><i class='fa fa-arrows item_handle'></i></span></div>"
      },
      
      click            : function (el) {
      },                             
      dragEl           : function (el, source) {
      },
      dragendEl        : function (el) {
      },                             
      dropEl           : function (el, target, source, sibling) {
        let url = getUpdateUrl($(el));

        // get board
        let boardid = $(el).closest('.kanban-board').data('id');
        let params = JSON.parse($('#kanban_value').val());
        let param = null;
        let  = null;
        for(let key in params){
          let p = params[key];
          if(p.id != boardid){
            continue;
          }
          updateColumn = p.column_name;
          param = p;
          break;
        }

        let value = {};
        value[updateColumn] = param.key;

        let data = {
          _token: LA.token,
          value:value,
        };
        mergeIdAndTable($(el), data);

        $.ajax({
          type: 'POST',
          url: url,
          data: data,
          success: function (repsonse) {
            toastr.success('更新が完了しました！');
          },
          error: function (repsonse) {
            toastr.error('更新に失敗しました。');
          }
        });
      },    
      dragBoard        : function (el, source) {},                     
      dragendBoard     : function (el) {},                             
      buttonClick      : function(el, boardId) {}                      
  });
}