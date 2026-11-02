<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->input('search', ''));
        $categoryFilter = strtoupper(trim((string) $request->input('category', '')));
        $statusFilter = strtolower(trim((string) $request->input('status', 'all')));

        $query = Customer::query();

        if ($search !== '') {
            $query->where('name', 'like', '%'.$search.'%');
        }

        if ($categoryFilter === Customer::CATEGORY_LOCAL) {
            $query->where(function ($q) {
                $q->where('category', Customer::CATEGORY_LOCAL)
                    ->orWhereRaw("UPPER(TRIM(name)) = 'STOK NON PO'");
            });
        } elseif ($categoryFilter === Customer::CATEGORY_EXPORT) {
            $query->where(function ($q) {
                $q->where('category', Customer::CATEGORY_EXPORT)
                    ->orWhereRaw("UPPER(TRIM(name)) = 'STOK EXP'");
            });
        } elseif ($categoryFilter === Customer::CATEGORY_UNCLASSIFIED) {
            $query->where(function ($q) {
                $q->whereNull('category')->orWhere('category', '');
            })->whereRaw("UPPER(TRIM(name)) NOT IN ('STOK NON PO', 'STOK EXP')");
        }

        if ($statusFilter === 'active') {
            $query->where('is_active', true);
        } elseif ($statusFilter === 'inactive') {
            $query->where('is_active', false);
        }

        $customers = $query->orderBy('name')->get();

        // Compute stats across all master customers
        $allCustomers = Customer::orderBy('name')->get();
        $stats = [
            'total' => $allCustomers->count(),
            'local' => $allCustomers->filter(fn ($c) => Customer::resolveCategory($c->name, [strtoupper(trim($c->name)) => strtoupper((string) $c->category)]) === Customer::CATEGORY_LOCAL)->count(),
            'export' => $allCustomers->filter(fn ($c) => Customer::resolveCategory($c->name, [strtoupper(trim($c->name)) => strtoupper((string) $c->category)]) === Customer::CATEGORY_EXPORT)->count(),
            'unclassified' => $allCustomers->filter(fn ($c) => Customer::resolveCategory($c->name, [strtoupper(trim($c->name)) => strtoupper((string) $c->category)]) === Customer::CATEGORY_UNCLASSIFIED)->count(),
            'active' => $allCustomers->where('is_active', true)->count(),
            'inactive' => $allCustomers->where('is_active', false)->count(),
        ];

        $usedCustomerNames = $this->getUsedCustomerNamesMap();

        return view('settings.customers.index', compact(
            'customers',
            'stats',
            'usedCustomerNames',
            'search',
            'categoryFilter',
            'statusFilter'
        ));
    }

    public function store(Request $request)
    {
        $normalizedName = trim((string) $request->input('name', ''));
        $upperName = strtoupper($normalizedName);

        $resolvedCategory = strtoupper(trim((string) $request->input('category', '')));
        if ($upperName === 'STOK NON PO') {
            $resolvedCategory = Customer::CATEGORY_LOCAL;
        } elseif ($upperName === 'STOK EXP') {
            $resolvedCategory = Customer::CATEGORY_EXPORT;
        }

        $request->merge([
            'name' => $normalizedName,
            'category' => $resolvedCategory,
        ]);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|in:LOCAL,EXPORT',
            'is_active' => 'nullable|boolean',
        ], [
            'name.required' => 'Nama customer wajib diisi.',
            'category.required' => 'Kategori customer (LOCAL atau EXPORT) wajib dipilih.',
            'category.in' => 'Kategori customer harus LOCAL atau EXPORT.',
        ]);

        $duplicate = Customer::whereRaw('UPPER(TRIM(name)) = ?', [$upperName])->exists();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'name' => ['Nama customer sudah terdaftar. Gunakan nama lain atau edit customer yang sudah ada.'],
            ]);
        }

        Customer::create([
            'name' => $data['name'],
            'category' => $data['category'],
            'is_active' => $request->has('is_active') ? (bool) $request->input('is_active') : true,
        ]);

        return redirect()->route('settings.customers.index')->with('success', 'Customer '.$data['name'].' ('.$data['category'].') berhasil ditambahkan.');
    }

    public function update(Request $request, Customer $customer)
    {
        $normalizedName = $request->has('name')
            ? trim((string) $request->input('name', ''))
            : trim((string) $customer->name);
        $upperName = strtoupper($normalizedName);

        $resolvedCategory = strtoupper(trim((string) $request->input('category', $customer->category ?? '')));
        if ($upperName === 'STOK NON PO') {
            $resolvedCategory = Customer::CATEGORY_LOCAL;
        } elseif ($upperName === 'STOK EXP') {
            $resolvedCategory = Customer::CATEGORY_EXPORT;
        }

        $request->merge([
            'name' => $normalizedName,
            'category' => $resolvedCategory,
        ]);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|in:LOCAL,EXPORT',
            'is_active' => 'nullable|boolean',
        ], [
            'name.required' => 'Nama customer wajib diisi.',
            'category.required' => 'Kategori customer (LOCAL atau EXPORT) wajib dipilih.',
            'category.in' => 'Kategori customer harus LOCAL atau EXPORT.',
        ]);

        $duplicate = Customer::where('id', '!=', $customer->id)
            ->whereRaw('UPPER(TRIM(name)) = ?', [$upperName])
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'name' => ['Nama customer sudah digunakan oleh data customer lain.'],
            ]);
        }

        $isActive = $request->has('is_active')
            ? (bool) $request->input('is_active')
            : (bool) $customer->is_active;

        $customer->update([
            'name' => $data['name'],
            'category' => $data['category'],
            'is_active' => $isActive,
        ]);

        return redirect()->route('settings.customers.index')->with('success', 'Data customer '.$customer->name.' berhasil diperbarui.');
    }

    public function destroy(Customer $customer)
    {
        if ($customer->isUsedInProduction()) {
            return redirect()->route('settings.customers.index')
                ->with('error', 'Customer "'.$customer->name.'" tidak dapat dihapus karena sudah digunakan pada data produksi / rencana cor. Gunakan status Nonaktif jika customer sudah tidak aktif.');
        }

        $name = $customer->name;
        $customer->delete();

        return redirect()->route('settings.customers.index')->with('success', 'Customer "'.$name.'" berhasil dihapus.');
    }

    /**
     * Build a lookup map of uppercase customer names currently referenced in production tables.
     *
     * @return array<string, bool>
     */
    protected function getUsedCustomerNamesMap(): array
    {
        $used = [];

        if (Schema::hasTable('production_plans')) {
            foreach (\App\Models\ProductionPlan::whereNotNull('customer')->where('customer', '!=', '')->distinct()->pluck('customer') as $name) {
                $used[strtoupper(trim((string) $name))] = true;
            }
        }

        if (Schema::hasTable('sand_casting_casting_order_lines')) {
            foreach (\App\Models\SandCastingCastingOrderLine::whereNotNull('customer')->where('customer', '!=', '')->distinct()->pluck('customer') as $name) {
                $used[strtoupper(trim((string) $name))] = true;
            }
        }

        if (Schema::hasTable('production_items')) {
            foreach (\App\Models\ProductionItem::whereNotNull('customer')->where('customer', '!=', '')->distinct()->pluck('customer') as $name) {
                $used[strtoupper(trim((string) $name))] = true;
            }
        }

        return $used;
    }
}
