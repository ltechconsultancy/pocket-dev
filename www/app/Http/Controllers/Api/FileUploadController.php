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
    // The browser sends the file in numbered pieces (raw body, metadata in the query string). The
    // pieces may arrive in any order and more than once: Android's Background Fetch (uploads that
    // keep going when the PWA is in the background) does not promise an order, and the in-app
    // fallback re-sends only what is missing after an interruption.
    // Data: {upload dir}/.partial/{upload_id}.part + {upload_id}.json (received chunk indices).

    private const MIN_CHUNK = 1024 * 1024;
    private const MAX_CHUNK = 64 * 1024 * 1024;

    public function uploadChunk(Request $request): JsonResponse
    {
        $maxBytes = config('uploads.max_size_mb', 250) * 1024 * 1024;
        $v = validator($request->query(), [
            'upload_id' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/'],
            'filename' => 'required|string|max:255',
            'total_size' => 'required|integer|min:0|max:' . $maxBytes,
            'chunk_size' => 'required|integer|min:' . self::MIN_CHUNK . '|max:' . self::MAX_CHUNK,
            'index' => 'required|integer|min:0',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'error' => $v->errors()->first()], 422);
        }

        $uploadId = $request->query('upload_id');
        $total = (int) $request->query('total_size');
        $chunkSize = (int) $request->query('chunk_size');
        $index = (int) $request->query('index');
        $count = max(1, (int) ceil($total / $chunkSize));
        if ($index >= $count) {
            return response()->json(['success' => false, 'error' => 'Chunk index out of range'], 422);
        }
        $expected = $index === $count - 1 ? $total - $index * $chunkSize : $chunkSize;

        [$partial, $metaPath] = $this->partialPaths($uploadId);
        if (!$partial) {
            return response()->json(['success' => false, 'error' => 'Failed to create upload directory'], 500);
        }
        $this->cleanupStalePartials(dirname($partial));

        $lock = fopen($metaPath . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            return response()->json(['success' => false, 'error' => 'Upload failed'], 500);
        }

        try {
            $meta = $this->readMeta($metaPath) ?? [
                'filename' => $request->query('filename'),
                'total' => $total,
                'chunk_size' => $chunkSize,
                'chunks' => [],
            ];
            if (!empty($meta['path'])) {
                return response()->json($this->doneResponse($meta)); // already complete (duplicate request)
            }
            if ($meta['total'] !== $total || $meta['chunk_size'] !== $chunkSize) {
                return response()->json(['success' => false, 'error' => 'Upload parameters changed'], 409);
            }

            if (!in_array($index, $meta['chunks'], true)) {
                // Stream the raw body to a temp file first: a cut-off request never lands in the upload
                $tmp = $partial . '.' . $index . '.tmp';
                $in = fopen('php://input', 'rb');
                $out = fopen($tmp, 'wb');
                $written = stream_copy_to_stream($in, $out);
                fclose($in);
                fclose($out);
                if ($written !== $expected) {
                    @unlink($tmp);

                    return response()->json(['success' => false, 'error' => 'Incomplete chunk', 'received_chunks' => $meta['chunks']], 400);
                }

                $fp = fopen($partial, 'c+b');
                $src = fopen($tmp, 'rb');
                fseek($fp, $index * $chunkSize);
                stream_copy_to_stream($src, $fp);
                fflush($fp);
                fclose($src);
                fclose($fp);
                @unlink($tmp);

                $meta['chunks'][] = $index;
                sort($meta['chunks']);
            }

            if (count($meta['chunks']) < $count) {
                $this->writeMeta($metaPath, $meta);

                return response()->json([
                    'success' => true,
                    'done' => false,
                    'received_chunks' => $meta['chunks'],
                    'total_chunks' => $count,
                ]);
            }

            // Complete: move to its final name, exactly like a normal upload
            $original = $meta['filename'];
            $extension = preg_replace('/[^a-zA-Z0-9]+/', '', (string) pathinfo($original, PATHINFO_EXTENSION));
            $safeName = Str::slug(pathinfo($original, PATHINFO_FILENAME)) ?: 'file';
            $uniqueName = $safeName . '-' . Str::random(8) . ($extension ? ('.' . $extension) : '');
            $path = config('uploads.directory', '/tmp/pocketdev-uploads') . '/' . $uniqueName;
            if (!rename($partial, $path)) {
                return response()->json(['success' => false, 'error' => 'Upload failed'], 500);
            }
            @chmod($path, 0644);
            $meta['path'] = $path;
            $meta['size'] = filesize($path);
            $this->writeMeta($metaPath, $meta); // kept a while so a late duplicate/status call gets the path

            return response()->json($this->doneResponse($meta));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Which chunks the server has (to resume), or the final path when the upload is complete. */
    public function uploadStatus(Request $request): JsonResponse
    {
        $request->validate(['upload_id' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/']]);
        [, $metaPath] = $this->partialPaths($request->input('upload_id'));
        $meta = $metaPath ? $this->readMeta($metaPath) : null;
        if ($meta && !empty($meta['path'])) {
            return response()->json($this->doneResponse($meta));
        }

        return response()->json([
            'success' => true,
            'done' => false,
            'exists' => (bool) $meta,
            'received_chunks' => $meta['chunks'] ?? [],
        ]);
    }

    /** Cancel a chunked upload: drop what arrived so far. */
    public function uploadCancel(Request $request): JsonResponse
    {
        $request->validate(['upload_id' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/']]);
        [$partial, $metaPath] = $this->partialPaths($request->input('upload_id'));
        if ($partial) {
            $meta = $this->readMeta($metaPath);
            foreach (array_merge([$partial, $metaPath, $metaPath . '.lock'], glob($partial . '.*.tmp') ?: []) as $f) {
                @unlink($f);
            }
            // Already complete: remove the finished file too (it was cancelled after all)
            if (!empty($meta['path']) && is_file($meta['path'])) {
                @unlink($meta['path']);
            }
        }

        return response()->json(['success' => true]);
    }

    private function doneResponse(array $meta): array
    {
        return [
            'success' => true,
            'done' => true,
            'path' => $meta['path'],
            'filename' => $meta['filename'],
            'size' => $meta['size'] ?? (is_file($meta['path']) ? filesize($meta['path']) : 0),
            'size_formatted' => $this->formatFileSize((int) ($meta['size'] ?? 0)),
        ];
    }

    private function readMeta(string $metaPath): ?array
    {
        $data = is_file($metaPath) ? json_decode((string) file_get_contents($metaPath), true) : null;

        return is_array($data) ? $data : null;
    }

    private function writeMeta(string $metaPath, array $meta): void
    {
        file_put_contents($metaPath . '.new', json_encode($meta));
        rename($metaPath . '.new', $metaPath);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function partialPaths(string $uploadId): array
    {
        $dir = config('uploads.directory', '/tmp/pocketdev-uploads') . '/.partial';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return [null, null];
        }

        return [$dir . '/' . $uploadId . '.part', $dir . '/' . $uploadId . '.json'];
    }

    /** Uploads untouched for a day were abandoned (app closed for good). */
    private function cleanupStalePartials(string $dir): void
    {
        if (random_int(1, 20) !== 1) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file) && @filemtime($file) < time() - 86400) {
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
