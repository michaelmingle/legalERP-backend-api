<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class SettingsService
{
    private const CACHE_TTL = 300; // 5 minutes

    /**
     * All system-wide settings (organization_id IS NULL) keyed by setting key.
     */
    public function system(): array
    {
        return Cache::remember('settings.system', self::CACHE_TTL, function () {
            return Setting::whereNull('organization_id')
                ->get()
                ->mapWithKeys(fn ($s) => [$s->key => $this->castValue($s)])
                ->toArray();
        });
    }

    /**
     * All settings (any group) for an organization keyed by key.
     */
    public function org(int $organizationId): array
    {
        return Cache::remember("settings.org.{$organizationId}", self::CACHE_TTL, function () use ($organizationId) {
            return Setting::where('organization_id', $organizationId)
                ->get()
                ->mapWithKeys(fn ($s) => [$s->key => $this->castValue($s)])
                ->toArray();
        });
    }

    /**
     * Get a single system value, with optional default.
     */
    public function systemGet(string $key, $default = null)
    {
        return $this->system()[$key] ?? $default;
    }

    /**
     * Get a single org value, with optional default.
     */
    public function orgGet(int $organizationId, string $key, $default = null)
    {
        return $this->org($organizationId)[$key] ?? $default;
    }

    /**
     * Flush all caches; call after any setting write.
     */
    public function forget(?int $organizationId = null): void
    {
        Cache::forget('settings.system');
        if ($organizationId) {
            Cache::forget("settings.org.{$organizationId}");
        }
    }

    private function castValue(Setting $s)
    {
        $v = $s->value;

        // Strip stale outer double-quotes left over from the old `value => json` cast.
        if (is_string($v) && strlen($v) >= 2 && $v[0] === '"' && substr($v, -1) === '"') {
            $decoded = json_decode($v, true);
            if (is_string($decoded)) {
                $v = $decoded;
            }
        }

        return match ($s->type) {
            'boolean' => filter_var($v, FILTER_VALIDATE_BOOLEAN),
            'integer' => is_numeric($v) ? (int) $v : 0,
            'json'    => is_string($v) ? json_decode($v, true) : $v,
            default   => $v,
        };
    }
}
