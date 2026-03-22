<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

class RichContentSanitizer
{
    private const array ALLOWED_TAGS = [
        'a',
        'b',
        'blockquote',
        'br',
        'code',
        'em',
        'h1',
        'h2',
        'h3',
        'h4',
        'h5',
        'h6',
        'hr',
        'i',
        'img',
        'li',
        'ol',
        'p',
        'pre',
        's',
        'strong',
        'u',
        'ul',
    ];

    private const DROP_WITH_CONTENT = [
        'base',
        'embed',
        'form',
        'frame',
        'frameset',
        'iframe',
        'input',
        'link',
        'math',
        'meta',
        'object',
        'script',
        'select',
        'style',
        'svg',
        'textarea',
    ];

    private const SAFE_IMAGE_DATA_URI_PATTERN = '/^data:image\/(?:png|gif|jpe?g|webp);base64,[A-Za-z0-9+\/=\s]+$/i';

    public function sanitize(?string $content): ?string
    {
        if ($content === null || trim($content) === '') {
            return $content;
        }

        if (!$this->hasHtmlMarkup($content)) {
            return $content;
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<?xml encoding="utf-8" ?><div id="shift-rich-content-root">' . $content . '</div>';
        $loaded = $document->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $root = $loaded ? $document->documentElement : null;

        if (!$root instanceof DOMElement || $root->getAttribute('id') !== 'shift-rich-content-root') {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            return $content;
        }

        $this->sanitizeChildren($root);

        $sanitized = '';
        foreach ($this->childNodes($root) as $child) {
            $sanitized .= $document->saveHTML($child) ?: '';
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $sanitized;
    }

    private function hasHtmlMarkup(string $content): bool
    {
        return preg_match('/<\/?[a-z][\s\S]*>/i', $content) === 1;
    }

    private function sanitizeChildren(DOMNode $node): void
    {
        foreach ($this->childNodes($node) as $child) {
            if ($child->nodeType === XML_COMMENT_NODE) {
                $child->parentNode?->removeChild($child);

                continue;
            }

            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            $this->sanitizeElement($child);
        }
    }

    /**
     * @return list<DOMNode>
     */
    private function childNodes(DOMNode $node): array
    {
        $children = [];

        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        return $children;
    }

    private function sanitizeElement(DOMElement $element): void
    {
        $tag = strtolower($element->tagName);

        if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
            $element->parentNode?->removeChild($element);

            return;
        }

        if (!in_array($tag, self::ALLOWED_TAGS, true)) {
            $this->unwrap($element);

            return;
        }

        $this->sanitizeAttributes($element, $tag);

        if ($tag === 'img' && !$element->hasAttribute('src')) {
            $element->parentNode?->removeChild($element);

            return;
        }

        if ($tag === 'a') {
            $hasHref = $element->hasAttribute('href');
            $target = $element->getAttribute('target');

            if (!$hasHref) {
                $element->removeAttribute('target');
                $element->removeAttribute('rel');
            } elseif ($target === '_blank') {
                $element->setAttribute('rel', 'noopener noreferrer');
            } else {
                $element->removeAttribute('target');
                $element->removeAttribute('rel');
            }
        }

        $this->sanitizeChildren($element);
    }

    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if (!$parent) {
            return;
        }

        foreach ($this->childNodes($element) as $child) {
            $parent->insertBefore($child, $element);
        }

        $parent->removeChild($element);
    }

    private function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        $allowedAttributes = $this->allowedAttributes($tag);

        foreach ($this->attributes($element) as $attribute) {
            $name = strtolower($attribute->name);
            $value = trim($attribute->value);

            if (str_starts_with($name, 'on') || !in_array($name, $allowedAttributes, true)) {
                $element->removeAttribute($attribute->name);

                continue;
            }

            if ($tag === 'a' && $name === 'href' && !$this->isSafeHref($value)) {
                $element->removeAttribute($attribute->name);

                continue;
            }

            if ($tag === 'a' && $name === 'target' && $value !== '_blank') {
                $element->removeAttribute($attribute->name);

                continue;
            }

            if ($tag === 'img' && $name === 'src' && !$this->isSafeImageSource($value)) {
                $element->removeAttribute($attribute->name);

                continue;
            }

            if ($tag === 'img' && $name === 'class') {
                $normalized = $this->normalizeAllowedClasses($value, ['editor-tile']);
                if ($normalized === '') {
                    $element->removeAttribute($attribute->name);
                } else {
                    $element->setAttribute($attribute->name, $normalized);
                }

                continue;
            }

            if ($tag === 'blockquote' && $name === 'class') {
                $normalized = $this->normalizeAllowedClasses($value, ['shift-reply']);
                if ($normalized === '') {
                    $element->removeAttribute($attribute->name);
                } else {
                    $element->setAttribute($attribute->name, $normalized);
                }

                continue;
            }

            if ($tag === 'blockquote' && $name === 'data-reply-to' && preg_match('/^\d+$/', $value) !== 1) {
                $element->removeAttribute($attribute->name);

                continue;
            }

            if ($tag === 'code' && $name === 'class') {
                $classes = preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $allowed = array_values(array_filter($classes, fn(string $class) => $class === 'hljs' || str_starts_with($class, 'language-')));
                if ($allowed === []) {
                    $element->removeAttribute($attribute->name);
                } else {
                    $element->setAttribute($attribute->name, implode(' ', array_unique($allowed)));
                }

                continue;
            }

            if ($tag === 'ol' && $name === 'start' && preg_match('/^\d+$/', $value) !== 1) {
                $element->removeAttribute($attribute->name);
            }
        }
    }

    private function allowedAttributes(string $tag): array
    {
        return match ($tag) {
            'a' => ['href', 'target', 'rel', 'title'],
            'blockquote' => ['class', 'data-reply-to'],
            'code' => ['class'],
            'img' => ['src', 'alt', 'title', 'class'],
            'ol' => ['start'],
            default => [],
        };
    }

    /**
     * @return list<object>
     */
    private function attributes(DOMElement $element): array
    {
        $attributes = [];

        foreach ($element->attributes as $attribute) {
            $attributes[] = $attribute;
        }

        return $attributes;
    }

    private function isSafeHref(string $value): bool
    {
        if ($this->isSafeRelativeUrl($value)) {
            return true;
        }

        $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true);
    }

    private function isSafeRelativeUrl(string $value): bool
    {
        return (str_starts_with($value, '/') && !str_starts_with($value, '//'))
            || str_starts_with($value, '#')
            || str_starts_with($value, '?');
    }

    private function isSafeImageSource(string $value): bool
    {
        if ($this->isSafeRelativeUrl($value)) {
            return true;
        }

        $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
        if (in_array($scheme, ['http', 'https'], true)) {
            return true;
        }

        return preg_match(self::SAFE_IMAGE_DATA_URI_PATTERN, $value) === 1;
    }

    private function normalizeAllowedClasses(string $value, array $allowed): string
    {
        $classes = preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $filtered = array_values(array_filter($classes, fn(string $class) => in_array($class, $allowed, true)));

        return implode(' ', array_unique($filtered));
    }
}
