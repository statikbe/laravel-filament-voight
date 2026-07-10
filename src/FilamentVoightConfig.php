<?php

namespace Statikbe\FilamentVoight;

use Illuminate\Foundation\Auth\User;
use Statikbe\FilamentVoight\Models\AlertRecipient;
use Statikbe\FilamentVoight\Models\AlertSetting;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Models\DependencySync;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Team;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Models\VulnerablePackageRange;

class FilamentVoightConfig
{
    // -- Panel --

    public function getPanelPath(): string
    {
        return $this->packageConfig('panel.path', 'voight');
    }

    // -- Scanner --

    public function getScannerUrl(): ?string
    {
        return $this->packageConfig('scanner.url');
    }

    public function getScannerToken(): ?string
    {
        return $this->packageConfig('scanner.token');
    }

    // -- Lockfiles --

    public function getLockfilesDisk(): string
    {
        return $this->packageConfig('lockfiles.disk', 'voight-lockfiles');
    }

    // -- API --

    /**
     * @return array<string>
     */
    public function getApiMiddleware(): array
    {
        return $this->packageConfig('api.middleware', ['auth:sanctum']);
    }

    // -- Models --

    /**
     * @return class-string<Customer>
     */
    public function getCustomerModel(): string
    {
        return $this->packageConfig('models.customer', Customer::class);
    }

    /**
     * @return class-string<Project>
     */
    public function getProjectModel(): string
    {
        return $this->packageConfig('models.project', Project::class);
    }

    /**
     * @return class-string<Environment>
     */
    public function getEnvironmentModel(): string
    {
        return $this->packageConfig('models.environment', Environment::class);
    }

    /**
     * @return class-string<Package>
     */
    public function getPackageModel(): string
    {
        return $this->packageConfig('models.package', Package::class);
    }

    /**
     * @return class-string<EnvironmentPackage>
     */
    public function getEnvironmentPackageModel(): string
    {
        return $this->packageConfig('models.environment_package', EnvironmentPackage::class);
    }

    /**
     * @return class-string<DependencySync>
     */
    public function getDependencySyncModel(): string
    {
        return $this->packageConfig('models.dependency_sync', DependencySync::class);
    }

    /**
     * @return class-string<Vulnerability>
     */
    public function getVulnerabilityModel(): string
    {
        return $this->packageConfig('models.vulnerability', Vulnerability::class);
    }

    /**
     * @return class-string<VulnerablePackageRange>
     */
    public function getVulnerablePackageRangeModel(): string
    {
        return $this->packageConfig('models.vulnerable_package_range', VulnerablePackageRange::class);
    }

    /**
     * @return class-string<AuditRun>
     */
    public function getAuditRunModel(): string
    {
        return $this->packageConfig('models.audit_run', AuditRun::class);
    }

    /**
     * @return class-string<AuditFinding>
     */
    public function getAuditFindingModel(): string
    {
        return $this->packageConfig('models.audit_finding', AuditFinding::class);
    }

    /**
     * @return class-string<AlertSetting>
     */
    public function getAlertSettingModel(): string
    {
        return $this->packageConfig('models.alert_setting', AlertSetting::class);
    }

    /**
     * Resolve the host application's authenticatable user model.
     *
     * The module never references App\Models\User directly, so the class
     * is resolved from config at runtime.
     *
     * @return class-string
     */
    public function getUserModel(): string
    {
        return $this->packageConfig('models.user')
            ?? config('auth.providers.users.model')
            ?? User::class;
    }

    // -- Notifications --

    public function getSlackDefaultChannel(): ?string
    {
        return $this->packageConfig('notifications.slack_default_channel');
    }

    /**
     * @return array{address: string, name: string|null}|null
     */
    public function getAlertMailFrom(): ?array
    {
        $address = $this->packageConfig('notifications.mail_from_address');

        if (blank($address)) {
            return null;
        }

        return [
            'address' => $address,
            'name' => $this->packageConfig('notifications.mail_from_name'),
        ];
    }

    public function getAlertsPanelId(): string
    {
        return $this->packageConfig('notifications.panel_id') ?: 'voight';
    }

    public function getAlertsQueue(): ?string
    {
        return $this->packageConfig('notifications.queue');
    }

    // -- Morph Map --

    /**
     * @return array<string, class-string>
     */
    public function getMorphMap(): array
    {
        return $this->packageConfig('morph_map', [
            'voight-customer' => Customer::class,
            'voight-project' => Project::class,
            'voight-environment' => Environment::class,
            'voight-package' => Package::class,
            'voight-environment-package' => EnvironmentPackage::class,
            'voight-dependency-sync' => DependencySync::class,
            'voight-vulnerability' => Vulnerability::class,
            'voight-vulnerable-package-range' => VulnerablePackageRange::class,
            'voight-audit-run' => AuditRun::class,
            'voight-audit-finding' => AuditFinding::class,
            'voight-alert-setting' => AlertSetting::class,
            'voight-alert-recipient' => AlertRecipient::class,
            'voight-team' => Team::class,
        ]);
    }

    // -- Allowed lockfile names --

    /**
     * @return array<string>
     */
    public function getAllowedLockfileNames(): array
    {
        return $this->packageConfig('lockfiles.allowed_names', [
            'composer.lock',
            'package-lock.json',
            'yarn.lock',
            'pnpm-lock.yaml',
            'package.json',
        ]);
    }

    private function packageConfig(string $configKey, mixed $default = null): mixed
    {
        return config(FilamentVoightPlugin::ID . '.' . $configKey, $default);
    }
}
