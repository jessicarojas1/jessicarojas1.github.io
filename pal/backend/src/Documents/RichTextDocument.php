<?php

declare(strict_types=1);

namespace App\Documents;

use App\Platform\Http\ApiProblem;

/**
 * The server-side schema for content written in PAL's editor (ADR-0009). Editor output is
 * ProseMirror JSON from the browser, so it is untrusted: this class rebuilds it from an
 * allow-list of node types, marks and attributes, rejecting anything else.
 *
 * The rebuilt JSON is canonical (fixed key order, no unknown attributes), so the same
 * content always serializes to the same bytes, and approvals can bind to its SHA-256.
 * Nothing here ever produces HTML: clients render the canonical JSON node by node.
 */
final class RichTextDocument
{
    /** Stored and request-size ceiling for one body, in bytes of canonical JSON. */
    public const MAX_BYTES = 1_048_576;
    private const MAX_DEPTH = 40;
    private const MAX_NODES = 20_000;
    private const MAX_TEXT_NODE = 100_000;
    private const MAX_HREF = 2_048;

    /** Block nodes allowed wherever the schema says "block". */
    private const BLOCKS = ['paragraph', 'heading', 'bulletList', 'orderedList', 'blockquote', 'horizontalRule', 'table', 'image'];
    private const MAX_ALT = 250;
    private const INLINE = ['text', 'hardBreak'];
    private const MARKS = ['bold', 'italic', 'underline', 'strike', 'link'];

    private int $nodes = 0;
    private string $plainText = '';
    /** @var array<string, true> SHA-256 of every image the content shows */
    private array $images = [];

    private function __construct()
    {
    }

    /**
     * Validates and canonicalizes editor JSON. Returns null for an empty document (no text,
     * table or rule), so an untouched editor never changes a revision's content.
     *
     * @return array{json: string, text: string, sha256: string, images: list<string>}|null
     *
     * @throws ApiProblem 422 when the content does not fit the schema
     */
    public static function normalize(mixed $input, string $field = 'body'): ?array
    {
        if (null === $input) {
            return null;
        }
        $builder = new self();
        try {
            $doc = $builder->node($input, ['doc'], 0) ?? ['type' => 'doc'];
        } catch (\InvalidArgumentException $e) {
            throw ApiProblem::validation([$field => 'The content could not be saved: '.$e->getMessage()]);
        }
        if (!isset($doc['content']) || !$builder->hasSubstance($doc)) {
            return null;
        }
        $json = json_encode($doc, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        if (\strlen($json) > self::MAX_BYTES) {
            throw ApiProblem::validation([$field => 'The content is too long. Split it into several documents.']);
        }

        return ['json' => $json, 'text' => trim($builder->plainText), 'sha256' => hash('sha256', $json), 'images' => array_keys($builder->images)];
    }

    /**
     * Decodes stored canonical JSON for an API response.
     *
     * @return array<string, mixed>|null
     */
    public static function decodeStored(?string $json): ?array
    {
        if (null === $json) {
            return null;
        }
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            return null;
        }
        $document = [];
        foreach ($decoded as $key => $value) {
            if (\is_string($key)) {
                $document[$key] = $value;
            }
        }

        return $document;
    }

    /**
     * @param list<string> $allowed node types permitted at this position
     *
     * @return array<string, mixed>|null null only for a text piece that is empty once cleaned
     */
    private function node(mixed $raw, array $allowed, int $depth): ?array
    {
        if (!\is_array($raw) || array_is_list($raw) && [] !== $raw) {
            throw new \InvalidArgumentException('a content item is malformed.');
        }
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException('the content is nested too deeply.');
        }
        if (++$this->nodes > self::MAX_NODES) {
            throw new \InvalidArgumentException('the content has too many elements.');
        }
        $type = $raw['type'] ?? null;
        if (!\is_string($type) || !\in_array($type, $allowed, true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not allowed here.', \is_string($type) ? mb_substr($type, 0, 40) : '?'));
        }
        $attrs = \is_array($raw['attrs'] ?? null) ? $raw['attrs'] : [];

        return match ($type) {
            'doc' => $this->container('doc', [], $raw, self::BLOCKS, $depth, true),
            'paragraph' => $this->textBlock('paragraph', [], $raw, $depth),
            'heading' => $this->textBlock('heading', ['level' => $this->intAttr($attrs, 'level', 1, 1, 4)], $raw, $depth),
            'bulletList' => $this->container('bulletList', [], $raw, ['listItem'], $depth, true),
            'orderedList' => $this->container('orderedList', $this->orderedListAttrs($attrs), $raw, ['listItem'], $depth, true),
            'listItem' => $this->listItem($raw, $depth),
            'blockquote' => $this->container('blockquote', [], $raw, self::BLOCKS, $depth, true),
            'horizontalRule' => $this->leaf('horizontalRule', $raw),
            'table' => $this->container('table', [], $raw, ['tableRow'], $depth, true),
            'tableRow' => $this->container('tableRow', [], $raw, ['tableHeader', 'tableCell'], $depth, true),
            'tableHeader', 'tableCell' => $this->container($type, $this->cellAttrs($attrs), $raw, self::BLOCKS, $depth, true),
            'hardBreak' => $this->leaf('hardBreak', $raw),
            'image' => $this->image($raw, $attrs),
            default => $this->text($raw),
        };
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<mixed>         $raw
     * @param list<string>         $children
     *
     * @return array<string, mixed>
     */
    private function container(string $type, array $attrs, array $raw, array $children, int $depth, bool $required): array
    {
        $content = [];
        foreach ($this->rawContent($raw) as $child) {
            $node = $this->node($child, $children, $depth + 1);
            if (null !== $node) {
                $content[] = $node;
            }
        }
        if ($required && [] === $content) {
            // ProseMirror requires at least one child; the editor never sends an empty one.
            if ('doc' === $type) {
                return ['type' => 'doc'];
            }
            throw new \InvalidArgumentException(\sprintf('a "%s" is empty.', $type));
        }
        if (\in_array($type, ['tableHeader', 'tableCell', 'listItem', 'blockquote'], true)) {
            $this->plainText .= "\n";
        }

        return $this->build($type, $attrs, $content);
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<mixed>         $raw
     *
     * @return array<string, mixed>
     */
    private function textBlock(string $type, array $attrs, array $raw, int $depth): array
    {
        $content = [];
        foreach ($this->rawContent($raw) as $child) {
            $node = $this->node($child, self::INLINE, $depth + 1);
            if (null !== $node) {
                $content[] = $node;
            }
        }
        $this->plainText .= "\n";

        return $this->build($type, $attrs, $content);
    }

    /**
     * A list item starts with a paragraph (the ProseMirror schema "paragraph block*").
     *
     * @param array<mixed> $raw
     *
     * @return array<string, mixed>
     */
    private function listItem(array $raw, int $depth): array
    {
        $item = $this->container('listItem', [], $raw, self::BLOCKS, $depth, true);
        $first = \is_array($item['content'] ?? null) ? ($item['content'][0] ?? null) : null;
        if (!\is_array($first) || 'paragraph' !== ($first['type'] ?? null)) {
            throw new \InvalidArgumentException('a list item must start with text.');
        }

        return $item;
    }

    /**
     * @param array<mixed> $raw
     *
     * @return array<string, mixed>
     */
    private function leaf(string $type, array $raw): array
    {
        if ([] !== $this->rawContent($raw)) {
            throw new \InvalidArgumentException(\sprintf('a "%s" cannot contain anything.', $type));
        }
        $this->plainText .= 'hardBreak' === $type ? "\n" : '';

        return ['type' => $type];
    }

    /**
     * An image is a reference to a stored, scanned, metadata-free file by its SHA-256 plus a
     * text description. There is no URL: content can never make a browser fetch anything but
     * PAL's own image endpoint, which checks access. Whether the user may use that file is
     * checked by the caller (ContentImages).
     *
     * @param array<mixed> $raw
     * @param array<mixed> $attrs
     *
     * @return array<string, mixed>
     */
    private function image(array $raw, array $attrs): array
    {
        if ([] !== $this->rawContent($raw)) {
            throw new \InvalidArgumentException('an image cannot contain anything.');
        }
        $sha = $attrs['sha256'] ?? null;
        if (!\is_string($sha) || 1 !== preg_match('/^[0-9a-f]{64}$/', $sha)) {
            throw new \InvalidArgumentException('an image reference is not valid.');
        }
        $alt = \is_string($attrs['alt'] ?? null) ? $attrs['alt'] : '';
        $alt = trim((string) preg_replace(['/[\t\n\r]/u', '/[\x00-\x1F\x7F]/u'], [' ', ''], $alt));
        if (!mb_check_encoding($alt, 'UTF-8') || mb_strlen($alt) > self::MAX_ALT) {
            throw new \InvalidArgumentException('an image description is too long (250 characters at most).');
        }
        $this->images[$sha] = true;
        $this->plainText .= $alt."\n";

        return ['type' => 'image', 'attrs' => ['sha256' => $sha, 'alt' => $alt]];
    }

    /**
     * The images referenced by stored canonical JSON.
     *
     * @return list<string>
     */
    public static function imagesIn(?string $json): array
    {
        if (null === $json || !str_contains($json, '"image"')) {
            return [];
        }
        $found = [];
        $stack = [self::decodeStored($json) ?? []];
        while ([] !== $stack) {
            $node = array_pop($stack);
            $attrs = $node['attrs'] ?? null;
            if ('image' === ($node['type'] ?? null) && \is_array($attrs) && \is_string($attrs['sha256'] ?? null)) {
                $found[$attrs['sha256']] = true;
            }
            foreach (\is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
                if (\is_array($child)) {
                    $stack[] = $child;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * @param array<mixed> $raw
     *
     * @return array<string, mixed>|null
     */
    private function text(array $raw): ?array
    {
        $text = $raw['text'] ?? null;
        if (!\is_string($text) || !mb_check_encoding($text, 'UTF-8')) {
            throw new \InvalidArgumentException('a piece of text is malformed.');
        }
        // Control characters have no place in rendered text; tabs and newlines become spaces.
        $text = (string) preg_replace(['/[\t\n\r]/u', '/[\x00-\x1F\x7F]/u'], [' ', ''], $text);
        if ('' === $text) {
            // Pasted content can carry text made only of control characters (e.g. Word's
            // vertical tab); dropping it beats refusing the whole save.
            return null;
        }
        if (mb_strlen($text) > self::MAX_TEXT_NODE) {
            throw new \InvalidArgumentException('a paragraph is too long.');
        }
        $this->plainText .= $text;

        $marks = [];
        $seen = [];
        $rawMarks = $raw['marks'] ?? [];
        if (!\is_array($rawMarks)) {
            throw new \InvalidArgumentException('text formatting is malformed.');
        }
        foreach ($rawMarks as $mark) {
            $type = \is_array($mark) ? ($mark['type'] ?? null) : null;
            if (!\is_string($type) || !\in_array($type, self::MARKS, true)) {
                throw new \InvalidArgumentException('that text formatting is not supported.');
            }
            if (isset($seen[$type])) {
                continue;
            }
            $seen[$type] = true;
            $marks[] = 'link' === $type
                ? ['type' => 'link', 'attrs' => ['href' => self::href(\is_array($mark['attrs'] ?? null) ? ($mark['attrs']['href'] ?? null) : null)]]
                : ['type' => $type];
        }
        // Canonical mark order, so equal formatting always serializes identically.
        usort($marks, fn (array $a, array $b): int => array_search($a['type'], self::MARKS, true) <=> array_search($b['type'], self::MARKS, true));

        return [] === $marks ? ['type' => 'text', 'text' => $text] : ['type' => 'text', 'marks' => $marks, 'text' => $text];
    }

    /**
     * Links may only point at web pages or mail addresses: no javascript:, data:, file: or
     * relative URLs (which could reach PAL's own API).
     */
    private static function href(mixed $href): string
    {
        if (!\is_string($href)) {
            throw new \InvalidArgumentException('a link has no address.');
        }
        $href = trim($href);
        if ('' === $href || \strlen($href) > self::MAX_HREF || 1 === preg_match('/[\x00-\x20\x7F<>"\\\\]/', $href)) {
            throw new \InvalidArgumentException('a link address is not valid.');
        }
        if (1 === preg_match('#^https?://[^/?\#@]+#i', $href)) {
            $parts = parse_url($href);
            // No user@host forms: they hide the real destination behind a familiar-looking name.
            if (\is_array($parts) && isset($parts['host']) && '' !== $parts['host'] && !isset($parts['user']) && !isset($parts['pass'])) {
                return $href;
            }
        }
        if (1 === preg_match('/^mailto:[^@\s]+@[^@\s]+$/i', $href)) {
            return $href;
        }

        throw new \InvalidArgumentException('links must start with https://, http:// or mailto:.');
    }

    /**
     * @param array<mixed> $raw
     *
     * @return list<mixed>
     */
    private function rawContent(array $raw): array
    {
        $content = $raw['content'] ?? [];
        if (!\is_array($content) || !array_is_list($content)) {
            throw new \InvalidArgumentException('a content list is malformed.');
        }

        return $content;
    }

    /**
     * @param array<string, mixed> $attrs
     * @param list<array<string, mixed>> $content
     *
     * @return array<string, mixed>
     */
    private function build(string $type, array $attrs, array $content): array
    {
        $node = ['type' => $type];
        if ([] !== $attrs) {
            $node['attrs'] = $attrs;
        }
        if ([] !== $content) {
            $node['content'] = $content;
        }

        return $node;
    }

    /**
     * @param array<mixed> $attrs
     */
    private function intAttr(array $attrs, string $name, int $default, int $min, int $max, bool $clamp = false): int
    {
        $value = $attrs[$name] ?? $default;
        if ($clamp) {
            // Pasted HTML can carry odd numbers (e.g. <ol start="0">): bring them into range.
            return \is_int($value) ? max($min, min($max, $value)) : $default;
        }
        if (!\is_int($value) || $value < $min || $value > $max) {
            throw new \InvalidArgumentException(\sprintf('"%s" must be between %d and %d.', $name, $min, $max));
        }

        return $value;
    }

    /**
     * @param array<mixed> $attrs
     *
     * @return array<string, int>
     */
    private function orderedListAttrs(array $attrs): array
    {
        $start = $this->intAttr($attrs, 'start', 1, 1, 100_000, true);

        return 1 === $start ? [] : ['start' => $start];
    }

    /**
     * @param array<mixed> $attrs
     *
     * @return array<string, int>
     */
    private function cellAttrs(array $attrs): array
    {
        $out = [];
        $colspan = $this->intAttr($attrs, 'colspan', 1, 1, 20, true);
        $rowspan = $this->intAttr($attrs, 'rowspan', 1, 1, 50, true);
        if (1 !== $colspan) {
            $out['colspan'] = $colspan;
        }
        if (1 !== $rowspan) {
            $out['rowspan'] = $rowspan;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasSubstance(array $node): bool
    {
        if ('' !== trim($this->plainText)) {
            return true;
        }
        $stack = [$node];
        while ([] !== $stack) {
            $current = array_pop($stack);
            if (\in_array($current['type'] ?? null, ['table', 'horizontalRule', 'image'], true)) {
                return true;
            }
            foreach (\is_array($current['content'] ?? null) ? $current['content'] : [] as $child) {
                if (\is_array($child)) {
                    $stack[] = $child;
                }
            }
        }

        return false;
    }
}
