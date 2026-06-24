<div class="box">
    <div class="box-body">
        @if($link->isLinked())
            <p style="font-size:16px;">LINE連携済み
                @if($link->linked_at) <small>({{ $link->linked_at }})</small> @endif
            </p>
            <form method="POST" action="{{ admin_url('line/link/unlink') }}">
                {{ csrf_field() }}
                <button type="submit" class="btn btn-danger">連携解除</button>
            </form>
        @else
            <form method="POST" action="{{ admin_url('line/link/generate') }}">
                {{ csrf_field() }}
                <button type="submit" class="btn btn-primary">QRコードを生成</button>
            </form>
            @if($qr)
                <hr>
                <p>LINEアプリを開き、以下のQRコードを読み取って連携してください:</p>
                <img src="{{ $qr }}" alt="LINE QR" width="256" height="256">
            @endif
        @endif
    </div>
</div>
