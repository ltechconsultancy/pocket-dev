<?php

return [

    /*
    |--------------------------------------------------------------------------
    | File Upload Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for file uploads in PocketDev. The chat uploads every file in
    | resumable chunks of 8 MB (FileUploadController::uploadChunk), so the Nginx/PHP
    | request limit of 2GB only applies per chunk, not to the whole file.
    |
    */

    // Maximum file size for chat attachments (in MB). Chunked, so larger than the
    // per-request ceiling is fine; the free disk space is checked per upload.
    'max_size_mb' => (int) env('PD_UPLOAD_MAX_MB', 20480),

    // Keep at least this much disk free (MB) when accepting a new upload
    'min_free_mb' => (int) env('PD_UPLOAD_MIN_FREE_MB', 3072),

    // Upload directory (shared across containers via pocket-dev-shared-tmp volume)
    'directory' => env('PD_UPLOAD_DIR', '/tmp/pocketdev-uploads'),

];
