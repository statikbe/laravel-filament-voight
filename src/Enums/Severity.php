<?php

namespace Statikbe\FilamentVoight\Enums;

enum Severity: string
{
    use Concerns\HasOptions;
    case None = 'none';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public static function fromScore(float $score): self
    {
        return match (true) {
            $score >= 9.0 => self::Critical,
            $score >= 7.0 => self::High,
            $score >= 4.0 => self::Medium,
            $score >= 0.1 => self::Low,
            default => self::None,
        };
    }

    public static function fromString(string $severity): self
    {
        return match (strtoupper($severity)) {
            'CRITICAL' => self::Critical,
            'HIGH' => self::High,
            'MEDIUM', 'MODERATE' => self::Medium,
            'LOW' => self::Low,
            default => self::None,
        };
    }

    public function toRepresentativeScore(): float
    {
        return match ($this) {
            self::Critical => 9.5,
            self::High => 7.5,
            self::Medium => 5.0,
            self::Low => 2.0,
            self::None => 0.0,
        };
    }

    public function label(): string
    {
        return voightTrans('enums.severity.' . $this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::None => 'gray',
            self::Low => 'success',
            self::Medium => 'warning',
            self::High, self::Critical => 'danger',
        };
    }
}
