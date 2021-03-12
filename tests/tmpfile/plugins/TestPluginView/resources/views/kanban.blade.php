<div id="kanban"></div>

<input type="hidden" id="kanban_value" value="{{json_encode($values)}}">

<script>
$(function(){
    callJkanban();
    appendKanbanItemEvent();
});
</script>