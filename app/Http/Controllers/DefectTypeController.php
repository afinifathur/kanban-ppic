<?php

namespace App\Http\Controllers;

use App\Models\DefectType;
use Illuminate\Http\Request;

class DefectTypeController extends Controller
{
    public function index()
    {
        $departments = ['cor', 'netto', 'bubut_od', 'bubut_cnc', 'bor', 'finish'];
        $defectTypes = DefectType::orderBy('department')->orderBy('name')->get()->groupBy('department');

        return view('settings.defect_types.index', compact('departments', 'defectTypes'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'department' => 'required|string',
            'name' => 'required|string|max:255',
        ]);

        DefectType::create($request->all());

        return redirect()->route('settings.defect-types.index')->with('success', 'Jenis kerusakan berhasil ditambahkan.');
    }

    public function update(Request $request, DefectType $defectType)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $defectType->update([
            'name' => $request->name,
        ]);

        return redirect()->route('settings.defect-types.index')->with('success', 'Jenis kerusakan berhasil diperbarui.');
    }
}
