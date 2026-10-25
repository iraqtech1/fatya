<?php

namespace App\Console\Commands;

use App\Services\Store;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PDO;

class ImportLegacy extends Command
{
    protected $signature = 'fatya:import-sqlite {file : Absolute path to a stopped-server SQLite backup}';

    protected $description = 'Import the old Node database into an empty MariaDB store without changing the source';

    public function handle(Store $store): int
    {
        $file = realpath($this->argument('file'));
        if (! $file || ! is_file($file)) {
            $this->error('Source SQLite backup not found.');

            return self::FAILURE;
        }
        if (! extension_loaded('pdo_sqlite')) {
            $this->error('Enable pdo_sqlite for this one-time import.');

            return self::FAILURE;
        }
        if (config('database.default') !== 'mariadb') {
            $this->error('Destination must use DB_CONNECTION=mariadb.');

            return self::FAILURE;
        }
        $source = new PDO('sqlite:file:'.str_replace('\\', '/', $file).'?mode=ro', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $source->exec('PRAGMA query_only=ON');
        $state = $source->query('SELECT value FROM state WHERE id=1')->fetchColumn();
        if (! $state) {
            $this->error('No legacy store state found.');

            return self::FAILURE;
        }
        $legacy = json_decode($state, true, 512, JSON_THROW_ON_ERROR);
        $credentials = $source->query('SELECT * FROM credentials')->fetchAll(PDO::FETCH_ASSOC);
        if ($credentials && ! extension_loaded('sodium')) {
            $this->error('Enable Sodium to preserve legacy password sign-in.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($store, $legacy, $credentials) {
            $s = $store->read(true);
            if ($s['revision'] !== 0 || collect(Store::TABLES)->contains(fn ($table) => DB::table($table)->exists())) {
                throw new \RuntimeException('Import requires a completely empty store. Do not seed the catalog first.');
            }
            $seed = json_decode(file_get_contents(database_path('seeders/catalog.json')), true);
            $s['settings'] = array_replace($s['settings'], $legacy['settings'] ?? []);
            foreach (Store::TABLES as $key => $table) {
                $s[$key] = $legacy[$key] ?? ($key === 'categories' ? $seed['categories'] : []);
            }
            foreach ($s['products'] as $p) {
                if (! collect($s['categories'])->contains('name', $p['category'])) {
                    $s['categories'][] = ['id' => 'legacy-'.count($s['categories']), 'name' => $p['category'], 'en' => $p['category'], 'icon' => 'Layers', 'active' => true];
                }
            }
            foreach (['name', 'privacyText', 'aboutText'] as $key) {
                $s['settings'][$key] = preg_replace('/\bmasal\b/i', 'Fatya', str_replace('ماسال', 'فاتيا', $s['settings'][$key]));
            }
            $s['revision'] = max(0, (int) ($legacy['revision'] ?? 0));
            $store->save($s);
            foreach ($credentials as $c) {
                DB::table('customer_credentials')->insert(['customer_id' => $c['customer'], 'password' => 'node-scrypt:'.$c['salt'].':'.$c['digest']]);
            }
            foreach ($legacy['codes'] ?? [] as $c) {
                DB::table('inventory_cards')->insert(['product_id' => (string) $c['productId'], 'denomination' => $c['value'], 'code_hash' => hash('sha256', $c['code']), 'encrypted_code' => Crypt::encryptString($c['code']), 'serial' => ($c['serial'] ?? '') ?: null]);
            }
        });
        $this->info('Store, accounts, password hashes and encrypted inventory imported. Sign in again; old sessions are intentionally not copied.');

        return self::SUCCESS;
    }
}
