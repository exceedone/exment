{{-- The older versions of an attachment (DefaultShow::documentVersions). --}}
@if(count($versions) === 0)
    <small class="text-muted">{{ exmtrans('custom_value.document_versions_empty') }}</small>
@else
    @foreach($versions as $version)
        <small class="d-block">
            {{ exmtrans('custom_value.document_version_no', ['no' => $version['version_no']]) }}&nbsp;:
            <a href="{{ $version['url'] }}" target="_blank" rel="noopener noreferrer" data-bs-toggle='tooltip' title="{{ exmtrans('common.download') }}">{{ $version['name'] }}</a>
            &nbsp;({{ \Exceedone\Exment\Services\UserTimezone::display($version['created_at']) }}@if($version['created_user'] !== '')&nbsp;{{ $version['created_user'] }}@endif)
        </small>
    @endforeach
@endif
