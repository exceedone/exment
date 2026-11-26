{{-- A connection of the data sync (ISS00113): SyncController@connection. A secret is never put back. --}}
@if($appKeyWarning)
<div class="alert alert-warning">{{ exmtrans('sync.app_key_warning') }}</div>
@endif
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">{{ $connector->label() }}</h3>
    </div>
    <form method="post" action="{{ $connection->exists ? admin_urls('sync', 'connections', $connection->id) : admin_url('sync/connections') }}" class="form-horizontal" autocomplete="off">
        <input type="hidden" name="_token" value="{{ csrf_token() }}">
        <input type="hidden" name="connector" value="{{ $connector->key() }}">
        <div class="box-body">
            <div class="form-group">
                <label class="col-sm-2 control-label" for="sync-name">{{ exmtrans('sync.name') }}</label>
                <div class="col-sm-8"><input type="text" class="form-control" id="sync-name" name="name" maxlength="100" required value="{{ array_get($old, 'name', $connection->name) }}"></div>
            </div>
            @foreach($connector->settingFields() as $field => $label)
                <div class="form-group">
                    <label class="col-sm-2 control-label" for="sync-setting-{{ $field }}">{{ $label }}</label>
                    <div class="col-sm-8"><input type="text" class="form-control" id="sync-setting-{{ $field }}" name="settings_{{ $field }}" value="{{ array_get($old, 'settings_' . $field, $connection->setting($field)) }}"></div>
                </div>
            @endforeach
            @foreach($connector->secretFields() as $field => $label)
                <div class="form-group">
                    <label class="col-sm-2 control-label" for="sync-secret-{{ $field }}">{{ $label }}</label>
                    <div class="col-sm-8">
                        <p class="form-control-static">{{ $connection->hasSecret($field) ? exmtrans('sync.secret_set') : exmtrans('sync.secret_unset') }}</p>
                        <input type="password" class="form-control" id="sync-secret-{{ $field }}" name="sync_{{ $field }}_secret" value="" autocomplete="new-password" placeholder="{{ exmtrans('sync.secret_new') }}">
                        @if($connection->hasSecret($field))
                            <label class="checkbox-inline"><input type="checkbox" name="clear_{{ $field }}" value="1"> {{ exmtrans('sync.secret_clear') }}</label>
                        @endif
                    </div>
                </div>
            @endforeach
            <div class="form-group">
                <label class="col-sm-2 control-label">{{ exmtrans('sync.enabled') }}</label>
                <div class="col-sm-8"><label class="checkbox-inline"><input type="checkbox" name="enabled" value="1" @if($connection->enabled) checked @endif> {{ exmtrans('sync.enabled_help') }}</label></div>
            </div>
        </div>
        <div class="box-footer">
            <a class="btn btn-default" href="{{ admin_url('sync') }}">{{ trans('admin.back') }}</a>
            <button type="submit" class="btn btn-primary pull-right">{{ trans('admin.save') }}</button>
        </div>
    </form>
</div>
