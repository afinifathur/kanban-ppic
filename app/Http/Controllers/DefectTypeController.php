<?php

namespace App\Http\Controllers;

use App\Models\DefectType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DefectTypeController extends Controller
{
    public const SAND_CASTING_DEPARTMENTS = [
        'cor',
        'netto',
        'bubut_od',
        'bubut_cnc',
        'bor',
        'finish',
    ];

    public const FITTING_DEPARTMENTS = [
        'netto_pf',
        'kimia',
        'marking',
        'bubut_cnc_pf',
        'qc_pf',
        'gudang_jadi_pf',
    ];

    public const FITTING_DEPARTMENT_LABELS = [
        'netto_pf' => 'Netto PF',
        'kimia' => 'Kimia',
        'marking' => 'Marking',
        'bubut_cnc_pf' => 'Bubut CNC PF',
        'qc_pf' => 'QC PF',
        'gudang_jadi_pf' => 'Gudang Jadi PF',
    ];

    public function index()
    {
        $departments = self::SAND_CASTING_DEPARTMENTS;
        $fittingDepartments = self::FITTING_DEPARTMENTS;
        $fittingDepartmentLabels = self::FITTING_DEPARTMENT_LABELS;
        $defectTypes = DefectType::orderBy('department')->orderBy('name')->get()->groupBy('department');

        return view('settings.defect_types.index', compact(
            'departments',
            'fittingDepartments',
            'fittingDepartmentLabels',
            'defectTypes'
        ));
    }

    public function store(Request $request)
    {
        $allowedDepartments = array_merge(self::SAND_CASTING_DEPARTMENTS, self::FITTING_DEPARTMENTS);

        $validated = $request->validate([
            'department' => ['required', 'string', Rule::in($allowedDepartments)],
            'name' => 'required|string|max:255',
        ]);

        $normalizedName = trim((string) $validated['name']);
        if ($normalizedName === '') {
            return back()->withErrors(['name' => 'Nama jenis kerusakan tidak boleh kosong.'])->withInput();
        }

        $exists = DefectType::where('department', $validated['department'])
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($normalizedName)])
            ->exists();

        if ($exists) {
            return back()
                ->withErrors(['name' => "Jenis kerusakan '{$normalizedName}' sudah terdaftar pada departemen ini."])
                ->withInput();
        }

        DefectType::create([
            'department' => $validated['department'],
            'name' => $normalizedName,
        ]);

        return redirect()->route('settings.defect-types.index')->with('success', 'Jenis kerusakan berhasil ditambahkan.');
    }

    public function update(Request $request, DefectType $defectType)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $normalizedName = trim((string) $validated['name']);
        if ($normalizedName === '') {
            return back()->withErrors(['name' => 'Nama jenis kerusakan tidak boleh kosong.'])->withInput();
        }

        $exists = DefectType::where('department', $defectType->department)
            ->where('id', '!=', $defectType->id)
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($normalizedName)])
            ->exists();

        if ($exists) {
            return back()
                ->withErrors(['name' => "Jenis kerusakan '{$normalizedName}' sudah terdaftar pada departemen ini."])
                ->withInput();
        }

        $defectType->update([
            'name' => $normalizedName,
        ]);

        return redirect()->route('settings.defect-types.index')->with('success', 'Jenis kerusakan berhasil diperbarui.');
    }
}
