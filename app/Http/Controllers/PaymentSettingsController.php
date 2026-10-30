<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PaymentSettingsController extends Controller
{
    private const SERVICES = ['psychotherapy', 'aba', 'assessment'];
    public const FULL_ONLY = ['aba'];
    // GET /api/admin/payment-settings
    public function show()
    {
        $settings = DB::table('payment_settings')->first();
        $prices   = DB::table('service_prices')->pluck('price', 'service_id');

        $out = [];
        foreach (self::SERVICES as $s) {
            $out[$s] = (float) ($prices[$s] ?? 0);
        }

        return response()->json([
            'downpayment_percent' => (int) ($settings->downpayment_percent ?? 50),
            'note'                => $settings->note ?? '',
            'prices'              => $out,
            'full_only'           => self::FULL_ONLY, 
            'methods'             => PaymentMethod::orderBy('sort')->orderBy('id')->get()
                ->map(fn ($m) => $m->toArrayPublic())->values(),
        ]);
    }

    // PUT /api/admin/payment-settings
    public function update(Request $request)
    {
        $rules = [
            'downpayment_percent' => ['required', 'integer', 'min:1', 'max:100'],
            'note'                => ['nullable', 'string', 'max:500'],
            'prices'              => ['required', 'array'],
        ];
        foreach (self::SERVICES as $s) {
            $rules["prices.$s"] = ['required', 'numeric', 'min:1', 'max:1000000'];
        }
        $data = $request->validate($rules, [
            'prices.*.min' => 'Price must be at least 1.',
        ]);

        $now = now();
        $row = DB::table('payment_settings')->first();

        if ($row) {
            DB::table('payment_settings')->where('id', $row->id)->update([
                'downpayment_percent' => $data['downpayment_percent'],
                'note'                => $data['note'] ?? null,
                'updated_at'          => $now,
            ]);
        } else {
            DB::table('payment_settings')->insert([
                'downpayment_percent' => $data['downpayment_percent'],
                'note'                => $data['note'] ?? null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach (self::SERVICES as $s) {
            DB::table('service_prices')->updateOrInsert(
                ['service_id' => $s],
                ['price' => $data['prices'][$s], 'updated_at' => $now, 'created_at' => $now]
            );
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