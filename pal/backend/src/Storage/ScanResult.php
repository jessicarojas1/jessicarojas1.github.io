<?php

declare(strict_types=1);

namespace App\Storage;

final class ScanResult
{
    private function __construct(
        public readonly string $outcome,
        public readonly string $engine,
        public readonly ?string $detail = null,
    ) {
    }

    public static function clean(string $engine): self
    {
        return new self('clean', $engine);
    }

    public static function infected(string $engine, string $signature): self
    {
        return new self('infected', $engine, $signature);
    }

    public static function error(string $engine, string $reason): self
    {
        return new self('error', $engine, $reason);
    }

    public function isClean(): bool
    {
        return 'clean' === $this->outcome;
    }

    public function isInfected(): bool
    {
        return 'infected' === $this->outcome;
    }
}
