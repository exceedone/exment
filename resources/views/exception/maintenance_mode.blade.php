<!doctype html>
<html lang="{{ app()->getLocale() }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <title>{{ exmtrans('maintenance.title') }}</title>
        <style>
            html, body {
                background-color: #f4f6f9;
                color: #333;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Hiragino Sans", "Meiryo", sans-serif;
                margin: 0;
                height: 100%;
            }
            .wrap {
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100%;
                padding: 16px;
                box-sizing: border-box;
            }
            .box {
                background: #fff;
                border-top: 3px solid #f39c12;
                box-shadow: 0 1px 3px rgba(0, 0, 0, .12);
                max-width: 560px;
                width: 100%;
                padding: 24px 28px;
                box-sizing: border-box;
            }
            h1 {
                font-size: 22px;
                margin: 0 0 16px;
            }
            .message {
                line-height: 1.7;
                white-space: pre-wrap;
                overflow-wrap: anywhere;
            }
            .end {
                margin-top: 16px;
                color: #666;
            }
        </style>
    </head>
    <body>
        <div class="wrap">
            <div class="box">
                <h1>{{ exmtrans('maintenance.title') }}</h1>
                <div class="message">{{ $message }}</div>
                @if(isset($end_at))
                <p class="end">{{ exmtrans('maintenance.end_at', ['end_at' => $end_at]) }}</p>
                @endif
            </div>
        </div>
    </body>
</html>
