<?php
// app/Http/Controllers/Api/V1/ArchiveController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Cases;
use App\Models\Client;
use App\Models\User;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\SoftDeletes;

class ArchiveController extends Controller
{
    /**
     * Check if model uses SoftDeletes trait
     */
    private function hasSoftDeletes($model)
    {
        return in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses_recursive($model));
    }
    
    /**
     * Get all archived items (filtered by organization)
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        
        if ($user->role !== 'admin' && $user->role !== 'owner') {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }
        
        $organizationId = $user->organization_id;
        $type = $request->get('type', 'cases');
        $search = $request->get('search', '');
        
        // Get stats for all types (filtered by organization)
        $stats = [
            'cases' => Cases::where('organization_id', $organizationId)->onlyTrashed()->count(),
            'clients' => Client::where('organization_id', $organizationId)->onlyTrashed()->count(),
            'users' => User::where('organization_id', $organizationId)
                ->onlyTrashed()
                ->where('role', '!=', 'admin')
                ->count(),
            'documents' => Document::where('organization_id', $organizationId)->onlyTrashed()->count(),
            'invoices' => Invoice::where('organization_id', $organizationId)->onlyTrashed()->count(),
            'appointments' => Appointment::whereHas('case', function($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            })->onlyTrashed()->count(),
        ];
        
        // Get data based on selected type
        $data = [];
        
        try {
            switch ($type) {
                case 'cases':
                    $query = Cases::where('organization_id', $organizationId)
                        ->onlyTrashed()
                        ->with(['client', 'assignedUser']);
                    
                    if ($search) {
                        $query->where(function($q) use ($search) {
                            $q->where('case_number', 'like', "%{$search}%")
                              ->orWhere('case_name', 'like', "%{$search}%");
                        });
                    }
                    
                    $data = $query->orderBy('deleted_at', 'desc')->get();
                    break;
                    
                case 'clients':
                    $query = Client::where('organization_id', $organizationId)
                        ->onlyTrashed();
                    
                    if ($search) {
                        $query->where(function($q) use ($search) {
                            $q->where('full_name', 'like', "%{$search}%")
                              ->orWhere('email', 'like', "%{$search}%");
                        });
                    }
                    
                    $data = $query->orderBy('deleted_at', 'desc')->get();
                    break;
                    
                case 'users':
                    $query = User::where('organization_id', $organizationId)
                        ->onlyTrashed()
                        ->where('role', '!=', 'admin');
                    
                    if ($search) {
                        $query->where(function($q) use ($search) {
                            $q->where('first_name', 'like', "%{$search}%")
                              ->orWhere('last_name', 'like', "%{$search}%")
                              ->orWhere('email', 'like', "%{$search}%");
                        });
                    }
                    
                    $data = $query->orderBy('deleted_at', 'desc')->get();
                    break;
                    
                case 'documents':
                    $query = Document::where('organization_id', $organizationId)
                        ->onlyTrashed()
                        ->with('case');
                    
                    if ($search) {
                        $query->where('file_name', 'like', "%{$search}%");
                    }
                    
                    $data = $query->orderBy('deleted_at', 'desc')->get();
                    break;
                    
                case 'invoices':
                    $query = Invoice::where('organization_id', $organizationId)
                        ->onlyTrashed()
                        ->with(['case', 'client']);
                    
                    if ($search) {
                        $query->where('invoice_number', 'like', "%{$search}%");
                    }
                    
                    $data = $query->orderBy('deleted_at', 'desc')->get();
                    break;
                    
                case 'appointments':
                    $query = Appointment::whereHas('case', function($q) use ($organizationId) {
                            $q->where('organization_id', $organizationId);
                        })
                        ->onlyTrashed()
                        ->with('case');
                    
                    if ($search) {
                        $query->where('title', 'like', "%{$search}%");
                    }
                    
                    $data = $query->orderBy('deleted_at', 'desc')->get();
                    break;
                    
                default:
                    $data = [];
            }
        } catch (\Exception $e) {
            Log::error('Archive error: ' . $e->getMessage());
            $data = [];
        }
        
        return response()->json([
            'success' => true,
            'data' => $data,
            'stats' => $stats,
            'type' => $type
        ]);
    }
    
    /**
     * Restore a soft-deleted item
     */
    public function restore(Request $request)
    {
        $user = Auth::user();
        
        if ($user->role !== 'admin' && $user->role !== 'owner') {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }
        
        $organizationId = $user->organization_id;
        
        $request->validate([
            'type' => 'required|string|in:cases,clients,users,documents,invoices,appointments',
            'id' => 'required|integer'
        ]);
        
        $model = $this->getModel($request->type);
        
        // Add organization filter for the query
        $item = $this->getArchivedItem($model, $request->id, $organizationId, $request->type);
        
        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Item not found in archive'
            ], 404);
        }
        
        // Get item name before restoring
        $itemName = $this->getItemName($item, $request->type);
        $item->restore();
        
        return response()->json([
            'success' => true,
            'message' => ucfirst($request->type) . ' "' . $itemName . '" restored successfully'
        ]);
    }
    
    /**
     * Permanently delete an item
     */
    public function forceDelete(Request $request)
    {
        $user = Auth::user();
        
        if ($user->role !== 'admin' && $user->role !== 'owner') {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }
        
        $organizationId = $user->organization_id;
        
        $request->validate([
            'type' => 'required|string|in:cases,clients,users,documents,invoices,appointments',
            'id' => 'required|integer'
        ]);
        
        $model = $this->getModel($request->type);
        
        // Add organization filter for the query
        $item = $this->getArchivedItem($model, $request->id, $organizationId, $request->type);
        
        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Item not found in archive'
            ], 404);
        }
        
        $itemName = $this->getItemName($item, $request->type);
        $item->forceDelete();
        
        return response()->json([
            'success' => true,
            'message' => ucfirst($request->type) . ' "' . $itemName . '" permanently deleted'
        ]);
    }
    
    /**
     * Restore all items of a type
     */
    public function restoreAll(Request $request)
    {
        $user = Auth::user();
        
        if ($user->role !== 'admin' && $user->role !== 'owner') {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }
        
        $organizationId = $user->organization_id;
        
        $request->validate([
            'type' => 'required|string|in:cases,clients,users,documents,invoices,appointments'
        ]);
        
        $count = $this->countArchivedItems($request->type, $organizationId);
        $this->restoreArchivedItems($request->type, $organizationId);
        
        return response()->json([
            'success' => true,
            'message' => $count . ' ' . ucfirst($request->type) . ' restored successfully'
        ]);
    }
    
    /**
     * Empty all items of a type (permanent delete)
     */
    public function emptyAll(Request $request)
    {
        $user = Auth::user();
        
        if ($user->role !== 'admin' && $user->role !== 'owner') {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }
        
        $organizationId = $user->organization_id;
        
        $request->validate([
            'type' => 'required|string|in:cases,clients,users,documents,invoices,appointments'
        ]);
        
        $count = $this->countArchivedItems($request->type, $organizationId);
        $this->forceDeleteArchivedItems($request->type, $organizationId);
        
        return response()->json([
            'success' => true,
            'message' => $count . ' ' . ucfirst($request->type) . ' permanently deleted'
        ]);
    }
    
    /**
     * Get archived item with organization filter
     */
    private function getArchivedItem($model, $id, $organizationId, $type)
    {
        $query = $this->getArchivedQuery($model, $organizationId, $type);
        return $query->find($id);
    }
    
    /**
     * Get archived query with organization filter
     */
    private function getArchivedQuery($model, $organizationId, $type)
    {
        switch ($type) {
            case 'cases':
                return Cases::where('organization_id', $organizationId)->onlyTrashed();
            case 'clients':
                return Client::where('organization_id', $organizationId)->onlyTrashed();
            case 'users':
                return User::where('organization_id', $organizationId)->onlyTrashed();
            case 'documents':
                return Document::where('organization_id', $organizationId)->onlyTrashed();
            case 'invoices':
                return Invoice::where('organization_id', $organizationId)->onlyTrashed();
            case 'appointments':
                return Appointment::whereHas('case', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })->onlyTrashed();
            default:
                return $model::onlyTrashed();
        }
    }
    
    /**
     * Count archived items by type
     */
    private function countArchivedItems($type, $organizationId)
    {
        switch ($type) {
            case 'cases':
                return Cases::where('organization_id', $organizationId)->onlyTrashed()->count();
            case 'clients':
                return Client::where('organization_id', $organizationId)->onlyTrashed()->count();
            case 'users':
                return User::where('organization_id', $organizationId)
                    ->onlyTrashed()
                    ->where('role', '!=', 'admin')
                    ->count();
            case 'documents':
                return Document::where('organization_id', $organizationId)->onlyTrashed()->count();
            case 'invoices':
                return Invoice::where('organization_id', $organizationId)->onlyTrashed()->count();
            case 'appointments':
                return Appointment::whereHas('case', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })->onlyTrashed()->count();
            default:
                return 0;
        }
    }
    
    /**
     * Restore all archived items of a type
     */
    private function restoreArchivedItems($type, $organizationId)
    {
        switch ($type) {
            case 'cases':
                Cases::where('organization_id', $organizationId)->onlyTrashed()->restore();
                break;
            case 'clients':
                Client::where('organization_id', $organizationId)->onlyTrashed()->restore();
                break;
            case 'users':
                User::where('organization_id', $organizationId)
                    ->onlyTrashed()
                    ->where('role', '!=', 'admin')
                    ->restore();
                break;
            case 'documents':
                Document::where('organization_id', $organizationId)->onlyTrashed()->restore();
                break;
            case 'invoices':
                Invoice::where('organization_id', $organizationId)->onlyTrashed()->restore();
                break;
            case 'appointments':
                Appointment::whereHas('case', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })->onlyTrashed()->restore();
                break;
        }
    }
    
    /**
     * Force delete all archived items of a type
     */
    private function forceDeleteArchivedItems($type, $organizationId)
    {
        switch ($type) {
            case 'cases':
                Cases::where('organization_id', $organizationId)->onlyTrashed()->forceDelete();
                break;
            case 'clients':
                Client::where('organization_id', $organizationId)->onlyTrashed()->forceDelete();
                break;
            case 'users':
                User::where('organization_id', $organizationId)
                    ->onlyTrashed()
                    ->where('role', '!=', 'admin')
                    ->forceDelete();
                break;
            case 'documents':
                Document::where('organization_id', $organizationId)->onlyTrashed()->forceDelete();
                break;
            case 'invoices':
                Invoice::where('organization_id', $organizationId)->onlyTrashed()->forceDelete();
                break;
            case 'appointments':
                Appointment::whereHas('case', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })->onlyTrashed()->forceDelete();
                break;
        }
    }
    
    private function getModel($type)
    {
        return match($type) {
            'cases' => Cases::class,
            'clients' => Client::class,
            'users' => User::class,
            'documents' => Document::class,
            'invoices' => Invoice::class,
            'appointments' => Appointment::class,
            default => Cases::class,
        };
    }
    
    private function getItemName($item, $type)
    {
        return match($type) {
            'cases' => $item->case_name ?? $item->case_number ?? 'Case',
            'clients' => $item->full_name ?? 'Client',
            'users' => trim(($item->first_name ?? '') . ' ' . ($item->last_name ?? '')),
            'documents' => $item->file_name ?? 'Document',
            'invoices' => $item->invoice_number ?? 'Invoice',
            'appointments' => $item->title ?? 'Appointment',
            default => 'Item',
        };
    }
}