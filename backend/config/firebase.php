<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Firebase Admin credentials
    |--------------------------------------------------------------------------
    |
    | Absolute or storage-relative path to the Firebase service-account JSON.
    | Keep this file out of git. Never expose it to the frontend.
    |
    */
    'credentials' => env(
        'FIREBASE_CREDENTIALS',
        storage_path('como-d22dd-firebase-adminsdk-fbsvc-3385b1a382.json')
    ),

    /*
    | Optional override. When empty, project_id is read from the credentials file.
    */
    'project_id' => env('FIREBASE_PROJECT_ID'),

    /*
    | Optional FCM topic used in addition to per-device tokens on broadcast.
    | Mobile apps should subscribe to this topic after login.
    */
    'broadcast_topic' => env('FIREBASE_BROADCAST_TOPIC', 'all_users'),
];
