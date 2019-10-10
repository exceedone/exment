@if($errors->has($errorKeyFix))
    @foreach($errors->get($errorKeyFix) as $message)
        <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i> {{$message}}</label><br/>
    @endforeach
@endif
