<?php
declare(strict_types=1);

namespace Zd;

/**
 * Driver (and agent) wallets. A driver tops up in advance; commission, food shares and passes
 * come off the balance. The balance is money ZarDrive holds for the driver (account 2100), not income.
 */
final class Wallet
{
    public static function balance(int $userId): float
    {
        return r2((float) (Db::val('SELECT balance_after FROM wallet_tx WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$userId]) ?? 0));
    }

    public static function add(int $userId, string $kind, float $amount, string $ref, string $note = '', ?int $journalId = null): float
    {
        $bal = r2(self::balance($userId) + $amount);
        Db::insert('wallet_tx', ['user_id' => $userId, 'kind' => $kind, 'amount' => r2($amount), 'balance_after' => $bal, 'ref' => $ref, 'note' => $note, 'journal_id' => $journalId, 'created_at' => now()]);
        return $bal;
    }

    // ---- top-ups

    public static function requestTopup(int $userId, string $method, string $tid, float $amount): int
    {
        if (!in_array($method, ['jazzcash', 'easypaisa', 'bank'], true)) throw new ApiError('Choose JazzCash, Easypaisa or bank', 422);
        $tid = preg_replace('/\s+/', '', $tid);
        if (!preg_match('/^[A-Za-z0-9]{6,30}$/', $tid)) throw new ApiError('Enter the transaction ID from the SMS', 422);
        if ($amount < 100 || $amount > 100000) throw new ApiError('Top-up must be between Rs 100 and Rs 100,000', 422);
        if (Db::val('SELECT 1 FROM topups WHERE tid = ?', [$tid])) throw new ApiError('This transaction ID was already used', 409);
        return Db::insert('topups', ['user_id' => $userId, 'method' => $method, 'tid' => $tid, 'amount' => r2($amount), 'created_at' => now()]);
    }

    /** Admin decision. Approve may carry the amount actually found on the statement. */
    public static function decideTopup(int $topupId, bool $approve, int $adminId, ?float $actualAmount = null, string $note = ''): void
    {
        Db::tx(function () use ($topupId, $approve, $adminId, $actualAmount, $note) {
            $t = Db::one('SELECT * FROM topups WHERE id = ?', [$topupId]);
            if (!$t) throw new ApiError('Top-up not found', 404);
            if ($t['status'] !== 'pending') throw new ApiError('Already ' . $t['status'], 409);
            if (!$approve) {
                if (trim($note) === '') throw new ApiError('Give a reason to reject', 422);
                Db::run("UPDATE topups SET status = 'rejected', decided_by = ?, decided_at = ?, note = ? WHERE id = ?", [$adminId, now(), $note, $topupId]);
                Audit::log($adminId, 'topup.reject', "#$topupId $note");
                return;
            }
            $amt = r2($actualAmount ?? (float) $t['amount']);
            if ($amt <= 0) throw new ApiError('Amount must be above zero', 422);
            if ($amt != (float) $t['amount'] && trim($note) === '') throw new ApiError('Explain why the amount differs', 422);
            $cash = ['jazzcash' => '1010', 'easypaisa' => '1011', 'bank' => '1020'][$t['method']];
            $j = Ledger::post('topup', (string) $topupId, [[$cash, $amt, 0], ['2100', 0, $amt, (int) $t['user_id']]], 'Driver top-up ' . $t['tid'], $adminId);
            self::add((int) $t['user_id'], 'topup', $amt, 'topup:' . $topupId, strtoupper($t['method']) . ' ' . $t['tid'], $j);
            Db::run("UPDATE topups SET status = 'approved', amount = ?, decided_by = ?, decided_at = ?, note = ? WHERE id = ?", [$amt, $adminId, now(), $note, $topupId]);
            Audit::log($adminId, 'topup.approve', "#$topupId Rs $amt");
        });
    }

    /** Pay back unused balance (driver leaving). */
    public static function refund(int $userId, float $amount, string $method, int $adminId, string $note): void
    {
        Db::tx(function () use ($userId, $amount, $method, $adminId, $note) {
            $amount = r2($amount);
            if ($amount <= 0 || $amount > self::balance($userId)) throw new ApiError('Refund must be above zero and not more than the balance', 422);
            if (trim($note) === '') throw new ApiError('Give a reason for the refund', 422);
            $cash = ['jazzcash' => '1010', 'easypaisa' => '1011', 'bank' => '1020'][$method] ?? throw new ApiError('Unknown method', 422);
            $ref = $userId . '-' . (int) Db::val('SELECT COUNT(*) FROM wallet_tx WHERE user_id = ?', [$userId]);
            $j = Ledger::post('refund', $ref, [['2100', $amount, 0, $userId], [$cash, 0, $amount]], $note, $adminId);
            self::add($userId, 'refund', -$amount, 'refund:' . $ref, $note, $j);
            Audit::log($adminId, 'wallet.refund', "user $userId Rs $amount");
        });
    }

    // ---- passes

    public static function passPrice(string $vehicle, string $kind): float
    {
        $day = Settings::num('pass_day_' . $vehicle);
        if ($day <= 0) throw new ApiError('No pass for this vehicle', 422);
        return $kind === 'week' ? r2($day * Settings::num('pass_week_paid_days', 6)) : $day;
    }

    public static function buyPass(int $driverId, string $kind): array
    {
        if (!in_array($kind, ['day', 'week'], true)) throw new ApiError('Choose day or week', 422);
        return Db::tx(function () use ($driverId, $kind) {
            $d = Db::one('SELECT * FROM drivers WHERE user_id = ?', [$driverId]) ?? throw new ApiError('Not a driver', 403);
            if (in_array($d['plan'], ['day', 'week'], true) && $d['plan_until'] > now()) throw new ApiError('You already have a pass until ' . $d['plan_until'], 409);
            $price = self::passPrice($d['vehicle'], $kind);
            if (self::balance($driverId) < $price) throw new ApiError('Top up first: the pass costs Rs ' . money($price), 402);
            $days = $kind === 'week' ? (int) Settings::num('pass_week_days', 7) : 1;
            $start = now();
            $end = date('Y-m-d H:i:s', strtotime("+$days day"));
            $pid = Db::insert('passes', ['user_id' => $driverId, 'kind' => $kind, 'price' => $price, 'starts_at' => $start, 'ends_at' => $end, 'days' => $days, 'days_recognised' => $kind === 'day' ? 1 : 0]);
            // Day pass is earned at once; a week pass is held as unearned and earned one day at a time.
            $income = $kind === 'day' ? '4200' : '2300';
            $j = Ledger::post('pass', (string) $pid, [['2100', $price, 0, $driverId], [$income, 0, $price]], ucfirst($kind) . ' pass', $driverId);
            self::add($driverId, 'pass', -$price, 'pass:' . $pid, ucfirst($kind) . ' pass until ' . $end, $j);
            Db::run('UPDATE drivers SET plan = ?, plan_until = ? WHERE user_id = ?', [$kind, $end, $driverId]);
            if ($kind === 'week') self::recognisePasses();
            return ['pass_id' => $pid, 'price' => $price, 'until' => $end];
        });
    }

    /** Move week-pass income from unearned to income for each day started. Safe to run any time. */
    public static function recognisePasses(): int
    {
        $n = 0;
        foreach (Db::all("SELECT * FROM passes WHERE kind = 'week' AND days_recognised < days") as $p) {
            $elapsed = (int) floor((time() - strtotime($p['starts_at'])) / 86400) + 1;
            $target = min((int) $p['days'], max(1, $elapsed));
            for ($d = (int) $p['days_recognised'] + 1; $d <= $target; $d++) {
                $per = r2((float) $p['price'] / (int) $p['days']);
                $amt = $d === (int) $p['days'] ? r2((float) $p['price'] - $per * ((int) $p['days'] - 1)) : $per;
                Ledger::post('pass.earn', $p['id'] . ':' . $d, [['2300', $amt, 0], ['4200', 0, $amt]], 'Week pass day ' . $d);
                Db::run('UPDATE passes SET days_recognised = ? WHERE id = ?', [$d, $p['id']]);
                $n++;
            }
        }
        return $n;
    }
}
