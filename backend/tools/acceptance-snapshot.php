<?php

use App\Services\Store;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// CLI only: a consistent read-only production snapshot and catalog inventory.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
umask(0077);
$root = dirname(__DIR__);
chdir($root);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$dir = $root.'/storage/app/private/acceptance-audit-20261114';
if (is_file($dir.'/snapshot-manifest.json')) {
    exit(0);
}
if (! is_dir($dir)) {
    mkdir($dir, 0700, true);
}
$lock = fopen($dir.'/snapshot.lock', 'c');
if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
    exit(1);
}
$db = DB::connection();
if ($db->getDriverName() !== 'mysql' && $db->getDriverName() !== 'mariadb') {
    throw new RuntimeException('MariaDB snapshot required');
}
$envHash = hash_file('sha256', $root.'/.env');
$db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$db->statement('SET TRANSACTION READ ONLY');
$db->beginTransaction();
try {
    $state = app(Store::class)->read();
    $tables = array_map(fn ($r) => array_values((array) $r)[0], $db->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"));
    sort($tables);
    $sql = fopen($dir.'/database.sql', 'x');
    if (! $sql) {
        throw new RuntimeException('Snapshot exists');
    }
    $write = function ($value) use ($sql) {
        if (fwrite($sql, $value) !== strlen($value)) {
            throw new RuntimeException('Incomplete snapshot');
        }
    };
    $write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
    $manifest = ['at' => gmdate('c'), 'server' => $db->selectOne('SELECT VERSION() AS version')->version, 'readOnly' => true, 'tables' => []];
    foreach ($tables as $name) {
        $safe = '`'.str_replace('`', '``', $name).'`';
        $write(array_values((array) $db->select('SHOW CREATE TABLE '.$safe)[0])[1].";\n");
        $hashes = [];
        foreach ($db->table($name)->cursor() as $record) {
            $row = (array) $record;
            $normalized = array_map(fn ($v) => $v === null ? null : (string) $v, $row);
            ksort($normalized);
            $hashes[] = hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $columns = implode(',', array_map(fn ($k) => '`'.str_replace('`', '``', $k).'`', array_keys($row)));
            $values = implode(',', array_map(fn ($v) => $v === null ? 'NULL' : $db->getPdo()->quote((string) $v), array_values($row)));
            $write('INSERT INTO '.$safe.' ('.$columns.') VALUES ('.$values.");\n");
        }
        sort($hashes);
        $manifest['tables'][$name] = ['rows' => count($hashes), 'sha256' => hash('sha256', implode('', $hashes)), 'columns' => $db->getSchemaBuilder()->getColumnListing($name)];
    }
    $write("SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($sql);
    $catalog = ['at' => $manifest['at'], 'readOnly' => true, 'notes' => ['Name matches are review candidates, never proof that deletion is safe.', 'Unassigned legacy products are preserved.', 'No customer contacts, PINs, tokens or payment references are included.'], 'categories' => [], 'companies' => [], 'products' => [], 'images' => []];
    $categoryNames = array_column($state['categories'], 'name');
    $companyIds = array_map('strval', array_column($state['companies'], 'id'));
    $testName = fn ($s) => (bool) preg_match('/تجريب|اختبار|test|demo|fixture|sample/iu', $s);
    $image = function ($path, $owner) use (&$catalog, $root) {
        if (! is_string($path) || $path === '') {
            $catalog['images'][] = ['owner' => $owner, 'path' => '', 'status' => 'missing'];

            return;
        }
        if (preg_match('~^data:image/[a-z0-9.+-]+;base64,(.+)$~is', $path, $matches)) {
            $bytes = base64_decode($matches[1], true);
            $dimensions = $bytes !== false ? @getimagesizefromstring($bytes) : false;
            $catalog['images'][] = ['owner' => $owner, 'path' => 'inline-image', 'status' => $dimensions ? 'inline-present' : 'invalid-inline-image', 'bytes' => $bytes !== false ? strlen($bytes) : 0, 'width' => $dimensions[0] ?? null, 'height' => $dimensions[1] ?? null];

            return;
        }
        $parsed = parse_url($path);
        if (isset($parsed['host']) && ! in_array(strtolower($parsed['host']), ['fatyh.net', 'www.fatyh.net'], true)) {
            $catalog['images'][] = ['owner' => $owner, 'path' => preg_replace('/[?#].*$/', '', $path), 'status' => 'external-not-requested'];

            return;
        }
        $relative = ltrim(urldecode($parsed['path'] ?? $path), '/');
        $resolved = realpath(dirname($root).'/public_html/'.$relative);
        $public = realpath(dirname($root).'/public_html');
        if (! $resolved || ! $public || ! str_starts_with($resolved, $public.DIRECTORY_SEPARATOR) || ! is_file($resolved)) {
            $catalog['images'][] = ['owner' => $owner, 'path' => $relative, 'status' => 'file-not-found'];

            return;
        }
        $dimensions = @getimagesize($resolved);
        $catalog['images'][] = ['owner' => $owner, 'path' => $relative, 'status' => 'present', 'bytes' => filesize($resolved), 'width' => $dimensions[0] ?? null, 'height' => $dimensions[1] ?? null];
    };
    foreach (['categories', 'companies'] as $collection) {
        foreach ($state[$collection] as $entity) {
            $row = array_intersect_key($entity, array_flip(['id', 'name', 'en', 'active', 'parentId', 'role', 'image', 'displayOrder']));
            $row['testNameCandidate'] = $testName($entity['name']);
            $row['linkedProducts'] = count(array_filter($state['products'], fn ($p) => $collection === 'categories' ? $p['category'] === $entity['name'] : (string) ($p['manufacturerId'] ?? '') === (string) $entity['id']));
            $row['missingTranslationNames'] = array_values(array_filter(['ku', 'en'], fn ($locale) => empty($entity['translations'][$locale]['name']) && ! ($locale === 'en' && ! empty($entity['en']))));
            if ($collection === 'companies') {
                $row['parentMissing'] = ! empty($entity['parentId']) && ! in_array((string) $entity['parentId'], $companyIds, true);
            }
            $catalog[$collection][] = $row;
            $image($entity['image'] ?? '', $collection.':'.$entity['id']);
        }
    }
    foreach ($state['products'] as $product) {
        $id = (string) $product['id'];
        $row = array_intersect_key($product, array_flip(['id', 'name', 'en', 'category', 'active', 'manufacturerId', 'supplierCompanyId', 'image', 'values', 'denomNames', 'denomImages', 'denomImportCodes']));
        $row['testNameCandidate'] = $testName($product['name']);
        $row['categoryMissing'] = ! in_array($product['category'], $categoryNames, true);
        $row['manufacturerUnassigned'] = empty($product['manufacturerId']);
        $row['manufacturerMissing'] = ! empty($product['manufacturerId']) && ! in_array((string) $product['manufacturerId'], $companyIds, true);
        $row['inventoryCards'] = $db->table('inventory_cards')->where('product_id', $id)->count();
        $row['orders'] = count(array_filter($state['orders'], fn ($o) => (string) ($o['productId'] ?? '') === $id));
        $row['apiOffers'] = $db->table('digital_offers')->where('product_id', $id)->count();
        $row['apiPublications'] = $db->table('digital_publications')->where('product_id', $id)->count();
        $row['missingTranslationNames'] = array_values(array_filter(['ku', 'en'], fn ($locale) => empty($product['translations'][$locale]['name']) && ! ($locale === 'en' && ! empty($product['en']))));
        $catalog['products'][] = $row;
        $image($product['image'] ?? '', 'products:'.$id);
        foreach ($product['values'] as $index => $value) {
            $image($product['denomImages'][$index] ?? '', 'denomination:'.$id.':'.$index.':'.$value);
        }
    }
    // Keys stay private; never print this material or put it in Git/public_html.
    file_put_contents($dir.'/restore-material.json', json_encode(['appKey' => config('app.key'), 'cipher' => config('app.cipher'), 'sqlSha256' => hash_file('sha256', $dir.'/database.sql')]));
    file_put_contents($dir.'/catalog-inventory.json', json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $manifest['sqlSha256'] = hash_file('sha256', $dir.'/database.sql');
    $manifest['sqlBytes'] = filesize($dir.'/database.sql');
    $manifest['environmentPreserved'] = hash_file('sha256', $root.'/.env') === $envHash;
    $db->commit();
    file_put_contents($dir.'/snapshot-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
    file_put_contents($root.'/acceptance-snapshot-20261114-ok.json', json_encode(['ok' => true, 'at' => $manifest['at'], 'readOnly' => true, 'tables' => count($tables), 'sqlBytes' => $manifest['sqlBytes'], 'environmentPreserved' => $manifest['environmentPreserved'], 'catalogCounts' => array_map('count', array_intersect_key($catalog, array_flip(['categories', 'companies', 'products', 'images'])))]));
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}
