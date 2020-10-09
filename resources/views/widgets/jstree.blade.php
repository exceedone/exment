<div id="jstree_div"></div>
<script type="text/javascript">
    $(function () {
        $('#jstree_div').jstree({ 'core' : {
            'data' : {
                'url': "{{ $data_get_url }}",
                'data' : function (node) {
                    return { 'id' : node.id };
                }
            },
        }})
        .on("select_node.jstree", function(e, data){
            $.ajax({
                type: "GET",
                url: "{{ $file_get_url }}",
                data: "nodeid=" + data.node.id,
                cache: false,
                success: function(data){
                    if(data.editor){
                        $('section.content > div > div.col-sm-9').html(data.editor);
                    }
                    if ('function' == typeof selected_jstree_node) {
                        selected_jstree_node();
                    }
                },
                error: function(msg){
                    alert(msg);
                }
            });  
        });
    });
</script>
