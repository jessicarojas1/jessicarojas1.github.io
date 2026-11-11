<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documents;

use App\Documents\RevisionComparer;
use PHPUnit\Framework\TestCase;

final class RevisionComparerTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function p(string $text): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function h(string $text): array
    {
        return ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => $text]]];
    }

    /**
     * @param list<array<string, mixed>> $blocks
     *
     * @return array<string, mixed>
     */
    private static function doc(array $blocks): array
    {
        return ['type' => 'doc', 'content' => $blocks];
    }

    public function testUnchangedAddedRemovedAndEditedBlocks(): void
    {
        $before = self::doc([self::h('Purpose'), self::p('Calibrate every wrench yearly.'), self::p('Old note.'), self::h('Scope')]);
        $after = self::doc([self::h('Purpose'), self::p('Calibrate every wrench quarterly.'), self::h('Scope'), self::p('New section.')]);

        $result = RevisionComparer::compareBodies($before, $after);

        self::assertFalse($result['truncated']);
        self::assertSame(['same', 'changed', 'removed', 'same', 'added'], array_column($result['blocks'], 'op'));
        $changed = $result['blocks'][1];
        self::assertSame(
            [['op' => 'same', 'text' => 'Calibrate every wrench '], ['op' => 'removed', 'text' => 'yearly.'], ['op' => 'added', 'text' => 'quarterly.']],
            $changed['words'],
        );
        self::assertSame(self::p('Calibrate every wrench yearly.'), $changed['before']);
        self::assertSame(self::p('Calibrate every wrench quarterly.'), $changed['node']);
    }

    public function testABlockThatChangesTypeIsRemovedAndAddedNotChanged(): void
    {
        $result = RevisionComparer::compareBodies(self::doc([self::p('Scope')]), self::doc([self::h('Scope')]));

        self::assertSame(['removed', 'added'], array_column($result['blocks'], 'op'));
    }

    public function testMissingBodiesCompareAsEmpty(): void
    {
        self::assertSame([], RevisionComparer::compareBodies(null, null)['blocks']);
        self::assertSame(['added'], array_column(RevisionComparer::compareBodies(null, self::doc([self::p('x')]))['blocks'], 'op'));
        self::assertSame(['removed'], array_column(RevisionComparer::compareBodies(self::doc([self::p('x')]), null)['blocks'], 'op'));
    }

    public function testWordDiffJoinsBackIntoBothTexts(): void
    {
        $before = "The owner reviews it\nevery year, and QA signs.";
        $after = 'The owner and QA review it every two years.';
        $runs = RevisionComparer::words($before, $after);

        self::assertSame($before, implode('', array_map(fn (array $r): string => 'added' === $r['op'] ? '' : $r['text'], $runs)));
        self::assertSame($after, implode('', array_map(fn (array $r): string => 'removed' === $r['op'] ? '' : $r['text'], $runs)));
    }

    public function testTextOfNestedStructures(): void
    {
        $list = ['type' => 'bulletList', 'content' => [
            ['type' => 'listItem', 'content' => [self::p('One')]],
            ['type' => 'listItem', 'content' => [self::p('Two')]],
        ]];

        self::assertSame("One\nTwo", RevisionComparer::text($list));
    }

    public function testMetadataAndFileChanges(): void
    {
        $base = ['title' => 'T', 'description' => null, 'keywords' => ['a'], 'changeSummary' => null, 'file' => ['sha256' => str_repeat('a', 64), 'filename' => 'x.pdf'], 'body' => null];
        $next = ['title' => 'T2', 'description' => null, 'keywords' => ['a', 'b'], 'changeSummary' => 'Why', 'file' => ['sha256' => str_repeat('b', 64), 'filename' => 'x.pdf'], 'body' => null];

        $result = RevisionComparer::compare($base, $next);

        self::assertSame(['title', 'keywords', 'changeSummary'], array_column($result['fields'], 'field'));
        self::assertTrue($result['file']['changed']);
        self::assertFalse(RevisionComparer::compare($base, $base)['file']['changed']);
        self::assertSame([], RevisionComparer::compare($base, $base)['fields']);
    }

    public function testASwappedLinkWithTheSameWordsIsReported(): void
    {
        $link = fn (string $href): array => ['type' => 'paragraph', 'content' => [['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]], 'text' => 'the procedure']]];
        $result = RevisionComparer::compareBodies(self::doc([$link('https://good.example/sop')]), self::doc([$link('https://evil.example/sop')]));

        self::assertSame('changed', $result['blocks'][0]['op']);
        self::assertSame([['op' => 'same', 'text' => 'the procedure']], $result['blocks'][0]['words']);
        self::assertSame(['before' => ['https://good.example/sop'], 'after' => ['https://evil.example/sop']], $result['blocks'][0]['links']);
    }

    public function testManyLongEditedParagraphsStayWithinTheWorkBudget(): void
    {
        // About the worst a 1 MiB body allows: many long paragraphs, every one edited.
        $words = fn (string $w): string => implode(' ', array_fill(0, 1_400, $w));
        $before = self::doc(array_map(fn (int $i): array => self::p($words('alpha').' '.$i), range(1, 40)));
        $after = self::doc(array_map(fn (int $i): array => self::p($words('beta').' '.$i), range(1, 40)));
        $started = microtime(true);
        $result = RevisionComparer::compareBodies($before, $after);

        self::assertLessThan(3.0, microtime(true) - $started);
        self::assertCount(40, $result['blocks']);
        // Once the budget is spent, edited blocks fall back to whole old/new text.
        $last = $result['blocks'][39];
        self::assertSame(['removed', 'added'], array_column($last['words'], 'op'));
    }

    public function testVeryLargeBodiesFallBackWithoutQuadraticWork(): void
    {
        $many = self::doc(array_map(fn (int $i): array => self::p('Line '.$i), range(1, 2_001)));
        $started = microtime(true);
        $result = RevisionComparer::compareBodies($many, self::doc([self::p('Only')]));

        self::assertTrue($result['truncated']);
        self::assertLessThan(2.0, microtime(true) - $started);
    }
}
