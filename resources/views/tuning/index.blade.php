{{-- Tuning (ISS00414): the advice of SystemRequireList TUNING, with the buttons of TuningController::action --}}
{{-- what Exment can set by itself (AutoTuning), each to choose --}}
@if(!empty($autoItems))
<div class="box box-default" id="tuning-auto">
    <div class="box-header with-border">
        <h3 class="box-title">{{ exmtrans('tuning.auto.header') }}</h3>
    </div>
    <div class="box-body">
        <p>{{ exmtrans('tuning.auto.explain') }}</p>
        <form method="post" action="{{ $actionUrl }}" onsubmit="return window.confirm('{{ exmtrans('tuning.action_confirm') }}');">
            <input type="hidden" name="_token" value="{{ csrf_token() }}">
            <input type="hidden" name="action" value="auto">
            @foreach($autoItems as $key => $label)
            <div class="checkbox">
                <label><input type="checkbox" name="auto_tuning[]" value="{{ $key }}" checked> {{ $label }}</label>
            </div>
            @endforeach
            <button type="submit" class="btn btn-sm btn-primary">{{ exmtrans('tuning.auto.button') }}</button>
        </form>
    </div>
</div>
@endif
<div class="box box-default">
    <div class="box-body">
        <p>{{ exmtrans('tuning.explain') }}</p>
        <p class="text-muted">{{ exmtrans('tuning.server_admin') }}</p>
        <div class="container-fluid">
            <div class="row row-eq-height">
                @foreach($checkResult->getItems() as $check)
                @php
                    $set = $check->getResultClassSet();
                    $action = $check->getAction();
                    $canRun = !is_null($action) && ($serverActions || !$check->isServerWide());
                @endphp
                <div class="col-xs-12 col-sm-6 require_item tuning_item" data-tuning="{{ $check->getKey() }}">
                    <h4 class="require_item_header">
                        <i class="fa {{ $set['fontawesome'] }}" aria-hidden="true" style="color: {{ $set['color'] }}"></i>
                        {{ $check->getLabel() }}
                    </h4>
                    <p>{{ $check->getExplain() }}</p>
                    <p>{{ exmtrans('common.result') }} : <span class="bold">{{ $check->getResultText() }}</span></p>

                    @if($check->checkResult() != 'ok')
                        <p class="text-warning">{{ $check->getMessage() }}</p>
                        @if(!is_null($fix = $check->getFixText()))
                        <pre class="tuning_fix" style="white-space: pre-wrap;">{{ $fix }}</pre>
                        @endif
                    @endif

                    {{-- a div, not a p: a form may not be in a p (the browser would close the p before it) --}}
                    <div class="tuning_buttons">
                        @if($canRun)
                        <form method="post" action="{{ $actionUrl }}" style="display:inline" onsubmit="return window.confirm('{{ exmtrans('tuning.action_confirm') }}');">
                            <input type="hidden" name="_token" value="{{ csrf_token() }}">
                            <input type="hidden" name="action" value="{{ $action }}">
                            <button type="submit" class="btn btn-sm btn-warning">{{ exmtrans('tuning.action.' . $action) }}</button>
                        </form>
                        @elseif(!is_null($action) && $check->isServerWide())
                        <span class="text-muted small">{{ exmtrans('tuning.server_actions_off') }}</span>
                        @endif
                        <a href="{{ $check->getSettingUrl() }}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-default">
                            {{ exmtrans('system_require.check_setting') }}
                        </a>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</div>

{{-- the system requirements, moved here from the system setting (ISS02828) --}}
@if(isset($requireResult))
<div class="box box-default" id="tuning-system-require">
    <div class="box-header with-border">
        <h3 class="box-title">{{ exmtrans('install.system_require.header') }}</h3>
    </div>
    <div class="box-body">
        @include('exment::widgets.system-require', ['checkResult' => $requireResult])
    </div>
</div>
@endif
