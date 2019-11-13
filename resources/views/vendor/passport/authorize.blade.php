@extends('exment::auth.layout') 
@section('content')

<style>
        .passport-authorize .container {
            margin-top: 30px;
        }

        .passport-authorize .scopes {
            margin-top: 20px;
        }

        .passport-authorize .buttons {
            margin-top: 25px;
            text-align: center;
        }

        .passport-authorize .btn {
            width: 125px;
        }

        .passport-authorize .btn-approve {
            margin-right: 15px;
        }

        .passport-authorize form {
            display: inline;
        }
    </style>
        <div class="passport-authorize">
        <p class="login-box-msg">{{exmtrans('api.oauth.authorization_request')}}</p>

       <!-- Introduction -->
       <p><strong>{{ $client->name }}</strong>&nbsp;{{exmtrans('api.oauth.introduction')}}</p>

        <!-- Scope List -->
        @if (count($scopes) > 0)
            <div class="scopes">
                    <p><strong>{{exmtrans('api.oauth.scopes')}}:</strong></p>

                    <ul>
                        @foreach ($scopes as $scope)
                            <li>{{ $scope->description }}</li>
                        @endforeach
                    </ul>
            </div>
        @endif

        <div class="buttons">
            <!-- Authorize Button -->
            <form method="post" action="{{admin_urls('oauth', 'authorize')}}">
                {{ csrf_field() }}

                <input type="hidden" name="state" value="{{ $request->state }}">
                <input type="hidden" name="client_id" value="{{ $client->id }}">
                <button type="submit" class="btn btn-success btn-approve">{{exmtrans('api.oauth.authorize')}}</button>
            </form>

            <!-- Cancel Button -->
            <form method="post" action="/admin/oauth/authorize">
                {{ csrf_field() }}
                {{ method_field('DELETE') }}

                <input type="hidden" name="state" value="{{ $request->state }}">
                <input type="hidden" name="client_id" value="{{ $client->id }}">
                <button class="btn btn-danger">{{trans('admin.cancel')}}</button>
            </form>
        </div>
        </div>
<!-- /.login-box -->
@endsection
