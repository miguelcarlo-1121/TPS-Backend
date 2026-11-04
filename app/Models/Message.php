<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasFactory;

    // Dito natin sinasabi kung anong mga columns ang pwedeng i-save
    protected $fillable = [
        'sender_id',
        'receiver_id',
        'appointment_id',
        'message',
    ];

    // Relasyon: Ang isang message ay pagmamay-ari ng isang sender (User)
    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    // Relasyon: Ang isang message ay para sa isang receiver (User)
    public function receiver()
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }
}