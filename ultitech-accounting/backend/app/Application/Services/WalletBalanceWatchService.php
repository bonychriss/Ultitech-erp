<?php

namespace App\Application\Services;

use App\Models\WalletBalanceWatch;
use Illuminate\Support\Facades\Schema;

/**
 * Real balance comparison:
 * 1) Prefer change since last refresh (9:30 → 9:40 style).
 * 2) If no mid-day move yet, compare live balance to classic opening.
 */
final class WalletBalanceWatchService
{
    private const EPSILON = 0.005;

    /**
     * @return array{
     *   balance_direction:?string,
     *   balance_delta:?float,
     *   previous_balance:?float,
     *   balance_changed_at:?string,
     *   balance_change_basis:?string
     * }
     */
    public function observe(
        int $companyId,
        int $bookId,
        string $walletType,
        int $walletId,
        ?float $currentBalance,
        ?float $baselineBalance = null
    ): array {
        $empty = [
            'balance_direction' => null,
            'balance_delta' => null,
            'previous_balance' => null,
            'balance_changed_at' => null,
            'balance_change_basis' => null,
        ];

        if ($currentBalance === null || ! Schema::hasTable('acc_wallet_balance_watches')) {
            return $empty;
        }

        $walletType = $walletType === 'cash' ? 'cash' : 'bank';
        $now = now();

        $watch = WalletBalanceWatch::query()->firstOrNew([
            'company_id' => $companyId,
            'book_id' => $bookId,
            'wallet_type' => $walletType,
            'wallet_id' => $walletId,
        ]);

        $previous = $watch->exists && $watch->last_balance !== null
            ? (float) $watch->last_balance
            : null;

        if ($previous === null) {
            [$direction, $delta, $basis] = $this->directionFromBaseline($currentBalance, $baselineBalance);
            $watch->forceFill([
                'last_balance' => $currentBalance,
                'direction' => $direction,
                'delta' => $delta,
                'changed_at' => $direction ? $now : null,
                'seen_at' => $now,
            ])->save();

            return [
                'balance_direction' => $direction,
                'balance_delta' => $delta,
                'previous_balance' => $basis === 'opening' ? $baselineBalance : null,
                'balance_changed_at' => $direction ? $now->toIso8601String() : null,
                'balance_change_basis' => $basis,
            ];
        }

        $move = round($currentBalance - $previous, 4);
        $direction = $watch->direction;
        $delta = $watch->delta !== null ? (float) $watch->delta : null;
        $changedAt = $watch->changed_at;
        $previousForUi = $previous;
        $basis = in_array($direction, ['up', 'down'], true) ? 'last_seen' : null;

        if (abs($move) >= self::EPSILON) {
            $direction = $move > 0 ? 'up' : 'down';
            $delta = $move;
            $changedAt = $now;
            $previousForUi = $previous;
            $basis = 'last_seen';
            $watch->forceFill([
                'last_balance' => $currentBalance,
                'direction' => $direction,
                'delta' => $delta,
                'changed_at' => $changedAt,
                'seen_at' => $now,
            ])->save();
        } else {
            // No new move since last refresh.
            if (! in_array($direction, ['up', 'down'], true)) {
                [$direction, $delta, $basis] = $this->directionFromBaseline($currentBalance, $baselineBalance);
                $previousForUi = $basis === 'opening' ? $baselineBalance : null;
                $changedAt = $direction ? $now : null;
                $watch->forceFill([
                    'direction' => $direction,
                    'delta' => $delta,
                    'changed_at' => $changedAt,
                    'seen_at' => $now,
                ])->save();
            } else {
                $watch->forceFill([
                    'seen_at' => $now,
                ])->save();
                if ($delta !== null) {
                    $previousForUi = round($currentBalance - $delta, 4);
                }
                // If stored delta matches opening gap, label as opening; else last_seen.
                if (
                    $baselineBalance !== null
                    && $delta !== null
                    && abs(($currentBalance - $baselineBalance) - $delta) < self::EPSILON
                ) {
                    $basis = 'opening';
                    $previousForUi = $baselineBalance;
                }
            }
        }

        return [
            'balance_direction' => in_array($direction, ['up', 'down'], true) ? $direction : null,
            'balance_delta' => $delta,
            'previous_balance' => $previousForUi,
            'balance_changed_at' => $changedAt?->toIso8601String(),
            'balance_change_basis' => $basis,
        ];
    }

    /**
     * @return array{0:?string,1:?float,2:?string}
     */
    private function directionFromBaseline(float $currentBalance, ?float $baselineBalance): array
    {
        if ($baselineBalance === null) {
            return [null, null, null];
        }

        $delta = round($currentBalance - $baselineBalance, 4);
        if (abs($delta) < self::EPSILON) {
            return [null, null, null];
        }

        return [$delta > 0 ? 'up' : 'down', $delta, 'opening'];
    }
}
