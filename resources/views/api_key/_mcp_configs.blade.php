{{-- The settings of MCP, in tabs: Claude Desktop, Claude Code, others (JSON). $configs from McpGuide::configs, $prefix for the ids. --}}
@php
    $tabs = [
        'claude_desktop' => ['label' => 'Claude Desktop', 'help' => exmtrans('api_key.mcp.desktop_help'), 'rows' => 12],
        'claude_code' => ['label' => 'Claude Code', 'help' => exmtrans('api_key.mcp.code_help'), 'rows' => 3],
        'generic' => ['label' => exmtrans('api_key.mcp.generic'), 'help' => exmtrans('api_key.mcp.generic_help'), 'rows' => 10],
    ];
@endphp
<div class="exm-ak-configs">
    <div class="exm-ak-tabs" role="tablist">
    @foreach ($tabs as $name => $tab)
        <button type="button" role="tab" class="{{ $loop->first ? 'is-active' : '' }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}" data-tab="#{{ $prefix }}-{{ $name }}">{{ $tab['label'] }}</button>
    @endforeach
    </div>
    @foreach ($tabs as $name => $tab)
        <div class="exm-ak-tab {{ $loop->first ? 'is-active' : '' }}" id="{{ $prefix }}-{{ $name }}" role="tabpanel">
            <p class="text-muted" style="margin-bottom:4px">{{ $tab['help'] }}</p>
            <div class="exm-ak-copyrow">
                <textarea id="{{ $prefix }}-{{ $name }}-text" class="form-control exm-ak-code" rows="{{ $tab['rows'] }}" readonly onfocus="this.select()">{{ $configs[$name] }}</textarea>
                <button type="button" class="btn btn-default exm-ak-copy" data-target="#{{ $prefix }}-{{ $name }}-text"><i class="fa fa-copy"></i> {{ exmtrans('api_key.copy') }}</button>
            </div>
        </div>
    @endforeach
</div>
