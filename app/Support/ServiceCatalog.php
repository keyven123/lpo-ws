<?php

namespace App\Support;

use App\Models\Service;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\In;

class ServiceCatalog
{
    public static function slugs(bool $activeOnly = false): array
    {
        $key = $activeOnly ? 'services.slugs.active' : 'services.slugs.all';

        return Cache::remember($key, 60, function () use ($activeOnly) {
            $query = Service::query()->ordered();
            if ($activeOnly) {
                $query->active();
            }

            return $query->pluck('slug')->all();
        });
    }

    public static function forgetCache(): void
    {
        Cache::forget('services.slugs.active');
        Cache::forget('services.slugs.all');
    }

    /** Accept any known service slug (including inactive) so existing client picks stay valid. */
    public static function slugRule(): In|Exists
    {
        return self::ruleFromSlugs(self::slugs(false));
    }

    /** New registrations may only pick active services. */
    public static function activeSlugRule(): In|Exists
    {
        return self::ruleFromSlugs(self::slugs(true), true);
    }

    private static function ruleFromSlugs(array $slugs, bool $activeOnly = false): In|Exists
    {
        if ($slugs === []) {
            $rule = Rule::exists('services', 'slug');

            return $activeOnly ? $rule->where('is_active', true) : $rule;
        }

        return Rule::in($slugs);
    }
}
