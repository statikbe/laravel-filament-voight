<?php

use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Enums\AlertChannel;
use Statikbe\FilamentVoight\Enums\AlertFrequency;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Models\AlertRecipient;
use Statikbe\FilamentVoight\Models\AlertSetting;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Team;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\EditProject;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\AlertSettingsRelationManager;
use Statikbe\FilamentVoight\Tests\Support\User;

function makeAlertUiUser(string $name, string $email): User
{
    return User::forceCreate([
        'name' => $name,
        'email' => $email,
        'password' => bcrypt('password'),
    ]);
}

beforeEach(function () {
    $this->actingAs(new User);
});

it('creates an email alert setting with user and team recipients', function () {
    $project = Project::factory()->create();
    $userA = makeAlertUiUser('Alice', 'alice@example.com');
    $userB = makeAlertUiUser('Bob', 'bob@example.com');
    $team = Team::factory()->create();

    Livewire::test(AlertSettingsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => EditProject::class,
    ])
        ->callAction(TestAction::make('create')->table(), [
            'channel' => AlertChannel::Email->value,
            'severity_threshold' => Severity::High->value,
            'frequency' => AlertFrequency::Immediate->value,
            'recipient_users' => [$userA->getKey(), $userB->getKey()],
            'recipient_teams' => [$team->getKey()],
        ])
        ->assertHasNoActionErrors();

    $setting = AlertSetting::query()->where('project_id', $project->getKey())->sole();

    expect($setting->channel)->toBe(AlertChannel::Email)
        ->and((float) $setting->severity_threshold)->toBe(7.0);

    $recipients = $setting->recipients()->get();

    expect($recipients)->toHaveCount(3)
        ->and($recipients->where('recipient_type', 'voight-user')->pluck('recipient_id')->sort()->values()->all())
        ->toEqual([(string) $userA->getKey(), (string) $userB->getKey()])
        ->and($recipients->where('recipient_type', 'voight-team')->pluck('recipient_id')->values()->all())
        ->toEqual([$team->getKey()]);
});

it('loads existing recipients when editing and removing one deletes only that row', function () {
    $project = Project::factory()->create();
    $setting = AlertSetting::factory()->for($project)->create();

    $userA = makeAlertUiUser('Alice', 'alice@example.com');
    $userB = makeAlertUiUser('Bob', 'bob@example.com');
    $team = Team::factory()->create();

    AlertRecipient::factory()->for($setting)->forRecipient($userA)->create();
    AlertRecipient::factory()->for($setting)->forRecipient($userB)->create();
    AlertRecipient::factory()->for($setting)->forRecipient($team)->create();

    Livewire::test(AlertSettingsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => EditProject::class,
    ])
        ->mountAction(TestAction::make('edit')->table($setting))
        ->assertActionDataSet([
            'recipient_users' => [$userA->getKey(), $userB->getKey()],
            'recipient_teams' => [$team->getKey()],
        ])
        // setActionData() cannot shrink a numeric array (Filament's
        // unsetMissingNumericArrayKeys() accumulates a corrupt state path), so
        // the mounted action's data is replaced directly on the Livewire state.
        ->set('mountedActions.0.data.recipient_users', [(string) $userA->getKey()])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $recipients = $setting->recipients()->get();

    expect($recipients)->toHaveCount(2)
        ->and($recipients->where('recipient_type', 'voight-user')->pluck('recipient_id')->values()->all())
        ->toEqual([(string) $userA->getKey()])
        ->and($recipients->where('recipient_type', 'voight-team')->pluck('recipient_id')->values()->all())
        ->toEqual([$team->getKey()]);
});

it('creates a slack alert setting with a slack channel and offers no webhook url field', function () {
    $project = Project::factory()->create();

    Livewire::test(AlertSettingsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => EditProject::class,
    ])
        ->mountAction(TestAction::make('create')->table())
        ->assertSchemaComponentDoesNotExist('webhook_url')
        ->setActionData([
            'channel' => AlertChannel::Slack->value,
            'severity_threshold' => Severity::Critical->value,
            'frequency' => AlertFrequency::Daily->value,
            'slack_channel' => '#security-alerts',
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $setting = AlertSetting::query()->where('project_id', $project->getKey())->sole();

    expect($setting->channel)->toBe(AlertChannel::Slack)
        ->and((float) $setting->severity_threshold)->toBe(9.0)
        ->and($setting->slack_channel)->toBe('#security-alerts')
        ->and($setting->recipients()->count())->toBe(0);
});

it('shows create, edit and delete actions on the view project page', function () {
    $project = Project::factory()->create();
    $setting = AlertSetting::factory()->for($project)->create();

    Livewire::test(AlertSettingsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertActionVisible(TestAction::make('create')->table())
        ->assertActionVisible(TestAction::make('edit')->table($setting))
        ->assertActionVisible(TestAction::make('delete')->table($setting));
});

it('preselects every package type on the create form and saves them by default', function () {
    $project = Project::factory()->create();
    $allTypes = array_column(PackageType::cases(), 'value');

    Livewire::test(AlertSettingsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => EditProject::class,
    ])
        ->mountAction(TestAction::make('create')->table())
        ->assertSchemaStateSet(['package_types' => $allTypes]);

    Livewire::test(AlertSettingsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => EditProject::class,
    ])
        ->callAction(TestAction::make('create')->table(), [
            'channel' => AlertChannel::Slack->value,
            'severity_threshold' => Severity::High->value,
            'frequency' => AlertFrequency::Daily->value,
        ])
        ->assertHasNoFormErrors();

    expect(AlertSetting::query()->sole()->selectedPackageTypes())->toBe(PackageType::cases());
});

it('requires at least one package type', function () {
    $project = Project::factory()->create();

    Livewire::test(AlertSettingsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => EditProject::class,
    ])
        ->callAction(TestAction::make('create')->table(), [
            'channel' => AlertChannel::Slack->value,
            'severity_threshold' => Severity::High->value,
            'frequency' => AlertFrequency::Daily->value,
            'package_types' => [],
        ])
        ->assertHasFormErrors(['package_types' => 'required']);
});

it('treats a null package_types as every type', function () {
    expect(AlertSetting::factory()->make(['package_types' => null])->selectedPackageTypes())->toBe(PackageType::cases());
});
