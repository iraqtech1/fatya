<?php

namespace App\Services;

/** Preserve decimal money lexemes before PHP can round them to binary floats. */
class ProviderJson
{
    public static function decode(string $body): mixed
    {
        $text = trim($body);
        if (preg_match('/^-?(?:0|[1-9][0-9]*)\.[0-9]+$/D', $text)) {
            return $text;
        }
        // The second alternative skips complete JSON strings, including escaped quotes.
        $pattern = '~("(?:price|publicPrice|vendorPrice|remaining_balance|amount)"\s*:\s*)(-?(?:0|[1-9][0-9]*)\.[0-9]+(?:[eE][+-]?[0-9]+)?)(?=\s*[,}])|"(?:[^"\\\\]|\\\\.)*"(*SKIP)(*F)~s';
        $precise = preg_replace_callback($pattern, fn ($match) => $match[1].json_encode($match[2], JSON_THROW_ON_ERROR), $body);

        return json_decode($precise, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    }
}
