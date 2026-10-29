@props(['report'])
{{ $report['heading'] }}

{{ $report['exception'] }}@if (filled($report['location'])) at {{ $report['location'] }}@endif

{{ __('Event') }}: {{ $report['event'] }}
@if ($report['event'] === 'Recurring')
{{ __('This open error has returned after a quiet period. Further retries are recorded silently.') }}
@endif

{{ __('Project') }}: {{ $report['project'] }}
{{ __('Environment') }}: {{ $report['environment'] ?: __('N/A') }}
{{ __('Occurrences') }}: {{ $report['occurrences'] ?? 0 }}
