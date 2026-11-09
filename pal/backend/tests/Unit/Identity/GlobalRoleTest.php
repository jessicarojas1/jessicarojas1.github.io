<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Authorization\GlobalRole;
use PHPUnit\Framework\TestCase;

/**
 * The role classification helpers that the authorization model depends on, especially the
 * special handling of Owner (superuser): assignable to a user but never mapped to a group.
 */
final class GlobalRoleTest extends TestCase
{
    public function testOwnerIsAssignableToAUserButNeverMappedToAGroup(): void
    {
        self::assertTrue(GlobalRole::Owner->isAssignable(), 'Owner can be granted explicitly to a user');
        self::assertFalse(GlobalRole::Owner->isMappable(), 'Owner must never be conferred through a directory-group mapping');
    }

    public function testOwnerCarriesDocumentAuthority(): void
    {
        self::assertTrue(GlobalRole::Owner->hasDocumentAuthority());
    }

    public function testReaderIsNeitherAssignableNorMappable(): void
    {
        self::assertFalse(GlobalRole::Reader->isAssignable());
        self::assertFalse(GlobalRole::Reader->isMappable());
    }

    public function testMappableValuesExcludeOwnerAndReader(): void
    {
        $mappable = GlobalRole::mappableValues();
        self::assertNotContains('OWNER', $mappable);
        self::assertNotContains('READER', $mappable);
        self::assertContains('SYSTEM_ADMINISTRATOR', $mappable);
        self::assertContains('PUBLISHER', $mappable);
    }

    public function testAssignableValuesIncludeOwnerButNotReader(): void
    {
        $assignable = GlobalRole::assignableValues();
        self::assertContains('OWNER', $assignable);
        self::assertNotContains('READER', $assignable);
    }
}
