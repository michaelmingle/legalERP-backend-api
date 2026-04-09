<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use App\Models\InvoiceItem;
use App\Models\InvoiceDiscount;
use App\Models\InvoicePayment;
use Barryvdh\DomPDF\Facade\Pdf;

class BillingController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $invoices = Invoice::with(['items', 'payments'])->get();
        return response()->json($invoices);
    }

    public function myInvoice() 
    {

        $invoices = Invoice::where('lawyer_id', Auth::user()->id)->get();
        return response()->json($invoices);
    }

    // Client Invoices
    public function clientInvoices()
    {
        $clientInvoices = Invoice::where('client_id', Auth::user()->id)->get();
        return response()->json($clientInvoices);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
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

        // Prevent discount > subtotal
        $discount = min($discount, $subtotal);

        // Calculate VAT
        $vatPercentage = $request->vat_percentage ?? 12.5;
        $vat = (($subtotal - $discount) * $vatPercentage) / 100;

        // Final Total
        $total = $subtotal - $discount + $vat;

        // Create Invoice
        $invoice = Invoice::create([
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


// PDF generation method
public function generatePdf(Invoice $invoice)
{
    $invoice->load(['items', 'payments']);
    $pdf = Pdf::loadView('invoices.pdf', compact('invoice'));
    return $pdf->download($invoice->invoice_number . '.pdf');
}

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $invoice = Invoice::with('items')->find($id);
        if (!$invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }
        return response()->json($invoice);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Invoice $billing)
    {
        //match with id
        $billing->load(['items', 'discounts', 'payments']);
        return response()->json($billing);
        
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Invoice $invoice)
    {
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
     * Remove the specified resource from storage.
     */
    public function destroy(Invoice $invoice)
    {
        $invoice->delete();
        return response()->json(['message' => 'Invoice deleted successfully']);
    }
}
