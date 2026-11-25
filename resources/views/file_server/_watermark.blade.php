{{-- File server, phase 5 (ISS00379): the name of the user and the time over the preview, when the workspace says so. --}}
@if (!empty($watermark))
    <div class="exm-fs-watermark" aria-hidden="true">
        @for ($i = 0; $i < 12; $i++)
            <span>{{ $watermark }}</span>
        @endfor
    </div>
@endif
