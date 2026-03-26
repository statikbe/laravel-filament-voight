<?php

namespace Statikbe\FilamentVoight\Enums;

enum Severity: string
{
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

    public function label(): string
    {
        return voightTrans('enums.severity.' . $this->value);
    }
}
