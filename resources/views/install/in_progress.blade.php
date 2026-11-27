{{-- An install of the web installer is going on, seen from another browser than the one that
     started it (InstallRunner): nothing more than that. --}}
@extends('exment::install.layout')
@section('content')
        <p class="login-box-msg text-center">{{ exmtrans('install.progress.header') }}</p>
        <p>{{ exmtrans('install.progress.other_browser') }}</p>
@endsection
