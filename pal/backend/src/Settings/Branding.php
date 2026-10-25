<?php

declare(strict_types=1);

namespace App\Settings;

final class Branding
{
    public const DEFAULT_DISPLAY_NAME = 'PAL';
    public const DEFAULT_ACCENT_COLOR = '#2f5bd3';

    public function __construct(
        public readonly ?string $displayName,
        public readonly ?string $logoUrl,
        public readonly ?string $accentColor,
    ) {
    }

    /**
     * @return array{displayName: string, logoUrl: string|null, accentColor: string, isDefault: array{displayName: bool, logoUrl: bool, accentColor: bool}}
     */
    public function effective(): array
    {
        return [
            'displayName' => $this->displayName ?? self::DEFAULT_DISPLAY_NAME,
            'logoUrl' => $this->logoUrl,
            'accentColor' => $this->accentColor ?? self::DEFAULT_ACCENT_COLOR,
            'isDefault' => [
                'displayName' => null === $this->displayName,
                'logoUrl' => null === $this->logoUrl,
                'accentColor' => null === $this->accentColor,
            ],
        ];
    }

    /**
     * @return array{displayName: string|null, logoUrl: string|null, accentColor: string|null}
     */
    public function toStorage(): array
    {
        return ['displayName' => $this->displayName, 'logoUrl' => $this->logoUrl, 'accentColor' => $this->accentColor];
    }

    public static function fromStorage(mixed $data): self
    {
        if (!\is_array($data)) {
            return new self(null, null, null);
        }
        try {
            return BrandingSanitizer::sanitize($data);
        } catch (\App\Platform\Http\ApiProblem) {
            // Stored values that no longer pass validation fall back to defaults rather than breaking the UI.
            return new self(null, null, null);
        }
    }
}
