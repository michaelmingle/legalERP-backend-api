<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SettingsController extends Controller
{
    /**
     * Get settings for a specific tab/group
     */
    public function getSettings($tab)
    {
        try {
            // Prefer system-wide rows when present; otherwise fall back to any row in this group
            $query = Setting::where('group', $tab);
            if ($this->isOrganizationIdNullable()) {
                $query->whereNull('organization_id');
            }
            $settings = $query->get();
            
            $data = [];
            foreach ($settings as $setting) {
                $value = $setting->value;
                
                // Decode value based on type
                switch ($setting->type) {
                    case 'boolean':
                        $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                        break;
                    case 'integer':
                        $value = (int) $value;
                        break;
                    case 'json':
                        $value = is_string($value) ? json_decode($value, true) : $value;
                        break;
                    default:
                        $value = $value;
                }
                
                $data[$setting->key] = $value;
            }
            
            // Set default values if not found
            $data = $this->getDefaultSettings($tab, $data);
            
            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching settings: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load settings',
                'data' => $this->getDefaultSettings($tab, [])
            ], 500);
        }
    }
    
    /**
     * Update settings for a specific tab/group
     */
    public function updateSettings(Request $request, $tab)
    {
        try {
            $settings = $request->except(['_token']);

            // Detect whether settings.organization_id allows NULL (system-wide rows).
            $orgIdNullable = $this->isOrganizationIdNullable();

            foreach ($settings as $key => $value) {
                $type = $this->determineSettingType($key, $value);

                if (is_array($value)) {
                    $storedValue = json_encode($value);
                } elseif (is_bool($value)) {
                    $storedValue = $value ? 'true' : 'false';
                } elseif ($value === null) {
                    $storedValue = '';
                } else {
                    $storedValue = (string) $value;
                }

                $matchAttrs = [
                    'key'   => $key,
                    'group' => $tab,
                ];

                if ($orgIdNullable) {
                    $matchAttrs['organization_id'] = null;
                }

                $updateAttrs = [
                    'value' => $storedValue,
                    'type'  => $type,
                ];

                Setting::updateOrCreate($matchAttrs, $updateAttrs);
            }

            Cache::forget("system_settings_{$tab}");
            app(\App\Services\SettingsService::class)->forget();

            return response()->json([
                'success' => true,
                'message' => 'Settings saved successfully',
            ]);
        } catch (\Throwable $e) {
            Log::error('Error saving settings: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to save settings: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * True when settings.organization_id is nullable (after the schema-alignment migration).
     */
    private function isOrganizationIdNullable(): bool
    {
        try {
            $row = DB::selectOne("SHOW COLUMNS FROM settings LIKE 'organization_id'");
            if ($row && isset($row->Null)) {
                return strtoupper($row->Null) === 'YES';
            }
        } catch (\Throwable $e) {
            // ignore; fall through
        }
        return false;
    }
    
    /**
     * Get default settings for each tab
     */
    private function getDefaultSettings($tab, $currentSettings)
    {
        $defaults = [
            'general' => [
                'system_name' => 'LEGAL ERP',
                'timezone' => 'UTC',
                'date_format' => 'YYYY-MM-DD',
                'time_format' => '24h',
                'language' => 'en',
                'logo_url' => null,
                'favicon_url' => null
            ],
            'email' => [
                'smtp_host' => '',
                'smtp_port' => 587,
                'smtp_username' => '',
                'smtp_password' => '',
                'smtp_encryption' => 'tls',
                'from_email' => 'noreply@legalerp.com',
                'from_name' => 'LEGAL ERP',
                'mailer' => 'smtp'
            ],
            'security' => [
                'enable_2fa' => false,
                'session_timeout' => 60,
                'max_login_attempts' => 5,
                'lockout_duration' => 15,
                'password_expiry_days' => 90,
                'require_strong_password' => true,
                'allowed_domains' => []
            ],
            'notifications' => [
                'email_notifications' => true,
                'push_notifications' => true,
                'case_assigned_notify' => true,
                'payment_received_notify' => true,
                'document_upload_notify' => true,
                'daily_digest' => false
            ],
            'subscription' => [
                'plans' => [
                    [
                        'name' => 'Basic',
                        'price' => 99,
                        'billing_cycle' => 'monthly',
                        'features' => ['5 users', '50 cases/year', '10GB storage']
                    ],
                    [
                        'name' => 'Professional',
                        'price' => 199,
                        'billing_cycle' => 'monthly',
                        'features' => ['20 users', '200 cases/year', '50GB storage']
                    ],
                    [
                        'name' => 'Enterprise',
                        'price' => 399,
                        'billing_cycle' => 'monthly',
                        'features' => ['Unlimited users', 'Unlimited cases', '500GB storage']
                    ]
                ],
                'trial_days' => 14,
                'enable_promotions' => true,
                'currency' => 'USD',
                'tax_rate' => 0
            ],
            'system' => [
                'debug_mode' => false,
                'maintenance_mode' => false,
                'api_rate_limit' => 60,
                'log_retention_days' => 90,
                'backup_frequency' => 'daily',
                'auto_update' => false,
                'cron_job_status' => true
            ]
        ];
        
        // Merge defaults with current settings
        if (isset($defaults[$tab])) {
            return array_merge($defaults[$tab], $currentSettings);
        }
        
        return $currentSettings;
    }
    
    /**
     * Determine the type of setting based on key and value
     */
    private function determineSettingType($key, $value)
    {
        // Explicit type mapping for known keys
        $typeMap = [
            'smtp_port' => 'integer',
            'session_timeout' => 'integer',
            'max_login_attempts' => 'integer',
            'lockout_duration' => 'integer',
            'password_expiry_days' => 'integer',
            'trial_days' => 'integer',
            'api_rate_limit' => 'integer',
            'log_retention_days' => 'integer',
            'tax_rate' => 'integer',
            'enable_2fa' => 'boolean',
            'require_strong_password' => 'boolean',
            'email_notifications' => 'boolean',
            'push_notifications' => 'boolean',
            'case_assigned_notify' => 'boolean',
            'payment_received_notify' => 'boolean',
            'document_upload_notify' => 'boolean',
            'daily_digest' => 'boolean',
            'enable_promotions' => 'boolean',
            'debug_mode' => 'boolean',
            'maintenance_mode' => 'boolean',
            'auto_update' => 'boolean',
            'cron_job_status' => 'boolean',
            'allowed_domains' => 'json',
            'plans' => 'json'
        ];
        
        if (isset($typeMap[$key])) {
            return $typeMap[$key];
        }
        
        // Auto-detect type
        if (is_bool($value)) {
            return 'boolean';
        }
        
        if (is_int($value) || is_numeric($value)) {
            return 'integer';
        }
        
        if (is_array($value)) {
            return 'json';
        }
        
        return 'string';
    }
    
    /**
     * Get a specific system setting
     */
    public function getSetting($key)
    {
        try {
            $setting = Setting::where('key', $key)
                ->whereNull('organization_id')
                ->first();
                
            if (!$setting) {
                return response()->json([
                    'success' => false,
                    'message' => 'Setting not found'
                ], 404);
            }
            
            $value = $setting->value;
            switch ($setting->type) {
                case 'boolean':
                    $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    break;
                case 'integer':
                    $value = (int) $value;
                    break;
                case 'json':
                    $value = json_decode($value, true);
                    break;
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'key' => $setting->key,
                    'value' => $value,
                    'type' => $setting->type,
                    'group' => $setting->group
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch setting'
            ], 500);
        }
    }
    
    /**
     * Delete a setting
     */
    public function deleteSetting($key)
    {
        try {
            Setting::where('key', $key)
                ->whereNull('organization_id')
                ->delete();
                
            return response()->json([
                'success' => true,
                'message' => 'Setting deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete setting'
            ], 500);
        }
    }
}