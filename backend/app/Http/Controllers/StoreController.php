<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiError;
use App\Services\Accounts;
use App\Services\CatalogEditor;
use App\Services\CustomerNotifications;
use App\Services\Inventory;
use App\Services\OrderFulfillment;
use App\Services\Otp;
use App\Services\Store;
use App\Services\Takeed;
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

        if ($path === '/api/admin/otp/balance' && $request->isMethod('GET')) {
            $this->accounts->session($request, 'admin');
            if ($request->boolean('refresh')) {
                $key = 'fatya:otp-balance-refresh:'.$request->ip();
                if (RateLimiter::tooManyAttempts($key, 1)) {
                    throw new ApiError('انتظر قليلاً قبل تحديث الرصيد', 429);
                }
                RateLimiter::hit($key, 5);
            }

            return response()->json(app(Takeed::class)->balance($request->boolean('refresh')))->header('Cache-Control', 'no-store');
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
            $s['inventorySummary'] = $this->inventory->summary();
            $s['paymentReceipts'] = app(OrderFulfillment::class)->receipts();

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
            $p['availability'] = array_map(fn ($quantity) => $quantity > 0, $p['stock']);
            $p['stock'] = array_fill(0, count($p['stock']), 0);
        } unset($p);
        foreach (['orders', 'tickets'] as $key) {
            $s[$key] = $customer ? array_values(array_filter($s[$key], fn ($item) => $item['customerId'] === $customer['id'])) : [];
        }
        foreach (['customers', 'imports', 'suppliers', 'companies', 'events', 'inventorySummary', 'paymentReceipts'] as $key) {
            $s[$key] = [];
        }
        $s['viewer'] = $customer ? Accounts::identity($customer) : null;
        $s['notifications'] = $customer ? app(CustomerNotifications::class)->forCustomer($customer['id']) : [];

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
        $notifications = app(CustomerNotifications::class);
        if ($path === '/api/push/config' && $req->isMethod('GET')) {
            $customer = $this->accounts->customer($req, $s);

            return $json(app(Takeed::class)->clientConfig($customer['id']));
        }
        if ($path === '/api/notifications/read' && $req->isMethod('POST')) {
            $customer = $this->accounts->customer($req, $s);

            return $json($notifications->markRead($customer['id'], $b['ids'] ?? null));
        }
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

            return $json($this->publicState($req, $s, true));
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
            $notifications->welcome($c['id']);

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
                if (config('fatya.require_registration_otp')) {
                    return $json($this->otp->issue($s, [...$b, 'mode' => 'register'], false, $req->ip()));
                }
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
            $notifications->welcome($c['id']);

            return $json(Accounts::identity($c))->withCookie($this->accounts->issue('customer', $c['id']));
        }
        if ($path === '/api/auth/me' && $req->isMethod('GET')) {
            return $json(Accounts::identity($this->accounts->customer($req, $s)));
        }
        if (in_array($path, ['/api/auth/start', '/api/auth/resend']) && $req->isMethod('POST')) {
            return $json($this->otp->issue($s, $b, $path === '/api/auth/resend', $req->ip()));
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
                if (isset($challenge['passwordHash'])) {
                    DB::table('customer_credentials')->updateOrInsert(['customer_id' => $c['id']], ['password' => $challenge['passwordHash']]);
                }
            }
            if (! ($c['active'] ?? false) || $c['phone'] !== $challenge['account']['phone']) {
                throw new ApiError('الحساب موقوف', 403);
            }
            DB::table('otp_challenges')->where('id', $b['challengeId'])->delete();
            $notifications->welcome($c['id']);

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
        if (preg_match('~^/api/orders/([^/]+)/cancel$~D', $path, $match) && $req->isMethod('POST')) {
            $customer = $this->accounts->customer($req, $s);

            return $json(app(OrderFulfillment::class)->cancel($match[1], $customer));
        }
        if (preg_match('~^/api/orders/([^/]+)/codes$~D', $path, $match) && $req->isMethod('GET')) {
            $customer = $this->accounts->customer($req, $s);

            return $json(app(OrderFulfillment::class)->codes($match[1], $customer));
        }
        if ($path === '/api/orders' && $req->isMethod('POST')) {
            $customer = $this->accounts->customer($req, $s);
            $result = app(OrderFulfillment::class)->reserve($customer, $b);

            return $json($result['order'], $result['created'] ? 201 : 200);
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
            $notifications->send($c['id'], 'وصلتنا تذكرتك', 'يمكنك متابعة تفاصيلها من صفحة الدعم', 'ticket-'.$ticket['id']);

            return $json($ticket, 201);
        }
        if ($path === '/api/admin/customers/status' && $req->isMethod('POST')) {
            $this->accounts->session($req, 'admin');
            $this->revision($s, $b);
            $id = CatalogEditor::text($b['id'] ?? null, 100);
            $index = array_search($id, array_column($s['customers'], 'id'), true);
            if ($index === false) {
                throw new ApiError('العميل غير موجود', 404);
            }
            if (! is_bool($b['active'] ?? null)) {
                throw new ApiError('حالة الحساب غير صحيحة');
            }
            $s['customers'][$index]['active'] = $b['active'];
            if (! $b['active']) {
                DB::table('auth_tokens')->where('customer_id', $id)->delete();
                $this->removeChallenges($id);
            }
            $this->store->event($s, $b['active'] ? 'تم تفعيل حساب عميل' : 'تم إيقاف حساب عميل');
            $this->store->save($s);

            return $json($this->publicState($req, $s, true));
        }
        if ($path === '/api/admin/customers/delete' && $req->isMethod('POST')) {
            $this->accounts->session($req, 'admin');
            $this->revision($s, $b);
            $id = CatalogEditor::text($b['id'] ?? null, 100);
            $customer = collect($s['customers'])->firstWhere('id', $id);
            if (! $customer) {
                throw new ApiError('العميل غير موجود', 404);
            }
            if (($b['confirm'] ?? false) !== true) {
                throw new ApiError('يجب تأكيد حذف العميل');
            }
            if (DB::table('orders')->where('customer_id', $id)->exists() || DB::table('tickets')->where('customer_id', $id)->exists()) {
                throw new ApiError('هذا العميل مرتبط بطلبات أو تذاكر دعم ولا يمكن حذفه للحفاظ على السجلات يمكنك إيقاف حسابه من تعديل', 409);
            }
            $this->removeChallenges($id);
            DB::table('auth_tokens')->where('customer_id', $id)->delete();
            DB::table('customers')->where('id', $id)->delete();
            $s['customers'] = array_values(array_filter($s['customers'], fn ($c) => $c['id'] !== $id));
            $this->store->event($s, 'تم حذف حساب عميل');
            $this->store->save($s);

            return $json($this->publicState($req, $s, true));
        }
        if (str_starts_with($path, '/api/admin/') && $req->isMethod('PUT')) {
            $this->accounts->session($req, 'admin');
            $this->revision($s, $b);
            $collection = substr($path, strlen('/api/admin/'));
            $data = $b['data'] ?? null;
            if (! is_array($data) || (! in_array($collection, ['slides', 'middleSlides', 'webSlides', 'faqs']) && array_is_list($data))) {
                throw new ApiError('بيانات غير صحيحة');
            }
            if ($collection === 'orders') {
                return $json(app(OrderFulfillment::class)->adminStatus(CatalogEditor::text($data['id'] ?? null, 100), $data['status'] ?? null));
            }
            $previous = in_array($collection, ['orders', 'tickets']) ? collect($s[$collection])->firstWhere('id', $data['id'] ?? null) : null;
            $item = $this->editor->update($s, $collection, $data);
            $this->store->event($s, 'تحديث الإدارة: '.$collection);
            $this->store->save($s);
            if ($collection === 'orders' && $previous['status'] !== $item['status']) {
                $notifications->send($item['customerId'], 'تحديث حالة الطلب', $item['name'].' · '.$item['status']);
            }
            if ($collection === 'tickets' && ($previous['reply'] !== $item['reply'] || $previous['status'] !== $item['status'])) {
                $notifications->send($item['customerId'], 'تحديث تذكرة الدعم', $item['subject'].' · '.$item['status']);
            }
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
