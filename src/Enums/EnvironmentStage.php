<?php

namespace Statikbe\FilamentVoight\Enums;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Statikbe\FilamentVoight\Enums\Concerns\HasOptions;
use Statikbe\FilamentVoight\Facades\FilamentVoight;

/**
 * The deployment stage of an environment, derived from its name using the
 * prefixes in the `environments.stages` config.
 */
enum EnvironmentStage: string implements HasColor, HasLabel
{
    use HasOptions;

    case Production = 'production';
    case Acceptance = 'acceptance';
    case Staging = 'staging';
    case Feature = 'feature';
    case Local = 'local';
    case Other = 'other';

    public static function fromName(?string $name): self
    {
        $name = mb_strtolower(trim((string) $name));

        foreach (FilamentVoight::config()->getEnvironmentStagePrefixes() as $stage => $prefixes) {
            if (str($name)->startsWith(array_filter($prefixes))) {
                return self::tryFrom($stage) ?? self::Other;
            }
        }

        return self::Other;
    }

    public function label(): string
    {
        return voightTrans('enums.environment_stage.' . $this->value);
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    /**
     * @return string|array<int|string, string>
     */
    public function getColor(): string | array
    {
        return match ($this) {
            self::Production => Color::Orange,
            self::Acceptance => Color::Yellow,
            self::Staging, self::Feature => Color::Green,
            self::Local, self::Other => 'gray',
        };
    }
}
