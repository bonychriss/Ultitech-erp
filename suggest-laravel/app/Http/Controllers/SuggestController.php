<?php

namespace App\Http\Controllers;

use App\Models\DeveloperSuggestion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SuggestController extends Controller
{
    public function index(Request $request): View
    {
        $erp = $request->attributes->get('erp', []);
        $suggestions = DeveloperSuggestion::query()
            ->with('user:id,full_name')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return view('suggest.index', [
            'suggestions' => $suggestions,
            'backUrl' => (string) ($erp['back_url'] ?? '/'),
            'formAction' => (string) ($erp['suggest_url'] ?? ''),
            'success' => $request->session()->get('success'),
            'error' => $request->session()->get('error'),
            'engine' => 'Laravel + Blade',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $erp = $request->attributes->get('erp', []);
        $userId = (int) ($erp['user_id'] ?? 0);
        $redirectTo = (string) ($erp['suggest_url'] ?? '/suggest');

        $validated = $request->validate([
            'suggestion' => ['required', 'string', 'min:3', 'max:5000'],
        ]);

        if ($userId <= 0) {
            return redirect()->to($redirectTo)
                ->with('error', 'You must be logged in to submit a suggestion.');
        }

        DeveloperSuggestion::query()->create([
            'user_id' => $userId,
            'suggestion' => trim($validated['suggestion']),
            'status' => 'pending',
        ]);

        return redirect()->to($redirectTo)
            ->with('success', 'Feature request submitted successfully! The developer will review it.');
    }
}
