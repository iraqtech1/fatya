<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiError;
use App\Services\Accounts;
use App\Services\CatalogEditor;
use App\Services\Inventory;
use App\Services\Otp;
use App\Services\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class StoreController extends Controller
{
    public function __construct(private Store $store, private Accounts $accounts, private CatalogEditor $editor, private Otp $otp, private Inventory $inventory) {}

    public function __invoke(Request $request)
    {
        $path = '/'.$request->path();
        $limits = ['/api/admin/login' => ['admin', 10], '/api/auth/guest' => ['guest', 20], '/api/auth/register' => ['password', 10], '/api/auth/login' => ['password', 10], '/api/auth/start' => ['otp', 10], '/api/auth/resend' => ['otp', 10], '/api/auth/verify' => ['verify', 30], '/api/auth/reset-password' => ['reset', 10]];
        if (isset($limits[$path])) {
            [$group, $max] = $limits[$path];
            $key = 'fatya:'.$group.':'.$request->ip();
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new ApiError('انتظر دقيقة وحاول مرة ثانية', 429);
            }
            RateLimiter::hit($key, 60);
        }
        if ($path === '/api/health' && $request->isMethod('GET')) {
            DB::select('SELECT 1');

            return response()->json(['ok' => true]);
        }

        return DB::transaction(function () use ($request, $path) {
            // Serialize writes on the revision row, across PHP workers. Public snapshots use
            // a consistent shared lock so a response cannot mix entities from two revisions.
            $meta = DB::table('store_meta')->where('id', 1);
            ($request->isMethod('GET') ? $meta->sharedLock() : $meta->lockForUpdate())->first();
            try {
                return $this->dispatch($request, $path, $this->store->read());
            } catch (ApiError $e) {
                // Failed OTP checks must retain their attempt count, not roll it back.
                return response()->json(['error' => $e->getMessage()], $e->status);
            }
        }, 3);
    }

    private function publicState(Request $req, array $s, bool $admin = false): array
    {
        if ($admin) {
            return $s;
        }
        $customer = null;
        try {
            $customer = $this->accounts->customer($req, $s);
        } catch (ApiError $e) {
            if (! in_array($e->status, [401, 403])) {
                throw $e;
            }
        }
        $s['categories'] = array_values(array_filter($s['categories'], fn ($c) => $c['active']));
        $s['products'] = array_values(array_filter($s['products'], fn ($p) => $p['active'] && collect($s['categories'])->contains('name', $p['category'])));
        foreach ($s['products'] as &$p) {
            $p['stock'] = array_fill(0, count($p['stock']), 0);
        } unset($p);
        foreach (['orders', 'tickets'] as $key) {
            $s[$key] = $customer ? array_values(array_filter($s[$key], fn ($item) => $item['customerId'] === $customer['id'])) : [];
        }
        foreach (['customers', 'imports', 'suppliers', 'companies', 'events'] as $key) {
            $s[$key] = [];
        }

        return $s;
    }

    private function revision(array $s, array $b): void
    {
        if (($b['revision'] ?? null) !== $s['revision']) {
            throw new ApiError('البيانات تغيرت حدّث الصفحة وأعد المحاولة', 409);
        }
    }

    private function dispatch(Request $req, string $path, array $s)
    {
        $b = $req->isMethod('GET') ? [] : $req->json()->all();
        $json = fn ($data, $status = 200) => response()->json($data, $status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($path === '/api/state' && $req->isMethod('GET')) {
            return $json($this->publicState($req, $s));
        }
        if ($path === '/api/admin/login' && $req->isMethod('POST')) {
            $password = config('fatya.admin_password');
            if (! is_string($password) || strlen($password) < 12) {
                throw new ApiError('حساب الإدارة غير مهيأ', 503);
            }
            if (($b['username'] ?? null) !== config('fatya.admin_user') || ! is_string($b['password'] ?? null) || ! hash_equals(hash('sha256', $password), hash('sha256', $b['password']))) {
                throw new ApiError('اسم المستخدم أو كلمة المرور غير صحيح', 401);
            }

            return $json(['ok' => true])->withCookie($this->accounts->issue('admin'));
        }
        if ($path === '/api/admin/session' && $req->isMethod('GET')) {
            $this->accounts->session($req, 'admin');

            return $json(['ok' => true]);
        }
        if ($path === '/api/admin/state' && $req->isMethod('GET')) {
            $this->accounts->session($req, 'admin');

            return $json($s);
        }
        if (in_array($path, ['/api/admin/logout', '/api/auth/logout']) && $req->isMethod('POST')) {
            $role = str_contains($path, '/admin/') ? 'admin' : 'customer';
            $session = $this->accounts->session($req, $role);
            DB::table('auth_tokens')->where('token', $session->token)->delete();

            return $json(['ok' => true])->withCookie(cookie('masal_'.$role, '', -1, '/', null, config('fatya.secure_cookies'), true, false, 'strict'));
        }
        if ($path === '/api/auth/guest' && $req->isMethod('POST')) {
            try {
                return $json(Accounts::identity($this->accounts->customer($req, $s)));
            } catch (ApiError) {
            }
            $c = [...Accounts::newCustomer(['name' => 'زائر', 'email' => ''], ''), 'previewId' => (string) Str::uuid()];
            $s['customers'][] = $c;
            $this->store->event($s, 'انضم حساب زائر');
            $this->store->save($s);

            return $json(Accounts::identity($c))->withCookie($this->accounts->issue('customer', $c['id']));
        }
        if (in_array($path, ['/api/auth/login', '/api/auth/register']) && $req->isMethod('POST')) {
            $phone = Accounts::phone($b['phone'] ?? null);
            $password = $b['password'] ?? null;
            if (! preg_match('/^07[3-9][0-9]{8}$/D', $phone)) {
                throw new ApiError('اكتب رقم هاتف عراقي صحيح');
            }
            Accounts::validatePassword($password);
            $c = collect($s['customers'])->firstWhere('phone', $phone);
            if ($path === '/api/auth/register') {
                if ($c) {
                    throw new ApiError('هذا الرقم مسجّل بالفعل');
                }
                $p = CatalogEditor::profile($b);
                if ($p['email'] && collect($s['customers'])->contains('email', $p['email'])) {
                    throw new ApiError('البريد مستخدم');
                }
                $c = Accounts::newCustomer($p, $phone);
                $s['customers'][] = $c;
                $this->store->event($s, 'انضم حساب جديد');
                $this->store->save($s);
                $this->accounts->setPassword($c['id'], $password);
            } else {
                $credential = $c ? DB::table('customer_credentials')->where('customer_id', $c['id'])->first() : null;
                $valid = $credential ? $this->accounts->checkPassword($password, $credential->password) : Hash::check($password, '$argon2id$v=19$m=65536,t=4,p=1$N1dkM2d5bXNwUWh1Ri8wYw$ELJMj1JrKsGhKr/vEe0oPnow6TofmrSTk+PpYevNUEA');
                if (! $credential || ! $valid) {
                    throw new ApiError('رقم الهاتف أو كلمة المرور غير صحيح', 401);
                }
                if (! $c['active']) {
                    throw new ApiError('الحساب موقوف', 403);
                }
                if (str_starts_with($credential->password, 'node-scrypt:') || Hash::needsRehash($credential->password)) {
                    DB::table('customer_credentials')->where('customer_id', $c['id'])->update(['password' => Hash::make($password)]);
                }
            }

            return $json(Accounts::identity($c))->withCookie($this->accounts->issue('customer', $c['id']));
        }
        if ($path === '/api/auth/me' && $req->isMethod('GET')) {
            return $json(Accounts::identity($this->accounts->customer($req, $s)));
        }
        if (in_array($path, ['/api/auth/start', '/api/auth/resend']) && $req->isMethod('POST')) {
            return $json($this->otp->issue($s, $b, $path === '/api/auth/resend'));
        }
        if ($path === '/api/auth/reset-password' && $req->isMethod('POST')) {
            $challenge = $this->otp->verify($b, true);
            Accounts::validatePassword($b['password'] ?? null);
            $c = collect($s['customers'])->firstWhere('id', $challenge['account']['id']);
            if (! ($c['active'] ?? false) || $c['phone'] !== $challenge['account']['phone']) {
                throw new ApiError('الحساب موقوف', 403);
            }
            $this->accounts->setPassword($c['id'], $b['password']);
            $this->removeChallenges($c['id']);

            return $json(['ok' => true]);
        }
        if ($path === '/api/auth/verify' && $req->isMethod('POST')) {
            $challenge = $this->otp->verify($b, false);
            $c = collect($s['customers'])->firstWhere('id', $challenge['account']['id']);
            if ($challenge['mode'] === 'register') {
                $c = $challenge['account'];
                if (collect($s['customers'])->contains(fn ($a) => $a['phone'] === $c['phone'] || ($c['email'] && $a['email'] === $c['email']))) {
                    throw new ApiError('الحساب مسجّل بالفعل');
                }
                $s['customers'][] = $c;
                $this->store->event($s, 'انضم حساب جديد');
                $this->store->save($s);
            }
            if (! ($c['active'] ?? false) || $c['phone'] !== $challenge['account']['phone']) {
                throw new ApiError('الحساب موقوف', 403);
            }
            DB::table('otp_challenges')->where('id', $b['challengeId'])->delete();

            return $json(Accounts::identity($c))->withCookie($this->accounts->issue('customer', $c['id']));
        }
        if ($path === '/api/auth/profile' && $req->isMethod('PUT')) {
            $c = $this->accounts->customer($req, $s);
            $p = CatalogEditor::profile($b);
            if ($p['email'] && collect($s['customers'])->contains(fn ($a) => $a['id'] !== $c['id'] && $a['email'] === $p['email'])) {
                throw new ApiError('البريد مستخدم');
            }
            $index = array_search($c['id'], array_column($s['customers'], 'id'), true);
            $c = $s['customers'][$index] = [...$c, ...$p];
            $this->store->save($s);

            return $json(Accounts::identity($c));
        }
        if ($path === '/api/orders' && $req->isMethod('POST')) {
            $c = $this->accounts->customer($req, $s);
            $p = collect($s['products'])->first(fn ($p) => $p['id'] === ($b['productId'] ?? null) && $p['active'] && collect($s['categories'])->contains(fn ($cat) => $cat['active'] && $cat['name'] === $p['category']));
            if (! $p || ! is_int($b['denom'] ?? null) || $b['denom'] < 0 || $b['denom'] >= count($p['values']) || ! is_int($b['quantity'] ?? null) || $b['quantity'] < 1 || $b['quantity'] > 20) {
                throw new ApiError('تفاصيل الطلب غير صحيحة');
            }
            if (! in_array($b['payment'] ?? '', ['Qi', 'ZainCash'])) {
                throw new ApiError('وسيلة الدفع غير صحيحة');
            }
            if (! is_string($b['reference'] ?? null) || mb_strlen($b['reference']) > 100) {
                throw new ApiError('مرجع الطلب مطلوب');
            }
            $old = collect($s['orders'])->first(fn ($o) => $o['customerId'] === $c['id'] && $o['reference'] === $b['reference']);
            if ($old) {
                return $json($old);
            }
            $o = ['id' => 'ORDER-'.Str::uuid(), 'reference' => $b['reference'], 'customerId' => $c['id'], 'customer' => $c['name'], 'phone' => $c['phone'], 'email' => $c['email'], ...isset($c['previewId']) ? ['previewId' => $c['previewId']] : [], 'productId' => $p['id'], 'name' => $p['name'], 'value' => $p['values'][$b['denom']], 'quantity' => $b['quantity'], 'price' => $p['prices'][$b['denom']] * $b['quantity'], 'date' => now('Asia/Baghdad')->toDateString(), 'kind' => $c['kind'], 'status' => 'قيد المراجعة', 'payment' => $b['payment']];
            array_unshift($s['orders'], $o);
            $this->store->event($s, 'طلب جديد: '.$p['name']);
            $this->store->save($s);

            return $json($o, 201);
        }
        if ($path === '/api/tickets' && $req->isMethod('POST')) {
            $c = $this->accounts->customer($req, $s);
            $subject = CatalogEditor::text($b['subject'] ?? null, 100);
            $message = CatalogEditor::text($b['message'] ?? null, 2000);
            if (! $subject || ! $message) {
                throw new ApiError('اكتب عنوان ورسالة');
            }
            $ticket = ['id' => 'TICKET-'.Str::uuid(), 'customerId' => $c['id'], 'customer' => $c['name'], 'phone' => $c['phone'], 'email' => $c['email'], ...isset($c['previewId']) ? ['previewId' => $c['previewId']] : [], 'subject' => $subject, 'message' => $message, 'date' => now('Asia/Baghdad')->toDateString(), 'status' => 'مفتوحة', 'reply' => ''];
            array_unshift($s['tickets'], $ticket);
            $this->store->event($s, 'تذكرة دعم جديدة');
            $this->store->save($s);

            return $json($ticket, 201);
        }
        if (str_starts_with($path, '/api/admin/') && $req->isMethod('PUT')) {
            $this->accounts->session($req, 'admin');
            $this->revision($s, $b);
            $collection = substr($path, strlen('/api/admin/'));
            $data = $b['data'] ?? null;
            if (! is_array($data) || (! in_array($collection, ['slides', 'middleSlides']) && array_is_list($data))) {
                throw new ApiError('بيانات غير صحيحة');
            }
            $item = $this->editor->update($s, $collection, $data);
            $this->store->event($s, 'تحديث الإدارة: '.$collection);
            $this->store->save($s);
            if ($collection === 'customers' && ! empty($data['password'])) {
                $this->accounts->setPassword($item['id'], $data['password']);
                $this->removeChallenges($item['id']);
            }

            return $json($s);
        }
        if ($path === '/api/admin/cards/search' && $req->isMethod('POST')) {
            $this->accounts->session($req, 'admin');

            return $json(['card' => $this->inventory->search($s, $b['serial'] ?? null)]);
        }
        if ($path === '/api/admin/import' && $req->isMethod('POST')) {
            $this->accounts->session($req, 'admin');
            $this->revision($s, $b);
            $result = $this->inventory->import($s, $b);
            $this->store->event($s, 'استيراد '.$result['accepted'].' بطاقة');
            $this->store->save($s);

            return $json(['state' => $s, ...$result]);
        }
        throw new ApiError('غير موجود', 404);
    }

    private function removeChallenges(string $customer): void
    {
        foreach (DB::table('otp_challenges')->get() as $row) {
            if (json_decode($row->payload, true)['account']['id'] === $customer) {
                DB::table('otp_challenges')->where('id', $row->id)->delete();
            }
        }
    }
}
