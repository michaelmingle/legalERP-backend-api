<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index()
    {
        $payments = Payment::with(['organization', 'client', 'invoice'])->get();
        return response()->json($payments);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'organization_id' => 'required|exists:organizations,id',
            'invoice_id' => 'nullable|string|unique:invoices,invoice_number',
            'amount' => 'required|numeric|min:0',
            'payment_date' => 'required|date',
            'status' => 'required|in:paid,partial_payment',
        ]);

        $payment = Payment::create([
            'client_id' => $validated['client_id'],
            'organization_id' => $validated['organization_id'],
            'invoice_id' => $validated['invoice_id'],
            'amount' => $validated['amount'],
            'payment_date' => $validated['payment_date'],
            'status' => $validated['status'],
        ]);


        return response()->json([
            'message' => 'Payment saved successfully',
            'data' => $payment,
        ], 201);
    }

    public function edit($id)
    {
        $payment = Payment::findOrFail($id);

        return response()->json([
            'data' => $payment
        ], 200);
    }

    public function show($id)
    {
        $payment = Payment::with(['client', 'organization', 'invoice'])
            ->findOrFail($id);

        return response()->json([
            'data' => $payment
        ], 200);
    }

    public function update(Request $request, $id)
    {
        $payment = Payment::findOrFail($id);

        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'organization_id' => 'required|exists:organizations,id',
            'invoice_id' => 'nullable|exists:invoices,id',
            'amount' => 'required|numeric|min:0',
            'payment_date' => 'required|date',
            'status' => 'required|in:paid,opened,partial_payment',
        ]);

        $payment->update($validated);

        return response()->json([
            'message' => 'Payment updated successfully',
            'data' => $payment
        ], 200);
    }

    public function destroy($id)
    {
        $payment = Payment::findOrFail($id);
        $payment->delete();

        return response()->json([
            'message' => 'Payment deleted successfully'
        ], 200);
    }
}
