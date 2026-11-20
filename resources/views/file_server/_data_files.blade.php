{{-- File server (ISS00378): the folders and files linked to a data, on the screen of the data. Data from DefaultShow::setFileServerBox. --}}
<ul class="list-unstyled" style="margin:0">
    @foreach ($nodes as $node)
        @php
            $base = admin_urls('file_server', $node->workspace_id);
            $url = $node->isFolder() ? $base . '/folder/' . $node->id : $base . '/nodes/' . $node->id;
        @endphp
        <li style="margin-bottom:6px;overflow-wrap:anywhere">
            <a href="{{ $url }}"><i class="fa {{ $node->isFolder() ? 'fa-folder' : 'fa-file-o' }}" aria-hidden="true"></i> {{ $node->name }}</a>
            <span style="opacity:.65;font-size:.85em">{{ optional($node->workspace)->name }} · {{ optional($node->updated_at)->format('Y/m/d') }}</span>
            @if ($node->isFile())
                <a href="{{ $base }}/nodes/{{ $node->id }}/download" title="{{ exmtrans('file_server.download') }}" aria-label="{{ exmtrans('file_server.download') }}"><i class="fa fa-download" aria-hidden="true"></i></a>
            @endif
        </li>
    @endforeach
</ul>
