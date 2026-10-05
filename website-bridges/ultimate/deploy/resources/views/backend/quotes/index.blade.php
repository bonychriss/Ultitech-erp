@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3">
    <div class="row align-items-center">
        <div class="col-md-6">
            <h1 class="h3">Quotes</h1>
        </div>
    </div>
</div>
<div class="card">
    <form class="card-header" method="GET">
        <div class="row gutters-5 w-100">
            <div class="col-md-3">
                <input type="text" class="form-control" name="q" value="{{ request('q') }}" placeholder="Number, name, or phone">
            </div>
            <div class="col-md-2">
                <select class="form-control" name="status">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-control" name="sync_status">
                    <option value="">All sync states</option>
                    @foreach (['pending', 'syncing', 'synced', 'failed'] as $sync)
                        <option value="{{ $sync }}" @selected(request('sync_status') === $sync)>{{ ucfirst($sync) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary" type="submit">Filter</button>
            </div>
        </div>
    </form>
    <div class="card-body">
        <table class="table aiz-table mb-0">
            <thead>
                <tr>
                    <th>Quote</th>
                    <th>Customer</th>
                    <th>Products</th>
                    <th>Status</th>
                    <th>Sync</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($quotes as $quote)
                    <tr>
                        <td>{{ $quote->quote_number }}</td>
                        <td>
                            {{ $quote->customer_name }}<br>
                            <span class="text-muted">{{ $quote->customer_phone }}</span>
                        </td>
                        <td>{{ $quote->items_count }}</td>
                        <td>{{ ucfirst($quote->status) }}</td>
                        <td>{{ ucfirst($quote->sync_status) }}</td>
                        <td>{{ $quote->created_at }}</td>
                        <td><a class="btn btn-soft-primary btn-sm" href="{{ route('quotes.show', $quote) }}">Open</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7">No quotes yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="aiz-pagination mt-3">{{ $quotes->links() }}</div>
    </div>
</div>
@endsection
