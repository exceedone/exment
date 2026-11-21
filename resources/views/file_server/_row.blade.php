{{-- File server: a row of the list of a folder. Data from FileServerController::nodeRows. --}}
<tr data-id="{{ $item['id'] }}" data-name="{{ $item['name'] }}">
    <td class="exm-fs-check"><input type="checkbox" data-fs="check" value="{{ $item['id'] }}" aria-label="{{ $item['name'] }}"></td>
    <td class="exm-fs-name">
        <a href="{{ $item['url'] }}">
            @if ($item['thumbnail_url'])
                <img class="exm-fs-thumb" src="{{ $item['thumbnail_url'] }}" alt="" loading="lazy">
            @else
                <i class="fa {{ $item['icon'] }}" aria-hidden="true"></i>
            @endif{{ $item['name'] }}</a>
        @if ($item['checked_out'])<span class="exm-fs-badge" title="{{ exmtrans('file_server.checked_out_badge') }}"><i class="fa fa-lock" aria-hidden="true"></i> {{ exmtrans('file_server.checked_out_badge') }}</span>@endif
        @if (!empty($item['approval_status']) && $item['approval_status'] !== 'approved')<span class="exm-fs-badge">{{ exmtrans('file_server.approval_' . $item['approval_status']) }}</span>@endif
        @if (!empty($item['legal_hold']))<span class="exm-fs-badge" title="{{ exmtrans('file_server.legal_hold') }}"><i class="fa fa-lock" aria-hidden="true"></i></span>@endif
        @if ($item['unique_permissions'])<span class="exm-fs-badge" title="{{ exmtrans('file_server.unique_permissions') }}"><i class="fa fa-key" aria-hidden="true"></i></span>@endif
        @if ($item['version_no'] && $item['version_no'] > 1)<span class="exm-fs-badge">{{ exmtrans('file_server.version_no', ['no' => $item['version_no']]) }}</span>@endif
    </td>
    @foreach ($list_fields as $field)
        <td class="exm-fs-muted exm-fs-meta">{{ array_get($item['meta'], $field['name']) }}</td>
    @endforeach
    <td class="exm-fs-muted">{{ optional($item['updated_at'])->format('Y/m/d H:i') }} {{ $item['updated_user'] }}</td>
    <td class="exm-fs-muted">@if (!$item['is_folder'])<span data-fs-size="{{ $item['size'] }}"></span>@endif</td>
    <td class="exm-fs-actions">
        <a class="btn btn-xs btn-default" href="{{ $item['download_url'] }}" title="{{ exmtrans('file_server.download') }}"><i class="fa fa-download" aria-hidden="true"></i></a>
        @if ($can_edit)
            <button type="button" class="btn btn-xs btn-default" data-fs="rename" title="{{ exmtrans('file_server.rename') }}"><i class="fa fa-pencil" aria-hidden="true"></i></button>
        @endif
        <a class="btn btn-xs btn-default" href="{{ $item['detail_url'] }}" title="{{ exmtrans('file_server.detail') }}"><i class="fa fa-info-circle" aria-hidden="true"></i></a>
    </td>
</tr>
