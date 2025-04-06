<?php

return [
    'path' => realpath(env('MEDIA_PATH', storage_path('app/public'))),
    'trust_x_send_file' => env('MEDIA_TRUST_X_SEND_FILE', false),
];
