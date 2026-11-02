<?php

namespace App\Services;

/** Provider catalog content is data, never HTML, credentials or executable URLs. */
class ProviderCatalogMetadata
{
    private static function sensitive(string $key): bool
    {
        $key = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key);

        return (bool) preg_match('/password|secret|credential|authorization|private.?key|api.?key|access.?token|bearer|(^|[_.-])(pin|cvv|cvc|token)($|[_.-])/i', $key);
    }

    public static function image(mixed $value, string $base): ?string
    {
        if (! is_string($value) || trim($value) === '' || in_array($value, ['/', '#'], true) || strlen($value) > 2000 || preg_match('/[\x00-\x20\\\\]/', $value)) {
            return null;
        }
        if (str_starts_with($value, '//')) {
            $value = 'https:'.$value;
        } elseif (! preg_match('~^[a-z][a-z0-9+.-]*:~i', $value)) {
            $b = parse_url($base);
            $prefix = str_starts_with($value, '/') ? ($b['scheme'] ?? 'https').'://'.($b['host'] ?? '') : rtrim($base, '/');
            $value = $prefix.'/'.ltrim($value, '/');
        }
        $u = parse_url($value);
        $host = strtolower($u['host'] ?? '');
        if (($u['scheme'] ?? '') !== 'https' || ! $host || str_contains($host, ':') || ! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || isset($u['user']) || isset($u['pass']) || (isset($u['port']) && $u['port'] !== 443) || ! str_contains($host, '.') || preg_match('/(^|\.)(localhost|local|internal|test|invalid)$/i', $host)) {
            return null;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }
        // Numeric host aliases can resolve to loopback even when they are not normal IPv4 literals.
        if (preg_match('/^[0-9.]+$/', $host) && ! filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }
        parse_str($u['query'] ?? '', $query);
        foreach (array_keys($query) as $key) {
            if (self::sensitive((string) $key)) {
                return null;
            }
        }

        return $value;
    }

    public static function read(array $row, string $base): array
    {
        $fields = [];
        $images = [];
        $public = [];
        $walk = function (array $values, string $path = '', int $depth = 0, bool $imageContext = false) use (&$walk, &$fields, &$images, &$public, $base): void {
            if ($depth > 3) {
                return;
            }
            foreach (array_slice($values, 0, 60, true) as $key => $value) {
                $key = (string) $key;
                $label = $path ? $path.'.'.$key : $key;
                if (self::sensitive($label)) {
                    continue;
                }
                $isImage = $imageContext || (bool) preg_match('/image|photo|picture|thumbnail|logo|icon|banner|^img$/i', $key);
                if (is_array($value)) {
                    $walk($value, $label, $depth + 1, $isImage);

                    continue;
                }
                if (! is_scalar($value) && $value !== null) {
                    continue;
                }
                $text = $value === null ? '—' : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
                if (preg_match('/^Bearer\s/i', $text)) {
                    continue;
                }
                if ($isImage && (preg_match('/image|photo|picture|thumbnail|logo|icon|banner|^img$/i', $key) || ($imageContext && (ctype_digit($key) || preg_match('/url|src|path|href|^(original|large|small|medium)$/i', $key)))) && count($images) < 6) {
                    $url = self::image($value, $base);
                    if ($url) {
                        $images[] = $url;
                    }
                }
                // URL credentials must not enter the details table either.
                if (str_contains($text, '?') || preg_match('~^https?://~i', $text)) {
                    $u = parse_url($text);
                    parse_str($u['query'] ?? '', $query);
                    if (isset($u['user']) || isset($u['pass']) || array_filter(array_keys($query), fn ($k) => self::sensitive((string) $k))) {
                        continue;
                    }
                }
                $text = mb_substr(trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 500);
                if (count($fields) < 120) {
                    $fields[] = ['label' => mb_substr($label, 0, 160), 'value' => $text];
                }
                if (count($public) < 12 && preg_match('/^(duration|duration_days|validity|validity_days|validityDays|data_amount|dataAmount|internet_data|voice_minutes|minutes|sms|quota|currency|operator|network|brand|country|region)$/i', $key)) {
                    $public[] = ['label' => $key, 'value' => mb_substr($text, 0, 150)];
                }
            }
        };
        $walk($row);
        $description = '';
        foreach (['description_ar', 'description', 'short_description', 'shortDescription', 'details'] as $key) {
            if (is_string($row[$key] ?? null) && trim($row[$key]) !== '') {
                $description = CatalogEditor::text(trim(html_entity_decode(strip_tags($row[$key]), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 2000);
                break;
            }
        }

        return ['description' => $description, 'images' => array_values(array_unique($images)), 'supplierDetails' => $fields, 'serviceInfo' => $public];
    }
}
