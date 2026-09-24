<?php

declare(strict_types=1);

use Hyperf\Guzzle\ClientFactory as GuzzleClientFactory;
use Psr\Container\ContainerInterface;
use SllhSmile\Elasticsearch\Hyperf\Factory\ClientFactory;
use SllhSmile\Elasticsearch\Hyperf\Manager;
use Swoole\Coroutine;
use Swoole\Runtime;

require dirname(__DIR__) . '/vendor/autoload.php';

$host = getenv('ELASTICSEARCH_BENCHMARK_HOST');
$requests = filter_var(getenv('ELASTICSEARCH_BENCHMARK_REQUESTS') ?: 100, FILTER_VALIDATE_INT);
$concurrency = filter_var(getenv('ELASTICSEARCH_BENCHMARK_CONCURRENCY') ?: 10, FILTER_VALIDATE_INT);
if (! is_string($host) || $host === '' || ! is_int($requests) || $requests < 1
    || ! is_int($concurrency) || $concurrency < 1) {
    fwrite(
        STDERR,
        "Set ELASTICSEARCH_BENCHMARK_HOST and optional positive ELASTICSEARCH_BENCHMARK_REQUESTS/CONCURRENCY.\n",
    );
    exit(1);
}
if (! function_exists('Swoole\\Coroutine\\run')) {
    fwrite(STDERR, "The concurrent benchmark requires the Swoole extension.\n");
    exit(1);
}

$container = new class implements ContainerInterface {
    /** 只为基准测试提供 Hyperf Guzzle 工厂。 */
    public function get(string $id): mixed
    {
        return $id === GuzzleClientFactory::class
            ? new GuzzleClientFactory($this) : throw new RuntimeException($id);
    }

    /** 基准测试不解析日志器或其他宿主服务。 */
    public function has(string $id): bool
    {
        return $id === GuzzleClientFactory::class;
    }
};
$manager = new Manager(new ClientFactory($container), [
    'default' => 'benchmark',
    'connections' => ['benchmark' => ['hosts' => [$host]]],
]);
$client = $manager->connection();
$durations = [];
$failures = [];
$nextRequest = 0;
$workers = min($requests, $concurrency);

// Hook blocking transports so the same shared client is exercised under real coroutine concurrency.
Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
$started = microtime(true);
\Swoole\Coroutine\run(static function () use (
    $client,
    $requests,
    $workers,
    &$nextRequest,
    &$durations,
    &$failures,
): void {
    for ($worker = 0; $worker < $workers; $worker++) {
        Coroutine::create(static function () use (
            $client,
            $requests,
            &$nextRequest,
            &$durations,
            &$failures,
        ): void {
            while (true) {
                $request = $nextRequest++;
                if ($request >= $requests) {
                    return;
                }

                $requestStarted = microtime(true);
                try {
                    $client->info();
                    $durations[] = (microtime(true) - $requestStarted) * 1000;
                } catch (Throwable $exception) {
                    $type = $exception::class;
                    $failures[$type] = ($failures[$type] ?? 0) + 1;
                }
            }
        });
    }
});
$elapsed = microtime(true) - $started;

sort($durations);
$succeeded = count($durations);
$failed = array_sum($failures);
$percentile = static fn(float $p): ?float => $durations === []
    ? null
    : $durations[(int) floor(($succeeded - 1) * $p)];
$p50 = $percentile(0.50);
$p95 = $percentile(0.95);

fwrite(STDOUT, json_encode([
    'requests' => $requests,
    'concurrency' => $workers,
    'succeeded' => $succeeded,
    'failed' => $failed,
    'failure_types' => $failures,
    'elapsed_seconds' => round($elapsed, 4),
    'requests_per_second' => round(($succeeded + $failed) / max($elapsed, PHP_FLOAT_EPSILON), 2),
    'p50_ms' => $p50 === null ? null : round($p50, 3),
    'p95_ms' => $p95 === null ? null : round($p95, 3),
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL);
