@extends('exment::auth.layout')

@section('content')
@if($mode === 'error')
    <p class="text-danger mb-0">{{ $message }}</p>
@elseif($mode === 'closed')
    <p class="mb-0">{{ exmtrans('safety.answer_closed') }}</p>
@elseif($mode === 'done')
    <h4 class="mb-3">{{ exmtrans('safety.answer_done_title') }}</h4>
    <p class="mb-0">{{ exmtrans('safety.answer_done_body', ['status' => exmtrans('safety.status_' . $status)]) }}</p>
@else
    <h4 class="mb-3">{{ $event->getValue('title') }}</h4>
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
        <div class="form-check justify-content-start mb-2">
            <input class="form-check-input" type="radio" name="st" id="st-{{ $st }}" value="{{ $st }}" required {{ $current === $st ? 'checked' : '' }}>
            <label class="form-check-label" for="st-{{ $st }}">{{ exmtrans('safety.status_' . $st) }}</label>
        </div>
        @endforeach
        <div class="mb-3 mt-3">
            <label class="form-label" for="safety-comment">{{ exmtrans('safety.answer_comment_label') }}</label>
            <textarea class="form-control" id="safety-comment" name="comment" rows="3"></textarea>
        </div>
        <button type="submit" class="btn btn-primary w-100">{{ exmtrans('safety.answer_submit') }}</button>
    </form>
@endif
@endsection
