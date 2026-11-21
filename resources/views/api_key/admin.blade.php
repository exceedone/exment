{{-- All the API keys of the site. Data from UserApiKeyController::adminIndex. --}}
<div class="exm-api-key">
    <div class="callout callout-info">
        <p>{{ exmtrans('api_key.admin_help') }}</p>
        @if (!$enabled)
            <p>{{ exmtrans('api_key.feature_off') }} <a href="{{ $feature_url }}">{{ exmtrans('feature.header') }}</a></p>
        @endif
    </div>
    <div class="box card">
        <div class="box-body">
            @include('exment::api_key._list', ['keys' => $keys, 'admin' => true, 'read_only' => $read_only,
                'revoke_url' => function ($id) use ($action) { return url_join($action, $id, 'revoke'); },
                'revoke_user_url' => function ($userId) use ($action) { return url_join($action, 'user', $userId, 'revoke'); }])
        </div>
    </div>
</div>
