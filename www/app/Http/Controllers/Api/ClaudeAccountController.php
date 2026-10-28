<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ClaudeAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Switch between saved Claude Code subscription accounts (see ClaudeAccountService).
 */
class ClaudeAccountController extends Controller
{
    public function __construct(private ClaudeAccountService $accounts)
    {
    }

    public function index(): JsonResponse
    {
        return $this->run(fn() => $this->accounts->list());
    }

    public function switch(string $id): JsonResponse
    {
        return $this->run(fn() => $this->accounts->switchTo($id));
    }

    public function add(): JsonResponse
    {
        return $this->run(fn() => $this->accounts->beginAdd());
    }

    public function cancelAdd(): JsonResponse
    {
        return $this->run(fn() => $this->accounts->cancelAdd());
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return $this->run(fn() => $this->accounts->rename($id, (string) $request->input('label')));
    }

    public function destroy(string $id): JsonResponse
    {
        return $this->run(fn() => $this->accounts->remove($id));
    }

    private function run(callable $fn): JsonResponse
    {
        try {
            return response()->json(['ok' => true] + $this->accounts->locked($fn));
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
