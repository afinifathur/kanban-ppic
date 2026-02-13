<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index()
    {
        $customers = Customer::orderBy('name')->get();
        return view('settings.customers.index', compact('customers'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:customers',
        ]);

        Customer::create($request->all());

        return redirect()->route('settings.customers.index')->with('success', 'Customer berhasil ditambahkan.');
    }

    public function destroy(Customer $customer)
    {
        $customer->delete();
        return redirect()->route('settings.customers.index')->with('success', 'Customer berhasil dihapus.');
    }
}
