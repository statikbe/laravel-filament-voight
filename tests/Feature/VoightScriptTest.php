<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Statikbe\FilamentVoight\Http\Requests\StoreSystemDetailsRequest;

it('posts framework-neutral system details and never fails the script on an endpoint error', function (int $systemDetailsStatus, string $docroot, ?string $artisan = null) {
    $dir = sys_get_temp_dir() . '/voight-sh-' . uniqid();
    File::makeDirectory($dir . '/' . $docroot, recursive: true);

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    // Records each API request body by endpoint name and answers with a fixed status; other paths are served from the docroot.
    File::put($dir . '/router.php', <<<'PHP'
        <?php
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if (! str_starts_with($path, '/api/voight/')) {
            return false;
        }
        file_put_contents(getenv('RECORD_DIR') . '/' . basename($path) . '.body', file_get_contents('php://input'));
        http_response_code(str_ends_with($path, 'system-details') ? (int) getenv('SYSTEM_DETAILS_STATUS') : 202);
        PHP);
    File::put($dir . '/composer.lock', '{}');
    if ($artisan !== null) {
        File::put($dir . '/artisan', $artisan);
    }

    $env = array_merge(getenv(), [
        'RECORD_DIR' => $dir,
        'SYSTEM_DETAILS_STATUS' => (string) $systemDetailsStatus,
        'VOIGHT_API_BASE_URL' => "http://127.0.0.1:{$port}",
        'VOIGHT_API_TOKEN' => 'token',
        'PROJECT_CODE' => 'demo',
        'APP_ENV' => 'staging',
        'APP_DEBUG' => 'true',
        'APP_URL' => "http://127.0.0.1:{$port}",
    ]);

    $server = proc_open(
        [PHP_BINARY, '-d', 'memory_limit=345M', '-S', "127.0.0.1:{$port}", '-t', $dir . '/' . $docroot, $dir . '/router.php'],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes,
        $dir,
        $env,
    );

    try {
        for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', $port); $i++) {
            usleep(100_000);
        }

        $script = proc_open(
            ['sh', dirname(__DIR__, 2) . '/resources/scripts/voight.sh'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $scriptPipes,
            $dir,
            $env,
        );
        $output = stream_get_contents($scriptPipes[1]);
        $exitCode = proc_close($script);
    } finally {
        proc_terminate($server);
    }

    $payload = json_decode(File::get($dir . '/system-details.body'), true);
    $probeFiles = glob($dir . '/*/voight-probe-*.php');
    File::deleteDirectory($dir);

    expect($exitCode)->toBe(0, $output)
        ->and($probeFiles)->toBe([]);
    expect(Validator::make($payload, (new StoreSystemDetailsRequest)->rules())->errors()->all())->toBe([]);
    expect($payload)->toMatchArray(['environment' => 'staging'])
        ->and($payload['app'])->toBe(['environment' => 'staging', 'debug_mode' => true])
        ->and($payload['framework'])->toBe($artisan === null ? null : ['name' => 'laravel', 'version' => null, 'groups' => null])
        ->and(array_keys($payload))->toBe(['environment', 'collected_at', 'app', 'framework', 'server'])
        ->and($payload['server']['php']['version'])->toBe(PHP_VERSION)
        // The web server runs with a different memory_limit than the CLI; without a docroot the CLI value is posted.
        ->and($payload['server']['php']['memory_limit'] === '345M')->toBe($docroot === 'public')
        ->and($payload['server'])->toHaveKeys(['php', 'system', 'deployment', 'database', 'disk']);
})->with([
    'accepted' => [204, 'public'],
    'server error' => [500, 'public'],
    'no docroot' => [204, 'www'],
    // `artisan` prints garbage and fails: only the framework groups and database fall back to null.
    'failing artisan' => [204, 'public', "<?php\necho \"not json\";\nexit(1);\n"],
]);
