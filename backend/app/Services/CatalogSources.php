<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Illuminate\Support\Facades\DB;

class CatalogSources
{
    public static function resolve(array $state, mixed $id, string $previous = ''): ?array
    {
        if ($id === '') {
            return null;
        }
        if (! is_string($id) || strlen($id) > 100) {
            throw new ApiError('اختر مصدراً صحيحاً', 422);
        }
        $source = collect($state['companies'])->firstWhere('id', $id);
        if (! $source || ! in_array($source['role'], ['supplier', 'both'], true) || (! $source['active'] && $id !== $previous)) {
            throw new ApiError('اختر مصدراً مفعّلاً من قائمة المصادر', 422);
        }

        return $source;
    }

    public static function storedOptions(): array
    {
        $companies = DB::table('companies')->orderBy('position')->get(['payload'])->map(fn ($r) => json_decode($r->payload, true, 512, JSON_THROW_ON_ERROR))->all();

        return self::options(['companies' => $companies]);
    }

    public static function options(array $state): array
    {
        return array_values(array_map(fn ($c) => ['id' => $c['id'], 'name' => $c['name'], 'phone' => $c['phone'] ?? '', 'address' => $c['address'] ?? ''], array_filter($state['companies'], fn ($c) => $c['active'] && in_array($c['role'], ['supplier', 'both'], true))));
    }
}
