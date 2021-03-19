<!DOCTYPE html>
<html lang="{{ config('app.locale') }}">
<head>
    @if(isset($analytics))
    @include('exment::public-form.analytics')
    @endif
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ Admin::title() }} @if($header) | {{ $header }}@endif</title>
    <!-- Tell the browser to be responsive to screen width -->
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">

    @if(!is_null($favicon = Admin::favicon()))
    <link rel="shortcut icon" href="{{$favicon}}">
    @endif

    {!! Admin::css() !!}

    @include('exment::public-form.style')

    <script src="{{ Admin::jQuery() }}"></script>
    {!! Admin::headerJs() !!}
</head>

<body id="body-publicform" class="hold-transition">
<div class="wrapper">
    <!-- Main Header -->
    @if($use_header)
    <header class="main-header">
        @if(!is_nullorempty($header_logo_url))
            <img src="{{$header_logo_url}}" alt="header logo" class="header_logo">
        @else
            {{$header_label}}
        @endif
    </header>
    @endif

    <div id="">
        {!! Admin::style() !!}
        <div id="app">
            <div id="container-inner" class="{{$container ? 'container' : 'container-middle'}}">
            @yield('content')
            </div>
        </div>
        {!! Admin::script() !!}
    </div>

    <!-- Main Footer -->
    @if($use_footer)
    <footer class="main-footer">
        @if($use_footer_label)
        <strong>Powered by <a href="https://exment.net" target="_blank">Exment</a></strong>
        @endif
    </footer>
    @endif
</div>

{!! Admin::html() !!}

<button id="totop" title="Go to top" style="display: none;"><i class="fa fa-chevron-up"></i></button>

<script>
    function LA() {}
    LA.token = "{{ csrf_token() }}";
</script>

<!-- REQUIRED JS SCRIPTS -->
{!! Admin::js() !!}

</body>
</html>
