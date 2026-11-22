{{-- All the API keys of the site. Data from UserApiKeyController::adminIndex. --}}
<div class="exm-api-key">
    <div class="callout callout-info">
        <p>{{ exmtrans('api_key.admin_help') }}</p>
        @if (!$enabled)
            <p>{{ exmtrans('api_key.feature_off') }} <a href="{{ $feature_url }}">{{ exmtrans('feature.header') }}</a></p>
        @endif
    </div>
    <div class="box card">
        <div class="box-header with-border">
            @foreach (['active', 'inactive', 'all'] as $s)
                <a href="{{ $action }}?status={{ $s }}" class="btn btn-sm {{ $status === $s ? 'btn-primary' : 'btn-default' }}">{{ exmtrans('api_key.filters.' . $s) }}</a>
            @endforeach
        </div>
        <div class="box-body">
            @include('exment::api_key._list', ['keys' => $keys, 'admin' => true, 'read_only' => $read_only,
                'revoke_url' => function ($id) use ($action) { return url_join($action, $id, 'revoke'); },
                'revoke_user_url' => function ($userId) use ($action) { return url_join($action, 'user', $userId, 'revoke'); }])
        </div>
        @if ($paginator->hasPages())
            <div class="box-footer">
                {{ exmtrans('api_key.page', $paginator->currentPage(), $paginator->lastPage(), $paginator->total()) }}
                @if ($paginator->previousPageUrl())<a href="{{ $paginator->previousPageUrl() }}" class="btn btn-sm btn-default">&lsaquo;</a>@endif
                @if ($paginator->nextPageUrl())<a href="{{ $paginator->nextPageUrl() }}" class="btn btn-sm btn-default">&rsaquo;</a>@endif
            </div>
        @endif
    </div>
</div>
