<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Documents\DocumentRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Keywords travel through PostgreSQL text[]: what goes in must come out unchanged, even
 * with commas, quotes, backslashes, braces and non-ASCII text.
 */
final class KeywordArrayRoundTripTest extends KernelTestCase
{
    public function testHostileKeywordsSurviveARoundTrip(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        $keywords = ['ISO 27001', 'a,b', 'say "hi"', 'back\\slash', '{braces}', 'Ünïcödé', 'NULL'];

        $stored = $db->fetchOne('SELECT ?::text[]', [DocumentRepository::toPgArray($keywords)]);

        self::assertSame($keywords, DocumentRepository::keywords($stored));
        self::assertSame([], DocumentRepository::keywords($db->fetchOne("SELECT '{}'::text[]")));
    }
}
