@extends('exment::auth.layout') 
@section('content')
        <p class="login-box-msg">{{ exmtrans('2factor.2factor') }}</p>

        <div class="form-group">
            <p>
                {{ exmtrans('2factor.message.google_email_sended') }}
            </p>
        </div>
        
<!-- /.login-box -->
@endsection