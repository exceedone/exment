{{-- A feature not used on the site (404), or not allowed for the user (403). Data from Services\Features::deny(). --}}
<div class="callout callout-warning">
    <p>{{ $message }}</p>
    @if (!empty($note))<p>{{ $note }}</p>@endif
</div>
<p>
    @if (!empty($feature_url))
        <a href="{{ $feature_url }}" class="btn btn-primary"><i class="fa fa-toggle-on" aria-hidden="true"></i> {{ exmtrans('feature.open_feature') }}</a>
    @endif
    <a href="{{ $home_url }}" class="btn btn-default"><i class="fa fa-home" aria-hidden="true"></i> {{ exmtrans('feature.back_home') }}</a>
</p>
