<?php

namespace App\Services;

/** The same published catalog transformation serves web, PWA and the admin display preview. */
class CustomerCatalog
{
    public function decorate(array &$s): void
    {
        $s['categories'] = array_values(array_filter($s['categories'], fn ($c) => $c['active']));
        $s['products'] = ProductFields::sort($s['products']);
        $s['products'] = array_values(array_filter($s['products'], fn ($p) => $p['active'] && collect($s['categories'])->contains('name', $p['category'])));
        $s['products'] = array_values(array_filter($s['products'], function ($product) use ($s) {
            $id = $product['manufacturerId'] ?? '';
            if (! $id || ! collect($s['companies'])->contains('id', $id)) {
                return true; // Preserve legacy unassigned cards.
            }
            $path = CompanyHierarchy::path($s['companies'], $id);

            return $path && ! collect($path)->contains(fn ($company) => ! $company['active']);
        }));
        foreach ($s['products'] as &$p) {
            $p['manufacturerPath'] = CompanyHierarchy::path($s['companies'], $p['manufacturerId'] ?? '');
            unset($p['importFields'], $p['denomImportFields'], $p['denomImportCodes']);
            $manufacturer = collect($s['companies'])->first(fn ($c) => $c['id'] === ($p['manufacturerId'] ?? ''));
            $p['manufacturer'] = $manufacturer ? ['id' => $manufacturer['id'], 'name' => $manufacturer['name'], 'image' => $manufacturer['image'] ?? ''] : null;
            $p['availability'] = array_map(fn ($quantity) => $quantity > 0, $p['stock']);
            $p['stock'] = array_fill(0, count($p['stock']), 0);
        } unset($p);
        app(DigitalSales::class)->decorate($s);
        app(DigitalPublications::class)->decorate($s);
    }
}
