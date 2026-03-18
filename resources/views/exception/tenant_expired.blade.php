<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">

<head>
    <meta charset="utf-8">

    <!-- Bootstrap 3 -->
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">

    <!-- AdminLTE 2 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/admin-lte/2.4.18/css/AdminLTE.min.css">

    <style>
        body {
            background: #f4f6f9;
        }

        .center-box {
            max-width: 500px;
            margin: 100px auto;
        }
    </style>
</head>

<body>

    <div class="center-box">
        <div class="box-body">
            <div class="alert alert-warning text-center">
                {{ exmtrans('error.tenant_expired') }}
            </div>
        </div>
    </div>

</body>

</html>