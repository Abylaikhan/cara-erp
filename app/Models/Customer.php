<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Покупатель (M08, legacy table customers / personsData). The channel tells who brought the customer: the office or
 * a salon/dealer; channel-bound roles see only their own customers (App\Support\ChannelScope).
 *
 * @property int $id
 * @property string $name
 * @property string $type
 * @property string|null $inn
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property string|null $comment
 * @property string $channel
 * @property int $lock_version
 */
class Customer extends Model
{
    use HasPreciseTimestamps;

    public const LEGAL = 'Юр. лицо';

    public const INDIVIDUAL = 'Физ. лицо';

    /** Types the form offers (legacy personModal); older legacy rows also have «client» and «company». */
    public const FORM_TYPES = [self::LEGAL, self::INDIVIDUAL];

    // Legal forms of renderPersons (L18889): at the start of the name, the six main ones anywhere as a separate word.
    private const LEGAL_PREFIX = '/^(ТОО|ИП|АО|ООО|ЗАО|ОАО|КПК|ПК|СХПК|НКО|МКО)(?![\p{L}\p{N}_])/iu';

    private const LEGAL_WORD = '/(?<![\p{L}\p{N}_])(ТОО|ИП|АО|ООО|ЗАО|ОАО)(?![\p{L}\p{N}_])/iu';

    protected $fillable = ['name', 'type', 'inn', 'phone', 'email', 'address', 'comment'];

    protected function casts(): array
    {
        return ['lock_version' => 'integer'];
    }

    /** @return BelongsTo<SalesChannel, $this> */
    public function salesChannel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class, 'channel', 'code');
    }

    /**
     * Legacy renderPersons: a legal entity by type or by a legal form in the name. Legacy wrote the name test with JS `\b`,
     * which does not see word boundaries next to Cyrillic letters, so «ТОО …» was shown as «Физ. лицо»
     * (docs/adr/0006-customers-and-channels.md, decision 4).
     */
    public function isLegal(): bool
    {
        return in_array($this->type, [self::LEGAL, 'company'], true) || self::hasLegalForm($this->name);
    }

    public static function hasLegalForm(string $name): bool
    {
        $name = trim($name);

        return preg_match(self::LEGAL_PREFIX, $name) === 1 || preg_match(self::LEGAL_WORD, $name) === 1;
    }

    /**
     * «Контрагенты» tab (legacy renderPersons): direct customers of the office — added with the office channel, or named
     * as the customer of an office order.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOfficeDirect(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->where('channel', SalesChannel::OFFICE)
            ->orWhereIn('name', Order::query()->select('customer')->where('channel', SalesChannel::OFFICE)->whereNotNull('customer')));
    }
}
