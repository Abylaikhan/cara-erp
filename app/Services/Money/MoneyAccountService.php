<?php

namespace App\Services\Money;

use App\Models\MoneyAccount;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Деньги» → the window «Расчётный счёт» (legacy openBankAccountModal/saveBankAccount/deleteBankAccount, L15121–15170). Legacy
 * changed its list of accounts only in the page and lost it on reload; here the account is saved (answer of the accountant
 * 23.10, № 37: the opening balances are entered by the company). The type and the currency of an account with operations stay
 * as they are, and such an account is not deleted: its operations would be counted in another currency or left without an
 * account (ADR-0047).
 */
class MoneyAccountService
{
    public const STALE = 'Счёт изменён другим пользователем — обновите страницу и повторите.';

    public const NOT_FOUND = 'Счёт не найден — обновите страницу.';

    /** @param  array{type: string, name: string, bank: ?string, iik: ?string, bik: ?string, opening: string, currency: string}  $data */
    public function create(array $data, User $by): MoneyAccount
    {
        return DB::transaction(function () use ($data, $by) {
            $account = MoneyAccount::query()->create([...self::fields($data), 'sort_order' => (int) MoneyAccount::query()->lockForUpdate()->pluck('sort_order')->max() + 1]);
            Audit::record('money_account.created', 'money_account', $account->id, self::fields($data), $by);

            return $account;
        });
    }

    /** @param  array{type: string, name: string, bank: ?string, iik: ?string, bik: ?string, opening: string, currency: string}  $data */
    public function update(int $id, int $lockVersion, array $data, User $by): MoneyAccount
    {
        return DB::transaction(function () use ($id, $lockVersion, $data, $by) {
            $account = $this->locked($id, $lockVersion);
            $fields = self::fields($data);
            if (($fields['type'] !== $account->type || $fields['currency'] !== $account->currency) && $this->hasOperations($account)) {
                throw ValidationException::withMessages(['type' => 'У счёта есть операции — тип и валюту не изменить']);
            }
            $before = $account->only(array_keys($fields));
            $account->fill($fields);
            $diff = collect($fields)->filter(fn ($v, $k) => $account->isDirty($k))->map(fn ($v, $k) => ['from' => (string) $before[$k], 'to' => (string) $v])->all();
            if ($diff) {
                $account->lock_version++;
                $account->save();
                Audit::record('money_account.updated', 'money_account', $account->id, $diff, $by);
            }

            return $account;
        });
    }

    /** Legacy «Удалить счёт? Все операции по нему сохранятся.»: here only an account without operations. */
    public function delete(int $id, int $lockVersion, User $by): void
    {
        DB::transaction(function () use ($id, $lockVersion, $by) {
            $account = $this->locked($id, $lockVersion);
            if ($this->hasOperations($account)) {
                throw ValidationException::withMessages(['type' => 'У счёта есть операции — его нельзя удалить']);
            }
            Audit::record('money_account.deleted', 'money_account', $account->id, ['name' => $account->name], $by);
            $account->delete();
        });
    }

    private function hasOperations(MoneyAccount $account): bool
    {
        return DB::table('money_transactions')->where('account_id', $account->id)->exists()
            || DB::table('bank_statement_rows')->where('account_id', $account->id)->exists();
    }

    /**
     * Legacy icon by type (💵 cash, 🏦 bank); the opening balance as typed, to the tiyn.
     *
     * @param  array{type: string, name: string, bank: ?string, iik: ?string, bik: ?string, opening: string, currency: string}  $data
     * @return array<string, string|null>
     */
    private static function fields(array $data): array
    {
        return ['type' => $data['type'], 'name' => $data['name'], 'bank' => $data['bank'], 'iik' => $data['iik'], 'bik' => $data['bik'],
            'opening_balance' => bcadd($data['opening'], '0', 2), 'currency' => $data['currency'], 'icon' => $data['type'] === 'cash' ? '💵' : '🏦'];
    }

    private function locked(int $id, int $lockVersion): MoneyAccount
    {
        $account = MoneyAccount::query()->lockForUpdate()->find($id);
        if (! $account) {
            throw ValidationException::withMessages(['lock_version' => self::NOT_FOUND]);
        }
        if ($account->lock_version !== $lockVersion) {
            throw ValidationException::withMessages(['lock_version' => self::STALE]);
        }

        return $account;
    }
}
