@if($errors->has($errorKeyFix))
    @foreach($errors->get($errorKeyFix) as $message)
        <label class="control-label text-lg-end text-nowrap" for="inputError"><i class="fa fa-times-circle-o"></i> {{$message}}</label><br/>
    @endforeach
@endif
