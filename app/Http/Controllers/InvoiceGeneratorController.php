<?php

namespace App\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class InvoiceGeneratorController extends Controller
{
    public function create(): View
    {
        return view('invoices.generator', [
            'company' => [
                'name' => 'Liverpool Hair & Cosmetics Ltd',
                'address' => '31 Dawson Way, St Johns Shopping Centre, Liverpool L1 1LJ',
                'phone' => '0151 708 0699',
                'logo' => asset('images/lhc-logo.png'),
            ],
        ]);
    }

    public function pdf(Request $request): Response
    {
        $validated = $request->validate([
            'fields' => ['nullable', 'array'],
            'fields.number' => ['nullable', 'string', 'max:120'],
            'fields.date' => ['nullable', 'string', 'max:40'],
            'fields.dueDate' => ['nullable', 'string', 'max:40'],
            'fields.customerName' => ['nullable', 'string', 'max:255'],
            'fields.customerAddress' => ['nullable', 'string', 'max:1000'],
            'fields.customerContact' => ['nullable', 'string', 'max:255'],
            'fields.vatRate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fields.notes' => ['nullable', 'string', 'max:1500'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.qty' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'lines.*.price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        $fields = $validated['fields'] ?? [];
        $lines = collect($validated['lines'])
            ->map(function (array $line): array {
                $qty = (float) ($line['qty'] ?? 0);
                $price = (float) ($line['price'] ?? 0);

                return [
                    'description' => trim((string) ($line['description'] ?? '')) ?: 'N/A',
                    'qty' => $qty,
                    'price' => $price,
                    'total' => $qty * $price,
                ];
            })
            ->values();

        $subtotal = (float) $lines->sum('total');
        $vatRate = (float) ($fields['vatRate'] ?? 0);
        $vat = $subtotal * ($vatRate / 100);
        $total = $subtotal + $vat;

        $logoPath = public_path('images/lhc-logo.png');
        $logoDataUri = file_exists($logoPath)
            ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath))
            : null;

        $company = [
            'name' => 'Liverpool Hair & Cosmetics Ltd',
            'address' => '31 Dawson Way, St Johns Shopping Centre, Liverpool L1 1LJ',
            'phone' => '0151 708 0699',
            'logo' => $logoDataUri,
        ];

        $invoiceNumber = trim((string) ($fields['number'] ?? 'LHC-INVOICE')) ?: 'LHC-INVOICE';
        $fileName = Str::slug($invoiceNumber).'.pdf';

        $pdf = Pdf::loadView('invoices.pdf', [
            'company' => $company,
            'fields' => $fields,
            'lines' => $lines,
            'subtotal' => $subtotal,
            'vatRate' => $vatRate,
            'vat' => $vat,
            'total' => $total,
        ])->setPaper('a4');

        /** @var \Barryvdh\DomPDF\PDF $pdf */
        return $pdf->download($fileName);
    }
}
