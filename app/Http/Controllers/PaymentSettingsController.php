<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PaymentSettingsController extends Controller
{
    public const FULL_ONLY = ['aba'];

    // GET /api/admin/payment-settings
    public function show()
    {
        $settings = DB::table('payment_settings')->first();
        $storedPrices = DB::table('service_prices')->pluck('price', 'service_id');

        // All services except assessment (assessment pricing is per assessment type)
        $services = DB::table('services')
            ->where('slug', '!=', 'assessment')
            ->orderBy('id')
            ->get();

        $serviceList = [];
        $pricesOut = [];
        foreach ($services as $s) {
            $p = (float) ($storedPrices[$s->slug] ?? 0);
            $serviceList[] = [
                'id'    => $s->slug,
                'name'  => $s->name,
                'price' => $p,
            ];
            $pricesOut[$s->slug] = $p;
        }

        // Assessment types
        $assessmentTypes = DB::table('assessment_types')
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(fn($t) => [
                'id'               => $t->id,
                'name'             => $t->name,
                'price'            => (float) $t->price,
                'duration_minutes' => (int) $t->duration_minutes,
                'hours'            => round($t->duration_minutes / 60, 1),
                'enabled'          => (bool) $t->enabled,
            ])
            ->values()
            ->all();

        $assessmentPricesOut = [];
        foreach ($assessmentTypes as $at) {
            $assessmentPricesOut[(string) $at['id']] = $at['price'];
        }

        return response()->json([
            'downpayment_percent' => (int) ($settings->downpayment_percent ?? 50),
            'psychotherapy_downpayment_amount' => (float) ($settings->psychotherapy_downpayment_amount ?? 750),
            'assessment_downpayment_amount' => (float) ($settings->assessment_downpayment_amount ?? 750),
            'note'                => $settings->note ?? '',
            'services'            => $serviceList,
            'prices'              => $pricesOut,
            'assessment_types'    => $assessmentTypes,
            'assessment_prices'   => $assessmentPricesOut,
            'full_only'           => self::FULL_ONLY,
            'methods'             => PaymentMethod::orderBy('sort')->orderBy('id')->get()
                ->map(fn ($m) => $m->toArrayPublic())->values(),
        ]);
    }

    // PUT /api/admin/payment-settings
    public function update(Request $request)
    {
        $data = $request->validate([
            'downpayment_percent' => ['required', 'integer', 'min:1', 'max:100'],
            'psychotherapy_downpayment_amount' => ['required', 'numeric', 'min:1', 'max:1000000'],
            'assessment_downpayment_amount' => ['required', 'numeric', 'min:1', 'max:1000000'],
            'note'                => ['nullable', 'string', 'max:500'],
            'prices'              => ['nullable', 'array'],
            'prices.*'            => ['numeric', 'min:0', 'max:1000000'],
            'assessment_prices'   => ['nullable', 'array'],
            'assessment_prices.*' => ['numeric', 'min:0', 'max:1000000'],
        ]);

        $psychotherapyPrice = (float) (
            $data['prices']['psychotherapy']
            ?? DB::table('service_prices')->where('service_id', 'psychotherapy')->value('price')
            ?? 0
        );
        if ($data['psychotherapy_downpayment_amount'] >= $psychotherapyPrice) {
            return response()->json([
                'message' => 'Psychotherapy downpayment must be less than the session fee.',
            ], 422);
        }

        $now = now();
        $row = DB::table('payment_settings')->first();

        if ($row) {
            DB::table('payment_settings')->where('id', $row->id)->update([
                'downpayment_percent' => $data['downpayment_percent'],
                'psychotherapy_downpayment_amount' => $data['psychotherapy_downpayment_amount'],
                'assessment_downpayment_amount' => $data['assessment_downpayment_amount'],
                'note'                => $data['note'] ?? null,
                'updated_at'          => $now,
            ]);
        } else {
            DB::table('payment_settings')->insert([
                'downpayment_percent' => $data['downpayment_percent'],
                'psychotherapy_downpayment_amount' => $data['psychotherapy_downpayment_amount'],
                'assessment_downpayment_amount' => $data['assessment_downpayment_amount'],
                'note'                => $data['note'] ?? null,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);
        }

        // Save service prices
        if (!empty($data['prices'])) {
            foreach ($data['prices'] as $serviceSlug => $priceVal) {
                if ($serviceSlug === 'assessment') continue; // assessment price is not stored as single service price
                DB::table('service_prices')->updateOrInsert(
                    ['service_id' => $serviceSlug],
                    ['price' => (float) $priceVal, 'updated_at' => $now, 'created_at' => $now]
                );
            }
        }

        // Save assessment type prices
        if (!empty($data['assessment_prices'])) {
            foreach ($data['assessment_prices'] as $typeId => $priceVal) {
                DB::table('assessment_types')
                    ->where('id', (int) $typeId)
                    ->update([
                        'price'      => (float) $priceVal,
                        'updated_at' => $now,
                    ]);
            }
        }

        return $this->show();
    }

    private function saveQr(string $dataUrl): ?string
    {
        if (!preg_match('/^data:image\/jpeg;base64,/', $dataUrl)) return null;

        $binary = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
        if ($binary === false || strlen($binary) > 2 * 1024 * 1024 || @getimagesizefromstring($binary) === false) {
            return null;
        }

        $path = 'payment-qr/' . Str::random(40) . '.jpg';
        Storage::disk('public')->put($path, $binary);

        return $path;
    }

    // POST /api/admin/payment-methods
    public function storeMethod(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:60']]);

        $m = PaymentMethod::create([
            'name'    => trim($data['name']),
            'letter'  => strtoupper(mb_substr(trim($data['name']), 0, 1)),
            'enabled' => false, // hindi pa kumpleto ang details
            'sort'    => (int) PaymentMethod::max('sort') + 1,
        ]);

        return response()->json(['method' => $m->toArrayPublic()], 201);
    }

    // PUT /api/admin/payment-methods/{id}
    public function updateMethod(Request $request, $id)
    {
        $m = PaymentMethod::findOrFail($id);

        $data = $request->validate([
            'name'           => ['sometimes', 'required', 'string', 'min:2', 'max:60'],
            'account_name'   => ['sometimes', 'nullable', 'string', 'max:120'],
            'account_number' => ['sometimes', 'nullable', 'string', 'max:60'],
            'bank'           => ['sometimes', 'nullable', 'string', 'max:80'],
            'enabled'        => ['sometimes', 'boolean'],
            'qr'             => ['sometimes', 'nullable', 'string'],
            'remove_qr'      => ['sometimes', 'boolean'],
        ]);

        if (isset($data['name'])) {
            $m->name   = trim($data['name']);
            $m->letter = strtoupper(mb_substr($m->name, 0, 1));
        }
        foreach (['account_name', 'account_number', 'bank'] as $f) {
            if (array_key_exists($f, $data)) {
                $m->$f = $data[$f] !== null && trim($data[$f]) !== '' ? trim($data[$f]) : null;
            }
        }

        if (!empty($data['remove_qr']) && $m->qr_path) {
            Storage::disk('public')->delete($m->qr_path);
            $m->qr_path = null;
        }

        if (!empty($data['qr'])) {
            $path = $this->saveQr($data['qr']);
            if (!$path) {
                return response()->json(['message' => 'That QR image could not be used. Try a smaller one.'], 422);
            }
            if ($m->qr_path) Storage::disk('public')->delete($m->qr_path);
            $m->qr_path = $path;
        }

        if (array_key_exists('enabled', $data)) {
            if ($data['enabled'] && (!$m->account_name || !$m->account_number)) {
                return response()->json(['message' => 'Add the account name and number before turning this on.'], 422);
            }
            $m->enabled = $data['enabled'];
        } elseif ($m->enabled && (!$m->account_name || !$m->account_number)) {
            $m->enabled = false;
        }

        $m->save();

        return response()->json(['method' => $m->toArrayPublic()]);
    }

    // DELETE /api/admin/payment-methods/{id}
    public function destroyMethod($id)
    {
        $m = PaymentMethod::findOrFail($id);
        if ($m->qr_path) Storage::disk('public')->delete($m->qr_path);
        $m->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
