<?php

namespace Statikbe\FilamentVoight\Support;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Statikbe\FilamentVoight\Models\Environment;

/**
 * Turns a stored snapshot payload into display rows, shared by the infolist and the Markdown export.
 * Keys are rendered as found, so fields from newer client versions need no change here.
 */
class SystemDetailsPresenter
{
    /** Known server groups come first, in this order; unknown groups follow. */
    private const array SERVER_ORDER = ['php', 'system', 'deployment', 'database', 'disk'];

    /**
     * @return array<string, array{title: string, rows: array<int, array{key: string, label: string, value: string|bool|null}>}>
     */
    public static function laravel(Environment $environment): array
    {
        return self::groups((array) data_get($environment->systemDetails?->payload, 'laravel'));
    }

    /**
     * @return array<string, array{title: string, rows: array<int, array{key: string, label: string, value: string|bool|null}>}>
     */
    public static function server(Environment $environment): array
    {
        $server = (array) data_get($environment->systemDetails?->payload, 'server');
        $ordered = array_merge(
            array_intersect_key(array_flip(self::SERVER_ORDER), $server),
            $server,
        );

        return self::groups($ordered);
    }

    /**
     * @return array<int, string>
     */
    public static function extensions(Environment $environment): array
    {
        return array_values(array_filter((array) data_get($environment->systemDetails?->payload, 'server.php.extensions'), is_string(...)));
    }

    public static function database(Environment $environment): ?string
    {
        $driver = data_get($environment->systemDetails?->payload, 'server.database.driver');

        if (! is_string($driver) || $driver === '') {
            return null;
        }

        $version = data_get($environment->systemDetails?->payload, 'server.database.version');

        return trim($driver . ' ' . (is_scalar($version) ? $version : ''));
    }

    public static function aboutValue(Environment $environment, string $key): mixed
    {
        return data_get($environment->systemDetails?->payload, "laravel.environment.{$key}");
    }

    public static function markdown(Environment $environment): string
    {
        $lines = ["# {$environment->name}", ''];

        foreach (['laravel' => self::laravel($environment), 'server' => self::server($environment)] as $groups) {
            foreach ($groups as $group) {
                $lines[] = "## {$group['title']}";

                foreach ($group['rows'] as $row) {
                    $value = match (true) {
                        is_bool($row['value']) => $row['value'] ? voightTrans('models.environment.system_details.yes') : voightTrans('models.environment.system_details.no'),
                        $row['value'] === null || $row['value'] === '' => '—',
                        default => $row['value'],
                    };
                    $lines[] = "- **{$row['label']}:** {$value}";
                }

                $lines[] = '';
            }
        }

        $extensions = self::extensions($environment);

        if ($extensions !== []) {
            $lines[] = '## ' . voightTrans('models.environment.system_details.tabs.extensions');
            $lines[] = implode(', ', $extensions);
        }

        return rtrim(implode("\n", $lines)) . "\n";
    }

    /**
     * @param  array<string, mixed>  $groups
     * @return array<string, array{title: string, rows: array<int, array{key: string, label: string, value: string|bool|null}>}>
     */
    private static function groups(array $groups): array
    {
        $result = [];

        foreach ($groups as $group => $values) {
            $rows = [];

            foreach (is_array($values) ? $values : ['value' => $values] as $key => $value) {
                // Extensions get their own tab.
                if ($key === 'extensions') {
                    continue;
                }

                $rows[] = ['key' => (string) $key] + self::row((string) $key, $value);
            }

            $result[(string) $group] = [
                'title' => self::translate("groups.{$group}", (string) $group),
                'rows' => $rows,
            ];
        }

        return $result;
    }

    /**
     * @return array{label: string, value: string|bool|null}
     */
    private static function row(string $key, mixed $value): array
    {
        $isBytes = str_ends_with($key, '_bytes');
        $baseKey = $isBytes ? Str::beforeLast($key, '_bytes') : $key;

        $display = match (true) {
            is_bool($value) || $value === null => $value,
            $isBytes && is_numeric($value) => Number::fileSize((float) $value),
            is_scalar($value) => (string) $value,
            is_array($value) && array_is_list($value) && array_filter($value, is_scalar(...)) === $value => implode($key === 'load_average' ? ' / ' : ', ', $value),
            default => (string) json_encode($value),
        };

        return ['label' => self::translate("labels.{$baseKey}", $baseKey), 'value' => $display];
    }

    private static function translate(string $key, string $fallbackKey): string
    {
        $full = "filament-voight::filament-voight.models.environment.system_details.{$key}";

        return Lang::has($full) ? (string) trans($full) : Str::headline($fallbackKey);
    }
}
