<?php
// app/Http/Controllers/Api/V1/BillingController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use App\Models\InvoiceItem;
use App\Models\Client;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;

class BillingController extends Controller
{
    /**
     * Display a listing of the resource (filtered by organization)
     */
    public function index()
    {
        $user = Auth::user();
        $organizationId = $user->organization_id;
        
        $invoices = Invoice::where('organization_id', $organizationId)
            ->with(['items', 'payments', 'client', 'case', 'lawyer'])
            ->orderBy('created_at', 'desc')
            ->get();
            
        return response()->json($invoices);
    }

    /**
     * Get invoices for the logged-in lawyer (filtered by organization)
     */
    public function myInvoice() 
    {
        $user = Auth::user();
        $organizationId = $user->organization_id;
        
        $invoices = Invoice::where('organization_id', $organizationId)
            ->where('lawyer_id', $user->id)
            ->with(['items', 'payments', 'client', 'case'])
            ->orderBy('created_at', 'desc')
            ->get();
            
        return response()->json($invoices);
    }

    /**
     * Get invoices for the logged-in client (filtered by organization)
     */
    public function clientInvoices()
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            Log::info('Fetching client invoices for user: ' . $user->id);
            
            // Get the client record for this user within the organization
            $client = Client::where('user_id', $user->id)
                ->where('organization_id', $organizationId)
                ->first();
            
            Log::info('Client found: ' . ($client ? $client->id : 'null'));
            
            if (!$client) {
                return response()->json([]);
            }
            
            // Get invoices for this client within the organization
            $clientInvoices = Invoice::where('organization_id', $organizationId)
                ->where('client_id', $client->id)
                ->with(['case', 'lawyer', 'items', 'payments'])
                ->orderBy('created_at', 'desc')
                ->get();
            
            Log::info('Invoices count: ' . $clientInvoices->count());
            
            return response()->json($clientInvoices);
            
        } catch (\Exception $e) {
            Log::error('Error fetching client invoices: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'client_id' => 'required|exists:clients,id',
            'case_id' => 'nullable|exists:cases,id',
            'invoice_number' => 'nullable|string|unique:invoices,invoice_number',
            'lawyer_id' => 'nullable|exists:users,id',
            'issue_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:issue_date',
            'vat_percentage' => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|in:fixed,percentage',
            'discount_value' => 'nullable|numeric|min:0',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.type' => 'required|string',
            'items.*.rate' => 'required|numeric|min:0',
            'items.*.hours' => 'required|numeric|min:0.01',
        ]);

        DB::beginTransaction();

        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            // Verify client belongs to this organization
            $client = Client::where('id', $request->client_id)
                ->where('organization_id', $organizationId)
                ->first();
                
            if (!$client) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client not found in your organization'
                ], 404);
            }

            // Calculate Subtotal
            $subtotal = 0;
            foreach ($request->items as $item) {
                $subtotal += $item['rate'] * $item['hours'];
            }

            // Calculate Discount
            $discount = 0;
            if ($request->discount_type === 'percentage') {
                $discount = ($subtotal * $request->discount_value) / 100;
            } elseif ($request->discount_type === 'fixed') {
                $discount = $request->discount_value;
            }
            $discount = min($discount, $subtotal);

            // Calculate VAT
            $vatPercentage = $request->vat_percentage ?? 12.5;
            $vat = (($subtotal - $discount) * $vatPercentage) / 100;

            // Final Total
            $total = $subtotal - $discount + $vat;

            // Create Invoice
            $invoice = Invoice::create([
                'organization_id' => $organizationId,
                'invoice_number' => $request->invoice_number ?? 'INV-' . Str::upper(Str::random(8)),
                'client_id' => $request->client_id,
                'case_id' => $request->case_id,
                'lawyer_id' => $request->lawyer_id,
                'issue_date' => $request->issue_date,
                'due_date' => $request->due_date,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'vat' => $vat,
                'total' => $total,
                'status' => 'draft',
            ]);

            // Save Items
            foreach ($request->items as $item) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $item['description'],
                    'type' => $item['type'],
                    'rate' => $item['rate'],
                    'hours' => $item['hours'],
                    'total' => $item['rate'] * $item['hours'],
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Invoice created successfully',
                'data' => $invoice->load('items')
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Invoice creation failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource (filtered by organization)
     */
    public function show($id)
    {
        $user = Auth::user();
        $organizationId = $user->organization_id;
        
        $invoice = Invoice::where('organization_id', $organizationId)
            ->with(['items', 'payments', 'client', 'case', 'lawyer'])
            ->find($id);
            
        if (!$invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }
        return response()->json($invoice);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Invoice $invoice)
    {
        $user = Auth::user();
        $organizationId = $user->organization_id;
        
        // Verify invoice belongs to organization
        if ($invoice->organization_id !== $organizationId) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        $validated = $request->validate([
            'invoice_number' => 'sometimes|required|string|unique:invoices,invoice_number,' . $invoice->id,
            'client_id' => 'sometimes|required|exists:clients,id',
            'case_id' => 'nullable|exists:cases,id',
            'lawyer_id' => 'nullable|exists:users,id',
            'issue_date' => 'sometimes|required|date',
            'due_date' => 'sometimes|required|date|after_or_equal:issue_date',
            'subtotal' => 'sometimes|required|numeric|min:0',
            'discount' => 'sometimes|required|numeric|min:0',
            'vat' => 'sometimes|required|numeric|min:0',
            'total' => 'sometimes|required|numeric|min:0',
            'status' => 'sometimes|required|in:draft,issued,paid,overdue,cancelled'
        ]);

        $invoice->update($validated);
        return response()->json($invoice);
    }

    /**
     * Remove the specified resource from storage (soft delete)
     */
    public function destroy(Invoice $invoice)
    {
        $user = Auth::user();
        $organizationId = $user->organization_id;
        
        // Verify invoice belongs to organization
        if ($invoice->organization_id !== $organizationId) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        $invoice->delete();
        return response()->json(['message' => 'Invoice deleted successfully']);
    }

    /**
     * Generate PDF for invoice (filtered by organization)
     */
    public function generatePdf($id)
    {
        $user = Auth::user();
        $organizationId = $user->organization_id;
        
        $invoice = Invoice::where('organization_id', $organizationId)
            ->with(['items', 'payments', 'client', 'case', 'lawyer'])
            ->find($id);
            
        if (!$invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }
        
        $pdf = Pdf::loadView('invoices.pdf', compact('invoice'));
        return $pdf->download($invoice->invoice_number . '.pdf');
    }
}