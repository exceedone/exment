{{-- Temporary raises of the API rate limit (ISS00442): ApiRateRaiseController --}}
@php
    $confirm = function ($text) { return json_encode(strval($text), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); };
    $oldType = array_get($old, 'target_type', 'user');
@endphp
<div class="box box-default" id="api-rate-raise-add">
    <div class="box-header with-border">
        <h3 class="box-title">{{ exmtrans('api_rate_raise.add_header') }}</h3>
    </div>
    <form method="post" action="{{ $storeUrl }}" class="form-horizontal api-rate-raise-form">
        <input type="hidden" name="_token" value="{{ csrf_token() }}">
        <div class="box-body">
            <p class="text-muted">
                {{ exmtrans('api_rate_raise.base_help', ['base' => $base]) }}
                @if(isset($ceiling))
                    {{ exmtrans('api_rate_raise.ceiling_help', ['ceiling' => $ceiling]) }}
                @endif
            </p>
            <div class="form-group">
                <label class="col-sm-2 control-label">{{ exmtrans('api_rate_raise.target_type') }}</label>
                <div class="col-sm-8">
                    <label class="radio-inline"><input type="radio" name="target_type" value="user" @if($oldType === 'user') checked @endif onchange="document.getElementById('api-rate-raise-user').style.display='';document.getElementById('api-rate-raise-client').style.display='none';"> {{ exmtrans('api_rate_raise.target_type_options.user') }}</label>
                    <label class="radio-inline"><input type="radio" name="target_type" value="client" @if($oldType === 'client') checked @endif onchange="document.getElementById('api-rate-raise-user').style.display='none';document.getElementById('api-rate-raise-client').style.display='';"> {{ exmtrans('api_rate_raise.target_type_options.client') }}</label>
                </div>
            </div>
            <div class="form-group" id="api-rate-raise-user" @if($oldType !== 'user') style="display:none" @endif>
                <label class="col-sm-2 control-label" for="api-rate-raise-user-id">{{ exmtrans('api_rate_raise.target_type_options.user') }}</label>
                <div class="col-sm-8">
                    <select class="form-control" name="user_id" id="api-rate-raise-user-id">
                        <option value=""></option>
                        @foreach($users as $id => $label)
                            <option value="{{ $id }}" @if(strval(array_get($old, 'user_id')) === strval($id)) selected @endif>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="form-group" id="api-rate-raise-client" @if($oldType !== 'client') style="display:none" @endif>
                <label class="col-sm-2 control-label" for="api-rate-raise-client-id">{{ exmtrans('api_rate_raise.target_type_options.client') }}</label>
                <div class="col-sm-8">
                    <select class="form-control" name="client_id" id="api-rate-raise-client-id">
                        <option value=""></option>
                        @foreach($clients as $id => $label)
                            <option value="{{ $id }}" @if(strval(array_get($old, 'client_id')) === strval($id)) selected @endif>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-2 control-label" for="api-rate-raise-per-minute">{{ exmtrans('api_rate_raise.per_minute') }}</label>
                <div class="col-sm-3">
                    <input type="number" class="form-control" name="per_minute" id="api-rate-raise-per-minute" min="{{ $base + 1 }}" @if(isset($ceiling)) max="{{ $ceiling }}" @endif required value="{{ array_get($old, 'per_minute') }}">
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-2 control-label" for="api-rate-raise-hours">{{ exmtrans('api_rate_raise.hours') }}</label>
                <div class="col-sm-3">
                    <input type="number" class="form-control" name="hours" id="api-rate-raise-hours" min="1" max="{{ $maxHours }}" required value="{{ array_get($old, 'hours', min(8, $maxHours)) }}">
                </div>
                <div class="col-sm-5"><p class="form-control-static text-muted">{{ exmtrans('api_rate_raise.hours_help', ['max' => $maxHours]) }}</p></div>
            </div>
            <div class="form-group">
                <label class="col-sm-2 control-label" for="api-rate-raise-reason">{{ exmtrans('api_rate_raise.reason') }}</label>
                <div class="col-sm-8">
                    <input type="text" class="form-control" name="reason" id="api-rate-raise-reason" maxlength="1000" placeholder="{{ exmtrans('api_rate_raise.reason_placeholder') }}" value="{{ array_get($old, 'reason') }}">
                </div>
            </div>
        </div>
        <div class="box-footer">
            <div class="col-sm-2"></div>
            <div class="col-sm-8">
                <button type="submit" class="btn btn-primary">{{ exmtrans('api_rate_raise.add') }}</button>
            </div>
        </div>
    </form>
</div>

<div class="box box-default" id="api-rate-raise-list">
    <div class="box-header with-border">
        <h3 class="box-title">{{ exmtrans('api_rate_raise.list_header') }}</h3>
    </div>
    <div class="box-body">
        <p class="text-muted">{{ exmtrans('api_rate_raise.list_help') }}</p>
        @if($raises->isEmpty())
            <p class="api-rate-raise-empty">{{ exmtrans('api_rate_raise.empty') }}</p>
        @else
        <div class="table-responsive">
        <table class="table table-hover api-rate-raise-table">
            <thead>
                <tr>
                    <th>{{ exmtrans('api_rate_raise.column.target') }}</th>
                    <th>{{ exmtrans('api_rate_raise.per_minute') }}</th>
                    <th>{{ exmtrans('api_rate_raise.column.expires_at') }}</th>
                    <th>{{ exmtrans('api_rate_raise.column.state') }}</th>
                    <th>{{ exmtrans('api_rate_raise.reason') }}</th>
                    <th>{{ exmtrans('api_rate_raise.column.created') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @foreach($raises as $raise)
                @php
                    $active = $raise->isActive();
                    $targetName = $raise->target_type === 'client' ? ($clients[$raise->target_id] ?? $raise->target_id) : ($users[$raise->target_id] ?? $raise->target_id);
                @endphp
                <tr class="api-rate-raise{{ $active ? ' api-rate-raise-active' : ' text-muted api-rate-raise-expired' }}">
                    <td>
                        <small class="text-muted">{{ exmtrans('api_rate_raise.target_type_options.' . $raise->target_type) }}</small><br>
                        {{ $targetName }}
                    </td>
                    <td>{{ $raise->per_minute }}</td>
                    <td>{{ $local($raise->expires_at) }}</td>
                    <td>
                        @if($active)
                            <span class="label label-success">{{ exmtrans('api_rate_raise.state.active') }}</span>
                        @else
                            <span class="label label-default">{{ exmtrans('api_rate_raise.state.expired') }}</span>
                        @endif
                    </td>
                    <td style="white-space: pre-wrap;">{{ $raise->reason }}</td>
                    <td>
                        {{ $local($raise->created_at) }}<br>
                        <small class="text-muted">{{ isset($raise->created_user_id) ? ($users[strval($raise->created_user_id)] ?? $raise->created_user_id) : '' }}</small>
                    </td>
                    <td>
                        <form method="post" action="{{ $deleteUrl($raise->id) }}" style="display:inline" onsubmit="return window.confirm({{ $confirm(exmtrans('api_rate_raise.confirm_delete')) }});">
                            <input type="hidden" name="_token" value="{{ csrf_token() }}">
                            <button type="submit" class="btn btn-xs btn-danger"><i class="fa fa-trash"></i> {{ trans('admin.delete') }}</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
        @endif
    </div>
</div>
