<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1f2937; }
        h1 { font-size: 18px; margin: 0 0 12px; }
        .muted { color: #6b7280; }
        .grid { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .grid td { padding: 6px 8px; border: 1px solid #e5e7eb; }
        .label { background: #f3f4f6; width: 24%; font-weight: 700; }
        .table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .table th, .table td { border: 1px solid #e5e7eb; padding: 6px 8px; }
        .table th { background: #f9fafb; font-size: 11px; text-transform: uppercase; }
        .right { text-align: right; }
        .center { text-align: center; }
        .footer { margin-top: 10px; font-size: 11px; color: #6b7280; }
    </style>
</head>
<body>
    <h1>Movimiento de Inventario</h1>
    <table class="grid">
        <tr>
            <td class="label">Documento</td>
            <td>{{ $movement->document_number ?? 'N/D' }}</td>
            <td class="label">Referencia</td>
            <td>{{ $movement->reference_number ?? 'N/D' }}</td>
        </tr>
        <tr>
            <td class="label">Tipo</td>
            <td>{{ $movement->movementType->name ?? 'N/D' }}</td>
            <td class="label">Estado</td>
            <td>{{ strtoupper($movement->status ?? 'N/D') }}</td>
        </tr>
        <tr>
            <td class="label">Fecha</td>
            <td>{{ $movement->movement_date ? \Illuminate\Support\Carbon::parse($movement->movement_date)->format('d/m/Y') : 'N/D' }}</td>
            <td class="label">Creado por</td>
            <td>{{ $movement->createdBy->name ?? 'N/D' }}</td>
        </tr>
        <tr>
            <td class="label">Origen</td>
            <td>{{ $movement->sourceEntity->name ?? 'N/D' }}</td>
            <td class="label">Destino</td>
            <td>{{ $movement->destinationEntity->name ?? 'N/D' }}</td>
        </tr>
        <tr>
            <td class="label">Descripción</td>
            <td colspan="3">{{ $movement->description ?: 'Sin descripción' }}</td>
        </tr>
        <tr>
            <td class="label">Notas</td>
            <td colspan="3">{{ $movement->notes ?: 'Sin notas' }}</td>
        </tr>
    </table>

    <table class="table">
        <thead>
            <tr>
                <th>#</th>
                <th>Artículo</th>
                <th>SKU/Código</th>
                <th class="center">Unidad</th>
                <th class="right">Cantidad</th>
                <th class="right">Costo Unit.</th>
                <th class="right">Total</th>
                <th class="center">Lote</th>
            </tr>
        </thead>
        <tbody>
            @forelse($movement->details as $idx => $line)
                <tr>
                    <td class="center">{{ $idx + 1 }}</td>
                    <td>{{ $line->product->name ?? 'N/D' }}</td>
                    <td>{{ $line->product->sku ?? $line->product->code ?? 'N/D' }}</td>
                    <td class="center">{{ $line->unit->abbreviation ?? $line->unit->name ?? '-' }}</td>
                    <td class="right">{{ number_format((float)($line->quantity ?? 0), 4) }}</td>
                    <td class="right">${{ number_format((float)($line->unit_cost ?? 0), 2) }}</td>
                    <td class="right">${{ number_format((float)($line->total_cost ?? 0), 2) }}</td>
                    <td class="center">{{ $line->lot_number ?: '-' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="center muted">Sin detalles</td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="6" class="right"><strong>Total movimiento</strong></td>
                <td class="right"><strong>${{ number_format((float)($movement->total_amount ?? 0), 2) }}</strong></td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    <p class="footer">Generado: {{ now()->format('d/m/Y H:i') }}</p>
</body>
</html>
