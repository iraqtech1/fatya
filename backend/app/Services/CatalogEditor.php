<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Illuminate\Support\Str;

class CatalogEditor
{
    public static function text(mixed $v, int $max = 2000): string
    {
        if (! is_string($v) || mb_strlen($v) > $max) {
            throw new ApiError('بيانات غير صحيحة');
        }

        return trim($v);
    }

    public static function profile(array $b): array
    {
        $name = self::text($b['name'] ?? null, 80);
        $email = strtolower(self::text($b['email'] ?? '', 254));
        if (mb_strlen($name) < 2 || ($email && ! preg_match('/^\S+@\S+\.\S+$/', $email))) {
            throw new ApiError('اكتب اسماً وبريداً صحيحاً');
        }

        return compact('name', 'email');
    }

    public static function image(mixed $src): void
    {
        if (! is_string($src) || strlen($src) > 3000000 || ! ($src === '' || preg_match('~^data:image/(png|jpeg|webp|gif);base64,[A-Za-z0-9+/=]+$~D', $src) || preg_match('~^https://[^\s]+$~D', $src) || preg_match('~^(cards|middle-banners)/[\w.-]+$~D', $src))) {
            throw new ApiError('رابط الصورة غير صحيح');
        }
    }

    public function update(array &$s, string $collection, array $d): ?array
    {
        if (! array_key_exists($collection, Store::TABLES) && ! in_array($collection, ['settings', 'webSlides'])) {
            throw new ApiError('غير موجود', 404);
        }
        $old = collect($s[$collection] ?? [])->firstWhere('id', $d['id'] ?? null);
        $id = $old['id'] ?? (string) Str::uuid();
        $item = null;
        switch ($collection) {
            case 'categories':
                if (! empty($d['id']) && ! $old) {
                    throw new ApiError('التصنيف غير موجود');
                }
                $name = self::text($d['name'] ?? null, 40);
                $en = self::text($d['en'] ?? null, 60);
                if (! $name || $name === 'الكل' || ! $en || ! in_array($d['icon'] ?? '', ['Signal', 'Wifi', 'Gamepad2', 'ShoppingBag', 'Smartphone', 'Layers', 'CreditCard', 'Headphones']) || ! is_bool($d['active'] ?? null)) {
                    throw new ApiError('اكتب اسم التصنيف والاسم الإنجليزي واختر أيقونة صحيحة');
                }
                if (collect($s['categories'])->contains(fn ($c) => $c['id'] !== $id && $c['name'] === $name)) {
                    throw new ApiError('اسم التصنيف موجود بالفعل');
                }
                if ($old) {
                    foreach ($s['products'] as &$p) {
                        if ($p['category'] === $old['name']) {
                            $p['category'] = $name;
                        }
                    }
                } unset($p);
                $item = ['id' => $id, 'name' => $name, 'en' => $en, 'icon' => $d['icon'], 'active' => $d['active']];
                break;
            case 'companies':
                if (! empty($d['id']) && ! $old) {
                    throw new ApiError('الشركة غير موجودة');
                }
                $name = self::text($d['name'] ?? null, 80);
                $role = $d['role'] ?? '';
                if (! $name || ! in_array($role, ['manufacturer', 'supplier', 'both']) || ! is_bool($d['active'] ?? null)) {
                    throw new ApiError('اكتب اسم الشركة واختر نوعها');
                }
                if (collect($s['companies'])->contains(fn ($c) => $c['id'] !== $id && $c['name'] === $name)) {
                    throw new ApiError('اسم الشركة موجود بالفعل');
                }
                foreach ($s['products'] as $p) {
                    if ((($p['manufacturerId'] ?? '') === $id && ! in_array($role, ['manufacturer', 'both'])) || (($p['supplierCompanyId'] ?? '') === $id && ! in_array($role, ['supplier', 'both']))) {
                        throw new ApiError('نوع الشركة مرتبط ببطاقات عدّل ربط البطاقات أولاً');
                    }
                }
                $item = ['id' => $id, 'name' => $name, 'role' => $role, 'active' => $d['active']];
                break;
            case 'settings':
                $webSlides = $s['settings']['webSlides'] ?? null;
                $name = self::text($d['name'] ?? null, 60);
                $email = self::text($d['supportEmail'] ?? null, 254);
                if (! $name || ! is_int($d['lowStock'] ?? null) || $d['lowStock'] < 0 || $d['lowStock'] > 10000 || ($email && ! preg_match('/^\S+@\S+\.\S+$/', $email))) {
                    throw new ApiError('إعدادات غير صحيحة');
                }
                $s['settings'] = ['name' => $name, 'supportEmail' => $email, 'supportPhone' => self::text($d['supportPhone'] ?? null, 30), 'lowStock' => $d['lowStock'], 'privacyText' => self::text($d['privacyText'] ?? $s['settings']['privacyText'], 10000), 'aboutText' => self::text($d['aboutText'] ?? $s['settings']['aboutText'], 10000)];

                if ($webSlides !== null) {
                    $s['settings']['webSlides'] = $webSlides;
                }

                return null;
            case 'slides': case 'middleSlides': case 'webSlides':
                if (! array_is_list($d) || count($d) > 10) {
                    throw new ApiError('الحد الأقصى 10 صور');
                }
                $slides = [];
                foreach ($d as $slide) {
                    if (! is_array($slide)) {
                        throw new ApiError('بيانات غير صحيحة');
                    }
                    self::image($slide['src'] ?? null);
                    $row = ['id' => self::text($slide['id'] ?? null, 100), 'name' => self::text($slide['name'] ?? null, 200), 'src' => $slide['src']];
                    if ($collection === 'webSlides') {
                        $product = collect($s['products'])->first(fn ($p) => (string) $p['id'] === (string) ($slide['productId'] ?? ''));
                        if (! $product || ! $row['id'] || ! $row['src']) {
                            throw new ApiError('اختر بطاقة وصورة صحيحة لسلايدر الموقع');
                        }
                        $row['productId'] = $product['id'];
                    }
                    $slides[] = $row;
                }
                if (count(array_unique(array_column($slides, 'id'))) !== count($slides)) {
                    throw new ApiError('معرّف صورة مكرر');
                }
                if ($collection === 'webSlides') {
                    $s['settings']['webSlides'] = $slides;
                } else {
                    $s[$collection] = $slides;
                }

                return null;
            case 'products':
                foreach (['manufacturerId' => 'manufacturer', 'supplierCompanyId' => 'supplier'] as $field => $role) {
                    if (! empty($d[$field])) {
                        $company = collect($s['companies'])->firstWhere('id', $d[$field]);
                        if (! $company || ! in_array($company['role'], [$role, 'both'])) {
                            throw new ApiError('اختر شركة مناسبة لنوع البطاقة');
                        }
                    }
                }
                if (! collect($s['categories'])->contains('name', $d['category'] ?? '')) {
                    throw new ApiError('اختر تصنيفاً من التصنيفات المتوفرة');
                }
                self::image($d['image'] ?? '');
                $values = $d['values'] ?? null;
                $prices = $d['prices'] ?? null;
                if (! is_array($values) || ! array_is_list($values) || ! count($values) || count($values) > 100 || ! is_array($prices) || ! array_is_list($prices) || count($prices) !== count($values) || count(array_unique($values, SORT_REGULAR)) !== count($values)) {
                    throw new ApiError('فئات وأسعار غير صحيحة');
                }
                foreach ($values as $i => $v) {
                    if ((! is_int($v) && ! is_float($v)) || $v <= 0 || ! is_finite((float) $v) || (! is_int($prices[$i]) && ! is_float($prices[$i])) || $prices[$i] <= 0 || ! is_finite((float) $prices[$i])) {
                        throw new ApiError('فئات وأسعار غير صحيحة');
                    }
                }
                if ($old) {
                    foreach ($old['values'] as $i => $v) {
                        if (! in_array($v, $values) && (($old['stock'][$i] ?? 0) > 0 || collect($s['orders'])->contains(fn ($o) => $o['productId'] === $old['id'] && $o['value'] == $v))) {
                            throw new ApiError('الفئة مرتبطة بمخزون أو طلب');
                        }
                    }
                }
                $images = $d['denomImages'] ?? [];
                if (! is_array($images) || ! array_is_list($images) || count($images) > count($values)) {
                    throw new ApiError('صور الفئات غير صحيحة');
                }
                foreach ($images as $src) {
                    self::image($src ?: '');
                }
                $item = ['id' => $old['id'] ?? (max([0, ...array_column($s['products'], 'id')]) + 1)];
                foreach (['name' => 60, 'en' => 24, 'category' => 40, 'tag' => 40, 'unit' => 20] as $key => $max) {
                    $item[$key] = self::text($d[$key] ?? null, $max);
                }
                $item += ['color' => is_string($d['color'] ?? null) && preg_match('/^#[a-f0-9]{6}$/iD', $d['color']) ? $d['color'] : '#a21c2d', 'manufacturerId' => $d['manufacturerId'] ?? '', 'supplierCompanyId' => $d['supplierCompanyId'] ?? '', 'image' => $d['image'] ?? '', 'denomImages' => $images, 'values' => $values, 'prices' => $prices, 'active' => ($d['active'] ?? false) === true, 'stock' => array_map(function ($v) use ($old) {
                    $index = $old ? array_search($v, $old['values']) : false;

                    return $index === false ? 0 : ($old['stock'][$index] ?? 0);
                }, $values)];
                break;
            case 'customers':
                $p = self::profile($d);
                $phone = Accounts::phone($d['phone'] ?? null);
                if (empty($old['previewId']) && ! preg_match('/^07[3-9][0-9]{8}$/D', $phone)) {
                    throw new ApiError('رقم هاتف غير صحيح');
                }
                if (collect($s['customers'])->contains(fn ($c) => $c['id'] !== $id && (($phone && $c['phone'] === $phone) || ($p['email'] && $c['email'] === $p['email'])))) {
                    throw new ApiError('الهاتف أو البريد مستخدم');
                }
                if (! empty($d['password'])) {
                    Accounts::validatePassword($d['password']);
                }
                $item = [...($old ?? Accounts::newCustomer($p, $phone)), ...$p, 'phone' => $phone, 'id' => $id, 'active' => ($d['active'] ?? false) === true];
                break;
            case 'suppliers':
                if (! in_array($d['method'] ?? '', ['API', 'Excel']) || (($d['method'] ?? '') === 'API' && ! preg_match('~^https://~', $d['url'] ?? ''))) {
                    throw new ApiError('بيانات مورّد غير صحيحة');
                }
                $item = ['id' => $id, 'name' => self::text($d['name'] ?? null, 80), 'url' => self::text($d['url'] ?? '', 2000), 'method' => $d['method'], 'active' => ($d['active'] ?? false) === true];
                break;
            case 'tickets':
                if (! $old || ! in_array($d['status'] ?? '', ['مفتوحة', 'قيد المعالجة', 'مغلقة'])) {
                    throw new ApiError('تذكرة غير صحيحة');
                }
                $item = [...$old, 'status' => $d['status'], 'reply' => self::text($d['reply'] ?? '', 2000)];
                break;
            case 'orders':
                throw new ApiError('استخدم خدمة حجز وتسليم الطلبات', 409);
            default: throw new ApiError('غير موجود', 404);
        }
        if ($old) {
            $index = array_search($old['id'], array_column($s[$collection], 'id'), true);
            $s[$collection][$index] = $item;
        } else {
            $s[$collection][] = $item;
        }

        return $item;
    }
}
