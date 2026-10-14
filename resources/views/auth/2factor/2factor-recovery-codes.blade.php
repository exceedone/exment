@extends('exment::auth.layout')
@section('content')
<style>
.recovery-codes{
    display:grid;
    grid-template-columns: 1fr 1fr;
    gap:0.5em 1em;
    margin:1em 0;
    padding:1em;
    border:1px solid #d2d6de;
    background:#f9f9f9;
    font-family:monospace;
    font-size:1.2em;
    text-align:center;
    letter-spacing:0.05em;
}
</style>
        <p class="login-box-msg">{{ exmtrans('2factor.recovery_codes') }}</p>

        <div class="form-group mb-3">
            <p>{{ exmtrans('2factor.message.recovery_codes_issued') }}</p>
            <p class="text-danger"><i class="fa fa-warning"></i> {{ exmtrans('2factor.message.recovery_codes_show_once') }}</p>

            <div class="recovery-codes" id="recovery_codes">
                @foreach($codes as $code)
                <span>{{ $code }}</span>
                @endforeach
            </div>

            <div class="text-center">
                <button type="button" class="btn btn-default btn-sm" id="recovery_codes_copy"><i class="fa fa-copy"></i> {{ exmtrans('2factor.copy_recovery_codes') }}</button>
            </div>
        </div>

        <div class="row">
            <div class="col-xs-12 col-sm-8 col-sm-offset-2">
                <a href="{{ $continue_url }}" class="btn btn-primary btn-block btn-flat">{{ exmtrans('2factor.recovery_codes_saved') }}</a>
            </div>
        </div>

<script>
// jQuery and toastr are loaded after the content in the auth layout: wait for the document
document.addEventListener('DOMContentLoaded', function () {
    $('#recovery_codes_copy').on('click', function () {
        var text = $.map($('#recovery_codes span'), function (el) { return $(el).text(); }).join("\n");
        var done = function () { toastr.success('{{ exmtrans('common.message.copy_execute') }}', null, []); };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done);
            return;
        }
        var $textarea = $('<textarea>').val(text).appendTo('body');
        $textarea[0].select();
        document.execCommand('copy');
        $textarea.remove();
        done();
    });
});
</script>

<!-- /.login-box -->
@endsection
