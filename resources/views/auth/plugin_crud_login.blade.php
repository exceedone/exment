<div>
    <form action="{{$formUrl}}" method="GET">
        {!! exmtrans('plugin.error.crud_autherror_auth_help') !!}
        <div style="width:360px; max-width:100%; margin-top:1em;" >
            @include('exment::auth.login_providers')
        </div>
    </form>
</div>