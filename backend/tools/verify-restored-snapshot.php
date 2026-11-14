<?php

use Illuminate\Encryption\Encrypter;

// Only restore into a NEW, explicitly named database on a dedicated local port.
if (PHP_SAPI !== 'cli' || ! in_array(count($argv), [2, 3], true) || (isset($argv[2]) && $argv[2] !== '--verify-only')) {
    exit(1);
}
umask(0077);
$private = realpath($argv[1]);
$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$connection = json_decode(file_get_contents($private.'/connection.json'), true, 512, JSON_THROW_ON_ERROR);
if ($connection['host'] !== '127.0.0.1' || (int) $connection['port'] !== 33417 || ! preg_match('/^fatya_restore_[a-z0-9_]+$/D', $connection['database'])) {
    throw new RuntimeException('Isolated restore connection required');
}
$manifest = json_decode(file_get_contents($private.'/snapshot-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$material = json_decode(file_get_contents($private.'/restore-material.json'), true, 512, JSON_THROW_ON_ERROR);
if (! hash_equals($manifest['sqlSha256'], hash_file('sha256', $private.'/database.sql'))) {
    throw new RuntimeException('Snapshot SHA256 mismatch');
}
$pdo = new PDO('mysql:host=127.0.0.1;port=33417;charset=utf8mb4', $connection['user'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$name = $connection['database'];
$started = microtime(true);
if (! isset($argv[2])) {
    $pdo->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo->exec('USE `'.$name.'`');
if (! isset($argv[2])) {
    $sql = file_get_contents($private.'/database.sql');
    if (preg_match('/^(?:USE\s|CREATE\s+DATABASE|DROP\s+DATABASE)/mi', $sql)) {
        throw new RuntimeException('Cross-database SQL is forbidden');
    }
    $pdo->exec($sql);
    unset($sql);
}
$restoredAt = microtime(true);
$verified = [];
foreach ($manifest['tables'] as $table => $expected) {
    if (! preg_match('/^[a-z0-9_]+$/D', $table)) {
        throw new RuntimeException('Invalid table');
    }
    $hashes = [];
    foreach ($pdo->query('SELECT * FROM `'.$table.'`', PDO::FETCH_ASSOC) as $row) {
        $normalized = array_map(fn ($v) => $v === null ? null : (string) $v, $row);
        ksort($normalized);
        $hashes[] = hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    sort($hashes);
    $columns = $pdo->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_COLUMN);
    if (count($hashes) !== $expected['rows'] || ! hash_equals($expected['sha256'], hash('sha256', implode('', $hashes))) || $columns !== $expected['columns']) {
        throw new RuntimeException('Restored content or schema mismatch: '.$table);
    }
    $verified[$table] = count($hashes);
}
$actualTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if (count($actualTables) !== count($verified)) {
    throw new RuntimeException('Restored table count mismatch');
}
$key = $material['appKey'];
if (str_starts_with($key, 'base64:')) {
    $key = base64_decode(substr($key, 7), true);
}
$encrypter = new Encrypter($key, $material['cipher']);
$encrypted = [];
foreach (['inventory_cards' => ['encrypted_code', 'encrypted_details'], 'digital_connections' => ['credential'], 'digital_executions' => ['encrypted_input', 'encrypted_result'], 'rabiaa_activations' => ['encrypted_input', 'encrypted_result']] as $table => $fields) {
    $present = $pdo->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_COLUMN);
    foreach (array_intersect($fields, $present) as $field) {
        $count = 0;
        foreach ($pdo->query('SELECT `'.$field.'` FROM `'.$table.'` WHERE `'.$field.'` IS NOT NULL AND `'.$field."`<>''", PDO::FETCH_COLUMN, 0) as $ciphertext) {
            $plaintext = $encrypter->decryptString($ciphertext);
            unset($plaintext);
            $count++;
        }
        $encrypted[$table.'.'.$field] = $count;
    }
}
$foreignKeys = $pdo->query('SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='.$pdo->quote($name).' AND REFERENCED_TABLE_NAME IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC);
foreach ($foreignKeys as $fk) {
    $q = 'SELECT COUNT(*) FROM `'.$fk['TABLE_NAME'].'` child LEFT JOIN `'.$fk['REFERENCED_TABLE_NAME'].'` parent ON child.`'.$fk['COLUMN_NAME'].'`=parent.`'.$fk['REFERENCED_COLUMN_NAME'].'` WHERE child.`'.$fk['COLUMN_NAME'].'` IS NOT NULL AND parent.`'.$fk['REFERENCED_COLUMN_NAME'].'` IS NULL';
    if ((int) $pdo->query($q)->fetchColumn() !== 0) {
        throw new RuntimeException('Orphaned foreign key');
    }
}
$report = ['ok' => true, 'at' => gmdate('c'), 'sourceSnapshotAt' => $manifest['at'], 'sourceVersion' => $manifest['server'], 'restoreVersion' => $pdo->query('SELECT VERSION()')->fetchColumn(), 'isolatedLocalOnly' => true, 'networkAddress' => '127.0.0.1:33417', 'sqlSha256Verified' => true, 'tableCount' => count($verified), 'allTableContentAndColumnsMatch' => true, 'rowsByTable' => $verified, 'encryptionChecks' => $encrypted, 'foreignKeysChecked' => count($foreignKeys), 'importSeconds' => round($restoredAt - $started, 3), 'verificationSeconds' => round(microtime(true) - $restoredAt, 3), 'originalKeyUsedWithoutChangingProduction' => true, 'externalRequests' => 0];
if (isset($argv[2])) {
    $report['verificationOnly'] = true;
    $report['importSeconds'] = null;
}
file_put_contents($private.(isset($argv[2]) ? '/restore-recheck.json' : '/restore-verification.json'), json_encode($report, JSON_PRETTY_PRINT));
echo json_encode(array_intersect_key($report, array_flip(['ok', 'sourceVersion', 'restoreVersion', 'tableCount', 'allTableContentAndColumnsMatch', 'encryptionChecks', 'foreignKeysChecked', 'importSeconds', 'verificationSeconds', 'externalRequests']))).PHP_EOL;
