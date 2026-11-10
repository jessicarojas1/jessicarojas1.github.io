<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Identity\Authorization\GlobalRole;
use App\Identity\Authorization\RoleResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Regression guard for the Owner bootstrap wiring. PAL_BOOTSTRAP_OWNER_OIDS is optional and
 * unset on most deployments; it must resolve to an empty string, never null. A bare
 * `env(default::…)` resolved to null and threw a TypeError in RoleResolver's constructor on
 * every /api request that resolved roles (branding, me, …), taking the whole app down while the
 * test suite stayed green because phpunit.dist.xml always defined the variable. This boots the
 * real container with the variable removed and proves role resolution still works.
 */
final class RoleResolverBootstrapDefaultTest extends KernelTestCase
{
    private const VAR = 'PAL_BOOTSTRAP_OWNER_OIDS';

    private string|false $original = false;

    protected function setUp(): void
    {
        $this->original = getenv(self::VAR);
        putenv(self::VAR);
        unset($_ENV[self::VAR], $_SERVER[self::VAR]);
    }

    protected function tearDown(): void
    {
        if (false === $this->original) {
            putenv(self::VAR);
            unset($_ENV[self::VAR], $_SERVER[self::VAR]);
        } else {
            putenv(self::VAR.'='.$this->original);
            $_ENV[self::VAR] = $this->original;
            $_SERVER[self::VAR] = $this->original;
        }
        parent::tearDown();
    }

    public function testRolesResolveWhenBootstrapOwnerOidsIsUnset(): void
    {
        self::bootKernel();
        $resolver = self::getContainer()->get(RoleResolver::class);
        self::assertInstanceOf(RoleResolver::class, $resolver);

        // A user with no mappings and no bootstrap match is still a Reader — and, crucially,
        // resolving roles does not throw because the unset variable defaulted to '' not null.
        $roles = $resolver->rolesFor('00000000-0000-4000-8000-000000000000', null);
        self::assertSame([GlobalRole::Reader], $roles);
    }
}
