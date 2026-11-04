<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Events\MessageSent;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    // Kunin ang history ng usapan
    public function index(Request $request)
    {
        $request->validate(['contact_id' => 'required']);
        
        $myId = $request->user()->id;
        $contactId = $request->contact_id;

        $messages = Message::where(function ($q) use ($myId, $contactId) {
            $q->where('sender_id', $myId)->where('receiver_id', $contactId);
        })->orWhere(function ($q) use ($myId, $contactId) {
            $q->where('sender_id', $contactId)->where('receiver_id', $myId);
        })->orderBy('created_at', 'asc')->get();

        return response()->json($messages);
    }
    // Kunin ang listahan ng lahat ng pwedeng kausapin (users)
    public function getContacts(Request $request)
{
    $myId = $request->user()->id;
    
    $users = \App\Models\User::where('id', '!=', $myId)
        ->select('id', 'first_name', 'last_name', 'role', 'avatar_path', 'phone') // <-- Tiyakin na may 'phone'
        ->get()
        ->map(function ($user) {
            return [
                'id' => (string) $user->id,
                'name' => $user->first_name . ' ' . $user->last_name,
                'role' => $user->role,
                'avatar' => $user->avatar_url ?: '/img/default-avatar.png',
                'phone' => $user->phone, // <-- Dapat nasa array na ito
            ];
        });

    return response()->json($users);
}
    // Mag-send ng bagong message
    public function store(Request $request)
    {
        $request->validate([
            'receiver_id' => 'required',
            'message' => 'required|string'
        ]);

        $msg = Message::create([
            'sender_id' => $request->user()->id,
            'receiver_id' => $request->receiver_id,
            'message' => $request->message,
        ]);

        // I-broadcast sa real-time
        broadcast(new MessageSent($msg))->toOthers();

        return response()->json($msg->load('sender'));
    }
}