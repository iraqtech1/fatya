<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Keep the established Vue API shape while storing entities in separate MariaDB tables. */
class Store
{
    public const TABLES = ['categories' => 'categories', 'companies' => 'companies', 'products' => 'products', 'customers' => 'customers', 'suppliers' => 'suppliers', 'orders' => 'orders', 'tickets' => 'tickets', 'imports' => 'imports', 'events' => 'events', 'slides' => 'slides', 'middleSlides' => 'middle_slides'];

    public function read(bool $lock = false): array
    {
        $query = DB::table('store_meta')->where('id', 1);
        $meta = ($lock ? $query->lockForUpdate() : $query)->first();
        $s = ['revision' => (int) $meta->revision, 'settings' => json_decode($meta->settings, true, 512, JSON_THROW_ON_ERROR)];
        foreach (self::TABLES as $key => $table) {
            $s[$key] = DB::table($table)->orderBy('position')->get(['payload'])->map(fn ($r) => json_decode($r->payload, true, 512, JSON_THROW_ON_ERROR))->all();
        }

        return $s;
    }

    public function save(array &$s): void
    {
        $s['revision']++;
        DB::table('store_meta')->where('id', 1)->update(['revision' => $s['revision'], 'settings' => self::json($s['settings'])]);
        foreach (self::TABLES as $key => $table) {
            $existing = DB::table($table)->get()->keyBy('id');
            foreach ($s[$key] as $position => $item) {
                $row = ['position' => $position, 'payload' => self::json($item)];
                if ($key === 'customers') {
                    $row['phone'] = $item['phone'] ?: null;
                    $row['email'] = $item['email'] ?: null;
                }
                if (in_array($key, ['orders', 'tickets'])) {
                    $row['customer_id'] = $item['customerId'];
                }
                if ($key === 'orders') {
                    $row['reference'] = $item['reference'];
                }
                $old = $existing->get((string) $item['id']);
                if (! $old || $old->payload !== $row['payload'] || (int) $old->position !== $position) {
                    DB::table($table)->updateOrInsert(['id' => (string) $item['id']], $row);
                }
            }
            // Only banners and the bounded activity log have deletion in the established API.
            if (in_array($key, ['slides', 'middleSlides', 'events'])) {
                DB::table($table)->whereNotIn('id', array_column($s[$key], 'id'))->delete();
            }
        }
    }

    public function event(array &$s, string $message): void
    {
        array_unshift($s['events'], ['id' => (string) Str::uuid(), 'text' => $message, 'date' => now()->toISOString()]);
        $s['events'] = array_slice($s['events'], 0, 30);
    }

    public function seed(): void
    {
        DB::transaction(function () {
            $s = $this->read(true);
            if ($s['products'] || $s['revision'] !== 0) {
                return;
            }
            $seed = json_decode(file_get_contents(database_path('seeders/catalog.json')), true, 512, JSON_THROW_ON_ERROR);
            $s = array_replace($s, $seed);
            $this->save($s);
        });
    }

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
