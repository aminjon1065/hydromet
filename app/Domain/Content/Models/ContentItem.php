<?php

namespace App\Domain\Content\Models;

use App\Domain\Content\Enums\ContentStatus;
use App\Domain\Content\Enums\ContentType;
use App\Domain\Content\Exceptions\MissingContentTranslation;
use App\Domain\Content\Observers\ContentItemObserver;
use App\Domain\Identity\Models\User;
use App\Support\Locale\SupportedLocale;
use Database\Factories\ContentItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * A local, explicitly translated CMS record.
 *
 * Bodies are plain text rather than trusted HTML. This keeps both the public
 * React page and API safe by default while Hydromet's final authoring policy is
 * still pending.
 *
 * @property int $id
 * @property ContentType $type
 * @property string $slug
 * @property string|null $title_tj
 * @property string|null $title_ru
 * @property string|null $title_en
 * @property string|null $summary_tj
 * @property string|null $summary_ru
 * @property string|null $summary_en
 * @property string|null $body_tj
 * @property string|null $body_ru
 * @property string|null $body_en
 * @property ContentStatus $status
 * @property Carbon|null $published_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $published_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'type',
    'slug',
    'title_tj',
    'title_ru',
    'title_en',
    'summary_tj',
    'summary_ru',
    'summary_en',
    'body_tj',
    'body_ru',
    'body_en',
    'status',
    'published_at',
    'created_by',
    'updated_by',
    'published_by',
])]
#[ObservedBy(ContentItemObserver::class)]
#[UseFactory(ContentItemFactory::class)]
class ContentItem extends Model
{
    /** @use HasFactory<ContentItemFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (ContentItem $item): void {
            if ($item->status !== ContentStatus::Published) {
                return;
            }

            $errors = [];

            foreach (SupportedLocale::values() as $locale) {
                foreach (['title', 'body'] as $field) {
                    $attribute = $field.'_'.$locale;
                    $value = $item->getAttribute($attribute);

                    if (! is_string($value) || trim($value) === '') {
                        $errors[$attribute][] = __('content.validation.required_for_publication');
                    }
                }
            }

            if ($item->published_at === null) {
                $errors['published_at'][] = __('content.validation.published_at_required');
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ContentType::class,
            'status' => ContentStatus::class,
            'published_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<ContentItem>  $query
     * @return Builder<ContentItem>
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query
            ->where('status', ContentStatus::Published)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * @throws MissingContentTranslation when the record has no title in the
     *                                   requested language
     */
    public function localizedTitle(?SupportedLocale $locale = null): string
    {
        return $this->localizedRequired('title', $locale);
    }

    public function localizedSummary(?SupportedLocale $locale = null): ?string
    {
        return $this->localizedOptional('summary', $locale);
    }

    /**
     * @throws MissingContentTranslation when the record has no body in the
     *                                   requested language
     */
    public function localizedBody(?SupportedLocale $locale = null): string
    {
        return $this->localizedRequired('body', $locale);
    }

    /**
     * The title if this record has one in the active language.
     *
     * Administrative screens list drafts, which are allowed to be incomplete, so
     * they ask for the translation instead of demanding it.
     */
    public function localizedTitleIfPresent(?SupportedLocale $locale = null): ?string
    {
        return $this->localizedOptional('title', $locale);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    private function localizedRequired(string $field, ?SupportedLocale $locale): string
    {
        $locale ??= SupportedLocale::current();
        $value = $this->localizedOptional($field, $locale);

        if ($value === null) {
            throw MissingContentTranslation::for($this->slug, $field, $locale->value);
        }

        return $value;
    }

    private function localizedOptional(string $field, ?SupportedLocale $locale): ?string
    {
        $locale ??= SupportedLocale::current();
        $value = $this->getAttribute($field.'_'.$locale->value);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
