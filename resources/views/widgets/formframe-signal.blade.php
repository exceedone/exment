{{--
    What became of a record opened in a frame - saved, or not open to editing
    by this reader. Nothing is drawn: the screen that opened the frame is
    still on display behind it, and it is the one that closes the window and
    says what happened. Opened on its own - a saved link, a middle click -
    there is no such screen, so the record is shown.
--}}
<!DOCTYPE html>
<html lang="{{ config('app.locale') }}">
<head>
    <meta charset="utf-8">
    <title>{{ Admin::title() }}</title>
</head>
<body>
<script>
    (function () {
        // hex flags, so nothing written here can close the script tag it is
        // written inside - the same encoding the board's own payload uses
        var message = {
            exmentFormFrame: {!! json_encode(strval($action), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!},
            id: {!! json_encode(strval($id), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!},
            table: {!! json_encode(strval($table), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
        };
        if (window.parent && window.parent !== window) {
            window.parent.postMessage(message, window.location.origin);
            return;
        }
        window.location.href = {!! json_encode(strval($fallback), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
    })();
</script>
</body>
</html>
