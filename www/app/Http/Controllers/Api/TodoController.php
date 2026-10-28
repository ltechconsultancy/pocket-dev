<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TodoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Todo app API for the UI (agents use `pd todo`). Everything done here is by the "user".
 */
class TodoController extends Controller
{
    private const FIELDS = ['name', 'title', 'notes', 'client_id', 'project_id', 'parent_id', 'deadline', 'session_id', 'app_tray_item_id', 'sort_order'];

    public function __construct(private TodoService $todos)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->todos->snapshot());
    }

    public function store(Request $request, string $type): JsonResponse
    {
        return $this->guard(function () use ($request, $type) {
            $item = $this->todos->create($type, $request->only(self::FIELDS), 'user');

            return ['item' => $item->toTodoArray()];
        });
    }

    public function update(Request $request, string $type, string $id): JsonResponse
    {
        return $this->guard(function () use ($request, $type, $id) {
            $item = $this->find($type, $id);
            if ($request->has('done')) {
                $item = $this->todos->setDone($type, $item, $request->boolean('done'), 'user');
            }
            $data = $request->only(self::FIELDS);
            if ($data) {
                $item = $this->todos->update($type, $item, $data);
            }

            return ['item' => $item->fresh()->toTodoArray()];
        });
    }

    public function destroy(string $type, string $id): JsonResponse
    {
        return $this->guard(function () use ($type, $id) {
            $this->find($type, $id)->delete();

            return ['deleted' => true];
        });
    }

    private function find(string $type, string $id)
    {
        $item = TodoService::findKey($this->todos->modelClass($type), $id);
        if (!$item) {
            throw new InvalidArgumentException('Not found');
        }

        return $item;
    }

    private function guard(callable $fn): JsonResponse
    {
        try {
            return response()->json(['ok' => true] + $fn());
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
