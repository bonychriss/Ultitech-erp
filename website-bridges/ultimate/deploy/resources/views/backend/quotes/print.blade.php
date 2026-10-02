@extends('backend.layouts.blank')

@section('content')
<div class="container py-4">
    <h1>{{ $quote->quote_number }}</h1>
    <p>{{ $quote->customer_name }}<br>{{ $quote->customer_phone }}<br>{{ $quote->customer_email }}</p>
    @if ($quote->customer_notes)
        <p>{{ $quote->customer_notes }}</p>
    @endif
    <table class="table">
        <thead>
            <tr>
                <th>Product</th>
                <th>SKU</th>
                <th>Qty</th>
                <th>Unit price</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($quote->items as $item)
                <tr>
                    <td>{{ $item->product_name }}</td>
                    <td>{{ $item->sku }}</td>
                    <td>{{ $item->quantity }}</td>
                    <td>{{ $item->unit_price }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p>Status: {{ ucfirst($quote->status) }}</p>
    <button type="button" onclick="window.print()">Print</button>
</div>
@endsection
