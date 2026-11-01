@props(['url', 'type' => 'Notification', 'message' => null])

@php
    $logo = $message instanceof \Illuminate\Mail\Message
        ? $message->embed(public_path('brand/shift-wordmark-email.png'))
        : rtrim(config('app.url'), '/').'/brand/shift-wordmark-email.png';
@endphp

<tr>
<td class="header" bgcolor="#0f172a">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td>
<a href="{{ $url }}" aria-label="SHIFT">
<img src="{{ $logo }}" width="124" height="31" class="logo" alt="SHIFT">
</a>
</td>
@if (filled($type))
<td class="notification-type" align="right">{{ $type }}</td>
@endif
</tr>
</table>
</td>
</tr>
