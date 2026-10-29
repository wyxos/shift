@props(['report'])
<h1>{{ $report['heading'] }}</h1>
<p class="status-row"><span class="status">{{ $report['event'] }}</span></p>
@if ($report['event'] === 'Recurring')
<p class="summary">{{ __('This open error has returned after a quiet period. Further retries are recorded silently.') }}</p>
@endif
<table class="exception" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr><td class="exception-cell">
<strong>{{ $report['exception'] }}</strong>
@if (filled($report['location']))
<p class="source-location">{!! str_replace('/', '/<wbr>', e($report['location'])) !!}</p>
@endif
</td></tr>
</table>
<table class="details" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="project-detail"><span class="label">{{ __('Project') }}</span><span class="value">{{ $report['project'] }}</span></td>
<td><span class="label">{{ __('Environment') }}</span><span class="value">{{ $report['environment'] ?: __('N/A') }}</span></td>
<td class="count-detail"><span class="label">{{ __('Occurrences') }}</span><span class="value">{{ $report['occurrences'] ?? 0 }}</span></td>
</tr>
</table>
