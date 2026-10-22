<?php

namespace Statikbe\FilamentVoight\Resources\ProjectResource\Filters;

use Filament\Forms\Components\Select;
use Filament\Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Statikbe\FilamentVoight\Models\Team;
use Statikbe\FilamentVoight\Resources\Components\TeamOption;

/**
 * "Team is ..." query builder line that shows each team with its avatar, like the other team selects.
 */
class IsRelatedToTeamOperator extends IsRelatedToOperator
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->titleAttribute('name');
        $this->searchable();
        $this->preload();
        $this->getOptionLabelFromRecordUsing(fn (Team $record): string => TeamOption::label($record));
    }

    public function getFormSchema(): array
    {
        return array_map(
            fn ($field) => $field instanceof Select ? $field->allowHtml() : $field,
            parent::getFormSchema(),
        );
    }
}
