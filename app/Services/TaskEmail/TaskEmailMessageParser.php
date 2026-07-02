<?php

namespace App\Services\TaskEmail;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class TaskEmailMessageParser
{
    /**
     * @return array{
     *     subject: string,
     *     from: string,
     *     to: string,
     *     date: string,
     *     body_text: string,
     *     body_html: string,
     *     attachments: array<int, string>,
     *     filename: string
     * }
     */
    public function parse(UploadedFile $file): array
    {
        $content = (string) file_get_contents($file->getRealPath());
        [$headers, $body] = $this->splitEntity($content);
        $parts = $this->extractBodyParts($body, $headers);

        $bodyText = trim($parts['text']);
        if ($bodyText === '' && trim($parts['html']) !== '') {
            $bodyText = $this->htmlToText($parts['html']);
        }

        return [
            'subject' => $this->decodeHeader($headers['subject'][0] ?? ''),
            'from' => $this->decodeHeader($headers['from'][0] ?? ''),
            'to' => $this->decodeHeader($headers['to'][0] ?? ''),
            'date' => $this->decodeHeader($headers['date'][0] ?? ''),
            'body_text' => Str::of($bodyText)->replaceMatches("/\n{3,}/", "\n\n")->trim()->toString(),
            'body_html' => $parts['html'],
            'attachments' => array_values(array_unique($parts['attachments'])),
            'filename' => $file->getClientOriginalName(),
        ];
    }

    /**
     * @return array{0: array<string, array<int, string>>, 1: string}
     */
    private function splitEntity(string $content): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $content);
        $position = strpos($normalized, "\n\n");

        if ($position === false) {
            return [$this->parseHeaders($normalized), ''];
        }

        return [
            $this->parseHeaders(substr($normalized, 0, $position)),
            substr($normalized, $position + 2),
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function parseHeaders(string $rawHeaders): array
    {
        $headers = [];
        $current = null;

        foreach (explode("\n", $rawHeaders) as $line) {
            if ($line === '') {
                continue;
            }

            if (preg_match('/^\s+/', $line) === 1 && $current !== null) {
                $lastIndex = array_key_last($headers[$current]);
                $headers[$current][$lastIndex] .= ' '.trim($line);

                continue;
            }

            if (! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $current = strtolower(trim($name));
            $headers[$current][] = trim($value);
        }

        return $headers;
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     * @return array{text: string, html: string, attachments: array<int, string>}
     */
    private function extractBodyParts(string $body, array $headers): array
    {
        $contentType = $this->parseHeaderWithParameters($headers['content-type'][0] ?? 'text/plain');
        $disposition = $this->parseHeaderWithParameters($headers['content-disposition'][0] ?? '');

        if (($disposition['value'] ?? '') === 'attachment') {
            return [
                'text' => '',
                'html' => '',
                'attachments' => array_filter([
                    $this->decodeHeader($disposition['params']['filename'] ?? $contentType['params']['name'] ?? ''),
                ]),
            ];
        }

        if (str_starts_with($contentType['value'], 'multipart/') && isset($contentType['params']['boundary'])) {
            return $this->extractMultipartBodyParts($body, $contentType['params']['boundary']);
        }

        $decoded = $this->decodeBody($body, $headers['content-transfer-encoding'][0] ?? '');

        return match ($contentType['value']) {
            'text/html' => ['text' => '', 'html' => trim($decoded), 'attachments' => []],
            default => ['text' => trim($decoded), 'html' => '', 'attachments' => []],
        };
    }

    /**
     * @return array{text: string, html: string, attachments: array<int, string>}
     */
    private function extractMultipartBodyParts(string $body, string $boundary): array
    {
        $result = ['text' => '', 'html' => '', 'attachments' => []];

        foreach (explode('--'.$boundary, $body) as $part) {
            $part = trim($part, "\n- ");

            if ($part === '') {
                continue;
            }

            [$headers, $partBody] = $this->splitEntity($part);
            $extracted = $this->extractBodyParts($partBody, $headers);

            if ($result['text'] === '' && $extracted['text'] !== '') {
                $result['text'] = $extracted['text'];
            }

            if ($result['html'] === '' && $extracted['html'] !== '') {
                $result['html'] = $extracted['html'];
            }

            $result['attachments'] = [...$result['attachments'], ...$extracted['attachments']];
        }

        return $result;
    }

    /**
     * @return array{value: string, params: array<string, string>}
     */
    private function parseHeaderWithParameters(string $header): array
    {
        $segments = array_map('trim', explode(';', $header));
        $value = strtolower(array_shift($segments) ?: '');
        $params = [];

        foreach ($segments as $segment) {
            if (! str_contains($segment, '=')) {
                continue;
            }

            [$name, $parameterValue] = explode('=', $segment, 2);
            $params[strtolower(trim($name))] = trim($parameterValue, " \t\n\r\0\x0B\"");
        }

        return [
            'value' => $value,
            'params' => $params,
        ];
    }

    private function decodeBody(string $body, string $encoding): string
    {
        return match (strtolower(trim($encoding))) {
            'base64' => base64_decode(preg_replace('/\s+/', '', $body) ?: '', true) ?: '',
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    private function decodeHeader(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?: $value;
    }

    private function htmlToText(string $html): string
    {
        $withBreaks = preg_replace('/<(br|\/p|\/li|\/div|\/h[1-6])\b[^>]*>/i', "\n", $html) ?? $html;

        return trim(html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
