{{-- Maintenance mode (ISS01396): MaintenanceController --}}
@php
    $old = function ($key, $default) { return old($key, $default); };
@endphp
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">{{ exmtrans('maintenance.now') }}</h3>
    </div>
    <div class="box-body">
        @if($errors->has('maintenance'))
        <div class="alert alert-danger">{{ $errors->first('maintenance') }}</div>
        @endif

        @if(!isset($current))
            <p><span class="label label-success">{{ exmtrans('maintenance.state.off') }}</span></p>
            @if(isset($settings['start_at']))
            <p>{{ exmtrans('maintenance.planned', ['start_at' => $local($settings['start_at'], 'Y-m-d H:i'), 'end_at' => $local($settings['end_at'], 'Y-m-d H:i') ?: '-']) }}</p>
            @endif
        @elseif($operator)
            <p><span class="label label-danger">{{ exmtrans('maintenance.state.operator') }}</span></p>
            <p>{{ exmtrans('maintenance.operator_help') }}</p>
        @else
            <p><span class="label label-warning">{{ exmtrans('maintenance.state.on') }}</span></p>
            <p>
                {{ exmtrans('maintenance.started', ['started_at' => $local($settings['started_at'], 'Y-m-d H:i') ?: '-', 'user' => $startedBy ?? exmtrans('maintenance.by_schedule')]) }}
                @if(isset($settings['end_at']))
                <br>{{ exmtrans('maintenance.end_at', ['end_at' => $local($settings['end_at'], 'Y-m-d H:i')]) }}
                @endif
            </p>
            <form method="post" action="{{ $endUrl }}" style="display:inline" onsubmit="return window.confirm({{ json_encode(exmtrans('maintenance.confirm_end'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }});">
                <input type="hidden" name="_token" value="{{ csrf_token() }}">
                <button type="submit" class="btn btn-success">{{ exmtrans('maintenance.end_now') }}</button>
            </form>
        @endif
    </div>
</div>

<form method="post" action="{{ $saveUrl }}" class="form-horizontal" id="maintenance-form">
<input type="hidden" name="_token" value="{{ csrf_token() }}">
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">{{ exmtrans('maintenance.settings') }}</h3>
    </div>
    <div class="box-body">
        <p class="text-muted">{{ exmtrans('maintenance.help') }}</p>

        <div class="form-group">
            <label class="col-sm-2 control-label" for="maintenance-start">{{ exmtrans('maintenance.start_at') }}</label>
            <div class="col-sm-4">
                <input type="datetime-local" class="form-control" id="maintenance-start" name="start_at" value="{{ $old('start_at', $local($settings['start_at'])) }}">
                <span class="help-block">{{ exmtrans('maintenance.start_at_help') }}</span>
            </div>
            <label class="col-sm-2 control-label" for="maintenance-end">{{ exmtrans('maintenance.end_at_label') }}</label>
            <div class="col-sm-4">
                <input type="datetime-local" class="form-control" id="maintenance-end" name="end_at" value="{{ $old('end_at', $local($settings['end_at'])) }}">
                <span class="help-block">{{ exmtrans('maintenance.end_at_help') }}</span>
            </div>
        </div>

        @foreach($locales as $locale)
        <div class="form-group">
            <label class="col-sm-2 control-label" for="maintenance-message-{{ $locale }}">{{ exmtrans('maintenance.message_label', ['locale' => $locale]) }}</label>
            <div class="col-sm-10">
                <textarea class="form-control" rows="3" maxlength="2000" id="maintenance-message-{{ $locale }}" name="messages[{{ $locale }}]" placeholder="{{ exmtrans('maintenance.default_message') }}">{{ $old('messages.' . $locale, $settings['messages'][$locale] ?? '') }}</textarea>
            </div>
        </div>
        @endforeach

        <div class="form-group">
            <label class="col-sm-2 control-label" for="maintenance-ips">{{ exmtrans('maintenance.allow_ips') }}</label>
            <div class="col-sm-10">
                <textarea class="form-control" rows="3" id="maintenance-ips" name="allow_ips" placeholder="192.168.0.0/24">{{ $old('allow_ips', implode("\n", $settings['allow_ips'])) }}</textarea>
                <span class="help-block">{{ exmtrans('maintenance.allow_ips_help') }} {{ exmtrans('maintenance.your_ip', ['ip' => $yourIp]) }}</span>
            </div>
        </div>

        <div class="form-group">
            <label class="col-sm-2 control-label" for="maintenance-api">{{ exmtrans('maintenance.api') }}</label>
            <div class="col-sm-4">
                <select class="form-control" id="maintenance-api" name="api">
                    @foreach(['admin', 'stop', 'open'] as $api)
                    <option value="{{ $api }}" @if($old('api', $settings['api']) === $api) selected @endif>{{ exmtrans('maintenance.api_options.' . $api) }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="form-group">
            <div class="col-sm-offset-2 col-sm-10">
                @foreach(['stop_public', 'stop_schedule', 'stop_queue'] as $key)
                <div class="checkbox">
                    <label>
                        <input type="hidden" name="{{ $key }}" value="0">
                        <input type="checkbox" name="{{ $key }}" value="1" @if($old($key, $settings[$key])) checked @endif>
                        {{ exmtrans('maintenance.' . $key) }}
                    </label>
                </div>
                @endforeach
            </div>
        </div>
    </div>
    <div class="box-footer">
        <div class="col-sm-offset-2">
            <button type="submit" class="btn btn-primary">{{ exmtrans('maintenance.save') }}</button>
            @if(!isset($current))
            <button type="submit" class="btn btn-warning" formaction="{{ $startUrl }}" onclick="return window.confirm({{ json_encode(exmtrans('maintenance.confirm_start'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }});">{{ exmtrans('maintenance.start_now') }}</button>
            @endif
        </div>
    </div>
</div>
</form>
