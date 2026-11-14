<?php

use App\Exceptions\ApiError;
use App\Services\AdminAccess;
use App\Services\ApplicationDisplay;
use App\Services\CustomerCatalog;
use App\Services\DigitalHistory;
use App\Services\Inventory;
use App\Services\InventoryReports;
use App\Services\PriceCenter;
use App\Services\Store;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

if (PHP_SAPI !== 'cli' || count($argv) < 3) {
    exit(1);
}
umask(0077);
$private = realpath($argv[1]);
$mode = $argv[2];
$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$connection = json_decode(file_get_contents($private.'/connection.json'), true, 512, JSON_THROW_ON_ERROR);
$material = json_decode(file_get_contents($private.'/restore-material.json'), true, 512, JSON_THROW_ON_ERROR);
$database = in_array($mode, ['baseline', 'prepare'], true) ? $connection['database'] : 'fatya_bench_20261114';
if ($connection['host'] !== '127.0.0.1' || (int) $connection['port'] !== 33417 || ! preg_match('/^fatya_(restore|bench)_[a-z0-9_]+$/D', $database)) {
    throw new RuntimeException('Private local connection required');
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'acceptance', 'database.connections.acceptance' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 33417, 'database' => $database, 'username' => $connection['user'], 'password' => $connection['password'], 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true], 'app.key' => $material['appKey'], 'app.cipher' => $material['cipher'], 'cache.default' => 'array', 'queue.default' => 'sync', 'digital.purchases_enabled' => false, 'digital.topup.enabled' => false, 'digital.rabiaa.enabled' => false]);
Http::preventStrayRequests();
Http::fake(fn () => throw new RuntimeException('External request forbidden during acceptance'));
$db = DB::connection();
request()->attributes->set('adminAccess', app(AdminAccess::class)->resolveSession((object) []));
$queries = 0;
$db->listen(function () use (&$queries) {
    $queries++;
});
$catalog = function () {
    $state = app(Store::class)->readPublic(null);
    app(Inventory::class)->refreshStock($state);
    app(CustomerCatalog::class)->decorate($state);

    return $state;
};
$measure = function ($name, $callback, $iterations = 5) use (&$queries) {
    $samples = [];
    $queryCounts = [];
    $bytes = 0;
    for ($i = 0; $i < $iterations; $i++) {
        $queries = 0;
        $start = hrtime(true);
        $data = $callback();
        $bytes = strlen(json_encode($data));
        $samples[] = (hrtime(true) - $start) / 1e6;
        $queryCounts[] = $queries;
        unset($data);
    }
    sort($samples);

    return ['operation' => $name, 'iterations' => $iterations, 'medianMs' => round($samples[(int) floor(count($samples) / 2)], 2), 'p95Ms' => round($samples[max(0, (int) ceil(count($samples) * .95) - 1)], 2), 'maxMs' => round(max($samples), 2), 'queryCounts' => $queryCounts, 'responseJsonBytes' => $bytes, 'peakMemoryMiB' => round(memory_get_peak_usage(true) / 1048576, 2)];
};
if ($mode === 'prepare') {
    // The benchmark schema is separate from the verified restore and production.
    $pdo = new PDO('mysql:host=127.0.0.1;port=33417;charset=utf8mb4', $connection['user'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $manifest = json_decode(file_get_contents($private.'/snapshot-manifest.json'), true);
    if (! hash_equals($manifest['sqlSha256'], hash_file('sha256', $private.'/database.sql'))) {
        throw new RuntimeException('Snapshot mismatch');
    }
    $pdo->exec('CREATE DATABASE `fatya_bench_20261114` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `fatya_bench_20261114`');
    $pdo->exec(file_get_contents($private.'/database.sql'));
    echo json_encode(['isolatedBenchmarkPrepared' => true]).PHP_EOL;
    exit;
}
if ($mode === 'seed') {
    $target = (int) ($argv[3] ?? 0);
    if ($target < 1000 || $target > 50001 || $database !== 'fatya_bench_20261114') {
        throw new RuntimeException('Bounded synthetic fixture required');
    }
    $original = $db->table('inventory_cards')->first();
    $existing = $db->table('inventory_cards')->count();
    for ($i = $existing; $i < $target;) {
        $rows = [];
        for ($j = 0; $j < 500 && $i < $target; $j++, $i++) {
            $rows[] = ['product_id' => $original->product_id, 'denomination' => $original->denomination, 'code_hash' => hash('sha256', 'acceptance-fixture-20261114-'.$i), 'encrypted_code' => $original->encrypted_code, 'serial' => 'ACCEPTANCE-FIXTURE-'.$i, 'imported_at' => time(), 'unit_cost' => '1000.000'];
        }
        $db->table('inventory_cards')->insert($rows);
    }
    echo json_encode(['syntheticBenchmarkOnly' => true, 'inventoryRows' => $db->table('inventory_cards')->count()]).PHP_EOL;
    exit;
}
$results = [];
if ($mode === 'baseline') {
    $results[] = $measure('public_catalog_service', $catalog, 15);
    $results[] = $measure('display_preview_service', fn () => app(ApplicationDisplay::class)->preview(), 10);
    $results[] = $measure('price_center_service', fn () => app(PriceCenter::class)->listing(), 10);
    foreach (['inventory', 'movements', 'procurement', 'sales', 'digital', 'payments', 'exports', 'audit'] as $type) {
        $page = app(InventoryReports::class)->read(['type' => $type]);
        $all = app(InventoryReports::class)->read(['type' => $type, 'all' => true]);
        if ($page['totals'] !== $all['totals'] || count($all['rows']) !== $all['totals']['rows']) {
            throw new RuntimeException('Page/export totals mismatch');
        }
        $results[] = $measure('report_'.$type, fn () => app(InventoryReports::class)->read(['type' => $type, 'all' => true]), 5);
    }
    $results[] = $measure('digital_history_service', fn () => app(DigitalHistory::class)->read([]), 10);
} elseif ($mode === 'load' || $mode === 'worker') {
    $results[] = $measure('public_catalog_service', $catalog, $mode === 'worker' ? 4 : 5);
    $results[] = $measure('inventory_page_service', fn () => app(InventoryReports::class)->read(['type' => 'inventory']), $mode === 'worker' ? 3 : 5);
    if ($mode !== 'worker') {
        $results[] = $measure('inventory_full_export_dataset', fn () => app(InventoryReports::class)->read(['type' => 'inventory', 'all' => true]), 3);
        $all = app(InventoryReports::class)->read(['type' => 'inventory', 'all' => true]);
        $page = app(InventoryReports::class)->read(['type' => 'inventory']);
        if ($page['totals'] !== $all['totals'] || count($all['rows']) !== $all['totals']['rows']) {
            throw new RuntimeException('Stress totals mismatch');
        }
        file_put_contents($private.'/export-dataset-'.$db->table('inventory_cards')->count().'.json', json_encode($all));
    }
} elseif ($mode === 'limit') {
    try {
        app(InventoryReports::class)->read(['type' => 'inventory']);
        throw new RuntimeException('Expected report size limit');
    } catch (ApiError $e) {
        $results[] = ['reportLimitRejected' => true, 'message' => $e->getMessage()];
    }
} else {
    throw new RuntimeException('Unknown benchmark mode');
}
$report = ['at' => gmdate('c'), 'mode' => $mode, 'isolatedLocalOnly' => true, 'transport' => 'PHP CLI service calls; not production HTTP capacity', 'databaseVersion' => $db->selectOne('SELECT VERSION() AS version')->version, 'inventoryRows' => $db->table('inventory_cards')->count(), 'results' => $results, 'externalRequests' => count(Http::recorded()), 'peakMemoryMiB' => round(memory_get_peak_usage(true) / 1048576, 2)];
$suffix = $mode === 'worker' ? '-'.($argv[3] ?? getmypid()) : '-'.$report['inventoryRows'];
file_put_contents($private.'/benchmark-'.$mode.$suffix.'.json', json_encode($report, JSON_PRETTY_PRINT));
echo json_encode($report).PHP_EOL;
