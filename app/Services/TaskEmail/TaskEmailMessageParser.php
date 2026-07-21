<?php

namespace App\Services\TaskEmail;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TaskEmailMessageParser
{
    private const int MAX_MESSAGE_BYTES = 20 * 1024 * 1024;

    private const int MAX_HEADER_BYTES = 64 * 1024;

    private const int MAX_HEADER_LINE_BYTES = 8 * 1024;

    private const int MAX_HEADERS = 200;

    private const int MAX_MIME_DEPTH = 10;

    private const int MAX_MIME_PARTS = 100;

    private const int MAX_ATTACHMENTS = 50;

    private const int MAX_BOUNDARY_BYTES = 200;

    private const int MAX_EXTRACTED_TEXT_BYTES = 256 * 1024;

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
        $path = $file->getRealPath();
        $content = $path !== false ? file_get_contents($path) : false;

        if (! is_string($content) || $content === '') {
            throw new InvalidArgumentException('The email file is empty or unreadable.');
        }

        if (strlen($content) > self::MAX_MESSAGE_BYTES) {
            throw new InvalidArgumentException('The email file is too large.');
        }

        [$headers, $body] = $this->splitEntity($content);
        $this->assertTopLevelHeaders($headers);

        $partCount = 1;
        $parts = $this->extractBodyParts($body, $headers, 0, $partCount);

        $bodyText = trim($parts['text']);
        if ($bodyText === '' && trim($parts['html']) !== '') {
            $bodyText = $this->htmlToText($parts['html']);
        }

        return [
            'subject' => $this->decodeHeader($headers['subject'][0] ?? ''),
            'from' => $this->decodeHeader($headers['from'][0] ?? ''),
            'to' => $this->decodeHeader($headers['to'][0] ?? ''),
            'date' => $this->decodeHeader($headers['date'][0] ?? ''),
            'body_text' => Str::of($this->limitExtractedText($bodyText))->replaceMatches("/\n{3,}/", "\n\n")->trim()->toString(),
            'body_html' => $this->limitExtractedText($parts['html']),
            'attachments' => array_values(array_unique($parts['attachments'])),
            'filename' => $this->normalizeFilename($file->getClientOriginalName()),
        ];
    }

    /**
     * @return array{0: array<string, array<int, string>>, 1: string}
     */
    private function splitEntity(string $content): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $content);
        $position = strpos($normalized, "\n\n");
        $rawHeaders = $position === false ? $normalized : substr($normalized, 0, $position);

        if (strlen($rawHeaders) > self::MAX_HEADER_BYTES) {
            throw new InvalidArgumentException('The email contains an oversized header block.');
        }

        return [
            $this->parseHeaders($rawHeaders),
            $position === false ? '' : substr($normalized, $position + 2),
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function parseHeaders(string $rawHeaders): array
    {
        $headers = [];
        $current = null;
        $headerCount = 0;

        foreach (explode("\n", $rawHeaders) as $line) {
            if (strlen($line) > self::MAX_HEADER_LINE_BYTES) {
                throw new InvalidArgumentException('The email contains an oversized header line.');
            }

            if ($line === '') {
                continue;
            }

            if (preg_match('/^\s+/', $line) === 1 && $current !== null) {
                $lastIndex = array_key_last($headers[$current]);
                $headers[$current][$lastIndex] .= ' '.trim($line);

                if (strlen($headers[$current][$lastIndex]) > self::MAX_HEADER_LINE_BYTES) {
                    throw new InvalidArgumentException('The email contains an oversized folded header.');
                }

                continue;
            }

            if (! str_contains($line, ':')) {
                $current = null;

                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));

            if (preg_match('/^[a-z0-9][a-z0-9-]{0,126}$/', $name) !== 1) {
                $current = null;

                continue;
            }

            $headerCount++;
            if ($headerCount > self::MAX_HEADERS) {
                throw new InvalidArgumentException('The email contains too many headers.');
            }

            $current = $name;
            $headers[$current][] = trim($value);
        }

        return $headers;
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     */
    private function assertTopLevelHeaders(array $headers): void
    {
        if (array_intersect(['from', 'to', 'subject', 'date', 'message-id', 'mime-version'], array_keys($headers)) === []) {
            throw new InvalidArgumentException('The file does not contain recognizable email headers.');
        }
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     * @return array{text: string, html: string, attachments: array<int, string>}
     */
    private function extractBodyParts(string $body, array $headers, int $depth, int &$partCount): array
    {
        if ($depth > self::MAX_MIME_DEPTH) {
            throw new InvalidArgumentException('The email MIME structure is nested too deeply.');
        }

        $contentType = $this->parseHeaderWithParameters($headers['content-type'][0] ?? 'text/plain');
        $disposition = $this->parseHeaderWithParameters($headers['content-disposition'][0] ?? '');
        $filename = $this->normalizeFilename($disposition['params']['filename'] ?? $contentType['params']['name'] ?? '');
        $isAttachment = ($disposition['value'] ?? '') === 'attachment'
            || ($filename !== '' && ! in_array($contentType['value'], ['text/plain', 'text/html'], true));

        if ($isAttachment) {
            return [
                'text' => '',
                'html' => '',
                'attachments' => $filename !== '' ? [$filename] : [],
            ];
        }

        if (str_starts_with($contentType['value'], 'multipart/')) {
            $boundary = $contentType['params']['boundary'] ?? '';
            $this->assertSafeBoundary($boundary);

            return $this->extractMultipartBodyParts($body, $boundary, $depth + 1, $partCount);
        }

        $decoded = $this->limitExtractedText($this->decodeBody($body, $headers['content-transfer-encoding'][0] ?? ''));

        return match ($contentType['value']) {
            'text/html' => ['text' => '', 'html' => trim($decoded), 'attachments' => []],
            'text/plain', '' => ['text' => trim($decoded), 'html' => '', 'attachments' => []],
            default => ['text' => '', 'html' => '', 'attachments' => []],
        };
    }

    /**
     * @return array{text: string, html: string, attachments: array<int, string>}
     */
    private function extractMultipartBodyParts(string $body, string $boundary, int $depth, int &$partCount): array
    {
        $delimiter = '--'.$boundary;

        if (substr_count($body, $delimiter) > self::MAX_MIME_PARTS + 1) {
            throw new InvalidArgumentException('The email contains too many MIME parts.');
        }

        $segments = explode($delimiter, $body);
        array_shift($segments);
        $result = ['text' => '', 'html' => '', 'attachments' => []];

        foreach ($segments as $segment) {
            $segment = ltrim($segment, "\n");

            if (str_starts_with($segment, '--')) {
                break;
            }

            $part = trim($segment, "\n");
            if ($part === '') {
                continue;
            }

            $partCount++;
            if ($partCount > self::MAX_MIME_PARTS) {
                throw new InvalidArgumentException('The email contains too many MIME parts.');
            }

            [$headers, $partBody] = $this->splitEntity($part);
            $extracted = $this->extractBodyParts($partBody, $headers, $depth, $partCount);

            if ($result['text'] === '' && $extracted['text'] !== '') {
                $result['text'] = $extracted['text'];
            }

            if ($result['html'] === '' && $extracted['html'] !== '') {
                $result['html'] = $extracted['html'];
            }

            $result['attachments'] = [...$result['attachments'], ...$extracted['attachments']];
            if (count($result['attachments']) > self::MAX_ATTACHMENTS) {
                throw new InvalidArgumentException('The email references too many attachments.');
            }
        }

        return $result;
    }

    private function assertSafeBoundary(string $boundary): void
    {
        if ($boundary === '' || strlen($boundary) > self::MAX_BOUNDARY_BYTES || preg_match('/^[\x20-\x7E]+$/D', $boundary) !== 1) {
            throw new InvalidArgumentException('The email contains an invalid MIME boundary.');
        }
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

        $decoded = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?: $value;
        $decoded = mb_convert_encoding($decoded, 'UTF-8', 'UTF-8');
        $decoded = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $decoded) ?? '';

        return Str::limit(trim($decoded), 2048, '');
    }

    private function normalizeFilename(string $filename): string
    {
        $decoded = $this->decodeHeader($filename);
        $decoded = str_replace('\\', '/', $decoded);

        return Str::limit(basename($decoded), 255, '');
    }

    private function limitExtractedText(string $content): string
    {
        $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');

        if (strlen($content) <= self::MAX_EXTRACTED_TEXT_BYTES) {
            return $content;
        }

        return mb_strcut($content, 0, self::MAX_EXTRACTED_TEXT_BYTES, 'UTF-8')."\n[truncated]";
    }

    private function htmlToText(string $html): string
    {
        $withBreaks = preg_replace('/<(br|\/p|\/li|\/div|\/h[1-6])\b[^>]*>/i', "\n", $html) ?? $html;

        return trim(html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
