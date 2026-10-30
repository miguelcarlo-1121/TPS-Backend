<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class PaymentMethod extends Model
{
    protected $fillable = [
        'name', 'letter', 'badge', 'account_name', 'account_number',
        'bank', 'qr_path', 'enabled', 'sort',
    ];

    protected $casts = ['enabled' => 'boolean'];

    public function toArrayPublic(): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'letter'         => $this->letter,
            'badge'          => $this->badge,
            'account_name'   => $this->account_name,
            'account_number' => $this->account_number,
            'bank'           => $this->bank,
            'qr_url'         => $this->qr_path ? Storage::disk('public')->url($this->qr_path) : null,
            'enabled'        => $this->enabled,
        ];
    }
}