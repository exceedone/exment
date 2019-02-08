<div class="input-group form-inline">
    <input type="text" class="form-control {{ $id }}" placeholder="{{$placeholder}}" name="{{$name}}" value="{{ request($name, $value) }}" style="width:auto;" readonly>
    <button type="button" class="btn btn-primary {{ $id }}_button">
        <i class="fa fa-search"></i>
    </button>
</div>