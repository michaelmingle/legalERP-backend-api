<?php
// app/Http/Controllers/Api/V1/SettingsController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SettingController extends Controller
{
    /**
     * Get all settings for the organization
     */
    public function index(Request $request)
{
    $user = Auth::user();
    $organizationId = $user->organization_id;
    
    $group = $request->get('group', 'all');
    
    $query = Setting::where('organization_id', $organizationId);
    
    if ($group !== 'all') {
        $query->where('group', $group);
    }
    
    $settings = $query->get();
    
    // Format settings as key-value pairs
    $formattedSettings = [];
    foreach ($settings as $setting) {
        $value = $setting->value;
        if ($setting->type === 'json') {
            $value = json_decode($setting->value, true);
        } elseif ($setting->type === 'boolean') {
            $value = filter_var($setting->value, FILTER_VALIDATE_BOOLEAN);
        } elseif ($setting->type === 'integer') {
            $value = (int) $setting->value;
        }
        $formattedSettings[$setting->key] = $value;
    }
    
    // Add organization data
    $organization = $user->organization;
    $formattedSettings['organization'] = [
        'name' => $organization->name,
        'organisation_email' => $organization->organisation_email,
        'phone' => $organization->phone,
        'address' => $organization->address,
        'logo_url' => $organization->logo_url,
    ];
    
    return response()->json([
        'success' => true,
        'data' => $formattedSettings,
        'settings_list' => $settings
    ]);
}
    
   // app/Http/Controllers/Api/V1/SettingsController.php

public function updateGeneral(Request $request)
{
    try {
        $user = Auth::user();
        $organizationId = $user->organization_id;
        
        $validated = $request->validate([
            'organization_name' => 'nullable|string|max:255',
            'organization_email' => 'nullable|email|max:255',
            'organization_phone' => 'nullable|string|max:20',
            'organization_address' => 'nullable|string|max:500',
            'timezone' => 'nullable|string',
            'date_format' => 'nullable|string',
            'time_format' => 'nullable|string',
            'currency' => 'nullable|string|size:3',
            'language' => 'nullable|string|size:2',
        ]);
        
        // Update organization basic info
        $organization = $user->organization;
        
        // Only update fields that are provided, and ensure name is never null
        if ($request->has('organization_name') && !empty($request->organization_name)) {
            $organization->name = $request->organization_name;
        }
        if ($request->has('organization_email')) {
            $organization->organisation_email = $request->organization_email;
        }
        if ($request->has('organization_phone')) {
            $organization->phone = $request->organization_phone;
        }
        if ($request->has('organization_address')) {
            $organization->address = $request->organization_address;
        }
        
        // Make sure name is never null - use existing name if not provided
        if (empty($organization->name)) {
            $organization->name = $user->first_name . "'s Organization";
        }
        
        $organization->save();
        
        // Update settings table
        $settings = [
            'timezone' => $request->timezone ?? 'UTC',
            'date_format' => $request->date_format ?? 'Y-m-d',
            'time_format' => $request->time_format ?? 'H:i',
            'currency' => $request->currency ?? 'USD',
            'language' => $request->language ?? 'en',
        ];
        
        foreach ($settings as $key => $value) {
            app(\App\Services\SettingsService::class)->forget($organizationId);
        Setting::updateOrCreate(
                ['organization_id' => $organizationId, 'key' => $key],
                ['value' => $value, 'type' => 'string', 'group' => 'general']
            );
        }
        
        // Fetch updated settings
        $updatedSettings = Setting::where('organization_id', $organizationId)
            ->whereIn('key', array_keys($settings))
            ->get()
            ->pluck('value', 'key');
        
        return response()->json([
            'success' => true,
            'message' => 'General settings updated successfully',
            'data' => [
                'organization' => $organization,
                'settings' => $updatedSettings
            ]
        ]);
    } catch (\Exception $e) {
        Log::error('Error updating general settings: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Failed to update settings: ' . $e->getMessage()
        ], 500);
    }
}
    
    /**
     * Update email settings
     */
    public function updateEmail(Request $request)
    {
        $user = Auth::user();
        $organizationId = $user->organization_id;
        
        $validated = $request->validate([
            'mail_mailer' => 'required|string|in:smtp,mailgun,ses,postmark,sendmail',
            'mail_host' => 'required|string',
            'mail_port' => 'required|integer',
            'mail_username' => 'nullable|string',
            'mail_password' => 'nullable|string',
            'mail_encryption' => 'nullable|string|in:tls,ssl',
            'mail_from_address' => 'required|email',
            'mail_from_name' => 'required|string',
        ]);
        
        $emailSettings = $request->only([
            'mail_mailer', 'mail_host', 'mail_port', 'mail_username', 
            'mail_password', 'mail_encryption', 'mail_from_address', 'mail_from_name'
        ]);
        
        // Encrypt sensitive data
        if (isset($emailSettings['mail_password']) && $emailSettings['mail_password']) {
            $emailSettings['mail_password'] = encrypt($emailSettings['mail_password']);
        }
        
        app(\App\Services\SettingsService::class)->forget($organizationId);
        Setting::updateOrCreate(
            ['organization_id' => $organizationId, 'key' => 'email_settings'],
            ['value' => json_encode($emailSettings), 'type' => 'json', 'group' => 'email']
        );
        
        return response()->json([
            'success' => true,
            'message' => 'Email settings updated successfully'
        ]);
    }
    
    /**
     * Update appearance settings
     */
    public function updateAppearance(Request $request)
    {
        $user = Auth::user();
        $organizationId = $user->organization_id;
        
        $validated = $request->validate([
            'primary_color' => 'nullable|string',
            'logo_url' => 'nullable|url',
            'favicon_url' => 'nullable|url',
            'dark_mode' => 'nullable|boolean',
            'sidebar_collapsed' => 'nullable|boolean',
        ]);
        
        $appearanceSettings = $request->only([
            'primary_color', 'logo_url', 'favicon_url', 'dark_mode', 'sidebar_collapsed'
        ]);
        
        app(\App\Services\SettingsService::class)->forget($organizationId);
        Setting::updateOrCreate(
            ['organization_id' => $organizationId, 'key' => 'appearance_settings'],
            ['value' => json_encode($appearanceSettings), 'type' => 'json', 'group' => 'appearance']
        );
        
        return response()->json([
            'success' => true,
            'message' => 'Appearance settings updated successfully'
        ]);
    }
    
    /**
     * Update billing settings
     */
    public function updateBilling(Request $request)
    {
        $user = Auth::user();
        $organizationId = $user->organization_id;
        
        $validated = $request->validate([
            'invoice_prefix' => 'nullable|string',
            'invoice_due_days' => 'nullable|integer|min:1|max:90',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'default_billing_method' => 'nullable|string|in:hourly,fixed,retainer',
            'send_invoice_automatically' => 'nullable|boolean',
        ]);
        
        $billingSettings = $request->only([
            'invoice_prefix', 'invoice_due_days', 'tax_rate', 
            'default_billing_method', 'send_invoice_automatically'
        ]);
        
        app(\App\Services\SettingsService::class)->forget($organizationId);
        Setting::updateOrCreate(
            ['organization_id' => $organizationId, 'key' => 'billing_settings'],
            ['value' => json_encode($billingSettings), 'type' => 'json', 'group' => 'billing']
        );
        
        return response()->json([
            'success' => true,
            'message' => 'Billing settings updated successfully'
        ]);
    }
    
    /**
     * Update security settings
     */
    public function updateSecurity(Request $request)
    {
        $user = Auth::user();
        $organizationId = $user->organization_id;
        
        $validated = $request->validate([
            'two_factor_auth' => 'nullable|boolean',
            'session_timeout' => 'nullable|integer|min:15|max:480',
            'max_login_attempts' => 'nullable|integer|min:3|max:10',
            'password_expiry_days' => 'nullable|integer|min:30|max:365',
            'require_strong_password' => 'nullable|boolean',
            'ip_whitelist' => 'nullable|array',
        ]);
        
        $securitySettings = $request->only([
            'two_factor_auth', 'session_timeout', 'max_login_attempts', 
            'password_expiry_days', 'require_strong_password', 'ip_whitelist'
        ]);
        
        app(\App\Services\SettingsService::class)->forget($organizationId);
        Setting::updateOrCreate(
            ['organization_id' => $organizationId, 'key' => 'security_settings'],
            ['value' => json_encode($securitySettings), 'type' => 'json', 'group' => 'security']
        );
        
        return response()->json([
            'success' => true,
            'message' => 'Security settings updated successfully'
        ]);
    }
    
    /**
     * Upload logo
     */
    public function uploadLogo(Request $request)
    {
        $request->validate([
            'logo' => 'required|image|mimes:jpeg,png,jpg,svg|max:2048',
        ]);
        
        $user = Auth::user();
        $organization = $user->organization;
        
        // Delete old logo if exists
        if ($organization->logo_url) {
            $oldPath = str_replace('/storage/', '', $organization->logo_url);
            Storage::disk('public')->delete($oldPath);
        }
        
        $path = $request->file('logo')->store('organization-logos', 'public');
        $logoUrl = Storage::url($path);
        
        $organization->logo_url = $logoUrl;
        $organization->save();
        
        return response()->json([
            'success' => true,
            'message' => 'Logo uploaded successfully',
            'logo_url' => $logoUrl
        ]);
    }
    
    /**
     * Delete logo
     */
    public function deleteLogo()
    {
        $user = Auth::user();
        $organization = $user->organization;
        
        if ($organization->logo_url) {
            $path = str_replace('/storage/', '', $organization->logo_url);
            Storage::disk('public')->delete($path);
            $organization->logo_url = null;
            $organization->save();
        }
        
        return response()->json([
            'success' => true,
            'message' => 'Logo deleted successfully'
        ]);
    }
    
    /**
     * Test email configuration
     */
    public function testEmail(Request $request)
    {
        $request->validate([
            'test_email' => 'required|email'
        ]);
        
        try {
            // Temporarily configure mail settings from organization settings
            $emailSettings = Setting::getValue('email_settings', []);
            
            if ($emailSettings) {
                config([
                    'mail.mailers.smtp.host' => $emailSettings['mail_host'] ?? config('mail.mailers.smtp.host'),
                    'mail.mailers.smtp.port' => $emailSettings['mail_port'] ?? config('mail.mailers.smtp.port'),
                    'mail.mailers.smtp.username' => $emailSettings['mail_username'] ?? null,
                    'mail.mailers.smtp.password' => isset($emailSettings['mail_password']) ? decrypt($emailSettings['mail_password']) : null,
                    'mail.mailers.smtp.encryption' => $emailSettings['mail_encryption'] ?? 'tls',
                    'mail.from.address' => $emailSettings['mail_from_address'] ?? config('mail.from.address'),
                    'mail.from.name' => $emailSettings['mail_from_name'] ?? config('mail.from.name'),
                ]);
            }
            
            Mail::raw('This is a test email from your Legal ERP System. Your email configuration is working correctly!', function ($message) use ($request) {
                $message->to($request->test_email)
                        ->subject('Test Email from Legal ERP');
            });
            
            return response()->json([
                'success' => true,
                'message' => 'Test email sent successfully to ' . $request->test_email
            ]);
        } catch (\Exception $e) {
            Log::error('Test email failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to send test email: ' . $e->getMessage()
            ], 500);
        }
    }
}