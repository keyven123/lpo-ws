<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use App\Models\CandidateProfile;
use App\Models\Service;
use App\Models\User;
use App\Support\AccountOptions;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function show(): JsonResponse
    {
        $talentCounts = $this->talentCounts();
        $clientCounts = $this->clientCounts();
        $serviceCounts = [
            'all' => Service::query()->count(),
            'active' => Service::query()->active()->count(),
        ];

        $activeClients = BusinessProfile::query()
            ->where('status', BusinessProfile::STATUS_ACTIVE)
            ->whereHas('user', fn ($q) => $q->where('type', User::TYPE_CLIENT))
            ->get(['monthly_value', 'currency', 'team_size']);

        $monthlyValueByCurrency = [];
        $totalSeats = 0;
        foreach ($activeClients as $profile) {
            $totalSeats += (int) ($profile->team_size ?? 0);
            if ($profile->monthly_value === null) {
                continue;
            }
            $currency = $profile->currency ?: 'USD';
            $monthlyValueByCurrency[$currency] = ($monthlyValueByCurrency[$currency] ?? 0) + (float) $profile->monthly_value;
        }

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'talents' => $talentCounts['all'],
                'clients' => $clientCounts['all'],
                'active_talents' => $talentCounts['active'] ?? 0,
                'active_clients' => $clientCounts['active'] ?? 0,
                'services' => $serviceCounts['active'],
                'services_total' => $serviceCounts['all'],
                'pipeline_talents' => ($talentCounts['for_assessment'] ?? 0) + ($talentCounts['for_evaluation'] ?? 0),
                'pipeline_clients' => ($clientCounts['new_inquiry'] ?? 0)
                    + ($clientCounts['discovery'] ?? 0)
                    + ($clientCounts['proposal'] ?? 0),
                'active_seats' => $totalSeats,
                'monthly_value_by_currency' => collect($monthlyValueByCurrency)
                    ->map(fn ($value, $currency) => [
                        'currency' => $currency,
                        'amount' => round($value, 2),
                    ])
                    ->values()
                    ->all(),
            ],
            'talent_pipeline' => collect(AccountOptions::TALENT_STATUSES)
                ->map(fn (string $status) => [
                    'status' => $status,
                    'count' => (int) ($talentCounts[$status] ?? 0),
                ])
                ->values()
                ->all(),
            'client_pipeline' => collect(AccountOptions::CLIENT_STATUSES)
                ->map(fn (string $status) => [
                    'status' => $status,
                    'count' => (int) ($clientCounts[$status] ?? 0),
                ])
                ->values()
                ->all(),
            'registrations' => $this->registrationTrend(30),
            'top_services' => $this->topServices(6),
            'recent_talents' => $this->recentTalents(5),
            'recent_clients' => $this->recentClients(5),
            'needs_attention' => [
                'talents_missing_cv' => CandidateProfile::query()
                    ->whereHas('user', fn ($q) => $q->where('type', User::TYPE_TALENT))
                    ->where(function ($q) {
                        $q->whereNull('cv_path')->orWhere('cv_path', '');
                    })
                    ->count(),
                'clients_new_inquiry' => (int) ($clientCounts['new_inquiry'] ?? 0),
                'talents_for_assessment' => (int) ($talentCounts['for_assessment'] ?? 0),
                'clients_in_proposal' => (int) ($clientCounts['proposal'] ?? 0),
            ],
        ]);
    }

    /** @return array<string, int> */
    private function talentCounts(): array
    {
        $byStatus = CandidateProfile::query()
            ->whereHas('user', fn ($query) => $query->where('type', User::TYPE_TALENT))
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $counts = ['all' => (int) $byStatus->sum()];
        foreach (AccountOptions::TALENT_STATUSES as $status) {
            $counts[$status] = (int) ($byStatus[$status] ?? 0);
        }

        return $counts;
    }

    /** @return array<string, int> */
    private function clientCounts(): array
    {
        $byStatus = BusinessProfile::query()
            ->whereHas('user', fn ($query) => $query->where('type', User::TYPE_CLIENT))
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $counts = ['all' => (int) $byStatus->sum()];
        foreach (AccountOptions::CLIENT_STATUSES as $status) {
            $counts[$status] = (int) ($byStatus[$status] ?? 0);
        }

        return $counts;
    }

    /** @return list<array{date: string, label: string, talents: int, clients: int, total: int}> */
    private function registrationTrend(int $days): array
    {
        $start = Carbon::today()->subDays($days - 1)->startOfDay();

        $rows = User::query()
            ->whereIn('type', [User::TYPE_TALENT, User::TYPE_CLIENT])
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, type, COUNT(*) as total')
            ->groupBy('day', 'type')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->day][$row->type] = (int) $row->total;
        }

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i);
            $key = $date->toDateString();
            $talents = (int) ($map[$key][User::TYPE_TALENT] ?? 0);
            $clients = (int) ($map[$key][User::TYPE_CLIENT] ?? 0);
            $series[] = [
                'date' => $key,
                'label' => $date->format('M j'),
                'talents' => $talents,
                'clients' => $clients,
                'total' => $talents + $clients,
            ];
        }

        return $series;
    }

    /** @return list<array{slug: string, name: string, count: int}> */
    private function topServices(int $limit): array
    {
        $services = Service::query()->ordered()->get(['slug', 'name']);
        $counts = array_fill_keys($services->pluck('slug')->all(), 0);

        $profiles = BusinessProfile::query()
            ->whereHas('user', fn ($q) => $q->where('type', User::TYPE_CLIENT))
            ->whereNotNull('services')
            ->get(['services']);

        foreach ($profiles as $profile) {
            foreach ((array) $profile->services as $slug) {
                if (array_key_exists($slug, $counts)) {
                    $counts[$slug]++;
                }
            }
        }

        return $services
            ->map(fn (Service $service) => [
                'slug' => $service->slug,
                'name' => $service->name,
                'count' => $counts[$service->slug] ?? 0,
            ])
            ->sortByDesc('count')
            ->take($limit)
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function recentTalents(int $limit): array
    {
        return User::query()
            ->where('type', User::TYPE_TALENT)
            ->with('candidateProfile')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'desired_position' => $user->candidateProfile?->desired_position,
                'status' => $user->candidateProfile?->status ?? CandidateProfile::STATUS_FOR_ASSESSMENT,
                'has_cv' => filled($user->candidateProfile?->cv_path),
                'created_at' => $user->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function recentClients(int $limit): array
    {
        return User::query()
            ->where('type', User::TYPE_CLIENT)
            ->with('businessProfile')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'company_name' => $user->businessProfile?->company_name,
                'status' => $user->businessProfile?->status ?? BusinessProfile::STATUS_NEW_INQUIRY,
                'services' => $user->businessProfile?->services ?? [],
                'created_at' => $user->created_at?->toIso8601String(),
            ])
            ->all();
    }
}
