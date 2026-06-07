<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Backup management for super admins.
 *
 * Backups are stored as JSON snapshots on the local filesystem disk under
 * `storage/app/backups/`. Each snapshot is a single file containing the
 * full row data for every table in the BACKUP_TABLES list, plus metadata
 * (created_at, created_by, app_version).
 *
 * This is intentionally portable — no mysqldump or external tooling needed.
 */
class BackupController extends Controller
{
    private const BACKUP_DIR = 'backups';

    /**
     * Tables included in every backup. Order is significant for restore
     * (parents before children).
     */
    private const BACKUP_TABLES = [
        'shops',
        'branches',
        'users',
        'categories',
        'products',
        'customers',
        'invoices',
        'invoice_items',
        'payments',
        'stock_movements',
        'expense_categories',
        'expenses',
        'audit_trails',
        'sales',
    ];

    public function index(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'super_admin') {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $disk = Storage::disk('local');
        if (!$disk->exists(self::BACKUP_DIR)) {
            $disk->makeDirectory(self::BACKUP_DIR);
        }

        $files = $disk->files(self::BACKUP_DIR);
        $items = [];

        foreach ($files as $path) {
            if (!str_ends_with($path, '.json')) {
                continue;
            }
            $items[] = [
                'filename' => basename($path),
                'size' => $disk->size($path),
                'size_human' => $this->humanSize($disk->size($path)),
                'created_at' => date('c', $disk->lastModified($path)),
            ];
        }

        // Newest first.
        usort($items, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'super_admin') {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $disk = Storage::disk('local');
            if (!$disk->exists(self::BACKUP_DIR)) {
                $disk->makeDirectory(self::BACKUP_DIR);
            }

            $stamp = now()->format('Y-m-d_His');
            $filename = "vendex-backup-{$stamp}.json";

            $payload = [
                'meta' => [
                    'created_at' => now()->toIso8601String(),
                    'created_by' => $user->email,
                    'app' => config('app.name'),
                    'tables' => self::BACKUP_TABLES,
                ],
                'data' => [],
            ];

            foreach (self::BACKUP_TABLES as $table) {
                if (!\Schema::hasTable($table)) {
                    $payload['data'][$table] = [];
                    continue;
                }

                $rows = [];
                DB::table($table)->orderBy('created_at')->chunk(500, function ($chunk) use (&$rows) {
                    foreach ($chunk as $row) {
                        $rows[] = (array) $row;
                    }
                });
                $payload['data'][$table] = $rows;
            }

            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if ($json === false) {
                throw new \RuntimeException('Failed to encode backup payload');
            }
            $disk->put(self::BACKUP_DIR . '/' . $filename, $json);

            $path = self::BACKUP_DIR . '/' . $filename;
            return response()->json([
                'success' => true,
                'message' => 'Backup created successfully',
                'data' => [
                    'filename' => $filename,
                    'size' => $disk->size($path),
                    'size_human' => $this->humanSize($disk->size($path)),
                    'created_at' => date('c', $disk->lastModified($path)),
                ],
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Backup creation failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create backup: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function download(Request $request, string $filename)
    {
        $user = $request->user();
        if ($user->role !== 'super_admin') {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $disk = Storage::disk('local');
        $safe = basename($filename); // strip any traversal
        $path = self::BACKUP_DIR . '/' . $safe;

        if (!$disk->exists($path)) {
            return response()->json(['success' => false, 'message' => 'Backup not found'], 404);
        }

        return new StreamedResponse(function () use ($disk, $path) {
            $stream = $disk->readStream($path);
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="' . $safe . '"',
            'Content-Length' => (string) $disk->size($path),
        ]);
    }

    public function destroy(Request $request, string $filename)
    {
        $user = $request->user();
        if ($user->role !== 'super_admin') {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $disk = Storage::disk('local');
        $safe = basename($filename);
        $path = self::BACKUP_DIR . '/' . $safe;

        if (!$disk->exists($path)) {
            return response()->json(['success' => false, 'message' => 'Backup not found'], 404);
        }

        $disk->delete($path);

        return response()->json([
            'success' => true,
            'message' => 'Backup deleted',
        ]);
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }
}
