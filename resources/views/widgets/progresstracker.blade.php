<ol class="track-progress" data-steps="{!! count($steps) !!}">
    @foreach($steps as $step)
    <li class="{!! $step['class']?? '' !!}">
        <a href="{{$step['url']}}" data-modalclose="false">
            {{$step['title']}}&nbsp;&nbsp;<small>{{$step['description']}}</small>
        </a>
        <i></i>
    </li>
    @endforeach
</ol>
