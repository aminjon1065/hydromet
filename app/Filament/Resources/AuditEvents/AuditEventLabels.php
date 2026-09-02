<?php

namespace App\Filament\Resources\AuditEvents;

use App\Domain\Audit\Models\AuditEvent;
use Illuminate\Support\Facades\Lang;

/**
 * Audit rows outlive the translation file.
 *
 * An action or subject type recorded by a capability that has no label yet
 * falls back to its stable stored code, which is still readable, rather than to
 * a raw translation key such as `audit.actions.measurement_corrected`.
 */
final class AuditEventLabels
{
    public static function action(string $action): string
    {
        return self::translated('audit.actions.'.str_replace('.', '_', $action), $action);
    }

    public static function subjectType(string $subjectType): string
    {
        return self::translated('audit.subject_types.'.$subjectType, $subjectType);
    }

    /**
     * @return array<string, string>
     */
    public static function actionOptions(): array
    {
        return self::options('action', self::action(...));
    }

    /**
     * @return array<string, string>
     */
    public static function subjectTypeOptions(): array
    {
        return self::options('subject_type', self::subjectType(...));
    }

    /**
     * @param  callable(string): string  $label
     * @return array<string, string>
     */
    private static function options(string $column, callable $label): array
    {
        $options = [];

        foreach (AuditEvent::query()->distinct()->orderBy($column)->pluck($column) as $value) {
            if (is_string($value) && $value !== '') {
                $options[$value] = $label($value);
            }
        }

        return $options;
    }

    private static function translated(string $key, string $fallback): string
    {
        if (! Lang::has($key)) {
            return $fallback;
        }

        $translation = __($key);

        return is_string($translation) ? $translation : $fallback;
    }
}
