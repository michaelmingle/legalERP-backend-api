<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    /**
     * Display a listing of payments (filtered by organization)
     */
    public function index(Request $request)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            // Only admin, owner, and finance roles can view all payments
            if (!in_array($user->role, ['admin', 'owner', 'finance'])) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            
            $query = Payment::where('organization_id', $organizationId)
                ->with(['organization', 'client', 'invoice']);
            
            // Filter by client
            if ($request->has('client_id')) {
                $query->where('client_id', $request->client_id);
            }
            
            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }
            
            // Filter by date range
            if ($request->has('from_date')) {
                $query->whereDate('payment_date', '>=', $request->from_date);
            }
            if ($request->has('to_date')) {
                $query->whereDate('payment_date', '<=', $request->to_date);
            }
            
            $payments = $query->orderBy('payment_date', 'desc')->get();
            
            return response()->json($payments);
        } catch (\Exception $e) {
            Log::error('Error fetching payments: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch payments'], 500);
        }
    }

    /**
     * Store a newly created payment (filtered by organization)
     */
    public function store(Request $request)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            // Only admin, owner, and finance roles can create payments
            if (!in_array($user->role, ['admin', 'owner', 'finance'])) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            
            $validated = $request->validate([
                'client_id' => 'required|exists:clients,id',
                'invoice_id' => 'nullable|exists:invoices,id',
                'amount' => 'required|numeric|min:0',
                'payment_date' => 'required|date',
                'payment_method' => 'nullable|string|max:255',
                'reference_number' => 'nullable|string|max:255',
                'notes' => 'nullable|string',
                'status' => 'required|in:paid,partial_payment',
            ]);
            
            // Verify client belongs to organization
            $client = Client::where('id', $validated['client_id'])
                ->where('organization_id', $organizationId)
                ->first();
                
            if (!$client) {
                return response()->json(['error' => 'Client not found in your organization'], 404);
            }
            
            // Verify invoice belongs to organization if provided
            if ($request->has('invoice_id') && $request->invoice_id) {
                $invoice = Invoice::where('id', $validated['invoice_id'])
                    ->where('organization_id', $organizationId)
                    ->first();
                    
                if (!$invoice) {
                    return response()->json(['error' => 'Invoice not found in your organization'], 404);
                }
            }
            
            DB::beginTransaction();
            
            $payment = Payment::create([
                'client_id' => $validated['client_id'],
                'organization_id' => $organizationId,
                'invoice_id' => $validated['invoice_id'] ?? null,
                'amount' => $validated['amount'],
                'payment_date' => $validated['payment_date'],
                'payment_method' => $validated['payment_method'] ?? null,
                'reference_number' => $validated['reference_number'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'status' => $validated['status'],
                'created_by' => $user->id,
            ]);
            
            // If payment is associated with an invoice, update invoice status
            if ($payment->invoice_id) {
                $invoice = Invoice::find($payment->invoice_id);
                if ($invoice) {
                    $totalPaid = Payment::where('invoice_id', $invoice->id)
                        ->where('status', 'paid')
                        ->sum('amount');
                    
                    if ($totalPaid >= $invoice->total) {
                        $invoice->update(['status' => 'paid']);
                    } elseif ($totalPaid > 0) {
                        $invoice->update(['status' => 'partially_paid']);
                    }
                }
            }
            
            DB::commit();
            
            return response()->json([
                'message' => 'Payment saved successfully',
                'data' => $payment->load(['client', 'invoice'])
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Payment creation failed: ' . $e->getMessage());
            return response()->json([
                'error' => 'Payment creation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified payment (filtered by organization)
     */
    public function show($id)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            $payment = Payment::where('organization_id', $organizationId)
                ->with(['client', 'organization', 'invoice'])
                ->findOrFail($id);
                
            return response()->json([
                'data' => $payment
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching payment: ' . $e->getMessage());
            return response()->json(['error' => 'Payment not found'], 404);
        }
    }

    /**
     * Show the form for editing the specified payment (filtered by organization)
     */
    public function edit($id)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            $payment = Payment::where('organization_id', $organizationId)
                ->findOrFail($id);
                
            return response()->json([
                'data' => $payment
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching payment for edit: ' . $e->getMessage());
            return response()->json(['error' => 'Payment not found'], 404);
        }
    }

    /**
     * Update the specified payment (filtered by organization)
     */
    public function update(Request $request, $id)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            // Only admin, owner, and finance roles can update payments
            if (!in_array($user->role, ['admin', 'owner', 'finance'])) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            
            $payment = Payment::where('organization_id', $organizationId)->findOrFail($id);
            
            $validated = $request->validate([
                'client_id' => 'sometimes|exists:clients,id',
                'invoice_id' => 'nullable|exists:invoices,id',
                'amount' => 'sometimes|numeric|min:0',
                'payment_date' => 'sometimes|date',
                'payment_method' => 'nullable|string|max:255',
                'reference_number' => 'nullable|string|max:255',
                'notes' => 'nullable|string',
                'status' => 'sometimes|in:paid,partial_payment,refunded',
            ]);
            
            // Verify client belongs to organization if being updated
            if (isset($validated['client_id'])) {
                $client = Client::where('id', $validated['client_id'])
                    ->where('organization_id', $organizationId)
                    ->first();
                    
                if (!$client) {
                    return response()->json(['error' => 'Client not found in your organization'], 404);
                }
            }
            
            // Verify invoice belongs to organization if being updated
            if (isset($validated['invoice_id']) && $validated['invoice_id']) {
                $invoice = Invoice::where('id', $validated['invoice_id'])
                    ->where('organization_id', $organizationId)
                    ->first();
                    
                if (!$invoice) {
                    return response()->json(['error' => 'Invoice not found in your organization'], 404);
                }
            }
            
            DB::beginTransaction();
            
            $payment->update($validated);
            
            // If payment is associated with an invoice, update invoice status
            if ($payment->invoice_id) {
                $invoice = Invoice::find($payment->invoice_id);
                if ($invoice) {
                    $totalPaid = Payment::where('invoice_id', $invoice->id)
                        ->where('status', 'paid')
                        ->sum('amount');
                    
                    if ($totalPaid >= $invoice->total) {
                        $invoice->update(['status' => 'paid']);
                    } elseif ($totalPaid > 0) {
                        $invoice->update(['status' => 'partially_paid']);
                    } else {
                        $invoice->update(['status' => 'issued']);
                    }
                }
            }
            
            DB::commit();
            
            return response()->json([
                'message' => 'Payment updated successfully',
                'data' => $payment->load(['client', 'invoice'])
            ], 200);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Payment update failed: ' . $e->getMessage());
            return response()->json(['error' => 'Payment update failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified payment (filtered by organization)
     */
    public function destroy($id)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            // Only admin, owner, and finance roles can delete payments
            if (!in_array($user->role, ['admin', 'owner', 'finance'])) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            
            $payment = Payment::where('organization_id', $organizationId)->findOrFail($id);
            $invoiceId = $payment->invoice_id;
            
            DB::beginTransaction();
            
            $payment->delete();
            
            // Update invoice status after payment deletion
            if ($invoiceId) {
                $invoice = Invoice::find($invoiceId);
                if ($invoice) {
                    $totalPaid = Payment::where('invoice_id', $invoiceId)
                        ->where('status', 'paid')
                        ->sum('amount');
                    
                    if ($totalPaid >= $invoice->total) {
                        $invoice->update(['status' => 'paid']);
                    } elseif ($totalPaid > 0) {
                        $invoice->update(['status' => 'partially_paid']);
                    } else {
                        $invoice->update(['status' => 'issued']);
                    }
                }
            }
            
            DB::commit();
            
            return response()->json([
                'message' => 'Payment deleted successfully'
            ], 200);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Payment deletion failed: ' . $e->getMessage());
            return response()->json(['error' => 'Payment deletion failed: ' . $e->getMessage()], 500);
        }
    }
    
    /**
     * Get payments for a specific client (filtered by organization)
     */
    public function getClientPayments($clientId)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            // Verify client belongs to organization
            $client = Client::where('id', $clientId)
                ->where('organization_id', $organizationId)
                ->first();
                
            if (!$client) {
                return response()->json(['error' => 'Client not found'], 404);
            }
            
            $payments = Payment::where('organization_id', $organizationId)
                ->where('client_id', $clientId)
                ->with(['invoice'])
                ->orderBy('payment_date', 'desc')
                ->get();
                
            return response()->json([
                'success' => true,
                'data' => $payments,
                'client' => $client
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching client payments: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch payments'], 500);
        }
    }
    
    /**
     * Get payment summary (filtered by organization)
     */
    public function getSummary(Request $request)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            $query = Payment::where('organization_id', $organizationId);
            
            // Apply date filters
            if ($request->has('from_date')) {
                $query->whereDate('payment_date', '>=', $request->from_date);
            }
            if ($request->has('to_date')) {
                $query->whereDate('payment_date', '<=', $request->to_date);
            }
            
            $summary = [
                'total_paid' => (clone $query)->where('status', 'paid')->sum('amount'),
                'total_partial' => (clone $query)->where('status', 'partial_payment')->sum('amount'),
                'total_refunded' => (clone $query)->where('status', 'refunded')->sum('amount'),
                'total_payments' => (clone $query)->count(),
                'payments_by_month' => (clone $query)
                    ->selectRaw('DATE_FORMAT(payment_date, "%Y-%m") as month, SUM(amount) as total, COUNT(*) as count')
                    ->groupBy('month')
                    ->orderBy('month', 'desc')
                    ->limit(12)
                    ->get(),
            ];
            
            return response()->json([
                'success' => true,
                'data' => $summary
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching payment summary: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch summary'], 500);
        }
    }
}