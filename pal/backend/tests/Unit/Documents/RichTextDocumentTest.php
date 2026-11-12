<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documents;

use App\Documents\ManifestHasher;
use App\Documents\RichTextDocument;
use App\Platform\Http\ApiProblem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The editor schema is the trust boundary for content typed in the browser (ADR-0009,
 * threat T16): everything outside the allow-list is rejected, and what is accepted is
 * rebuilt canonically so approvals can bind to its hash.
 */
final class RichTextDocumentTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $content
     *
     * @return array<string, mixed>
     */
    private static function doc(array $content): array
    {
        return ['type' => 'doc', 'content' => $content];
    }

    /**
     * @param list<array<string, mixed>> $inline
     *
     * @return array<string, mixed>
     */
    private static function p(array $inline): array
    {
        return ['type' => 'paragraph', 'content' => $inline];
    }

    public function testATypicalProcedureIsAcceptedAndCanonicalized(): void
    {
        $input = self::doc([
            ['type' => 'heading', 'attrs' => ['level' => 1, 'textAlign' => 'left', 'id' => 'x'], 'content' => [['type' => 'text', 'text' => 'Purpose']]],
            self::p([
                ['text' => 'Calibrate ', 'type' => 'text'],
                ['type' => 'text', 'text' => 'every', 'marks' => [['type' => 'italic'], ['type' => 'bold'], ['type' => 'bold']]],
                ['type' => 'hardBreak'],
                ['type' => 'text', 'text' => 'spec', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.test/spec', 'target' => '_blank', 'onclick' => 'x()']]]],
            ]),
            ['type' => 'orderedList', 'attrs' => ['start' => 1, 'type' => null], 'content' => [
                ['type' => 'listItem', 'content' => [self::p([['type' => 'text', 'text' => 'Step one']])]],
            ]],
            ['type' => 'table', 'content' => [
                ['type' => 'tableRow', 'content' => [
                    ['type' => 'tableHeader', 'attrs' => ['colspan' => 1, 'rowspan' => 1, 'colwidth' => [120]], 'content' => [self::p([['type' => 'text', 'text' => 'Role']])]],
                    ['type' => 'tableCell', 'attrs' => ['colspan' => 2], 'content' => [self::p([])]],
                ]],
            ]],
            ['type' => 'horizontalRule'],
        ]);

        $body = RichTextDocument::normalize($input);

        self::assertNotNull($body);
        self::assertSame(
            '{"type":"doc","content":[{"type":"heading","attrs":{"level":1},"content":[{"type":"text","text":"Purpose"}]},'
            .'{"type":"paragraph","content":[{"type":"text","text":"Calibrate "},{"type":"text","marks":[{"type":"bold"},{"type":"italic"}],"text":"every"},'
            .'{"type":"hardBreak"},{"type":"text","marks":[{"type":"link","attrs":{"href":"https://example.test/spec"}}],"text":"spec"}]},'
            .'{"type":"orderedList","content":[{"type":"listItem","content":[{"type":"paragraph","content":[{"type":"text","text":"Step one"}]}]}]},'
            .'{"type":"table","content":[{"type":"tableRow","content":[{"type":"tableHeader","content":[{"type":"paragraph","content":[{"type":"text","text":"Role"}]}]},'
            .'{"type":"tableCell","attrs":{"colspan":2},"content":[{"type":"paragraph"}]}]}]},{"type":"horizontalRule"}]}',
            $body['json'],
        );
        self::assertSame(hash('sha256', $body['json']), $body['sha256']);
        self::assertStringContainsString('Purpose', $body['text']);
        self::assertStringContainsString('Calibrate every', $body['text']);
        self::assertStringContainsString('Step one', $body['text']);
    }

    public function testTheSameContentAlwaysHashesTheSameWhateverTheKeyOrder(): void
    {
        $a = RichTextDocument::normalize(self::doc([self::p([['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'strike'], ['type' => 'underline']]]])]));
        $b = RichTextDocument::normalize(['content' => [['content' => [['marks' => [['type' => 'underline'], ['type' => 'strike']], 'text' => 'x', 'type' => 'text']], 'type' => 'paragraph']], 'type' => 'doc']);

        self::assertNotNull($a);
        self::assertSame($a, $b);
    }

    public function testAnEmptyEditorIsNoContent(): void
    {
        self::assertNull(RichTextDocument::normalize(null));
        self::assertNull(RichTextDocument::normalize(['type' => 'doc']));
        self::assertNull(RichTextDocument::normalize(self::doc([self::p([]), ['type' => 'heading', 'attrs' => ['level' => 2]]])));
        self::assertNotNull(RichTextDocument::normalize(self::doc([['type' => 'horizontalRule']])));
    }

    public function testControlCharactersAreRemovedFromText(): void
    {
        $body = RichTextDocument::normalize(self::doc([self::p([['type' => 'text', 'text' => "a\u{0000}b\tc"]])]));

        self::assertNotNull($body);
        self::assertStringContainsString('"text":"ab c"', $body['json']);
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function rejected(): iterable
    {
        $link = fn (string $href): array => self::doc([self::p([['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]]])]);

        yield 'not an object' => ['<p>hi</p>'];
        yield 'top level is not a doc' => [self::p([])];
        yield 'raw html node' => [self::doc([['type' => 'html', 'content' => [['type' => 'text', 'text' => '<script>alert(1)</script>']]]])];
        yield 'iframe node' => [self::doc([['type' => 'iframe']])];
        yield 'image by URL instead of stored file' => [self::doc([['type' => 'image', 'attrs' => ['src' => 'https://evil.test/x.png']]])];
        yield 'image with a malformed reference' => [self::doc([['type' => 'image', 'attrs' => ['sha256' => '../../etc/passwd']]])];
        yield 'image with an overlong description' => [self::doc([['type' => 'image', 'attrs' => ['sha256' => str_repeat('a', 64), 'alt' => str_repeat('x', 251)]]])];
        yield 'image inside a paragraph' => [self::doc([self::p([['type' => 'image', 'attrs' => ['sha256' => str_repeat('a', 64)]]])])];
        yield 'text at block level' => [self::doc([['type' => 'text', 'text' => 'loose']])];
        yield 'block inside paragraph' => [self::doc([self::p([['type' => 'heading', 'attrs' => ['level' => 1]]])])];
        yield 'unknown mark' => [self::doc([self::p([['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'textStyle', 'attrs' => ['style' => 'x']]]]])])];
        yield 'javascript link' => [$link('javascript:alert(1)')];
        yield 'obfuscated javascript link' => [$link(' JaVaScRiPt:alert(1)')];
        yield 'data link' => [$link('data:text/html;base64,PHNjcmlwdD4=')];
        yield 'relative link to the API' => [$link('/api/admin/users')];
        yield 'protocol-relative link' => [$link('//evil.test/')];
        yield 'link hiding its host behind user info' => [$link('https://intranet.example.test@evil.test/')];
        yield 'link with user and password' => [$link('http://user:pass@evil.test/')];
        yield 'link with a quote' => [$link('https://example.test/"onmouseover=x')];
        yield 'heading level 7' => [self::doc([['type' => 'heading', 'attrs' => ['level' => 7], 'content' => [['type' => 'text', 'text' => 'x']]]])];
        yield 'heading level as string' => [self::doc([['type' => 'heading', 'attrs' => ['level' => '1'], 'content' => [['type' => 'text', 'text' => 'x']]]])];
        yield 'list item not starting with text' => [self::doc([['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'horizontalRule']]]]]])];
        yield 'empty list' => [self::doc([['type' => 'bulletList', 'content' => []]])];
        yield 'empty table' => [self::doc([['type' => 'table']])];
        yield 'cell outside a row' => [self::doc([['type' => 'table', 'content' => [['type' => 'tableCell', 'content' => [self::p([])]]]]])];
        yield 'content that is an object' => [['type' => 'doc', 'content' => ['a' => self::p([])]]];
        yield 'leaf with content' => [self::doc([['type' => 'horizontalRule', 'content' => [self::p([])]]])];
        $deep = self::p([['type' => 'text', 'text' => 'deep']]);
        for ($i = 0; $i < 45; ++$i) {
            $deep = ['type' => 'blockquote', 'content' => [$deep]];
        }
        yield 'nested too deeply' => [self::doc([$deep])];
    }

    #[DataProvider('rejected')]
    public function testContentOutsideTheSchemaIsRejected(mixed $input): void
    {
        try {
            RichTextDocument::normalize($input);
            self::fail('Expected the content to be rejected.');
        } catch (ApiProblem $problem) {
            self::assertSame(422, $problem->getStatusCode());
            self::assertArrayHasKey('body', $problem->fieldErrors);
        }
    }

    public function testTooManyElementsIsRejected(): void
    {
        $this->expectException(ApiProblem::class);
        RichTextDocument::normalize(self::doc(array_fill(0, 20_001, ['type' => 'horizontalRule'])));
    }

    public function testPastedOddsAndEndsAreRepairedNotRefused(): void
    {
        $body = RichTextDocument::normalize(self::doc([
            self::p([['type' => 'text', 'text' => "\u{000B}"], ['type' => 'text', 'text' => 'kept']]),
            ['type' => 'orderedList', 'attrs' => ['start' => 0], 'content' => [['type' => 'listItem', 'content' => [self::p([['type' => 'text', 'text' => 'x']])]]]],
            ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'attrs' => ['colspan' => 99, 'rowspan' => -1], 'content' => [self::p([])]]]]]],
        ]));

        self::assertNotNull($body);
        self::assertStringContainsString('{"type":"paragraph","content":[{"type":"text","text":"kept"}]}', $body['json']);
        self::assertStringContainsString('{"type":"orderedList","content"', $body['json']);
        self::assertStringContainsString('"attrs":{"colspan":20}', $body['json']);
    }

    public function testImagesAreStoredReferencesWithADescription(): void
    {
        $sha = str_repeat('b', 64);
        $body = RichTextDocument::normalize(self::doc([['type' => 'image', 'attrs' => ['sha256' => $sha, 'alt' => " Wrench\non bench ", 'src' => 'https://evil.test/', 'width' => 99]]]));

        self::assertNotNull($body, 'An image alone is content.');
        self::assertSame('{"type":"doc","content":[{"type":"image","attrs":{"sha256":"'.$sha.'","alt":"Wrench on bench"}}]}', $body['json']);
        self::assertSame([$sha], $body['images']);
        self::assertStringContainsString('Wrench on bench', $body['text']);
        self::assertSame([$sha], RichTextDocument::imagesIn($body['json']));
    }

    public function testMailtoAndHttpLinksAreAllowed(): void
    {
        foreach (['mailto:qa@example.test', 'http://intranet.example.test/a?b=c#d', 'https://example.test'] as $href) {
            $body = RichTextDocument::normalize(self::doc([self::p([['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]]])]));
            self::assertNotNull($body);
            self::assertStringContainsString(json_encode($href, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), $body['json']);
        }
    }

    public function testManifestHashIsUnchangedForRevisionsWithoutABody(): void
    {
        $files = [['role' => 'PRIMARY', 'sha256' => str_repeat('a', 64), 'filename' => 'x.pdf']];
        // The pre-editor manifest structure, written out: existing approvals must still verify.
        $legacy = hash('sha256', (string) json_encode([
            'title' => 'T', 'description' => null, 'keywords' => ['k'], 'change_summary' => null,
            'files' => $files,
        ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

        self::assertSame($legacy, ManifestHasher::hash('T', null, ['k'], null, $files));
        self::assertNotSame($legacy, ManifestHasher::hash('T', null, ['k'], null, $files, str_repeat('b', 64)));
        self::assertNotSame(
            ManifestHasher::hash('T', null, ['k'], null, [], str_repeat('b', 64)),
            ManifestHasher::hash('T', null, ['k'], null, [], str_repeat('c', 64)),
        );
    }
}
