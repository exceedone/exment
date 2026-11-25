{{-- The tuning advice on the install screen (ISS00414): only what is recommended, without buttons; it never blocks the install --}}
@php
    $tuningItems = collect($tuningResult->getItems())->filter(function ($check) {
        return $check->checkResult() != 'ok';
    });
@endphp
@if($tuningItems->count() > 0)
<div class="container-fluid tuning_advice">
    <h4 style="margin-top:1.5em;">{{ exmtrans('tuning.install_header') }}</h4>
    <p class="small">{{ exmtrans('tuning.install_explain') }}</p>
    <div class="row row-eq-height">
        @foreach($tuningItems as $check)
        <div class="col-xs-12 col-sm-6 require_item">
            <h5 class="bold">
                <i class="fa fa-lightbulb-o" aria-hidden="true" style="color: #3c8dbc"></i>
                {{ $check->getLabel() }} : {{ $check->getResultText() }}
            </h5>
            <p class="small">{{ $check->getMessage() }}</p>
            @if(!is_null($fix = $check->getFixText()))
            <pre class="small" style="white-space: pre-wrap;">{{ $fix }}</pre>
            @endif
        </div>
        @endforeach
    </div>
</div>
@endif
