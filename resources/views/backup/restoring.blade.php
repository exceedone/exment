{{-- The page of the maintenance while a restore goes on (ISS02943): rendered by "php artisan down --render", no database --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="15">
    <title>{{ exmtrans('backup.run.screen.restoring_title') }}</title>
    <style>
        body { margin: 0; font-family: -apple-system, "Segoe UI", "Hiragino Sans", "Noto Sans JP", Meiryo, sans-serif; background: #f4f6f9; color: #333; }
        main { max-width: 560px; margin: 12vh auto 0; padding: 32px 24px; background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0, 0, 0, .12); text-align: center; }
        h1 { font-size: 1.3rem; margin: 0 0 12px; }
        p { line-height: 1.7; margin: 0; }
        .spinner { width: 36px; height: 36px; margin: 0 auto 18px; border: 4px solid #d6dde6; border-top-color: #3c8dbc; border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        @media (prefers-reduced-motion: reduce) { .spinner { animation: none; } }
        @media (max-width: 600px) { main { margin: 8vh 16px 0; } }
    </style>
</head>
<body>
    <main role="status" aria-live="polite">
        <div class="spinner" aria-hidden="true"></div>
        <h1>{{ exmtrans('backup.run.screen.restoring_title') }}</h1>
        <p>{{ exmtrans('backup.run.screen.restoring_text') }}</p>
    </main>
</body>
</html>
