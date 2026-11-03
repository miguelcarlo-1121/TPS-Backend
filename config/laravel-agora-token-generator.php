<?php

return [
    "agora" => [
        "app_id" => env('AGORA_APP_ID', null),
        "app_certificate" => env('AGORA_APP_CERTIFICATE', null),
        "token_builder" => env('AGORA_TOKEN_BUILDER', 'v1'), // v1 for RtcTokenBuilder, v2 for RtcTokenBuilder2
    ]
];
