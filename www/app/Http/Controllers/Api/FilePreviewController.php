<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PathValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FilePreviewController extends Controller
{
    /**
     * Preview a file's contents.
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'path' => 'required|string',
        ]);

        $path = $request->input('path');

        // Validate path is within allowed directories
        $validation = $this->validatePath($path);
        if ($validation['error']) {
            return response()->json($validation['response'], $validation['status']);
        }

        $realPath = $validation['realPath'];

        // Check if it's a file (not directory)
        if (is_dir($realPath)) {
            return response()->json([
                'exists' => true,
                'error' => 'Path is a directory, not a file',
            ], 400);
        }

        // Check if readable
        if (!is_readable($realPath)) {
            return response()->json([
                'exists' => true,
                'readable' => false,
                'error' => 'Permission denied: cannot read file',
            ], 403);
        }

        // Get file info
        $fileSize = filesize($realPath);
        $extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
        $filename = basename($realPath);

        // Detect image files by extension - render visually instead of as binary
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico', 'avif', 'tiff', 'tif', 'svg'];
        $videoExtensions = ['mp4', 'webm', 'mov', 'ogg', 'm4v'];
        if (in_array($extension, $imageExtensions, true) || in_array($extension, $videoExtensions, true)) {
            return response()->json([
                'exists' => true,
                'readable' => true,
                'size' => $fileSize,
                'size_formatted' => $this->formatBytes($fileSize),
                'extension' => $extension,
                'filename' => $filename,
                'path' => $path,
                'is_image' => in_array($extension, $imageExtensions, true),
                'is_video' => in_array($extension, $videoExtensions, true),
            ]);
        }

        // Check file size
        $maxFileSize = config('ai.file_preview.max_file_size', 2 * 1024 * 1024);
        if ($fileSize > $maxFileSize) {
            return response()->json([
                'exists' => true,
                'readable' => true,
                'size' => $fileSize,
                'size_formatted' => $this->formatBytes($fileSize),
                'extension' => $extension,
                'filename' => $filename,
                'path' => $path,
                'too_large' => true,
                'error' => 'File too large for preview (max ' . $this->formatBytes($maxFileSize) . ')',
            ], 200);
        }

        // Read file content
        $content = file_get_contents($realPath);

        if ($content === false) {
            return response()->json([
                'exists' => true,
                'readable' => false,
                'error' => 'Failed to read file',
            ], 500);
        }

        // Detect if content is binary
        $isBinary = $this->isBinaryContent($content);

        if ($isBinary) {
            return response()->json([
                'exists' => true,
                'readable' => true,
                'size' => $fileSize,
                'size_formatted' => $this->formatBytes($fileSize),
                'extension' => $extension,
                'filename' => $filename,
                'path' => $path,
                'binary' => true,
                'error' => 'Binary file cannot be previewed as text',
            ], 200);
        }

        // Validate UTF-8 encoding (required for JSON response)
        if (!mb_check_encoding($content, 'UTF-8')) {
            return response()->json([
                'exists' => true,
                'readable' => true,
                'size' => $fileSize,
                'size_formatted' => $this->formatBytes($fileSize),
                'extension' => $extension,
                'filename' => $filename,
                'path' => $path,
                'encoding_error' => true,
                'error' => 'File contains invalid UTF-8 encoding',
            ], 200);
        }

        return response()->json([
            'exists' => true,
            'readable' => true,
            'size' => $fileSize,
            'size_formatted' => $this->formatBytes($fileSize),
            'extension' => $extension,
            'filename' => $filename,
            'path' => $path,
            'content' => $content,
            'is_markdown' => in_array($extension, ['md', 'markdown']),
            'is_html' => in_array($extension, ['html', 'htm']),
        ]);
    }

    /**
     * Write content to a file.
     */
    public function write(Request $request): JsonResponse
    {
        $request->validate([
            'path' => 'required|string',
            'content' => 'present|string', // Allow empty files
        ]);

        $path = $request->input('path');
        $content = $request->input('content');

        // For new files, we can't use realpath (file doesn't exist yet)
        // But for editing existing files via file preview, the file should exist
        $realPath = PathValidator::validate($path);

        if ($realPath === null) {
            // Return generic error to prevent information disclosure
            // (don't reveal whether file exists outside allowed paths)
            return response()->json([
                'success' => false,
                'error' => 'File not found or access denied',
            ], 403);
        }

        // Require a regular file (not a directory, device, pipe, etc.)
        if (!is_file($realPath)) {
            return response()->json([
                'success' => false,
                'error' => 'Path is not a regular file',
            ], 400);
        }

        // Check if writable
        if (!is_writable($realPath)) {
            return response()->json([
                'success' => false,
                'error' => 'File is not writable',
            ], 403);
        }

        // Write the file with exclusive lock to prevent concurrent write corruption
        $result = file_put_contents($realPath, $content, LOCK_EX);

        if ($result === false) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to write file',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'bytes_written' => $result,
            'size_formatted' => $this->formatBytes($result),
        ]);
    }

    /**
     * Download a file.
     */
    public function download(Request $request): BinaryFileResponse|JsonResponse
    {
        $request->validate([
            'path' => 'required|string',
        ]);

        $path = $request->input('path');

        $validation = $this->validatePath($path);
        if ($validation['error']) {
            return response()->json($validation['response'], $validation['status']);
        }

        $realPath = $validation['realPath'];

        if (!is_file($realPath)) {
            return response()->json(['error' => 'Path is not a regular file'], 400);
        }

        if (!is_readable($realPath)) {
            return response()->json(['error' => 'Permission denied: cannot read file'], 403);
        }

        return response()->download($realPath);
    }

    /**
     * Stream an image or video inline so the preview can render it.
     */
    public function media(Request $request): BinaryFileResponse|JsonResponse
    {
        $request->validate([
            'path' => 'required|string',
        ]);

        $validation = $this->validatePath($request->input('path'));
        if ($validation['error']) {
            return response()->json($validation['response'], $validation['status']);
        }

        $realPath = $validation['realPath'];

        if (!is_file($realPath) || !is_readable($realPath)) {
            return response()->json(['error' => 'File not found or access denied'], 404);
        }

        // Video: only send a short slice per request. The player asks for the
        // next slice as it plays, instead of downloading the whole file on play.
        // MP4s with the index at the end cannot start until that index arrives,
        // so those are rewritten once with the index at the front.
        if ($this->isVideoPath($realPath)) {
            $realPath = $this->fastStartVideo($realPath);
            $this->clampVideoRange($request, $realPath);
        }

        return response()->file($realPath, [
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-transform',
        ]);
    }

    /**
     * Return a playable path. MP4/MOV with the index (moov) after the media
     * data is copied once with the index moved to the front.
     */
    private function fastStartVideo(string $realPath): string
    {
        $extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
        if (!in_array($extension, ['mp4', 'mov', 'm4v'], true)) {
            return $realPath;
        }

        $atoms = $this->readTopLevelAtoms($realPath);
        if ($atoms === null) {
            return $realPath;
        }

        $moov = null;
        $mdat = null;
        foreach ($atoms as $atom) {
            if ($atom['type'] === 'moov' && $moov === null) {
                $moov = $atom;
            }
            if ($atom['type'] === 'mdat' && $mdat === null) {
                $mdat = $atom;
            }
        }

        if ($moov === null || $mdat === null || $moov['offset'] < $mdat['offset']) {
            return $realPath;
        }

        if ($moov['size'] > 32 * 1024 * 1024) {
            return $realPath;
        }

        $cacheDir = '/tmp/pocketdev-video-cache';
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
            return $realPath;
        }

        $cache = $cacheDir.'/'.sha1($realPath.'|'.filemtime($realPath).'|'.filesize($realPath)).'.mp4';
        if (is_file($cache) && filesize($cache) > 0) {
            return $cache;
        }

        $moovData = file_get_contents($realPath, false, null, $moov['offset'], $moov['size']);
        if ($moovData === false || strlen($moovData) !== $moov['size']) {
            return $realPath;
        }

        if (str_contains($moovData, 'cmov')) {
            return $realPath;
        }

        $moovData = $this->shiftChunkOffsets($moovData, $moov['size']);
        $tmp = $cache.'.'.getmypid().'.tmp';
        $in = fopen($realPath, 'rb');
        $out = fopen($tmp, 'wb');
        if ($in === false || $out === false) {
            if (is_resource($in)) {
                fclose($in);
            }
            if (is_resource($out)) {
                fclose($out);
            }

            return $realPath;
        }

        foreach ($atoms as $atom) {
            if ($atom['type'] === 'moov' || $atom['offset'] >= $mdat['offset']) {
                continue;
            }
            $this->copyStreamRange($in, $out, $atom['offset'], $atom['size']);
        }

        fwrite($out, $moovData);
        $this->copyStreamRange($in, $out, $mdat['offset'], $moov['offset'] - $mdat['offset']);
        foreach ($atoms as $atom) {
            if ($atom['offset'] > $moov['offset']) {
                $this->copyStreamRange($in, $out, $atom['offset'], $atom['size']);
            }
        }
        fclose($in);
        fclose($out);

        if (!rename($tmp, $cache)) {
            @unlink($tmp);

            return $realPath;
        }

        return $cache;
    }

    /**
     * @return list<array{type: string, offset: int, size: int}>|null
     */
    private function readTopLevelAtoms(string $path): ?array
    {
        $size = filesize($path);
        if ($size === false || $size < 8) {
            return null;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        $atoms = [];
        $offset = 0;
        while ($offset + 8 <= $size) {
            fseek($handle, $offset);
            $header = fread($handle, 8);
            if (strlen($header) < 8) {
                fclose($handle);

                return null;
            }

            $atomSize = unpack('N', substr($header, 0, 4))[1];
            $type = substr($header, 4, 4);
            $headerSize = 8;
            if ($atomSize === 1) {
                $large = fread($handle, 8);
                if (strlen($large) < 8) {
                    fclose($handle);

                    return null;
                }
                $atomSize = $this->unpackBe64($large);
                $headerSize = 16;
            } elseif ($atomSize === 0) {
                $atomSize = $size - $offset;
            }

            if ($atomSize < $headerSize || $offset + $atomSize > $size) {
                fclose($handle);

                return null;
            }

            $atoms[] = ['type' => $type, 'offset' => $offset, 'size' => $atomSize];
            $offset += $atomSize;
        }

        fclose($handle);

        return $atoms;
    }

    private function shiftChunkOffsets(string $moov, int $shift): string
    {
        $length = strlen($moov);
        $offset = 0;
        while ($offset + 8 <= $length) {
            $atomSize = unpack('N', substr($moov, $offset, 4))[1];
            $type = substr($moov, $offset + 4, 4);
            if ($atomSize < 8 || $offset + $atomSize > $length) {
                break;
            }

            $dataStart = $offset + 8;
            if ($type === 'stco') {
                $count = unpack('N', substr($moov, $dataStart + 4, 4))[1];
                $pos = $dataStart + 8;
                for ($i = 0; $i < $count; $i++) {
                    $value = unpack('N', substr($moov, $pos, 4))[1];
                    $moov = substr_replace($moov, pack('N', $value + $shift), $pos, 4);
                    $pos += 4;
                }
            } elseif ($type === 'co64') {
                $count = unpack('N', substr($moov, $dataStart + 4, 4))[1];
                $pos = $dataStart + 8;
                for ($i = 0; $i < $count; $i++) {
                    $value = $this->unpackBe64(substr($moov, $pos, 8));
                    $moov = substr_replace($moov, $this->packBe64($value + $shift), $pos, 8);
                    $pos += 8;
                }
            } elseif (in_array($type, ['moov', 'trak', 'mdia', 'minf', 'stbl', 'edts', 'udta', 'mvex'], true)) {
                $inner = $this->shiftChunkOffsets(substr($moov, $dataStart, $atomSize - 8), $shift);
                $moov = substr_replace($moov, $inner, $dataStart, $atomSize - 8);
            }

            $offset += $atomSize;
        }

        return $moov;
    }

    private function unpackBe64(string $bytes): int
    {
        $parts = unpack('N2', $bytes);

        return ($parts[1] << 32) | $parts[2];
    }

    private function packBe64(int $value): string
    {
        return pack('N2', ($value >> 32) & 0xFFFFFFFF, $value & 0xFFFFFFFF);
    }

    private function copyStreamRange($in, $out, int $offset, int $length): void
    {
        fseek($in, $offset);
        $left = $length;
        while ($left > 0) {
            $chunk = fread($in, min(1024 * 1024, $left));
            if ($chunk === false || $chunk === '') {
                break;
            }
            fwrite($out, $chunk);
            $left -= strlen($chunk);
        }
    }

    private function isVideoPath(string $realPath): bool
    {
        $extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));

        return in_array($extension, ['mp4', 'webm', 'mov', 'ogg', 'm4v'], true);
    }

    /**
     * Shrink an open or oversized Range so one response stays about 1 MB.
     * Small probes and tail requests (needed to find the MP4 header) stay intact.
     */
    private function clampVideoRange(Request $request, string $realPath): void
    {
        $size = filesize($realPath);
        if ($size === false || $size < 2) {
            return;
        }

        $max = 1024 * 1024;
        $last = $size - 1;
        $header = $request->header('Range');

        if (!is_string($header) || !preg_match('/bytes=(\d*)-(\d*)/', $header, $matches)) {
            $end = min($last, $max - 1);
            $request->headers->set('Range', "bytes=0-$end");

            return;
        }

        $startRaw = $matches[1];
        $endRaw = $matches[2];

        if ($startRaw === '' && $endRaw !== '') {
            $suffix = (int) $endRaw;
            if ($suffix > $max) {
                $request->headers->set('Range', 'bytes=-'.$max);
            }

            return;
        }

        $start = $startRaw === '' ? 0 : (int) $startRaw;
        if ($start > $last) {
            return;
        }

        $end = $endRaw === '' ? $last : (int) $endRaw;
        $end = min($end, $last, $start + $max - 1);
        $request->headers->set('Range', "bytes=$start-$end");
    }

    /**
     * Check if a path exists and is readable (quick check without content).
     */
    public function check(Request $request): JsonResponse
    {
        $request->validate([
            'path' => 'required|string',
        ]);

        $path = $request->input('path');

        // Validate path is within allowed directories
        $validation = $this->validatePath($path);
        if ($validation['error']) {
            // Don't reveal whether file exists outside allowed paths
            return response()->json(['exists' => false]);
        }

        $realPath = $validation['realPath'];

        return response()->json([
            'exists' => true,
            'is_file' => is_file($realPath),
            'readable' => is_readable($realPath),
        ]);
    }

    /**
     * Validate that a path exists and is within allowed directories.
     *
     * @return array{error: bool, realPath?: string, response?: array, status?: int}
     */
    private function validatePath(string $path): array
    {
        // Validate path is within allowed directories
        // PathValidator::validate() returns null for both non-existent paths
        // (realpath returns false) and paths outside allowed directories
        $realPath = PathValidator::validate($path);

        if ($realPath === null) {
            // Return generic error to prevent information disclosure
            // (don't reveal whether file exists outside allowed paths)
            return [
                'error' => true,
                'response' => ['exists' => false, 'error' => 'File not found or access denied'],
                'status' => 404,
            ];
        }

        return [
            'error' => false,
            'realPath' => $realPath,
        ];
    }

    /**
     * Format bytes to human readable string.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;
        $size = $bytes;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 2) . ' ' . $units[$unitIndex];
    }

    /**
     * Check if content appears to be binary.
     */
    private function isBinaryContent(string $content): bool
    {
        // Check first 8KB for null bytes or high concentration of non-printable chars
        $sample = substr($content, 0, 8192);

        // Null bytes are a strong indicator of binary
        if (strpos($sample, "\0") !== false) {
            return true;
        }

        // Count non-printable characters (excluding common whitespace)
        $nonPrintable = 0;
        $length = strlen($sample);

        for ($i = 0; $i < $length; $i++) {
            $ord = ord($sample[$i]);
            // Allow: tab (9), newline (10), carriage return (13), and printable ASCII (32-126)
            // Also allow UTF-8 continuation bytes (128-255)
            if ($ord < 9 || ($ord > 13 && $ord < 32) || $ord === 127) {
                $nonPrintable++;
            }
        }

        // If more than 10% non-printable, consider it binary
        return ($nonPrintable / max(1, $length)) > 0.1;
    }
}
