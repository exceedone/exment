{{-- File server: the workspaces of the user, favorites, recent files, shared with me. --}}
@include('exment::file_server._assets')
<div class="exm-fs">
    <form class="exm-fs-filters" method="get" action="{{ admin_urls('file_server', 'search') }}" role="search">
        <div class="input-group input-group-sm" style="flex:1;max-width:520px">
            <input type="search" name="q" class="form-control" placeholder="{{ exmtrans('file_server.search_placeholder') }}" aria-label="{{ exmtrans('file_server.search') }}">
            <span class="input-group-btn"><button class="btn btn-default" type="submit"><i class="fa fa-search" aria-hidden="true"></i> {{ exmtrans('file_server.search') }}</button></span>
        </div>
    </form>
    <div class="exm-fs-toolbar">
        <h4 style="margin:0">{{ exmtrans('file_server.workspaces') }}</h4>
        <span class="exm-fs-spacer"></span>
        @if ($create_url)
            <a href="{{ $create_url }}" class="btn btn-sm btn-primary"><i class="fa fa-plus" aria-hidden="true"></i> {{ exmtrans('file_server.create_workspace') }}</a>
        @endif
    </div>

    <div class="exm-fs-section">
        @if (count($workspaces) === 0)
            <div class="callout callout-info" style="margin:0">
                <p>{{ $create_url ? exmtrans('file_server.no_workspaces_admin') : exmtrans('file_server.no_workspaces') }}</p>
                @if ($create_url)<p><a href="{{ $create_url }}" class="btn btn-sm btn-primary"><i class="fa fa-plus" aria-hidden="true"></i> {{ exmtrans('file_server.create_workspace') }}</a></p>@endif
            </div>
        @else
            <div class="exm-fs-grid">
                @foreach ($workspaces as $ws)
                    <a class="exm-fs-card" href="{{ $ws['url'] }}">
                        <h5><i class="fa fa-folder-open" aria-hidden="true"></i> {{ $ws['name'] }}</h5>
                        @if (!empty($ws['description']))<div class="exm-fs-muted">{{ \Illuminate\Support\Str::limit($ws['description'], 120) }}</div>@endif
                        <div class="exm-fs-muted">{{ $ws['level'] }}</div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    @foreach ([['favorites', 'fa-star'], ['recent', 'fa-clock-o'], ['shared', 'fa-share-alt']] as $section)
        @php $rows = ${$section[0]}; @endphp
        @if (count($rows) > 0)
            <div class="exm-fs-section box card">
                <div class="box-header with-border"><h3 class="box-title"><i class="fa {{ $section[1] }}" aria-hidden="true"></i> {{ exmtrans('file_server.section_' . $section[0]) }}</h3></div>
                <div class="box-body table-responsive p-0">
                    <table class="table exm-fs-table">
                        <thead><tr><th>{{ exmtrans('file_server.name') }}</th><th>{{ exmtrans('file_server.workspace') }}</th><th>{{ exmtrans('file_server.updated_at') }}</th></tr></thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr>
                                    <td class="exm-fs-name"><a href="{{ $row['url'] }}"><i class="fa {{ $row['icon'] }}" aria-hidden="true"></i>{{ $row['name'] }}</a></td>
                                    <td>{{ $row['workspace'] }}</td>
                                    <td class="exm-fs-muted">{{ optional($row['updated_at'])->format('Y/m/d H:i') }} {{ $row['updated_user'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endforeach
</div>
