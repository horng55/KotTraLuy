<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkspaceNotification;
use App\Services\WorkspaceNotifier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotificationController
{
    private function inbox(Request $request)
    {
        $user = $request->user();
        abort_unless($user->business_id && $user->status === 'active', 403);
        $business = $user->business;
        abort_unless($business, 403);
        app(\App\Services\InventoryAlertService::class)->refresh($business);
        return WorkspaceNotification::where('business_id', $user->business_id)
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->id)
            ->when($user->accessRole() === 'staff', fn ($query) => $query->where('owner_only', false));
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'unread' => ['sometimes', 'boolean'],
            'category' => ['nullable', Rule::in(['sales', 'inventory', 'expenses', 'team', 'financing', 'system'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = $this->inbox($request);
        $unread = (clone $query)->whereNull('read_at')->count();
        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }
        if (!empty($filters['category'])) {
            $types = array_keys(array_filter(WorkspaceNotifier::TYPES, fn ($definition) => $definition[0] === $filters['category']));
            $query->whereIn('type', $types);
        }
        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 20);
        $page->setCollection($page->getCollection()->map(function ($notification) {
            $data = $notification->data;
            $definition = WorkspaceNotifier::TYPES[$notification->type] ?? null;
            return [
                'id' => $notification->id, 'type' => $notification->type,
                'category' => $definition[0] ?? 'system', 'params' => $data['params'] ?? [],
                'entity_type' => $data['entity_type'] ?? null, 'entity_id' => $data['entity_id'] ?? null,
                // Never trust a stored or client-supplied URL. Resolve a fixed SPA target.
                'action_page' => $definition[1] ?? null,
                'read_at' => $notification->read_at?->toIso8601String(),
                'created_at' => $notification->created_at->toIso8601String(),
            ];
        }));
        return response()->json([...$page->toArray(), 'unread_count' => $unread])
            ->header('Cache-Control', 'no-store, private');
    }

    public function count(Request $request)
    {
        return response()->json(['unread_count' => $this->inbox($request)->whereNull('read_at')->count()])
            ->header('Cache-Control', 'no-store, private');
    }

    public function read(Request $request, string $notification)
    {
        $item = $this->inbox($request)->whereKey($notification)->firstOrFail();
        $this->inbox($request)->whereKey($item->id)->whereNull('read_at')->update(['read_at' => now()]);
        return $this->count($request);
    }

    public function readAll(Request $request)
    {
        $this->inbox($request)->whereNull('read_at')->update(['read_at' => now()]);
        return $this->count($request);
    }

    public function unread(Request $request, string $notification)
    {
        $item = $this->inbox($request)->whereKey($notification)->firstOrFail();
        $this->inbox($request)->whereKey($item->id)->update(['read_at' => null]);
        return $this->count($request);
    }
}
