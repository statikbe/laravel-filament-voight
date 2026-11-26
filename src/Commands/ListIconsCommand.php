<?php

namespace Statikbe\FilamentVoight\Commands;

use BackedEnum;
use Illuminate\Console\Command;
use Illuminate\Contracts\Support\Htmlable;
use Statikbe\FilamentVoight\Enums\VoightIcon;

class ListIconsCommand extends Command
{
    use Concerns\HasVoightBanner;

    public $signature = 'voight:icons
                        {--json : Output as JSON, e.g. for an LLM remapping the icons to another set}';

    public $description = 'List every icon the package renders with its alias, default, current value and description';

    public function handle(): int
    {
        $rows = array_map(fn (VoightIcon $icon): array => [
            'case' => $icon->name,
            'alias' => $icon->alias(),
            'default' => $this->iconToString($icon->default()),
            'current' => $this->iconToString($icon->resolve()),
            'overridden' => $icon->isOverridden(),
            'description' => $icon->description(),
        ], VoightIcon::cases());

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->displayBanner();

        $this->table(
            ['Alias', 'Default', 'Current', 'Overridden', 'Description'],
            array_map(fn (array $row): array => [
                $row['alias'],
                $row['default'],
                $row['current'],
                $row['overridden'] ? 'yes' : 'no',
                $row['description'],
            ], $rows),
        );

        return self::SUCCESS;
    }

    private function iconToString(string | BackedEnum | Htmlable $icon): string
    {
        return match (true) {
            $icon instanceof BackedEnum => (string) $icon->value,
            $icon instanceof Htmlable => $icon->toHtml(),
            default => $icon,
        };
    }
}
