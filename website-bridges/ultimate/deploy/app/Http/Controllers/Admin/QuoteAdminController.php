<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Quote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuoteAdminController extends Controller
{
    private const STATUSES = ['pending', 'contacted', 'quoted', 'accepted', 'rejected'];

    public function index(Request $request)
    {
        $query = Quote::query()->withCount('items')->orderByDesc('id');
        $status = (string) $request->query('status', '');
        $sync = (string) $request->query('sync_status', '');
        $term = trim((string) $request->query('q', ''));
        if (in_array($status, self::STATUSES, true)) {
            $query->where('status', $status);
        }
        if (in_array($sync, ['pending', 'syncing', 'synced', 'failed'], true)) {
            $query->where('sync_status', $sync);
        }
        if ($term !== '') {
            $query->where(function ($inner) use ($term) {
                $inner->where('quote_number', 'like', '%' . $term . '%')
                    ->orWhere('customer_name', 'like', '%' . $term . '%')
                    ->orWhere('customer_phone', 'like', '%' . $term . '%')
                    ->orWhere('customer_email', 'like', '%' . $term . '%');
            });
        }
        $quotes = $query->paginate(20)->withQueryString();

        return view('backend.quotes.index', [
            'quotes' => $quotes,
            'statuses' => self::STATUSES,
        ]);
    }

    public function show(Quote $quote)
    {
        $quote->load('items');

        return view('backend.quotes.show', [
            'quote' => $quote,
            'statuses' => self::STATUSES,
        ]);
    }

    public function updateStatus(Request $request, Quote $quote)
    {
        $data = $request->validate([
            'status' => 'required|in:pending,contacted,quoted,accepted,rejected',
            'admin_notes' => 'nullable|string|max:5000',
        ]);
        $quote->status = $data['status'];
        $quote->admin_notes = $data['admin_notes'] ?? null;
        $quote->save();
        flash(translate('Quote updated'))->success();

        return back();
    }

    public function retry(Quote $quote)
    {
        $lib = base_path('ultitech/bridge-lib.php');
        if (!is_file($lib)) {
            flash(translate('Sync library is missing'))->error();
            return back();
        }
        require_once $lib;
        $pdo = DB::connection()->getPdo();
        $ok = ultitechSyncQuote($pdo, (int) $quote->id);
        if ($ok) {
            flash(translate('Quote synced'))->success();
        } else {
            flash(translate('Sync will retry automatically'))->warning();
        }

        return back();
    }

    public function printView(Quote $quote)
    {
        $quote->load('items');

        return view('backend.quotes.print', ['quote' => $quote]);
    }
}
