<?php

namespace App\Domain\Alerts\Enums;

/**
 * CAP 1.2 `info.severity`, docs/03-data-contracts.md section 7.
 *
 * The order below is the CAP order and is the only ranking the portal claims.
 * Display colours are NOT defined here: Hydromet has not approved a national
 * severity palette (docs/08-hydromet-input-checklist.md, section 3), so the
 * portal keeps them in configuration and labels them provisional.
 */
enum AlertSeverity: string
{
    case Extreme = 'Extreme';
    case Severe = 'Severe';
    case Moderate = 'Moderate';
    case Minor = 'Minor';
    case Unknown = 'Unknown';

    public function label(): string
    {
        return __('alerts.severities.'.$this->value);
    }

    /**
     * Rank used for ordering only, highest first. Not a published index.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Extreme => 5,
            self::Severe => 4,
            self::Moderate => 3,
            self::Minor => 2,
            self::Unknown => 1,
        };
    }

    /**
     * The same ranking, expressed so a database can sort by it.
     *
     * Sorting the stored column directly would be an alphabetical sort, which
     * puts `Extreme` after `Severe` and `Minor` before `Moderate` — the exact
     * opposite of the intended order in two places. A `CASE` expression is the
     * portable way to say it: MySQL's `FIELD()` does not exist on PostgreSQL or
     * SQLite, and a lookup table would put the ranking somewhere other than
     * here.
     *
     * Values are bound rather than interpolated, and an unknown stored value
     * sorts last instead of being silently treated as the most severe. The
     * column is fixed rather than a parameter, so the fragment stays a literal
     * string that no caller can inject into.
     *
     * @return array{literal-string, list<string|int>}
     */
    public static function descendingRankOrder(): array
    {
        $sql = 'CASE severity';
        $bindings = [];

        foreach (self::cases() as $case) {
            $sql .= ' WHEN ? THEN ?';
            $bindings[] = $case->value;
            $bindings[] = $case->rank();
        }

        return [$sql.' ELSE 0 END DESC', $bindings];
    }

    /**
     * @return list<string>
     */
    public static function descendingRankValues(): array
    {
        $cases = self::cases();

        usort($cases, static fn (self $left, self $right): int => $right->rank() <=> $left->rank());

        return array_column($cases, 'value');
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
