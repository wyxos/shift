@props(['report'])
{{ $report['heading'] }}

{{ $report['exception'] }}@if (filled($report['location'])) at {{ $report['location'] }}@endif

{{ __('Event') }}: {{ $report['event'] }}

{{ __('Project') }}: {{ $report['project'] }}
{{ __('Environment') }}: {{ $report['environment'] ?: __('N/A') }}
{{ __('Occurrences') }}: {{ $report['occurrences'] ?? 0 }}
