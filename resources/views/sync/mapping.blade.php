{{-- A mapping of the data sync (ISS00113): SyncController@mapping. The unit and the table are chosen first (the page reloads), then the fields. --}}
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">{{ exmtrans('sync.mapping') }} ・ {{ $connection->name }}</h3>
    </div>
    @if(isset($error))
        <div class="alert alert-danger" style="margin: 1em;">{{ exmtrans('sync.message.test_failed', ['message' => $error]) }}</div>
    @endif
    <form method="get" action="" class="form-horizontal" style="padding: 1em 0 0;">
        <input type="hidden" name="connection" value="{{ $connection->id }}">
        <div class="form-group">
            <label class="col-sm-2 control-label" for="sync-object">{{ exmtrans('sync.remote_object') }}</label>
            <div class="col-sm-5">
                <select class="form-control" id="sync-object" name="remote_object" onchange="this.form.submit()">
                    <option value=""></option>
                    @foreach($objects as $object)
                        <option value="{{ $object->key }}" @if($remoteObject === $object->key) selected @endif>{{ $object->label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-2 control-label" for="sync-table">{{ exmtrans('sync.table') }}</label>
            <div class="col-sm-5">
                <select class="form-control" id="sync-table" name="custom_table_id" onchange="this.form.submit()">
                    <option value=""></option>
                    @foreach($tables as $tableId => $label)
                        <option value="{{ $tableId }}" @if(isset($table) && intval($table->id) === intval($tableId)) selected @endif>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </form>
    @if($remoteObject !== '' && isset($table))
    @php
        $current = collect($mapping->field_map ?? [])->pluck('column', 'remote')->all();
        $objectLabel = collect($objects)->firstWhere('key', $remoteObject)->label ?? $remoteObject;
    @endphp
    <form method="post" action="{{ $mapping->exists ? admin_urls('sync', 'mappings', $mapping->id) : admin_url('sync/mappings') }}" class="form-horizontal">
        <input type="hidden" name="_token" value="{{ csrf_token() }}">
        <input type="hidden" name="sync_connection_id" value="{{ $connection->id }}">
        <input type="hidden" name="remote_object" value="{{ $remoteObject }}">
        <input type="hidden" name="remote_label" value="{{ $objectLabel }}">
        <input type="hidden" name="custom_table_id" value="{{ $table->id }}">
        <div class="box-body">
            <div class="form-group">
                <label class="col-sm-2 control-label" for="sync-mapping-name">{{ exmtrans('sync.name') }}</label>
                <div class="col-sm-8"><input type="text" class="form-control" id="sync-mapping-name" name="name" maxlength="100" required value="{{ $mapping->name ?: $objectLabel }}"></div>
            </div>
            <div class="form-group">
                <label class="col-sm-2 control-label">{{ exmtrans('sync.fields') }}</label>
                <div class="col-sm-8">
                    <p class="help-block">{{ exmtrans('sync.fields_help') }}</p>
                    <table class="table table-condensed">
                        <thead><tr><th>{{ exmtrans('sync.remote_field') }}</th><th>{{ exmtrans('sync.column') }}</th></tr></thead>
                        <tbody>
                        @foreach($fields as $field)
                            @php $chosen = $current[$field->code] ?? ($mapping->exists ? '' : ($suggested[$field->code] ?? '')); @endphp
                            <tr>
                                <td>{{ $field->label }} <small class="text-muted">{{ $field->code }}@if(!isset($field->type)) ・ {{ exmtrans('sync.no_type') }}@endif</small></td>
                                <td>
                                    <select class="form-control input-sm" name="field_map[{{ $field->code }}]">
                                        <option value=""></option>
                                        @foreach($columns as $name => $label)
                                            <option value="{{ $name }}" @if($chosen === $name) selected @endif>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-2 control-label">{{ exmtrans('sync.direction') }}</label>
                <div class="col-sm-8"><p class="form-control-static">{{ exmtrans('sync.direction_import') }}</p></div>
            </div>
            <div class="form-group">
                <label class="col-sm-2 control-label" for="sync-interval">{{ exmtrans('sync.interval') }}</label>
                <div class="col-sm-3">
                    <select class="form-control" id="sync-interval" name="interval_minutes">
                        @foreach(\Exceedone\Exment\Model\SyncMapping::INTERVALS as $minutes)
                            <option value="{{ $minutes }}" @if(intval($mapping->interval_minutes) === $minutes) selected @endif>{{ exmtrans('sync.every_minutes', ['minutes' => $minutes]) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-2 control-label" for="sync-conflict">{{ exmtrans('sync.conflict_rule') }}</label>
                <div class="col-sm-5">
                    <select class="form-control" id="sync-conflict" name="conflict_rule">
                        @foreach(\Exceedone\Exment\Model\SyncMapping::CONFLICT_RULES as $rule)
                            <option value="{{ $rule }}" @if($mapping->conflict_rule === $rule) selected @endif>{{ exmtrans('sync.conflict.' . $rule) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-2 control-label" for="sync-delete">{{ exmtrans('sync.delete_rule') }}</label>
                <div class="col-sm-5">
                    <select class="form-control" id="sync-delete" name="delete_rule">
                        @foreach(\Exceedone\Exment\Model\SyncMapping::DELETE_RULES as $rule)
                            <option value="{{ $rule }}" @if($mapping->delete_rule === $rule) selected @endif>{{ exmtrans('sync.delete.' . $rule) }}</option>
                        @endforeach
                    </select>
                    <label class="checkbox-inline"><input type="checkbox" name="check_deletes" value="1" @if(boolval($mapping->option('check_deletes', true))) checked @endif> {{ exmtrans('sync.check_deletes') }}</label>
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-2 control-label">{{ exmtrans('sync.options') }}</label>
                <div class="col-sm-8">
                    <label class="checkbox-inline"><input type="checkbox" name="keep_times" value="1" @if(boolval($mapping->option('keep_times', false))) checked @endif> {{ exmtrans('sync.keep_times') }}</label><br>
                    <label class="checkbox-inline"><input type="checkbox" name="enabled" value="1" @if($mapping->enabled) checked @endif> {{ exmtrans('sync.enabled_schedule') }}</label>
                </div>
            </div>
        </div>
        <div class="box-footer">
            <a class="btn btn-default" href="{{ admin_url('sync') }}">{{ trans('admin.back') }}</a>
            <button type="submit" class="btn btn-primary pull-right">{{ trans('admin.save') }}</button>
        </div>
    </form>
    @endif
</div>
