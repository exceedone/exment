@extends('exment::initialize.index')

@section('content')
    <section class="content-header">
        <h1>
            {{ $header ?? trans('admin.title') }}
            <small>{{ $description ?? trans('admin.description') }}</small>
        </h1>

    </section>

    <section class="content">

        @include('admin::partials.alerts')
        @include('admin::partials.exception')
        @include('admin::partials.toastr')

        {!! $content !!}

    </section>
@endsection