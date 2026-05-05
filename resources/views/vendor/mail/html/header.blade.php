@props(['url'])

@php
    $brandName = trim($slot) === 'Laravel' ? 'SHIFT' : trim($slot);
@endphp

<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{{ $brandName }}
</a>
</td>
</tr>
