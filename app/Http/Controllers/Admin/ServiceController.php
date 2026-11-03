<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveServiceRequest;
use App\Models\BusinessProfile;
use App\Models\Service;
use App\Support\ServiceCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['all', 'active', 'inactive'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $search = trim($validated['search'] ?? '');
        $status = $validated['status'] ?? 'all';

        $services = Service::query()
            ->ordered()
            ->when($status === 'active', fn ($q) => $q->active())
            ->when($status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('name', 'like', $like)
                        ->orWhere('slug', 'like', $like)
                        ->orWhere('description', 'like', $like);
                });
            })
            ->paginate($validated['per_page'] ?? 20);

        $usage = $this->usageCounts(
            $services->getCollection()->pluck('slug')->all(),
        );

        return response()->json([
            'data' => $services->getCollection()
                ->map(fn (Service $service) => $this->transform($service, $usage[$service->slug] ?? 0))
                ->values(),
            'meta' => [
                'current_page' => $services->currentPage(),
                'last_page' => $services->lastPage(),
                'per_page' => $services->perPage(),
                'total' => $services->total(),
                'from' => $services->firstItem(),
                'to' => $services->lastItem(),
            ],
            'counts' => [
                'all' => Service::query()->count(),
                'active' => Service::query()->active()->count(),
                'inactive' => Service::query()->where('is_active', false)->count(),
            ],
        ]);
    }

    public function store(SaveServiceRequest $request): JsonResponse
    {
        $data = $request->validated();

        $maxOrder = (int) Service::query()->max('sort_order');

        $service = Service::query()->create([
            'slug' => $data['slug'],
            'name' => $data['name'],
            'description' => $data['description'],
            'icon' => $data['icon'],
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? ($maxOrder + 1),
        ]);

        ServiceCatalog::forgetCache();

        return response()->json([
            'message' => 'Service created.',
            'service' => $this->transform($service, 0),
        ], 201);
    }

    public function show(Service $service): JsonResponse
    {
        $usage = $this->usageCounts([$service->slug]);

        return response()->json([
            'service' => $this->transform($service, $usage[$service->slug] ?? 0),
        ]);
    }

    public function update(SaveServiceRequest $request, Service $service): JsonResponse
    {
        $data = $request->validated();

        // Slug stays stable once created so existing client selections keep working.
        unset($data['slug']);

        $service->fill($data);
        $service->save();

        ServiceCatalog::forgetCache();

        $usage = $this->usageCounts([$service->slug]);

        return response()->json([
            'message' => 'Service updated.',
            'service' => $this->transform($service, $usage[$service->slug] ?? 0),
        ]);
    }

    public function destroy(Service $service): JsonResponse
    {
        $usage = $this->usageCounts([$service->slug]);
        $clients = $usage[$service->slug] ?? 0;

        if ($clients > 0) {
            return response()->json([
                'message' => "This service is used by {$clients} client".($clients === 1 ? '' : 's').'. Deactivate it instead of deleting.',
            ], 422);
        }

        $service->delete();
        ServiceCatalog::forgetCache();

        return response()->json(['message' => 'Service deleted.']);
    }

    /** Public catalog for registration / pickers (active only). */
    public function catalog(): JsonResponse
    {
        $services = Service::query()->active()->ordered()->get();

        return response()->json([
            'data' => $services->map(fn (Service $service) => [
                'value' => $service->slug,
                'label' => $service->name,
                'description' => $service->description,
                'icon' => $service->icon,
            ])->values(),
        ]);
    }

    private function transform(Service $service, int $clientCount): array
    {
        return [
            'id' => $service->id,
            'slug' => $service->slug,
            'name' => $service->name,
            'description' => $service->description,
            'icon' => $service->icon,
            'is_active' => $service->is_active,
            'sort_order' => $service->sort_order,
            'client_count' => $clientCount,
            'created_at' => $service->created_at?->toIso8601String(),
            'updated_at' => $service->updated_at?->toIso8601String(),
        ];
    }

    /** @param  array<int, string>  $slugs */
    private function usageCounts(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        $counts = array_fill_keys($slugs, 0);

        $profiles = BusinessProfile::query()
            ->whereNotNull('services')
            ->get(['services']);

        foreach ($profiles as $profile) {
            $selected = is_array($profile->services) ? $profile->services : [];
            foreach ($selected as $slug) {
                if (array_key_exists($slug, $counts)) {
                    $counts[$slug]++;
                }
            }
        }

        return $counts;
    }
}
