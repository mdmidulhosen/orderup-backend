<?php

namespace App\Models;

use App\Traits\Loadable;
use App\Traits\SetCurrency;
use Database\Factories\StockFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * App\Models\Stock
 *
 * @property int $id
 * @property string $countable_type
 * @property int $countable_id
 * @property float $price
 * @property int $quantity
 * @property string $sku
 * @property boolean $addon
 * @property string $img
 * @property Carbon|null $deleted_at
 * @property-read Product $product
 * @property-read Product $countable
 * @property-read mixed $actual_discount
 * @property-read mixed $rate_actual_discount
 * @property-read mixed $tax_price
 * @property-read mixed $rate_tax_price
 * @property-read mixed $total_price
 * @property-read mixed $rate_total_price
 * @property-read mixed $rate_price
 * @property-read Bonus|null $bonus
 * @property-read Collection|OrderDetail[] $orderDetails
 * @property-read int $order_details_count
 * @property-read Collection|OrderDetail[] $receipts
 * @property-read int $receipts_count
 * @property-read int $order_details_sum_quantity
 * @property-read Collection|StockAddon[] $addons
 * @property-read int $addons_count
 * @property-read Collection|CartDetail[] $cartDetails
 * @property-read int $cart_details_count
 * @property-read Collection|Bonus[] $bonusByShop
 * @property-read int $bonus_by_shop_count
 * @property-read Collection|ExtraValue[] $stockExtras
 * @property-read int|null $stock_extras_count
 * @property-read Collection|ExtraValue[] $stockExtrasTrashed
 * @property-read int|null $stock_extras_trashed_count
 * @property-read Collection|ModelLog[] $logs
 * @property-read int|null $logs_count
 * @property-read Collection|StockInventoryItem[] $inventoryItems
 * @property-read int|null $inventory_items_count
 * @method static StockFactory factory(...$parameters)
 * @method static Builder|Stock newModelQuery()
 * @method static Builder|Stock newQuery()
 * @method static Builder|Stock onlyTrashed()
 * @method static Builder|Stock query()
 * @method static Builder|Stock increment($column, $amount = 1, array $extra = [])
 * @method static Builder|Stock decrement($column, $amount = 1, array $extra = [])
 * @method static Builder|Stock whereCountableId($value)
 * @method static Builder|Stock whereCountableType($value)
 * @method static Builder|Stock whereDeletedAt($value)
 * @method static Builder|Stock whereId($value)
 * @method static Builder|Stock wherePrice($value)
 * @method static Builder|Stock whereQuantity($value)
 * @method static Builder|Stock whereSku($value)
 * @method static Builder|Stock withTrashed()
 * @method static Builder|Stock withoutTrashed()
 * @mixin Eloquent
 */
class Stock extends Model
{
    use HasFactory, SoftDeletes, SetCurrency, Loadable;

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'extras' => 'array',
    ];

    protected $hidden = [
        'pivot'
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'countable_id');
    }

    public function countable(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'countable_id');
    }

    public function bonus(): MorphOne
    {
        return $this->morphOne(Bonus::class, 'bonusable');
    }

    public function bonusByShop(): HasMany
    {
        return $this->hasMany(Bonus::class, 'bonus_stock_id');
    }

    public function addons(): HasMany
    {
        return $this->hasMany(StockAddon::class, 'stock_id');
    }

    public function orderDetails(): HasMany
    {
        return $this->hasMany(OrderDetail::class);
    }

    public function cartDetails(): HasMany
    {
        return $this->hasMany(CartDetail::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(ReceiptStock::class);
    }

    public function stockExtras(): BelongsToMany
    {
        return $this->belongsToMany(ExtraValue::class, StockExtra::class)->orderBy('id');
    }

    public function logs(): MorphMany
    {
        return $this->morphMany(ModelLog::class, 'model');
    }

	public function inventoryItems(): HasMany
	{
		return $this->hasMany(StockInventoryItem::class);
	}

    public function stockExtrasTrashed(): BelongsToMany
    {
        return $this->belongsToMany(ExtraValue::class, StockExtra::class)->withTrashed();
    }

    public function getActualDiscountAttribute()
    {
        /** @var Discount $discount */
        $discount = $this->countable?->discounts ?
            $this->countable?->discounts?->where('start', '<=', today())
                ->where('end', '>=', today())
                ->where('active', 1)
                ->first() : optional();

        if (!$discount?->type) {
            return 0;
        }

        $price = $discount->price;

        if ($discount->type == 'percent') {

            $price = ($price / 100 * $this->price);

        }

        return max($price, 0);
    }

    public function getRateActualDiscountAttribute()
    {
        if (request()->is('api/v1/dashboard/user/*') || request()->is('api/v1/rest/*')) {
            return $this->actual_discount * $this->currency();
        }

        return $this->actual_discount;
    }

	public function getTotalPriceAttribute()
	{
		return max($this->price - $this->actual_discount + $this->tax_price, 0);
	}

    public function getRateTotalPriceAttribute()
    {
        if (request()->is('api/v1/dashboard/user/*') || request()->is('api/v1/rest/*')) {
            return $this->total_price * $this->currency();
        }

        return $this->total_price;
    }

    public function getRatePriceAttribute(): float|int|null
    {
        if (request()->is('api/v1/dashboard/user/*') || request()->is('api/v1/rest/*')) {
            return $this->price * $this->currency();
        }

        return $this->price;
    }

    public function getDiscountExpiredAttribute(): ?string
    {
        return data_get($this, 'discount.end');
    }

    public function getTaxPriceAttribute()
    {
        return $this->countable?->tax > 0 ? max(($this->price / 100) * $this->countable?->tax, 0) : 0;
    }

    public function getRateTaxPriceAttribute(): float|int|null
    {
        if (request()->is('api/v1/dashboard/user/*') || request()->is('api/v1/rest/*')) {
            return $this->tax_price * $this->currency();
        }

        return $this->tax_price;
    }
}
