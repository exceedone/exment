@extends('exment::initialize.index')

@section('content')
    <section class="content-header">
        <h1>
            {{ $header or trans('admin.title') }}
            <small>{{ $description or trans('admin.description') }}</small>
        </h1>

    </section>

    <section class="content">

        @include('admin::partials.alerts')
        @include('admin::partials.exception')
        @include('admin::partials.toastr')

        {!! $content !!}

    </section>
@endsection