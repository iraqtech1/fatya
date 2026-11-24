<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;

/** Receives an opaque provider token. It does NOT authenticate a customer or confirm payment. */
class SwitchEntryController extends Controller
{
    private const COOKIE = 'fatya_switch_entry';

    private const TTL = 600;

    private function key(string $handle): string
    {
        return 'fatya:switch:entry:'.hash('sha256', $handle);
    }

    private function handle(Request $request): ?string
    {
        $handle = $request->cookie(self::COOKIE);

        return is_string($handle) && preg_match('/^[a-f0-9]{64}$/D', $handle) ? $handle : null;
    }

    private function headers($response)
    {
        foreach (['Cache-Control' => 'no-store, private, max-age=0', 'Pragma' => 'no-cache', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow', 'X-Content-Type-Options' => 'nosniff'] as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    private function forget(Request $request): void
    {
        if ($handle = $this->handle($request)) {
            Cache::forget($this->key($handle));
        }
    }

    public function launch(Request $request)
    {
        // The /api prefix also bypasses the existing PWA navigation fallback.
        if (! $request->isSecure() && ! app()->environment(['local', 'testing'])) {
            return $this->headers(response('HTTPS is required.', 400));
        }
        if ($request->query->has('token')) {
            $rateKey = 'fatya:switch:launch:'.$request->ip();
            if (RateLimiter::tooManyAttempts($rateKey, 20)) {
                return $this->headers(response('Please try again later.', 429));
            }
            RateLimiter::hit($rateKey, 60);
            $token = $request->query('token');
            $this->forget($request);
            if (! is_string($token) || $token === '' || strlen($token) > 8192 || preg_match('/[\x00-\x20\x7f]/', $token)) {
                return $this->headers(redirect('/api/switch/launch', 303))->withoutCookie(self::COOKIE, '/api/switch');
            }
            $handle = bin2hex(random_bytes(32));
            // Store only encrypted, short-lived, UNVERIFIED data. No identity is derived from it.
            $stored = Cache::put($this->key($handle), ['encrypted_token' => Crypt::encryptString($token), 'expires' => time() + self::TTL], self::TTL);
            if (! $stored) {
                return $this->headers(response('Service temporarily unavailable.', 503));
            }

            return $this->headers(redirect('/api/switch/launch', 303))->cookie(self::COOKIE, $handle, self::TTL / 60, '/api/switch', null, $request->isSecure(), true, false, 'lax');
        }
        $file = public_path('switch.html');
        if (! is_file($file)) {
            return $this->headers(response('Service temporarily unavailable.', 503));
        }

        return $this->headers(response()->file($file));
    }

    public function status(Request $request)
    {
        if (! $request->isSecure() && ! app()->environment(['local', 'testing'])) {
            return $this->headers(response()->json(['state' => 'unavailable'], 400));
        }
        $handle = $this->handle($request);
        $entry = $handle ? Cache::get($this->key($handle)) : null;
        $pending = is_array($entry) && ($entry['expires'] ?? 0) > time() && is_string($entry['encrypted_token'] ?? null);
        if ($handle && ! $pending) {
            Cache::forget($this->key($handle));
        }

        return $this->headers(response()->json(['state' => $pending ? 'pending_activation' : ($handle ? 'expired' : 'missing'), 'paymentEnabled' => false]));
    }
}
