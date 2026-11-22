{{-- The security check (ISS02833): each item now / the good state / how to fix it; a button where Exment can fix it (SecurityCheckController::fix) --}}
<div class="box box-default">
    <div class="box-body">
        <p>{{ exmtrans('security_check.explain') }}</p>
        @if(!$serverItems)
        <p class="text-muted">{{ exmtrans('security_check.server_items_hidden') }}</p>
        @endif
        <div class="container-fluid">
            <div class="row row-eq-height">
                @foreach($check->items() as $item)
                @php
                    $set = $item->getResultClassSet();
                    $ok = $item->checkResult() == 'ok';
                @endphp
                <div class="col-xs-12 col-sm-6 require_item security_item" data-security="{{ $item->getKey() }}">
                    <h4 class="require_item_header">
                        <i class="fa {{ $set['fontawesome'] }}" aria-hidden="true" style="color: {{ $set['color'] }}"></i>
                        {{ $item->getLabel() }}
                    </h4>
                    <p>{{ $item->getExplain() }}</p>
                    <p>{{ exmtrans('security_check.now') }} : <span class="bold">{{ $item->getResultText() }}</span></p>

                    @if(!$ok)
                        <p class="text-warning">{{ $item->getMessage() }}</p>
                        @if(!is_null($fixText = $item->getFixText()))
                        <pre class="security_fix" style="white-space: pre-wrap;">{{ $fixText }}</pre>
                        @endif
                    @endif

                    <div class="security_buttons">
                        @if(!$ok && $item->canFix())
                        <form method="post" action="{{ $fixUrl }}" style="display:inline" onsubmit="return window.confirm('{{ exmtrans('security_check.fix_confirm') }}');">
                            <input type="hidden" name="_token" value="{{ csrf_token() }}">
                            <input type="hidden" name="item" value="{{ $item->getKey() }}">
                            <button type="submit" class="btn btn-sm btn-warning">{{ exmtrans('security_check.fix') }}</button>
                        </form>
                        @endif
                        @if(!is_null($url = $item->getSettingUrl()))
                        <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-default">
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
