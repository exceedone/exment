{{-- The scheduled jobs (ISS02832): ScheduledJobController --}}
@php
    $confirm = function ($text) { return json_encode(strval($text), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); };
    $resultLabel = ['running' => 'label-info', 'success' => 'label-success', 'failed' => 'label-danger', 'skipped' => 'label-default'];
@endphp
<div class="box box-default" id="scheduled-jobs-cron">
    <div class="box-header with-border">
        <h3 class="box-title">{{ exmtrans('scheduled_jobs.cron_header') }}</h3>
    </div>
    <div class="box-body">
        @if($alive)
            <p><i class="fa fa-check-circle text-green" aria-hidden="true"></i> {{ exmtrans('scheduled_jobs.cron_alive', ['last' => $lastScheduleRun]) }}</p>
        @else
            <div class="alert alert-warning scheduled-jobs-cron-warning">
                @if(is_null($lastScheduleRun))
                    {{ exmtrans('scheduled_jobs.cron_never') }}
                @else
                    {{ exmtrans('scheduled_jobs.cron_stale', ['last' => $lastScheduleRun, 'minutes' => $staleMinutes]) }}
                @endif
            </div>
            <p>{{ exmtrans('scheduled_jobs.cron_help') }}</p>
            <pre style="white-space: pre-wrap;">{{ $cronLine }}</pre>
        @endif
    </div>
</div>

<div class="box box-default" id="scheduled-jobs-list">
    <div class="box-header with-border">
        <h3 class="box-title">{{ exmtrans('scheduled_jobs.list_header') }}</h3>
    </div>
    <div class="box-body">
        <p class="text-muted">{{ exmtrans('scheduled_jobs.list_help', ['count' => $keepRuns, 'failures' => $alertFailures]) }}</p>
        @if(!$processAvailable)
        <div class="alert alert-info scheduled-jobs-no-process">{{ exmtrans('scheduled_jobs.run_unavailable.no_process') }}</div>
        @endif
        <div class="table-responsive">
        <table class="table table-hover scheduled-jobs-table">
            <thead>
                <tr>
                    <th>{{ exmtrans('scheduled_jobs.column.name') }}</th>
                    <th>{{ exmtrans('scheduled_jobs.column.when') }}</th>
                    <th>{{ exmtrans('scheduled_jobs.column.next') }}</th>
                    <th>{{ exmtrans('scheduled_jobs.column.state') }}</th>
                    <th>{{ exmtrans('scheduled_jobs.column.last') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @foreach($rows as $row)
                <tr class="scheduled-job{{ $row['failing'] ? ' danger scheduled-job-failing' : '' }}" data-job="{{ $row['key'] }}">
                    <td>
                        <span class="bold">{{ $row['label'] }}</span><br>
                        <small class="text-muted"><code>{{ $row['command'] }}</code></small>
                    </td>
                    <td>
                        {{ $row['when'] }}<br>
                        <small class="text-muted"><code>{{ $row['expression'] }}</code></small>
                    </td>
                    <td>{{ isset($row['next']) ? $local($row['next']) : '-' }}</td>
                    <td>
                        @if($row['enabled'])
                            <span class="label label-success">{{ exmtrans('scheduled_jobs.state.enabled') }}</span>
                        @else
                            <span class="label label-default">{{ exmtrans('scheduled_jobs.state.disabled') }}</span><br>
                            <small class="text-muted">{{ $row['disabled_text'] }}</small>
                        @endif
                    </td>
                    <td>
                        @if(isset($row['last']))
                            <span class="label {{ $resultLabel[$row['last']->result] ?? 'label-default' }}">{{ exmtrans('scheduled_jobs.result.' . $row['last']->result) }}</span>
                            {{ $local($row['last']->started_at) }}
                            @if($row['failing'])
                            <br><span class="text-red">{{ exmtrans('scheduled_jobs.failing', ['count' => $row['streak']]) }}</span>
                            @endif
                        @else
                            <span class="text-muted">{{ exmtrans('scheduled_jobs.no_runs') }}</span>
                        @endif
                    </td>
                    <td class="text-right" style="white-space: nowrap;">
                        @if(is_null($row['unavailable']))
                        <form method="post" action="{{ $runUrl }}" style="display:inline" onsubmit="return window.confirm({{ $confirm(exmtrans('scheduled_jobs.confirm_run', ['name' => $row['label']])) }});">
                            <input type="hidden" name="_token" value="{{ csrf_token() }}">
                            <input type="hidden" name="job" value="{{ $row['key'] }}">
                            <button type="submit" class="btn btn-sm btn-primary scheduled-job-run">{{ exmtrans('scheduled_jobs.run_now') }}</button>
                        </form>
                        @else
                        @php $why = \Exceedone\Exment\Services\ScheduledJobs\ScheduledJobs::unavailableText($row['unavailable']); @endphp
                        <button type="button" class="btn btn-sm btn-default scheduled-job-run" disabled title="{{ $why }}">{{ exmtrans('scheduled_jobs.run_now') }}</button>
                        <br><small class="text-muted scheduled-job-run-reason">{{ $why }}</small>
                        @endif
                    </td>
                </tr>
                @if($row['runs']->count() > 0)
                <tr class="scheduled-job-runs">
                    <td colspan="6" style="border-top: none; padding-top: 0;">
                        <details>
                            <summary class="text-muted">{{ exmtrans('scheduled_jobs.runs_header', ['count' => $row['runs']->count()]) }}</summary>
                            <table class="table table-condensed" style="margin-top: 8px;">
                                <thead>
                                    <tr>
                                        <th>{{ exmtrans('scheduled_jobs.column.started_at') }}</th>
                                        <th>{{ exmtrans('scheduled_jobs.column.finished_at') }}</th>
                                        <th>{{ exmtrans('scheduled_jobs.column.duration') }}</th>
                                        <th>{{ exmtrans('scheduled_jobs.column.result') }}</th>
                                        <th>{{ exmtrans('scheduled_jobs.column.exit_code') }}</th>
                                        <th>{{ exmtrans('scheduled_jobs.column.triggered_by') }}</th>
                                        <th>{{ exmtrans('scheduled_jobs.column.output') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                @foreach($row['runs'] as $run)
                                    <tr>
                                        <td style="white-space: nowrap;">{{ $local($run->started_at) }}</td>
                                        <td style="white-space: nowrap;">{{ $local($run->finished_at) ?: '-' }}</td>
                                        <td>{{ is_null($run->duration_ms) ? '-' : exmtrans('scheduled_jobs.seconds', ['seconds' => number_format($run->duration_ms / 1000, 1)]) }}</td>
                                        <td><span class="label {{ $resultLabel[$run->result] ?? 'label-default' }}">{{ exmtrans('scheduled_jobs.result.' . $run->result) }}</span></td>
                                        <td>{{ $run->exit_code ?? '-' }}</td>
                                        <td>{{ $userName(strval($run->triggered_by)) }}</td>
                                        <td>
                                            @if(!is_null($run->output_tail) && $run->output_tail !== '')
                                            <pre style="white-space: pre-wrap; max-height: 200px; overflow: auto; margin: 0;">{{ $run->output_tail }}</pre>
                                            @else
                                            -
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </details>
                    </td>
                </tr>
                @endif
            @endforeach
            </tbody>
        </table>
        </div>
    </div>
</div>
