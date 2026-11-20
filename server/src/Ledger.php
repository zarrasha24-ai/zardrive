<?php
declare(strict_types=1);

namespace Zd;

/**
 * Double-entry books. Every money event posts one journal whose debits equal its credits.
 * A journal is unique per (event, ref), so retrying an event can never post it twice.
 */
final class Ledger
{
    /** @param array<int, array{0:string,1:float,2:float,3?:int|null}> $lines [account, debit, credit, party] */
    public static function post(string $event, string $ref, array $lines, string $memo = '', ?int $by = null): int
    {
        $dr = 0.0; $cr = 0.0;
        foreach ($lines as $l) { $dr += $l[1]; $cr += $l[2]; }
        if (abs(r2($dr) - r2($cr)) > 0.001) throw new \RuntimeException("Unbalanced journal $event/$ref: Dr $dr Cr $cr");
        if (r2($dr) <= 0) throw new \RuntimeException("Empty journal $event/$ref");
        $existing = Db::val('SELECT id FROM journal WHERE event = ? AND ref = ?', [$event, $ref]);
        if ($existing) return (int) $existing;
        return Db::tx(function () use ($event, $ref, $lines, $memo, $by) {
            $n = (int) Db::val('SELECT COUNT(*) FROM journal') + 1;
            $id = Db::insert('journal', ['jv_no' => sprintf('JV-%06d', $n), 'event' => $event, 'ref' => $ref, 'memo' => $memo, 'created_by' => $by, 'created_at' => now()]);
            foreach ($lines as $l) {
                if (r2($l[1]) == 0.0 && r2($l[2]) == 0.0) continue;
                Db::insert('journal_lines', ['journal_id' => $id, 'account' => $l[0], 'debit' => r2($l[1]), 'credit' => r2($l[2]), 'party_id' => $l[3] ?? null]);
            }
            return $id;
        });
    }

    /** Balance as debit minus credit. */
    public static function balance(string $account, ?int $party = null): float
    {
        $sql = 'SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines WHERE account = ?';
        $p = [$account];
        if ($party !== null) { $sql .= ' AND party_id = ?'; $p[] = $party; }
        return r2((float) Db::val($sql, $p));
    }

    public static function trial(): array
    {
        return Db::all('SELECT a.code, a.name, a.type, COALESCE(SUM(l.debit),0) dr, COALESCE(SUM(l.credit),0) cr FROM accounts a LEFT JOIN journal_lines l ON l.account = a.code GROUP BY a.code, a.name, a.type ORDER BY a.code');
    }
}
