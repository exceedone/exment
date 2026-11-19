<div class="box card p-3">
    <div class="box-body">
        @if($link->isLinked())
            <p style="font-size:16px;">{{ exmtrans('line.link_linked') }}
                @if($link->linked_at) <small>({{ $link->linked_at }})</small> @endif
            </p>
            <form method="POST" action="{{ admin_url('line/link/unlink') }}">
                {{ csrf_field() }}
                <button type="submit" class="btn btn-danger">{{ exmtrans('line.link_unlink') }}</button>
            </form>
        @else
            <form method="POST" action="{{ admin_url('line/link/generate') }}">
                {{ csrf_field() }}
                <button type="submit" class="btn btn-primary">{{ exmtrans('line.link_generate_qr') }}</button>
            </form>
            @if($qr)
                <hr>
                <p>{{ exmtrans('line.link_scan_qr') }}</p>
                <img src="{{ $qr }}" alt="LINE QR" width="256" height="256">
                <p><small>{{ exmtrans('line.link_code_expires', $link->line_link_code_expires_at) }}</small></p>
            @endif
        @endif
    </div>
</div>
