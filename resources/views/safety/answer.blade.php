@extends('exment::auth.layout')

@section('content')
@if($mode === 'error')
    <p class="text-danger">{{ $message }}</p>
@elseif($mode === 'closed')
    <p>{{ exmtrans('safety.answer_closed') }}</p>
@elseif($mode === 'done')
    <h4>{{ exmtrans('safety.answer_done_title') }}</h4>
    <p>{{ exmtrans('safety.answer_done_body', ['status' => exmtrans('safety.status_' . $status)]) }}</p>
@else
    <h4>{{ $event->getValue('title') }}</h4>
    @if(!is_nullorempty($event->getValue('quake_info')))
        <p style="white-space: pre-wrap;">{{ $event->getValue('quake_info') }}</p>
    @endif
    <p>{{ exmtrans('safety.answer_intro') }}</p>
    @php($current = $answerRow->getValue('answer_status'))
    @if(in_array($current, \Exceedone\Exment\Services\SafetyCheck\SafetyCheckDefine::ANSWER_STATUSES, true))
        <p>{{ exmtrans('safety.answer_current') }}: {{ exmtrans('safety.status_' . $current) }} ({{ $answerRow->getValue('answered_at') }})</p>
    @endif
    <form method="POST" action="{{ $action }}">
        {{ csrf_field() }}
        @foreach(\Exceedone\Exment\Services\SafetyCheck\SafetyCheckDefine::ANSWER_STATUSES as $st)
        <div class="radio">
            <label>
                <input type="radio" name="st" value="{{ $st }}" {{ $current === $st ? 'checked' : '' }}>
                {{ exmtrans('safety.status_' . $st) }}
            </label>
        </div>
        @endforeach
        <div class="form-group" style="margin-top:10px;">
            <label>{{ exmtrans('safety.answer_comment_label') }}</label>
            <textarea class="form-control" name="comment" rows="3"></textarea>
        </div>
        <button type="submit" class="btn btn-primary btn-block">{{ exmtrans('safety.answer_submit') }}</button>
    </form>
@endif
@endsection
