<?php

namespace Statikbe\FilamentVoight\Resources\ProjectResource\Pages;

use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Enums\VoightIcon;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Resources\Components\EnvironmentNameColumn;
use Statikbe\FilamentVoight\Resources\ProjectResource;
use Statikbe\FilamentVoight\Resources\ProjectResource\Schemas\ProjectInfoListSchema;
use Statikbe\FilamentVoight\Services\Mcp\CurrentFindings;

class ViewProject extends ViewRecord implements HasTable
{
    use InteractsWithTable;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            DeleteAction::make()
                ->requiresConfirmation(),
        ];
    }

    protected static string $resource = ProjectResource::class;

    public function getTitle(): string | Htmlable
    {
        return $this->getRecordTitle();
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return voightTrans('models.project.sections.info_tab');
    }

    public function getContentTabIcon(): string | BackedEnum | Htmlable
    {
        return VoightIcon::TabProjectInfo->resolve();
    }

    public function includesNpm(): bool
    {
        return (bool) ($this->getTableFilterState('include_npm')['isActive'] ?? false);
    }

    public function table(Table $table): Table
    {
        $severityColumn = fn (Severity $severity): TextColumn => TextColumn::make($severity->value)
            ->label($severity->label())
            ->state(fn (Environment $record): int => $this->findings($record)[$severity->value])
            ->when(
                $severity === Severity::Critical,
                fn (TextColumn $column): TextColumn => $column
                    ->color(fn (int $state): ?string => $state > 0 ? 'danger' : null)
                    ->weight(fn (int $state): ?FontWeight => $state > 0 ? FontWeight::Bold : null),
            );

        return $table
            ->query(fn (): Builder => $this->getRecord()->environments()->getQuery())
            ->defaultSort('name')
            ->paginated(false)
            ->columns([
                EnvironmentNameColumn::make('name')
                    ->label(voightTrans('models.environment.label')),
                TextColumn::make('status')
                    ->label(voightTrans('models.project.fields.status'))
                    ->state(fn (Environment $record): string => $this->status($record))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => voightTrans('models.project.fields.status_' . $state))
                    ->color(fn (string $state): string => match ($state) {
                        'vulnerable' => 'danger',
                        'needs_update' => 'warning',
                        default => 'success',
                    }),
                $severityColumn(Severity::Critical),
                $severityColumn(Severity::High),
                $severityColumn(Severity::Medium),
                $severityColumn(Severity::Low),
                TextColumn::make('scanned_at')
                    ->label(voightTrans('models.environment.fields.scanned_at'))
                    ->dateTime()
                    ->placeholder(voightTrans('models.environment.never_scanned')),
            ])
            ->filters([
                Filter::make('include_npm')
                    ->label(voightTrans('models.project.fields.include_npm'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false);
    }

    /**
     * Distinct open vulnerabilities per severity of the environment's latest audit run.
     *
     * @return array<string, int>
     */
    private function findings(Environment $environment): array
    {
        return CurrentFindings::severitySummary($this->severityCounts($this->includesNpm())->get($environment->getKey()))['findings'];
    }

    private function status(Environment $environment): string
    {
        $findings = $this->findings($environment);

        return match (true) {
            $findings[Severity::Critical->value] > 0 => 'vulnerable',
            $findings[Severity::High->value] > 0 => 'needs_update',
            default => 'healthy',
        };
    }

    /**
     * @return Collection<string, object>
     */
    private function severityCounts(bool $includeNpm): Collection
    {
        return once(fn (): Collection => ProjectInfoListSchema::severityCounts(
            $this->getRecord(),
            $includeNpm ? [PackageType::Composer, PackageType::Npm] : [PackageType::Composer],
        ));
    }
}
