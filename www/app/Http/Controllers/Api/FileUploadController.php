<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FileUploadController extends Controller
{
    /**
     * Upload a file to shared /tmp and return the path.
     */
    public function upload(Request $request): JsonResponse
    {
        $maxSizeKb = config('uploads.max_size_mb', 250) * 1024;

        $request->validate([
            'file' => 'required|file|max:' . $maxSizeKb,
        ]);

        $file = $request->file('file');

        // Generate unique filename preserving extension (sanitize extension)
        $extension = preg_replace('/[^a-zA-Z0-9]+/', '', (string) $file->getClientOriginalExtension());
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeName = Str::slug($originalName) ?: 'file';
        $uniqueName = $safeName . '-' . Str::random(8) . ($extension ? ('.' . $extension) : '');

        // Get upload directory from config
        $uploadDir = config('uploads.directory', '/tmp/pocketdev-uploads');

        // Ensure upload directory exists
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Failed to create upload directory',
                ], 500);
            }
        }

        $path = $uploadDir . '/' . $uniqueName;

        try {
            $file->move($uploadDir, $uniqueName);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'Upload failed',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'path' => $path,
            'filename' => $file->getClientOriginalName(),
            'size' => filesize($path),
            'size_formatted' => $this->formatFileSize(filesize($path)),
        ]);
    }

    // ===== Resumable uploads in chunks =====
    // The browser sends the file in pieces. When a phone suspends the app (PWA in the background,
    // screen off) the running piece fails; on return the client asks how much arrived and goes on
    // from there instead of starting over. Partial data: {upload dir}/.partial/{upload_id}.part

    /**
     * Append one chunk. offset must equal what the server already has (else 409 + received).
     * The last chunk (received == total_size) finishes the upload: same response as upload().
     */
    public function uploadChunk(Request $request): JsonResponse
    {
        $maxBytes = config('uploads.max_size_mb', 250) * 1024 * 1024;

        $request->validate([
            'upload_id' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/'],
            'filename' => 'required|string|max:255',
            'offset' => 'required|integer|min:0',
            'total_size' => 'required|integer|min:0|max:' . $maxBytes,
            'chunk' => 'required|file',
        ]);

        $partial = $this->partialPath($request->input('upload_id'));
        if (!$partial) {
            return response()->json(['success' => false, 'error' => 'Failed to create upload directory'], 500);
        }
        $this->cleanupStalePartials(dirname($partial));

        $total = (int) $request->input('total_size');
        $offset = (int) $request->input('offset');

        $fp = fopen($partial, 'c+b');
        if (!$fp || !flock($fp, LOCK_EX)) {
            return response()->json(['success' => false, 'error' => 'Upload failed'], 500);
        }

        try {
            clearstatcache(true, $partial);
            $received = (int) filesize($partial);
            if ($offset !== $received) {
                return response()->json(['success' => false, 'error' => 'offset_mismatch', 'received' => $received], 409);
            }

            $chunkPath = $request->file('chunk')->getRealPath();
            $chunkSize = (int) filesize($chunkPath);
            if ($received + $chunkSize > $total) {
                return response()->json(['success' => false, 'error' => 'Chunk exceeds total size', 'received' => $received], 422);
            }

            fseek($fp, $received);
            $in = fopen($chunkPath, 'rb');
            stream_copy_to_stream($in, $fp);
            fclose($in);
            fflush($fp);
            $received += $chunkSize;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        if ($received < $total) {
            return response()->json(['success' => true, 'done' => false, 'received' => $received]);
        }

        // Complete: move to its final name, exactly like a normal upload
        $original = $request->input('filename');
        $extension = preg_replace('/[^a-zA-Z0-9]+/', '', (string) pathinfo($original, PATHINFO_EXTENSION));
        $safeName = Str::slug(pathinfo($original, PATHINFO_FILENAME)) ?: 'file';
        $uniqueName = $safeName . '-' . Str::random(8) . ($extension ? ('.' . $extension) : '');
        $path = config('uploads.directory', '/tmp/pocketdev-uploads') . '/' . $uniqueName;

        if (!rename($partial, $path)) {
            return response()->json(['success' => false, 'error' => 'Upload failed'], 500);
        }
        @chmod($path, 0644);

        return response()->json([
            'success' => true,
            'done' => true,
            'received' => $received,
            'path' => $path,
            'filename' => $original,
            'size' => filesize($path),
            'size_formatted' => $this->formatFileSize(filesize($path)),
        ]);
    }

    /** How many bytes of a chunked upload the server has (to resume after an interruption). */
    public function uploadStatus(Request $request): JsonResponse
    {
        $request->validate(['upload_id' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/']]);
        $partial = $this->partialPath($request->input('upload_id'));
        clearstatcache(true, (string) $partial);

        return response()->json(['success' => true, 'received' => $partial && is_file($partial) ? (int) filesize($partial) : 0]);
    }

    /** Cancel a chunked upload: drop what arrived so far. */
    public function uploadCancel(Request $request): JsonResponse
    {
        $request->validate(['upload_id' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/']]);
        $partial = $this->partialPath($request->input('upload_id'));
        if ($partial && is_file($partial)) {
            @unlink($partial);
        }

        return response()->json(['success' => true]);
    }

    private function partialPath(string $uploadId): ?string
    {
        $dir = config('uploads.directory', '/tmp/pocketdev-uploads') . '/.partial';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }

        return $dir . '/' . $uploadId . '.part';
    }

    /** Partials untouched for a day were abandoned (app closed for good). */
    private function cleanupStalePartials(string $dir): void
    {
        if (random_int(1, 20) !== 1) {
            return;
        }
        foreach (glob($dir . '/*.part') ?: [] as $file) {
            if (@filemtime($file) < time() - 86400) {
                @unlink($file);
            }
        }
    }

    /**
     * Delete a previously uploaded file.
     */
    public function delete(Request $request): JsonResponse
    {
        $request->validate([
            'path' => 'required|string',
        ]);

        $path = $request->input('path');

        // Security: Use realpath() for canonical path validation (protects against symlink attacks)
        $uploadDir = config('uploads.directory', '/tmp/pocketdev-uploads');
        $uploadRoot = realpath($uploadDir);
        $resolved = $path ? realpath($path) : false;

        if (!$uploadRoot || !$resolved || !str_starts_with($resolved, $uploadRoot . DIRECTORY_SEPARATOR)) {
            return response()->json([
                'success' => false,
                'error' => 'Access denied',
            ], 403);
        }

        if (is_file($resolved)) {
            unlink($resolved);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Format bytes to human readable string.
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}
