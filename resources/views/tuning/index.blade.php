{{-- Tuning (ISS00414): the advice of SystemRequireList TUNING, with the buttons of TuningController::action --}}
{{-- what Exment can set by itself (AutoTuning), each to choose --}}
@if(!empty($autoItems) || !empty($autoLast))
<div class="box box-default" id="tuning-auto">
    <div class="box-header with-border">
        <h3 class="box-title">{{ exmtrans('tuning.auto.header') }}</h3>
    </div>
    <div class="box-body">
        @if(!empty($autoItems))
        <p>{{ exmtrans('tuning.auto.explain') }}</p>
        {{-- the confirm says what will change: the checked items --}}
        <form method="post" action="{{ $actionUrl }}" id="tuning-auto-form" data-confirm="{{ exmtrans('tuning.auto.confirm') }}" data-none="{{ exmtrans('tuning.auto.none_checked') }}"
            onsubmit="var f = this, items = Array.prototype.filter.call(f.querySelectorAll('input[name=&quot;auto_tuning[]&quot;]'), function (c) { return c.checked; }).map(function (c) { return '- ' + c.parentNode.textContent.trim(); }); if (!items.length) { window.alert(f.dataset.none); return false; } return window.confirm(f.dataset.confirm + '\n\n' + items.join('\n'));">
            <input type="hidden" name="_token" value="{{ csrf_token() }}">
            <input type="hidden" name="action" value="auto">
            @foreach($autoItems as $key => $label)
            <div class="checkbox">
                <label><input type="checkbox" name="auto_tuning[]" value="{{ $key }}" checked> {{ $label }}</label>
            </div>
            @endforeach
            <button type="submit" class="btn btn-sm btn-primary">{{ exmtrans('tuning.auto.button') }}</button>
        </form>
        @else
        <p class="text-muted">{{ exmtrans('tuning.auto.nothing') }}</p>
        @endif

        {{-- what it did last, at the setup or here --}}
        @if(!empty($autoLast))
        <p style="margin-top:12px" class="fw-bold">
            {{ exmtrans('tuning.auto.last', ['time' => \Carbon\Carbon::createFromTimestamp($autoLast['time'])->setTimezone(config('app.timezone'))->format('Y/m/d H:i')]) }}
            @if($autoLast['setup']) ({{ exmtrans('tuning.auto.last_setup') }}) @endif
        </p>
        <table class="table table-condensed" style="max-width: 900px;">
            <tbody>
                @foreach($autoLast['results'] as $result)
                <tr data-auto-tuning="{{ $result['key'] ?? '' }}" data-status="{{ $result['status'] ?? '' }}">
                    <th style="width: 50%;">{{ exmtrans('tuning.auto.item.' . ($result['key'] ?? '')) }}</th>
                    <td class="{{ ($result['status'] ?? '') === 'failed' ? 'text-danger' : 'text-success' }}">
                        {{ exmtrans('tuning.auto.status.' . ($result['status'] ?? '')) }}
                        @if(!empty($result['message'])) : {{ $result['message'] }} @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
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
                            {{-- what the action is for, chosen here (the columns of the search index) --}}
                            @foreach($check->getChoices() ?? [] as $value => $label)
                            <div class="checkbox">
                                <label><input type="checkbox" name="choices[]" value="{{ $value }}"> {{ $label }}</label>
                            </div>
                            @endforeach
                            <button type="submit" class="btn btn-sm btn-warning">{{ exmtrans('tuning.action.' . $action) }}</button>
                        </form>
                        @elseif(!is_null($action) && $check->isServerWide())
                        <span class="text-muted small">{{ exmtrans('tuning.server_actions_off') }}</span>
                        @endif
                        @if(!is_null($settingUrl = $check->getSettingUrl()))
                        <a href="{{ $settingUrl }}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-default">
                            {{ exmtrans('system_require.check_setting') }}
                        </a>
                        @endif
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
