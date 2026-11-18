<?php

return [
    'path' => realpath(env('MEDIA_PATH', storage_path('app/public'))),
    'trust_x_send_file' => env('MEDIA_TRUST_X_SEND_FILE', false),

    'ffmpeg_bin' => env('MEDIA_FFMPEG_BIN', '/usr/bin/ffmpeg'),
    'ffprobe_bin' => env('MEDIA_FFPROBE_BIN', '/usr/bin/ffprobe'),
    'tesseract_bin' => env('MEDIA_TESSERACT_BIN', '/usr/bin/tesseract'),
];
