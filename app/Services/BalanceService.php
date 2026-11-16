<?php

namespace App\Services;

use App\Models\CreditTransaction;
use App\Models\User;
use App\Models\UserBalance;
use App\Models\UserLedger;
use Illuminate\Support\Facades\DB;

class BalanceService
{
    public const INITIAL_BALANCE_NANO = 10_000_000_000;

    public function getOrCreate(User $user): UserBalance
    {
        return UserBalance::firstOrCreate(
            ['user_id' => $user->id],
            ['balance_nano' => self::INITIAL_BALANCE_NANO]
        );
    }

    public function hasSufficientBalance(User $user, int $costNano): bool
    {
        $balance = $this->getOrCreate($user);
        return $balance->balance_nano >= $costNano;
    }

    public function debit(User $user, int $costNano, $generation): bool
    {
        return DB::transaction(function () use ($user, $costNano, $generation) {
            if ($generation && UserLedger::where('generation_id', $generation->id)->exists()) {
                return true;
            }

            $balance = UserBalance::where('user_id', $user->id)->lockForUpdate()->first();
            if (!$balance) {
                $this->getOrCreate($user);
                $balance = UserBalance::where('user_id', $user->id)->lockForUpdate()->first();
            }

            if ($balance->balance_nano < $costNano) {
                return false;
            }

            $balance->balance_nano -= $costNano;
            $balance->save();

            if ($generation) {
                UserLedger::firstOrCreate(
                    ['generation_id' => $generation->id],
                    [
                        'user_id' => $user->id,
                        'amount_nano' => $costNano,
                        'type' => 'debit',
                    ]
                );
            }

            CreditTransaction::create([
                'user_id' => $user->id,
                'amount_nano' => $costNano,
                'type' => 'charge',
                'reference' => $generation?->id,
            ]);

            return true;
        });
    }

    public function credit(User $user, int $amountNano, ?string $reference = null): void
    {
        DB::transaction(function () use ($user, $amountNano, $reference) {
            $balance = $this->getOrCreate($user);
            $balance->balance_nano += $amountNano;
            $balance->save();

            CreditTransaction::create([
                'user_id' => $user->id,
                'amount_nano' => $amountNano,
                'type' => 'purchase',
                'reference' => $reference,
            ]);
        });
    }
}
