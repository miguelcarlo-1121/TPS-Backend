<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ServiceController extends Controller
{
    // The three canonical service slugs (order matters for display).
    public const SLUGS = ['psychotherapy', 'aba', 'assessment'];

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /** Return one service row joined with its current price. */
    private function row(object $s, float $price, float $downpayment_percent, float $psychotherapy_downpayment_amount, array $full_only): array
    {
        $slug = $s->slug ?? null;

        return [
            'id'                  => $slug,
            'slug'                => $slug,
            'name'                => $s->name,
            'title'               => $s->title ?: $s->name,
            'hours'               => round($s->duration_minutes / 60, 1),
            'meta'                => $s->meta ?? '',
            'text'                => $s->description ?? '',
            'goodFor'             => $s->good_for ? json_decode($s->good_for, true) : [],
            'steps'               => $s->steps    ? json_decode($s->steps,    true) : [],
            'image_url'           => $s->image_path ? Storage::disk('public')->url($s->image_path) : null,
            'enabled'             => (bool) $s->enabled,
            'price'               => $slug === 'assessment' ? 0.0 : $price,
            'downpayment_percent' => in_array($slug, $full_only) ? 100 : $downpayment_percent,
            'downpayment_amount'  => $slug === 'psychotherapy' ? $psychotherapy_downpayment_amount : null,
            'full_only'           => in_array($slug, $full_only),
            'is_custom'           => !in_array($slug, self::SLUGS, true),
        ];
    }

    /** Load services, prices, and payment settings in one go. */
    private function loadAll(): array
    {
        $services = DB::table('services')
            ->orderBy('id')
            ->get();

        $prices = DB::table('service_prices')
            ->pluck('price', 'service_id');

        $settings = DB::table('payment_settings')->first();
        $downpct  = (int) ($settings->downpayment_percent ?? 50);
        $psychotherapyDownpayment = (float) ($settings->psychotherapy_downpayment_amount ?? 750);
        $assessmentDownpayment = (float) ($settings->assessment_downpayment_amount ?? 750);

        $full_only = \App\Http\Controllers\PaymentSettingsController::FULL_ONLY;

        $assessmentTypes = DB::table('assessment_types')
            ->where('enabled', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(fn($t) => [
                'id'               => $t->id,
                'name'             => $t->name,
                'description'      => $t->description ?? '',
                'price'            => (float) $t->price,
                'downpayment_amount' => $assessmentDownpayment > 0 && $assessmentDownpayment < (float) $t->price
                    ? $assessmentDownpayment
                    : null,
                'duration_minutes' => (int) $t->duration_minutes,
                'hours'            => round($t->duration_minutes / 60, 1),
            ])
            ->values()
            ->all();

        // Return canonical 3 first
        $rows = [];
        foreach (self::SLUGS as $slug) {
            $s = $services->firstWhere('slug', $slug);
            if (!$s) continue;
            $row = $this->row($s, (float)($prices[$slug] ?? 0), $downpct, $psychotherapyDownpayment, $full_only);
            if ($slug === 'assessment') {
                $row['assessment_types'] = $assessmentTypes;
            }
            $rows[] = $row;
        }

        // Then custom services
        foreach ($services as $s) {
            if (in_array($s->slug, self::SLUGS, true)) continue;
            $rows[] = $this->row($s, (float)($prices[$s->slug] ?? 0), $downpct, $psychotherapyDownpayment, $full_only);
        }

        return $rows;
    }

    // ---------------------------------------------------------------
    // Public endpoint — GET /api/services
    // ---------------------------------------------------------------
    public function index()
    {
        $all = $this->loadAll();
        // Public page: only show enabled services
        $visible = array_values(array_filter($all, fn($s) => $s['enabled']));

        return response()->json(['services' => $visible]);
    }

    // ---------------------------------------------------------------
    // Admin endpoints
    // ---------------------------------------------------------------

    /** GET /api/admin/services */
    public function adminIndex()
    {
        return response()->json(['services' => $this->loadAll()]);
    }

    /** POST /api/admin/services */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'      => ['required', 'string', 'max:100'],
            'title'     => ['nullable', 'string', 'max:150'],
            'hours'     => ['required', 'numeric', 'min:0.5', 'max:8'],
            'meta'      => ['nullable', 'string', 'max:200'],
            'text'      => ['required', 'string', 'max:2000'],
            'goodFor'   => ['nullable', 'array'],
            'goodFor.*' => ['string', 'max:60'],
            'steps'     => ['nullable', 'array'],
            'steps.*'   => ['string', 'max:300'],
            'enabled'   => ['sometimes', 'boolean'],
        ]);

        $baseSlug = Str::slug($data['name']);
        $slug = $baseSlug ?: 'service';
        $count = 1;
        while (DB::table('services')->where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . (++$count);
        }

        $code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $slug), 0, 6));
        if (strlen($code) < 2) $code = 'SVC';
        $codeCount = 1;
        $uniqueCode = $code;
        while (DB::table('services')->where('code', $uniqueCode)->exists()) {
            $uniqueCode = substr($code, 0, 4) . (++$codeCount);
        }

        DB::table('services')->insert([
            'code'             => $uniqueCode,
            'slug'             => $slug,
            'name'             => $data['name'],
            'title'            => $data['title'] ?: $data['name'],
            'meta'             => $data['meta'] ?: null,
            'description'      => $data['text'],
            'good_for'         => json_encode(array_values($data['goodFor'] ?? [])),
            'steps'            => json_encode(array_values($data['steps'] ?? [])),
            'duration_minutes' => (int) round($data['hours'] * 60),
            'price'            => 0.00,
            'is_active'        => $data['enabled'] ?? true,
            'enabled'          => $data['enabled'] ?? true,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $all = $this->loadAll();
        $created = collect($all)->firstWhere('slug', $slug);

        return response()->json(['service' => $created], 201);
    }

    /** PUT /api/admin/services/{slug} */
    public function adminUpdate(Request $request, string $slug)
    {
        $service = DB::table('services')->where('slug', $slug)->first();
        if (!$service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        $data = $request->validate([
            'name'      => ['sometimes', 'required', 'string', 'max:100'],
            'title'     => ['sometimes', 'nullable', 'string', 'max:150'],
            'hours'     => ['sometimes', 'required', 'numeric', 'min:0.5', 'max:8'],
            'meta'      => ['sometimes', 'nullable', 'string', 'max:200'],
            'text'      => ['sometimes', 'required', 'string', 'max:2000'],
            'goodFor'   => ['sometimes', 'array'],
            'goodFor.*' => ['string', 'max:60'],
            'steps'     => ['sometimes', 'array'],
            'steps.*'   => ['string', 'max:300'],
            'enabled'   => ['sometimes', 'boolean'],
        ]);

        $update = ['updated_at' => now()];

        if (isset($data['name']))    $update['name']             = $data['name'];
        if (isset($data['title']))   $update['title']            = $data['title'] ?: null;
        if (isset($data['hours']))   $update['duration_minutes'] = (int) round($data['hours'] * 60);
        if (isset($data['meta']))    $update['meta']             = $data['meta'] ?: null;
        if (isset($data['text']))    $update['description']      = $data['text'];
        if (array_key_exists('goodFor', $data)) $update['good_for'] = json_encode(array_values($data['goodFor']));
        if (array_key_exists('steps',   $data)) $update['steps']    = json_encode(array_values($data['steps']));
        if (isset($data['enabled'])) {
            $update['enabled']   = $data['enabled'];
            $update['is_active'] = $data['enabled']; // keep is_active in sync
        }

        DB::table('services')->where('slug', $slug)->update($update);

        // Re-fetch and return the updated service
        $all = $this->loadAll();
        $updated = collect($all)->firstWhere('slug', $slug);

        return response()->json(['service' => $updated]);
    }

    /** POST /api/admin/services/{slug}/image */
    public function uploadImage(Request $request, string $slug)
    {
        $service = DB::table('services')->where('slug', $slug)->first();
        if (!$service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        $data = $request->validate([
            'image' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);
        $path = $data['image']->store('services', 'public');

        if ($service->image_path) {
            Storage::disk('public')->delete($service->image_path);
        }

        DB::table('services')->where('slug', $slug)->update([
            'image_path' => $path,
            'updated_at' => now(),
        ]);

        return response()->json(['image_url' => Storage::disk('public')->url($path)]);
    }

    /** DELETE /api/admin/services/{slug}/image */
    public function deleteImage(string $slug)
    {
        $service = DB::table('services')->where('slug', $slug)->first();
        if (!$service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        if ($service->image_path) {
            Storage::disk('public')->delete($service->image_path);
            DB::table('services')->where('slug', $slug)->update([
                'image_path' => null,
                'updated_at' => now(),
            ]);
        }

        return response()->json(['image_url' => null]);
    }

    /** DELETE /api/admin/services/{slug} */
    public function destroy(string $slug)
    {
        if (in_array($slug, self::SLUGS, true)) {
            return response()->json(['message' => 'Default services cannot be deleted.'], 403);
        }

        $service = DB::table('services')->where('slug', $slug)->first();
        if (!$service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }
        $deleted = DB::table('services')->where('slug', $slug)->delete();
        if (!$deleted) {
            return response()->json(['message' => 'Service not found.'], 404);
        }
        if ($service->image_path) {
            Storage::disk('public')->delete($service->image_path);
        }
        DB::table('service_prices')->where('service_id', $slug)->delete();

        return response()->json(['message' => 'Service deleted successfully.']);
    }
}
