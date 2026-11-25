{{-- The MCP server on the API settings page (ISS02879). Data from ApiSettingController::index. --}}
<div class="box card exm-api-mcp">
    <div class="box-header with-border">
        <h3 class="box-title">{{ exmtrans('api.mcp.header') }}</h3>
        @if ($enabled)
            <span class="label label-success" style="margin-left:8px">{{ exmtrans('api.mcp.on') }}</span>
        @else
            <span class="label label-default" style="margin-left:8px">{{ exmtrans('api.mcp.off') }}</span>
        @endif
    </div>
    <div class="box-body">
        <p>{{ exmtrans('api.mcp.help') }}</p>
        @if (!$feature_on)
            <div class="callout callout-warning"><p>{{ exmtrans('api.mcp.feature_off') }} <a href="{{ $feature_url }}">{{ exmtrans('api.mcp.feature_link') }}</a></p></div>
        @elseif (!$api_on)
            <div class="callout callout-warning"><p>{{ exmtrans('api.mcp.api_off') }}</p></div>
        @else
            <p><a href="{{ $feature_url }}">{{ exmtrans('api.mcp.feature_link') }}</a></p>
        @endif
        <p><strong>{{ exmtrans('api.mcp.url') }}</strong> <code>{{ $url }}</code></p>
        <p class="text-muted">{{ exmtrans('api.mcp.keys_help') }} <a href="{{ $keys_url }}">{{ exmtrans('api.mcp.keys_link') }}</a></p>

        <h4>{{ exmtrans('api.mcp.tools') }}</h4>
        {{-- long names break and the table scrolls inside the box, never the page (ISS02894) --}}
        <div class="table-responsive">
        <table class="table table-sm table-striped" style="table-layout:fixed;width:100%;min-width:480px">
            <thead><tr><th style="width:30%">{{ exmtrans('api.mcp.tool') }}</th><th style="width:20%">{{ exmtrans('api.mcp.scope') }}</th><th>{{ exmtrans('api.mcp.description') }}</th></tr></thead>
            <tbody>
            @foreach ($tools as $tool)
                <tr><td style="overflow-wrap:anywhere"><code style="white-space:normal">{{ $tool['name'] }}</code></td><td style="overflow-wrap:anywhere"><code style="white-space:normal">{{ $tool['scope'] }}</code></td><td style="overflow-wrap:anywhere">{{ $tool['description'] }}</td></tr>
            @endforeach
            </tbody>
        </table>
        </div>

        <h4>{{ exmtrans('api.mcp.recent') }}</h4>
        @if (empty($recent))
            <p class="text-muted">{{ exmtrans('api.mcp.recent_none') }}</p>
        @else
            <table class="table table-sm table-striped">
                <thead><tr><th>{{ exmtrans('api.mcp.user') }}</th><th style="width:180px">{{ exmtrans('api.mcp.last_at') }}</th><th style="width:140px">{{ exmtrans('api.mcp.calls') }}</th></tr></thead>
                <tbody>
                @foreach ($recent as $row)
                    <tr><td>{{ $row['user'] }}</td><td>{{ $row['last_at'] }}</td><td>{{ $row['calls'] }}</td></tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
