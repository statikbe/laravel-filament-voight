<x-mail::message>
# {{ $headline }}

{{ $intro }}

@foreach ($summary->sections as $section)
## {{ voightTrans('notifications.common.package_type_heading', ['type' => $section['label'], 'count' => $section['total']]) }}

<x-mail::table>
| {{ voightTrans('notifications.common.severity') }} | {{ voightTrans('notifications.common.count') }} |
|:--------|--------:|
@foreach ($section['severityCounts'] as $severityValue => $count)
| {{ \Statikbe\FilamentVoight\Enums\Severity::from($severityValue)->label() }} | {{ $count }} |
@endforeach
</x-mail::table>

@if ($section['fixableCount'] > 0)
{{ voightTrans('notifications.common.fixable', ['fixable' => $section['fixableCount'], 'total' => $section['total']]) }}

@endif
@foreach ($section['topFindings'] as $finding)
- **{{ $finding['package'] }}** — {{ $finding['severity']->label() }} ({{ number_format($finding['score'], 1) }}): {{ $finding['summary'] }}@if ($finding['fixed_version']) — {{ voightTrans('notifications.common.fixed_in', ['version' => $finding['fixed_version']]) }}@endif

@endforeach
@if ($section['total'] > count($section['topFindings']))
{{ voightTrans('notifications.common.more', ['count' => $section['total'] - count($section['topFindings'])]) }}

@endif
@endforeach

<x-mail::button :url="$summary->detailUrl">
{{ $summary->detailLabel }}
</x-mail::button>

{{ voightTrans('notifications.common.environments', ['environments' => $summary->environmentList()]) }}

{{ config('app.name') }}
</x-mail::message>
