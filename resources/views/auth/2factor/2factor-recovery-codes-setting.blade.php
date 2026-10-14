<div class="box-body">
    <p>{{ exmtrans('2factor.message.recovery_codes_description') }}</p>
    @if($has_codes)
    <p>{{ exmtrans('2factor.message.recovery_codes_remaining', $remaining, $total) }}</p>
    <p class="text-danger"><i class="fa fa-warning"></i> {{ exmtrans('2factor.message.recovery_codes_regenerate_warning') }}</p>
    @else
    <p>{{ exmtrans('2factor.message.recovery_codes_not_issued') }}</p>
    @endif

    <form action="{{ $action }}" method="post" style="margin-top:1.5em;">
        <input type="hidden" name="_token" value="{{ csrf_token() }}">
        <a href="{{ $back_url }}" class="btn btn-default"><i class="fa fa-arrow-left"></i> {{ trans('admin.back') }}</a>
        <button type="submit" class="btn btn-primary" style="margin-left:1em;"><i class="fa fa-refresh"></i> {{ exmtrans('2factor.regenerate_recovery_codes') }}</button>
    </form>
</div>
