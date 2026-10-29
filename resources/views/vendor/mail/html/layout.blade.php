@php
    $brandName = config('app.name', 'SHIFT') === 'Laravel' ? 'SHIFT' : config('app.name', 'SHIFT');
@endphp

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ $brandName }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<style>
@media only screen and (max-width: 600px) {
.shell { padding: 20px 12px 24px !important; }
.inner-body, .footer { width: 100% !important; }
.header { padding: 23px 24px !important; }
.content-cell { padding: 27px 24px !important; }
.subcopy-cell { padding: 20px 24px 23px !important; }
h1 { font-size: 24px !important; }
.action-inner { width: 100% !important; }
.button { display: block !important; text-align: center !important; }
.details td { padding-right: 9px !important; padding-left: 9px !important; }
.details td:first-child { padding-left: 0 !important; }
.details td:last-child { padding-right: 0 !important; }
.details .value { font-size: 13px !important; }
.details td, .details .count-detail { width: 30% !important; }
.details .project-detail { width: 40% !important; }
}
@media only screen and (max-width: 360px) {
.details td, .details .project-detail, .details .count-detail { display: block !important; width: 100% !important; border-left: 0 !important; padding: 6px 0 !important; }
.details .label { margin-bottom: 1px !important; }
}
</style>
{!! $head ?? '' !!}
</head>
<body>

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="shell" align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">

<!-- Email Body -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden !important;">
<table class="inner-body" align="center" width="600" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}
<!-- Body content -->
<tr>
<td class="content-cell">
{!! Illuminate\Mail\Markdown::parse($slot) !!}
</td>
</tr>
@isset($subcopy)
<tr><td class="subcopy-cell">{!! $subcopy !!}</td></tr>
@endisset
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
