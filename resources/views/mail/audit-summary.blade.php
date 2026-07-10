<x-mail::message>
# {{ $headline }}

{{ $intro }}

<x-mail::table>
| {{ voightTrans('notifications.common.severity') }} | {{ voightTrans('notifications.common.count') }} |
|:--------|--------:|
@foreach ($summary->severityCounts as $severityValue => $count)
| {{ \Statikbe\FilamentVoight\Enums\Severity::from($severityValue)->label() }} | {{ $count }} |
@endforeach
</x-mail::table>

## {{ voightTrans('notifications.common.top_findings') }}

@foreach ($summary->topFindings as $finding)
- **{{ $finding['package'] }}** — {{ $finding['severity']->label() }} ({{ number_format($finding['score'], 1) }}): {{ $finding['summary'] }}@if ($finding['fixed_version']) — {{ voightTrans('notifications.common.fixed_in', ['version' => $finding['fixed_version']]) }}@endif

@endforeach

<x-mail::button :url="$summary->detailUrl">
{{ voightTrans('notifications.common.view_project') }}
</x-mail::button>

{{ voightTrans('notifications.common.environments', ['environments' => $summary->environmentList()]) }}

{{ config('app.name') }}
</x-mail::message>
