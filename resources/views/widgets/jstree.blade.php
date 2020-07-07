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
            var path = data.instance.get_path(data.node,'/');
            $.ajax({
                type: "GET",
                dataType: "text",
                url: "{{ $file_get_url }}",
                data: "nodepath=" + path,
                cache: false,
                success: function(data){
                    var json = JSON.parse(data);
                    var editor = document.querySelector(".CodeMirror").CodeMirror;
                    editor.setValue(json.filedata);
                    editor.save();
                    $('#file_path').val(json.filepath);
                    $('h3.box-title').html(json.filename);
                },
                error: function(msg){
                    alert(msg);
                }
            });  
        });
    });
</script>
