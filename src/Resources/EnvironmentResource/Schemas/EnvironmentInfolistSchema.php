<?php

namespace Statikbe\FilamentVoight\Resources\EnvironmentResource\Schemas;

use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Support\SystemDetailsPresenter;

class EnvironmentInfolistSchema
{
    /** @var array<string, Heroicon> */
    private const array GROUP_ICONS = [
        'environment' => Heroicon::Cog6Tooth,
        'cache' => Heroicon::Bolt,
        'drivers' => Heroicon::CpuChip,
        'php' => Heroicon::CodeBracket,
        'system' => Heroicon::ComputerDesktop,
        'deployment' => Heroicon::RocketLaunch,
        'database' => Heroicon::CircleStack,
        'disk' => Heroicon::ServerStack,
    ];

    public static function configure(Schema $schema): Schema
    {
        $hasSnapshot = fn (Environment $record): bool => $record->systemDetails !== null;

        return $schema->components([
            Grid::make(['default' => 2, 'md' => 3, 'xl' => 4])
                ->columnSpanFull()
                ->visible($hasSnapshot)
                ->components([
                    self::stat('php', Heroicon::CodeBracket, fn (Environment $record): ?string => $record->systemDetails?->php_version),
                    self::stat('database', Heroicon::CircleStack, fn (Environment $record): ?string => SystemDetailsPresenter::database($record)),
                    self::stat('environment', Heroicon::GlobeAlt, fn (Environment $record): string => (string) (SystemDetailsPresenter::appValue($record, 'environment') ?? $record->name)),
                    Section::make()
                        ->compact()
                        ->components([
                            TextEntry::make('stat_debug_mode')
                                ->label(voightTrans('models.environment.system_details.stats.debug_mode'))
                                ->icon(Heroicon::BugAnt)
                                ->state(fn (Environment $record): ?bool => is_bool($debug = SystemDetailsPresenter::appValue($record, 'debug_mode')) ? $debug : null)
                                ->formatStateUsing(fn (?bool $state): string => $state === null ? '—' : voightTrans('models.environment.system_details.' . ($state ? 'yes' : 'no')))
                                ->badge()
                                ->color(fn (?bool $state): string => match ($state) {
                                    true => 'warning',
                                    false => 'success',
                                    null => 'gray',
                                }),
                        ]),
                ]),
            Text::make(voightTrans('models.environment.system_details.empty'))
                ->columnSpanFull()
                ->visible(fn (Environment $record): bool => ! $hasSnapshot($record)),
            Group::make()
                ->columnSpanFull()
                ->visible($hasSnapshot)
                ->components(fn (Environment $record): array => [self::tabs($record)]),
        ]);
    }

    private static function stat(string $name, BackedEnum $icon, \Closure $state): Section
    {
        return Section::make()
            ->compact()
            ->components([
                TextEntry::make("stat_{$name}")
                    ->label(voightTrans("models.environment.system_details.stats.{$name}"))
                    ->icon($icon)
                    ->fontFamily(FontFamily::Mono)
                    ->state($state)
                    ->placeholder('—'),
            ]);
    }

    private static function tabs(Environment $record): Tabs
    {
        $tabs = [];
        $framework = SystemDetailsPresenter::framework($record);
        $extensions = SystemDetailsPresenter::extensions($record);

        if ($framework !== []) {
            $tabs[] = Tab::make(SystemDetailsPresenter::frameworkTitle($record))
                ->id('framework')
                ->icon(Heroicon::Cube)
                ->components([self::frameworkColumns($framework)]);
        }

        $tabs[] = Tab::make(voightTrans('models.environment.system_details.tabs.server'))
            ->id('server')
            ->icon(Heroicon::ServerStack)
            ->components([self::serverColumns(SystemDetailsPresenter::server($record))]);

        if ($extensions !== []) {
            $tabs[] = Tab::make(voightTrans('models.environment.system_details.tabs.extensions'))
                ->id('extensions')
                ->icon(Heroicon::PuzzlePiece)
                ->badge(count($extensions))
                ->components([
                    Text::make(voightTrans('models.environment.system_details.extensions_description', [
                        'version' => data_get($record->systemDetails?->payload, 'server.php.version') ?? '—',
                    ]))
                        ->size(TextSize::Small)
                        ->color('gray'),
                    TextEntry::make('php_extensions')
                        ->hiddenLabel()
                        ->state(SystemDetailsPresenter::extensionLabels($record))
                        ->badge(),
                ]);
        }

        return Tabs::make()->tabs($tabs)->persistTabInQueryString()->columnSpanFull();
    }

    /**
     * Two independent column stacks. Placement hints apply only when the known Laravel groups exist:
     * environment on the left, cache and storage on the right. Every other group, whatever the
     * framework, goes to whichever stack currently has fewer rows.
     *
     * @param  array<string, array{title: string, rows: array<int, array{key: string, label: string, value: string|bool|null}>}>  $groups
     */
    private static function frameworkColumns(array $groups): Grid
    {
        $columns = [[], []];
        $rowCounts = [0, 0];
        $place = function (string $group, int $column) use (&$columns, &$rowCounts, $groups): void {
            $columns[$column][$group] = $groups[$group];
            $rowCounts[$column] += count($groups[$group]['rows']);
        };

        foreach (['environment' => 0, 'cache' => 1, 'storage' => 1] as $group => $column) {
            if (isset($groups[$group])) {
                $place($group, $column);
            }
        }

        $remaining = array_diff(array_keys($groups), ['environment', 'cache', 'storage', 'drivers']);

        foreach (isset($groups['drivers']) ? ['drivers', ...$remaining] : $remaining as $group) {
            $place($group, $rowCounts[1] < $rowCounts[0] ? 1 : 0);
        }

        return Grid::make(['default' => 1, 'lg' => 2])->components(array_map(
            fn (array $column): Group => Group::make(self::sections($column, 'framework')),
            $columns,
        ));
    }

    /**
     * PHP alone on the left, every other group stacked on the right. The database group stays out:
     * the stat card already shows it (the Markdown export keeps it).
     *
     * @param  array<string, array{title: string, rows: array<int, array{key: string, label: string, value: string|bool|null}>}>  $groups
     */
    private static function serverColumns(array $groups): Grid
    {
        $groups = Arr::except($groups, 'database');
        $left = Arr::only($groups, 'php');

        return Grid::make(['default' => 1, 'lg' => 2])->components([
            Group::make(self::sections($left, 'server')),
            Group::make(self::sections(Arr::except($groups, 'php'), 'server')),
        ]);
    }

    /**
     * @param  array<string, array{title: string, rows: array<int, array{key: string, label: string, value: string|bool|null}>}>  $groups
     * @return array<int, Section>
     */
    private static function sections(array $groups, string $prefix): array
    {
        $sections = [];

        foreach ($groups as $group => $data) {
            $sections[] = Section::make($data['title'])
                ->icon(self::GROUP_ICONS[$group] ?? Heroicon::Squares2x2)
                ->compact()
                ->components(array_map(
                    fn (array $row): TextEntry => self::entry("{$prefix}.{$group}.{$row['key']}", $row),
                    $data['rows'],
                ));
        }

        return $sections;
    }

    /**
     * @param  array{key: string, label: string, value: string|bool|null}  $row
     */
    private static function entry(string $name, array $row): TextEntry
    {
        $entry = TextEntry::make($name)
            ->label($row['label'])
            ->inlineLabel()
            ->placeholder('—');

        if (is_bool($row['value'])) {
            return $entry
                ->state(voightTrans('models.environment.system_details.' . ($row['value'] ? 'yes' : 'no')))
                ->badge()
                ->color($row['value'] ? 'success' : 'gray');
        }

        $entry->state($row['value'])->fontFamily(FontFamily::Mono);

        if (is_string($row['value']) && str_starts_with($row['value'], '/')) {
            return $entry
                ->color('gray')
                ->icon(pathinfo($row['value'], PATHINFO_EXTENSION) !== '' ? Heroicon::DocumentText : Heroicon::Folder)
                ->copyable()
                ->wrap();
        }

        return $entry;
    }
}
