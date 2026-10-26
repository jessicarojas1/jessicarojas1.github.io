<?php

declare(strict_types=1);

namespace App\Platform\Health;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A dependency check contributed by a feature module to /health/ready. Lets Platform report
 * module dependencies (for example blob storage) without depending on those modules.
 */
#[AutoconfigureTag(self::TAG)]
interface ReadinessProbe
{
    public const TAG = 'pal.readiness_probe';

    /** Short, stable check name shown in the readiness response. */
    public function name(): string;

    /** True when the dependency is usable. May throw; the caller treats that as a failure. */
    public function isReady(): bool;
}
