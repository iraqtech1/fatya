<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Illuminate\Http\Request;

class AdminPolicy
{
    public function __construct(private AdminAccess $access) {}

    public function authorize(Request $request): void
    {
        $path = substr($request->path(), strlen('api/admin/'));
        $method = $request->method();
        $b = $request->json()->all();
        if (in_array($path, ['session', 'logout', 'state'], true)) {
            return;
        }
        if (str_starts_with($path, 'access/')) {
            $permission = match ([$method, substr($path, 7)]) {
                ['GET', 'catalog'] => null,
                ['GET', 'roles'] => 'roles.view',
                ['GET', 'staff'] => 'staff.view',
                ['GET', 'audit'] => 'access_audit.view',
                ['GET', 'account'] => 'my_account.view',
                ['POST', 'account/save'] => ['my_account.update', 'my_account.password'],
                ['GET', 'role-options'] => ['staff.view', 'staff.create', 'staff.assign'],
                ['POST', 'roles/save'] => ['roles.create', 'roles.update'],
                ['POST', 'roles/delete'] => 'roles.delete',
                ['POST', 'staff/save'] => ['staff.create', 'staff.update', 'staff.assign', 'staff.status', 'staff.password'],
                ['POST', 'staff/revoke'] => 'staff.revoke',
                ['POST', 'staff/delete'] => 'staff.delete',
                default => throw new ApiError('العملية الإدارية غير معرّفة', $this->access->current()['owner'] ? 404 : 403),
            };
            $this->oneOf($permission);

            return;
        }
        if ($method === 'PUT' && ! str_starts_with($path, 'procurement/')) {
            // Field changes are checked again against the revision-validated store.
            if (! in_array($path, ['products', 'categories', 'companies', 'customers', 'orders', 'suppliers', 'tickets', 'settings', 'slides', 'middleSlides', 'webSlides', 'faqs', 'featuredCards'], true)) {
                throw new ApiError('العملية الإدارية غير معرّفة', $this->access->current()['owner'] ? 404 : 403);
            }

            $group = match ($path) {
                'featuredCards' => 'featured_cards', 'middleSlides' => 'middle_slides', 'webSlides' => 'web_slides', 'tickets' => 'support', default => $path
            };
            $this->oneOf(array_values(array_filter(AdminAccess::keys(), fn ($key) => str_starts_with($key, $group.'.') && ! in_array($key, [$group.'.view', $group.'.contact', $group.'.export', $group.'.search_serial'], true))));

            return;
        }
        if ($path === 'procurement/digital/receipt') {
            $this->access->require('digital.executions');
            $this->access->require('inventory.codes');

            return;
        }
        if (in_array($path, ['procurement/digital/history', 'procurement/digital/rabiaa-history', 'procurement/digital/topup-profit'], true)) {
            $this->access->require($path === 'procurement/digital/topup-profit' ? 'finance.profits' : 'digital.executions');
            if (($b['all'] ?? false) === true) {
                $this->access->require('digital.export');
            }

            return;
        }
        if ($path === 'procurement/company-order' && $method === 'PUT') {
            $this->access->require('companies.update');

            return;
        }
        if ($path === 'procurement/refunds/settle' && $method === 'POST') {
            $this->access->require('payments.refund');

            return;
        }
        if ($path === 'procurement/application-display' && in_array($method, ['GET', 'PUT'], true)) {
            $this->access->require($method === 'GET' ? 'application_display.view' : 'application_display.update');

            return;
        }
        if (str_starts_with($path, 'procurement/notifications')) {
            $permission = match ([$method, $path]) {
                ['GET', 'procurement/notifications'] => 'view',
                ['POST', 'procurement/notifications/save'] => 'create',
                ['POST', 'procurement/notifications/review'] => 'review',
                ['POST', 'procurement/notifications/send'] => 'send',
                ['POST', 'procurement/notifications/rules'] => 'expiry',
                default => throw new ApiError('عملية الإشعار غير موجودة', 404),
            };
            $this->access->require('notifications.'.$permission);

            return;
        }
        if (in_array($path, ['procurement/prices', 'procurement/prices/preview', 'procurement/prices/apply'], true)) {
            $this->access->require('prices.view');
            if ($method !== 'GET') {
                $this->oneOf(['products.prices', 'digital.prices']);
            }

            return;
        }
        if ($path === 'procurement/reports' && $method === 'POST') {
            $type = $b['type'] ?? 'inventory';
            if (! is_string($type) || ! in_array($type, ['inventory', 'movements', 'procurement', 'sales', 'digital', 'payments', 'exports', 'audit'], true)) {
                throw new ApiError('التقرير غير موجود', 422);
            }
            $this->access->require('reports_'.$type.'.view');
            if (! empty($b['all'])) {
                $this->access->require('reports_'.$type.'.export');
            }

            return;
        }
        if ($path === 'procurement/lookup' && $method === 'POST') {
            $this->access->require('products.search_serial');
            if (($b['kind'] ?? '') === 'pin') {
                $this->access->require('inventory.codes');
            }

            return;
        }
        if ($path === 'procurement/stock-orders/review' && $method === 'POST') {
            $this->access->require('stock_orders.'.match ($b['decision'] ?? '') {
                'approve' => 'approve', 'return' => 'return', 'reject' => 'reject', default => throw new ApiError('قرار غير صحيح', 422)
            });

            return;
        }
        if ($path === 'procurement/exports' && $method === 'POST') {
            $this->access->require(match ($b['kind'] ?? '') {
                'copy' => 'exports.copy', 'withdrawal' => 'exports.withdraw', default => throw new ApiError('نوع العملية غير صحيح', 422)
            });

            return;
        }
        if ($path === 'procurement/exports/review' && $method === 'POST') {
            $this->access->require(match ($b['decision'] ?? '') {
                'approve' => 'exports.approve', 'reject' => 'exports.reject', default => throw new ApiError('قرار غير صحيح', 422)
            });

            return;
        }
        if ($path === 'procurement/exports/download' && $method === 'POST') {
            $this->access->require('exports.download', 'inventory.codes');

            return;
        }
        if ($path === 'procurement/stock-orders/submit' && $method === 'POST') {
            $this->access->require('stock_orders.submit');
            $this->access->require('stock_orders.approve');

            return;
        }
        if (in_array($path, ['procurement/digital/publications', 'procurement/digital/publications/bulk', 'procurement/digital/offers'], true) && $method === 'PUT') {
            $bulk = str_ends_with($path, '/bulk');
            $unpublish = ($b['action'] ?? '') === 'unpublish' || (($b['published'] ?? null) === false && isset($b['id']));
            if ($bulk) {
                $this->access->require('digital.bulk');
            }
            if ($unpublish) {
                $this->access->require('digital.visibility');
            } else {
                $this->oneOf([str_ends_with($path, '/offers') ? 'digital.offers' : 'digital.publications_update', 'digital.prices', 'digital.visibility']);
            }

            return;
        }
        $permission = match ([$method, $path]) {
            ['GET', 'otp/balance'] => 'dashboard.otp',
            ['POST', 'products/reorder'] => 'products.update',
            ['POST', 'customers/status'] => 'customers.status',
            ['POST', 'customers/delete'] => 'customers.delete',
            ['POST', 'orders/hide'] => 'orders.delete',
            ['POST', 'cards/search'] => 'products.search_serial',
            ['POST', 'import'] => 'inventory.import',
            ['GET', 'procurement/stock-orders'] => 'stock_orders.view',
            ['POST', 'procurement/stock-orders/read'] => 'stock_orders.read_file',
            ['POST', 'procurement/stock-orders/preview'] => 'stock_orders.preview',
            ['POST', 'procurement/stock-orders/submit'] => 'stock_orders.submit',
            ['POST', 'procurement/stock-orders/approve'] => 'stock_orders.approve',
            ['PUT', 'procurement/stock-orders/policy'] => 'stock_orders.policy',
            ['PUT', 'procurement/stock-orders/code'], ['PUT', 'procurement/stock-orders/codes'] => 'stock_orders.mapping',
            ['GET', 'procurement/exports'] => 'exports.view',
            ['POST', 'procurement/exports/renew'] => 'exports.renew',
            ['GET', 'procurement/digital'] => 'digital.view',
            ['GET', 'procurement/digital/balance'] => 'dashboard.balances',
            ['POST', 'procurement/digital/catalog/accept'], ['POST', 'procurement/digital/catalog'] => 'digital.catalog',
            ['POST', 'procurement/digital/provinces'], ['POST', 'procurement/digital/eligibility'] => 'digital.eligibility',
            ['GET', 'procurement/digital/activations'] => 'digital.activation_view',
            ['POST', 'procurement/digital/activations'] => 'digital.activate',
            ['POST', 'procurement/digital/refund-check'], ['POST', 'procurement/digital/refund-settle'] => 'digital.refund',
            ['POST', 'procurement/digital/reconcile'] => 'digital.reconcile',
            ['POST', 'procurement/digital/manual-payment'] => 'digital.manual_payment',
            ['PUT', 'procurement/digital/connections'] => 'digital.connections_update',
            default => throw new ApiError('العملية الإدارية غير معرّفة', $this->access->current()['owner'] ? 404 : 403),
        };
        $this->access->require($permission);
    }

    private function oneOf(string|array|null $permissions): void
    {
        if ($permissions === null) {
            return;
        }
        foreach ((array) $permissions as $key) {
            if ($this->access->can($key)) {
                return;
            }
        }
        throw new ApiError('لا تملك صلاحية الوصول إلى هذه العملية', 403);
    }

    private function changes(string $group, array $old, array $data, array $fields): void
    {
        foreach ($fields as $action => $keys) {
            foreach ($keys as $key) {
                if (array_key_exists($key, $data) && ! $this->same($old[$key] ?? null, $data[$key])) {
                    $this->access->require($group.'.'.$action);
                    break;
                }
            }
        }
    }

    private function same(mixed $before, mixed $after): bool
    {
        if ((is_int($before) || is_float($before)) && (is_int($after) || is_float($after))) {
            return (float) $before === (float) $after;
        }
        if (is_array($before) && is_array($after)) {
            if (count($before) !== count($after)) {
                return false;
            }
            foreach ($before as $key => $value) {
                if (! array_key_exists($key, $after) || ! $this->same($value, $after[$key])) {
                    return false;
                }
            }

            return true;
        }

        return $before === $after;
    }

    public function collection(string $collection, array $data, array $state): void
    {
        if ($collection === 'products') {
            // The editor receives identifiers from the mapping table, not stale JSON.
            app(ImportCategories::class)->attach($state);
        }
        $old = collect($state[$collection] ?? [])->firstWhere('id', $data['id'] ?? null);
        $group = match ($collection) {
            'featuredCards' => 'featured_cards', 'middleSlides' => 'middle_slides', 'webSlides' => 'web_slides', 'tickets' => 'support', default => $collection
        };
        $operations = array_values(array_filter(AdminAccess::keys(), fn ($key) => str_starts_with($key, $group.'.') && ! in_array($key, [$group.'.view', $group.'.contact', $group.'.export', $group.'.search_serial'], true)));
        $this->oneOf($operations);
        if ($collection === 'featuredCards') {
            $this->access->require('featured_cards.update');

            return;
        }
        if (in_array($collection, ['slides', 'middleSlides', 'webSlides', 'faqs'], true)) {
            $before = $collection === 'webSlides' || $collection === 'faqs' ? ($state['settings'][$collection] ?? []) : $state[$collection];
            $ids = array_column($data, 'id');
            $oldIds = array_column($before, 'id');
            if (array_diff($ids, $oldIds)) {
                $this->access->require($group.'.create');
            }
            if (array_diff($oldIds, $ids)) {
                $this->access->require($group.'.delete');
            }
            foreach ($data as $row) {
                $original = collect($before)->firstWhere('id', $row['id'] ?? null);
                if ($original && ! $this->same($original, $row)) {
                    $this->access->require($group.'.update');
                }
            }
            if (array_values(array_intersect($oldIds, $ids)) !== array_values(array_intersect($ids, $oldIds))) {
                $this->access->require($group.'.reorder');
            }

            return;
        }
        if ($collection === 'settings') {
            $this->changes('settings', $state['settings'], $data, ['identity' => ['name'], 'contacts' => ['supportEmail', 'supportPhone', ...CatalogEditor::SOCIAL_FIELDS], 'stock' => ['lowStock'], 'legal' => ['privacyText', 'aboutText']]);

            return;
        }
        if ($collection === 'orders') {
            $this->access->require('orders.status');

            return;
        }
        if ($collection === 'tickets') {
            $this->changes('support', $old ?? [], $data, ['reply' => ['reply', 'replyImage'], 'status' => ['status']]);

            return;
        }
        if (! in_array($collection, ['products', 'categories', 'companies', 'customers', 'suppliers'], true)) {
            throw new ApiError('العملية الإدارية غير معرّفة', $this->access->current()['owner'] ? 404 : 403);
        }
        if (! $old) {
            $this->access->require($group.'.create');
        }
        $maps = match ($collection) {
            'products' => ['update' => ['name', 'en', 'tag', 'unit', 'category', 'color', 'displayOrder', 'denomNames', 'denomDescriptions', 'denomOrder'], 'images' => ['image', 'denomImages'], 'denominations' => ['values'], 'prices' => ['prices'], 'visibility' => ['active'], 'companies' => ['manufacturerId', 'supplierCompanyId', 'denomSupplierIds'], 'import_fields' => ['importFields', 'denomImportFields', 'denomImportCodes']],
            'categories' => ['update' => ['name', 'en', 'icon'], 'images' => ['image'], 'visibility' => ['active']],
            'companies' => ['update' => ['name', 'role', 'phone', 'address', 'sourceId', 'parentId', 'displayOrder'], 'images' => ['image'], 'status' => ['active']],
            'customers' => ['update' => ['name', 'phone', 'email'], 'status' => ['active']],
            'suppliers' => ['update' => ['name', 'method', 'url'], 'status' => ['active']],
        };
        if ($old) {
            $this->changes($group, $old, $data, $maps);
        }
        if ($old && in_array($collection, ['products', 'categories', 'companies'], true)) {
            foreach (['ar', 'ku', 'en'] as $locale) {
                $this->changes($group, $old['translations'][$locale] ?? [], $data['translations'][$locale] ?? [], ['update' => ['name', 'description', 'denomNames', 'denomDescriptions'], 'images' => ['image', 'denomImages']]);
            }
        }
        if ($collection === 'customers' && ! empty($data['password'])) {
            $this->access->require('customers.password');
        }
        // Creating a card includes its initial metadata/prices; future edits are separate.
    }

    public function digitalOffer(?object $old, array $next, bool $publication): void
    {
        $action = $publication ? 'digital.publications_update' : 'digital.offers';
        if (! $old || (string) $old->product_id !== (string) $next['product_id'] || (string) $old->provider_id !== (string) $next['provider_id'] || $old->connection !== $next['connection']) {
            $this->access->require($action);
        }
        if (! $old || StockOrders::money((string) $old->retail) !== StockOrders::money((string) $next['retail'])) {
            $this->access->require('digital.prices');
        }
        if ((! $old && $next['active']) || ($old && (bool) $old->active !== (bool) $next['active'])) {
            $this->access->require('digital.visibility');
        }
    }

    public function filter(array $data, string $path): array
    {
        if (isset($data['revision'], $data['products'], $data['settings'], $data['orders'], $data['customers'], $data['tickets'])) {
            $data = $this->state($data);
        }
        if (isset($data['state']) && is_array($data['state'])) {
            $data['state'] = $this->state($data['state']);
        }
        if ($path === 'api/admin/procurement/digital') {
            foreach (['connections' => 'digital.connections_view', 'offers' => 'digital.offers_view', 'catalogs' => 'digital.catalog', 'publications' => 'digital.publications_view', 'executions' => 'digital.executions'] as $key => $permission) {
                if (! $this->access->can($permission)) {
                    $data[$key] = [];
                }
            }
        }

        if (! $this->access->current()['owner']) {
            $data = $this->hideOwnerIdentity($data);
        }

        return $this->financial($data);
    }

    private function hideOwnerIdentity(array $data): array
    {
        foreach ($data as $key => &$value) {
            if ($key === 'actor' && $value === (string) config('fatya.admin_user')) {
                $value = 'النظام';
            } elseif (is_array($value)) {
                $value = $this->hideOwnerIdentity($value);
            } elseif ($key === 'details' && is_string($value)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $value = Store::json($this->hideOwnerIdentity($decoded));
                }
            }
        }
        unset($value);

        return $data;
    }

    private function state(array $s): array
    {
        $metrics = [];
        foreach (['sales', 'orders', 'stock', 'customers', 'support'] as $key) {
            if (! $this->access->can('dashboard.'.$key)) {
                continue;
            }
            $metrics[$key] = match ($key) {
                'sales' => array_sum(array_map(fn ($o) => ($o['paymentStatus'] ?? '') === 'confirmed' && ($o['fulfillmentStatus'] ?? '') === 'delivered' && ($o['codesAvailable'] ?? false) ? $o['price'] : 0, $s['orders'])),
                'orders' => count($s['orders']),
                'customers' => count($s['customers']),
                'support' => count(array_filter($s['tickets'], fn ($t) => $t['status'] !== 'مغلقة')),
                'stock' => array_sum(array_map(fn ($p) => array_sum($p['stock']), $s['products'])),
            };
        }
        $s['adminMetrics'] = $metrics;
        foreach (['customers' => 'customers.view', 'orders' => 'orders.view', 'tickets' => 'support.view', 'imports' => 'inventory.history', 'suppliers' => 'suppliers.view', 'companies' => 'companies.view', 'events' => 'events.view', 'inventorySummary' => 'inventory.view', 'paymentReceipts' => 'payments.view'] as $collection => $permission) {
            if (! $this->access->can($permission)) {
                $s[$collection] = [];
            }
        }
        foreach ($s['products'] as &$p) {
            if (! $this->access->can('inventory.view')) {
                $p['stock'] = array_fill(0, count($p['values']), 0);
            }
        }
        unset($p);
        foreach (['customers' => 'customers.contact', 'tickets' => 'support.contact'] as $key => $permission) {
            if (! $this->access->can($permission)) {
                foreach ($s[$key] as &$row) {
                    foreach (['phone', 'email'] as $field) {
                        $row[$field] = '';
                    }
                }
                unset($row);
            }
        }
        $s['adminAccess'] = $this->access->current();

        return $s;
    }

    private function financial(array $data): array
    {
        $costs = $this->access->can('finance.costs');
        $profits = $this->access->can('finance.profits');
        foreach ($data as $key => &$value) {
            if ((! $costs && preg_match('/cost|purchasePrice/i', (string) $key)) || (! $profits && preg_match('/profit|margin/i', (string) $key))) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $value = $this->financial($value);
            } elseif ($key === 'details' && is_string($value)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $value = Store::json($this->financial($decoded));
                }
            }
        }
        unset($value);

        return $data;
    }
}
