@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3">
    <div class="row align-items-center">
        <div class="col-md-6">
            <h1 class="h3">{{ $quote->quote_number }}</h1>
        </div>
        <div class="col-md-6 text-md-right">
            <a class="btn btn-soft-secondary" href="{{ route('quotes.print', $quote) }}" target="_blank">Print</a>
            <a class="btn btn-secondary" href="{{ route('quotes.index') }}">Back</a>
        </div>
    </div>
</div>
<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><h5 class="mb-0 h6">Products requested</h5></div>
            <div class="card-body">
                <p><strong>{{ $quote->customer_name }}</strong><br>{{ $quote->customer_phone }}<br>{{ $quote->customer_email }}</p>
                @if ($quote->customer_notes)
                    <p>{{ $quote->customer_notes }}</p>
                @endif
                <table class="table aiz-table mb-0">
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
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h5 class="mb-0 h6">Follow-up</h5></div>
            <div class="card-body">
                <form method="POST" action="{{ route('quotes.status', $quote) }}">
                    @csrf
                    <div class="form-group">
                        <label>Status</label>
                        <select class="form-control" name="status">
                            @foreach ($statuses as $status)
                                <option value="{{ $status }}" @selected($quote->status === $status)>{{ ucfirst($status) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Internal notes</label>
                        <textarea class="form-control" name="admin_notes" rows="5">{{ $quote->admin_notes }}</textarea>
                    </div>
                    <button class="btn btn-primary" type="submit">Save</button>
                </form>
                <hr>
                <p>Sync: <strong>{{ ucfirst($quote->sync_status) }}</strong></p>
                @if ($quote->ultitech_reference)
                    <p>Reference: {{ $quote->ultitech_reference }}</p>
                @endif
                @if ($quote->last_sync_error)
                    <p class="text-muted">{{ $quote->last_sync_error }}</p>
                @endif
                <form method="POST" action="{{ route('quotes.retry', $quote) }}">
                    @csrf
                    <button class="btn btn-soft-primary" type="submit">Retry sync</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
