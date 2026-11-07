<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AssessmentTypeController extends Controller
{
    // ---------------------------------------------------------------
    // Public — GET /api/assessment-types
    // Returns only enabled types (for the booking flow)
    // ---------------------------------------------------------------
    public function index()
    {
        $types = DB::table('assessment_types')
            ->where('enabled', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(fn($t) => $this->format($t));

        return response()->json(['types' => $types]);
    }

    // ---------------------------------------------------------------
    // Admin — GET /api/admin/assessment-types
    // Returns all types including disabled
    // ---------------------------------------------------------------
    public function adminIndex()
    {
        $types = DB::table('assessment_types')
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(fn($t) => $this->format($t));

        return response()->json(['types' => $types]);
    }

    // ---------------------------------------------------------------
    // Admin — POST /api/admin/assessment-types
    // ---------------------------------------------------------------
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'             => ['required', 'string', 'max:150'],
            'description'      => ['nullable', 'string', 'max:1000'],
            'price'            => ['nullable', 'numeric', 'min:0'],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:480'],
            'enabled'          => ['sometimes', 'boolean'],
        ]);

        $sort = (int) DB::table('assessment_types')->max('sort') + 1;

        $id = DB::table('assessment_types')->insertGetId([
            'name'             => trim($data['name']),
            'description'      => isset($data['description']) ? trim($data['description']) : null,
            'price'            => $data['price'] ?? 0.00,
            'duration_minutes' => $data['duration_minutes'],
            'enabled'          => $data['enabled'] ?? true,
            'sort'             => $sort,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $type = DB::table('assessment_types')->find($id);

        return response()->json(['type' => $this->format($type)], 201);
    }

    // ---------------------------------------------------------------
    // Admin — PUT /api/admin/assessment-types/{id}
    // ---------------------------------------------------------------
    public function update(Request $request, int $id)
    {
        $type = DB::table('assessment_types')->find($id);
        if (!$type) return response()->json(['message' => 'Not found.'], 404);

        $data = $request->validate([
            'name'             => ['sometimes', 'required', 'string', 'max:150'],
            'description'      => ['sometimes', 'nullable', 'string', 'max:1000'],
            'price'            => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'duration_minutes' => ['sometimes', 'required', 'integer', 'min:15', 'max:480'],
            'enabled'          => ['sometimes', 'boolean'],
        ]);

        $patch = ['updated_at' => now()];
        if (isset($data['name']))             $patch['name']             = trim($data['name']);
        if (array_key_exists('description', $data)) $patch['description'] = $data['description'] ? trim($data['description']) : null;
        if (isset($data['price']))            $patch['price']            = $data['price'];
        if (isset($data['duration_minutes'])) $patch['duration_minutes'] = $data['duration_minutes'];
        if (isset($data['enabled']))          $patch['enabled']          = $data['enabled'];

        DB::table('assessment_types')->where('id', $id)->update($patch);

        $updated = DB::table('assessment_types')->find($id);
        return response()->json(['type' => $this->format($updated)]);
    }

    // ---------------------------------------------------------------
    // Admin — DELETE /api/admin/assessment-types/{id}
    // ---------------------------------------------------------------
    public function destroy(int $id)
    {
        $type = DB::table('assessment_types')->find($id);
        if (!$type) return response()->json(['message' => 'Not found.'], 404);

        DB::table('assessment_types')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // ---------------------------------------------------------------
    // Format a DB row for the API response
    // ---------------------------------------------------------------
    private function format(object $t): array
    {
        return [
            'id'               => $t->id,
            'name'             => $t->name,
            'description'      => $t->description ?? '',
            'price'            => (float) $t->price,
            'duration_minutes' => (int) $t->duration_minutes,
            'hours'            => round($t->duration_minutes / 60, 1),
            'enabled'          => (bool) $t->enabled,
            'sort'             => (int) $t->sort,
        ];
    }
}

