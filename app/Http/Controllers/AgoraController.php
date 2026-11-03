<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use CyberDeep\LaravelAgoraTokenGenerator\Services\Agora;

class AgoraController extends Controller
{
    public function generateToken(Request $request)
    {
        $request->validate([
            'channelName' => 'required|string',
        ]);

        $user = $request->user();
        $channelName = $request->channelName;
        $uid = $user->id;

        // Gumawa ng token gamit ang bagong package
        $token = Agora::make($user->id)
            ->channel($channelName)
            ->uId($uid)
            ->join(false) // false = publisher (makakapag-video/audio)
            ->audioOnly(false) // false = may video
            ->token();

        return response()->json([
            'token' => $token,
            'uid' => $uid,
            'appId' => env('AGORA_APP_ID'),
        ]);
    }
}