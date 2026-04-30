<?php

namespace Gloudemans\Shoppingcart;

use Gloudemans\Shoppingcart\Traits\CartHelper;
use Illuminate\Contracts\Support\Arrayable;
use Gloudemans\Shoppingcart\Contracts\Buyable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class CartItem implements Arrayable, Jsonable
{
    use CartHelper;

    /**
     * The rowID of the cart item.
     *
     * @var string
     */
    public $rowId;

    /**
     * The ID of the cart item.
     *
     * @var int|string
     */
    public $id;

    /**
     * The quantity for this cart item.
     *
     * @var int|float
     */
    public $qty;

    /**
     * The name of the cart item.
     *
     * @var string
     */
    public $name;

    /**
     * The price without TAX of the cart item.
     *
     * @var float
     */
    public $price;

    /**
     * The options for this cart item.
     *
     * @var array
     */
    public $options;

    /**
     * The FQN of the associated model.
     *
     * @var string|null
     */
    private $associatedModel = null;

    /**
     * The tax rate for the cart item.
     *
     * @var int|float
     */
    private $taxRate = 0;

    /**
     * Whether the tax is already included.
     *
     * @var bool
     */
    private $taxIncluded = false;

    /**
     * Is item saved for later.
     *
     * @var boolean
     */
    private $isSaved = false;

    /**
     * CartItem constructor.
     *
     * @param int|string $id
     * @param string     $name
     * @param float      $price
     * @param array      $options
     */
    public function __construct($id, $name, $price, array $options = [])
    {
        if(empty($id)) {
            throw new \InvalidArgumentException('Please supply a valid identifier.');
        }
        if(empty($name)) {
            throw new \InvalidArgumentException('Please supply a valid name.');
        }
        if(strlen($price) < 0 || ! is_numeric($price)) {
            throw new \InvalidArgumentException('Please supply a valid price.');
        }

        $this->id       = $id;
        $this->name     = $name;
        $this->price    = floatval($price);
        $this->options  = new CartItemOptions($options);
        $this->rowId = $this->generateRowId($id, $options);
    }

    /**
     * Returns the formatted price without TAX.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return string
     */
    public function price($decimals = null, $decimalPoint = null, $thousandSeperator = null): string
    {
        $decimals = is_null($decimals) ? config('cart.format.price_ex_tax_decimals') : $decimals;

        return $this->numberFormat($this->price, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Returns the formatted price with TAX.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return string
     */
    public function priceTax($decimals = null, $decimalPoint = null, $thousandSeperator = null): string
    {
        if ($this->taxIncluded === true) {
            $priceTax = $this->price;
        } else {
            $priceTax = $this->price + $this->getRawTax();
        }

        $decimals = is_null($decimals) ? config('cart.format.price_inc_tax_decimals') : $decimals;

        return $this->numberFormat($priceTax, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Returns the formatted subtotal.
     * Subtotal is price for whole CartItem without TAX
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return string
     */
    public function subtotal($decimals = null, $decimalPoint = null, $thousandSeperator = null): string
    {
        $subtotal = $this->qty * $this->price;
        $decimals = is_null($decimals) ? config('cart.format.subtotal_ex_tax_decimals') : $decimals;

        return $this->numberFormat($subtotal, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Returns the formatted subtotal.
     * Subtotal is price for whole CartItem with TAX
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return string
     */
    public function subtotalTax($decimals = null, $decimalPoint = null, $thousandSeperator = null): string
    {
        $subtotal = $this->getRawTotal();

        $decimals = is_null($decimals) ? config('cart.format.subtotal_inc_tax_decimals') : $decimals;

        return $this->numberFormat($subtotal, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Returns the formatted total.
     * Total is price for whole CartItem with tax
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return string
     */
    public function total($decimals = null, $decimalPoint = null, $thousandSeperator = null): string
    {
        $total = $this->getRawTotal();
        $decimals = is_null($decimals) ? config('cart.format.total_decimals') : $decimals;

        return $this->numberFormat($total, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Returns the formatted tax.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return string
     */
    public function tax($decimals = null, $decimalPoint = null, $thousandSeperator = null): string
    {
        if ($this->taxIncluded === true) {
            $tax = $this->price / (1 + $this->taxRate);
        } else {
            $tax = $this->price * ($this->taxRate / 100);
        }

        $decimals = is_null($decimals) ? config('cart.format.tax_decimals') : $decimals;

        return $this->numberFormat($tax, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Returns the formatted tax.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return string
     */
    public function taxTotal($decimals = null, $decimalPoint = null, $thousandSeperator = null): string
    {
        $taxTotal = $this->getRawTaxTotal();
        $decimals = is_null($decimals) ? config('cart.format.tax_total_decimals') : $decimals;

        return $this->numberFormat($taxTotal, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Returns the raw (unformatted) unit tax as a float.
     *
     * @return float
     */
    public function getRawTax(): float
    {
        if ($this->taxIncluded === true) {
            return $this->price / (1 + $this->taxRate);
        }

        return $this->price * ($this->taxRate / 100);
    }

    /**
     * Returns the raw (unformatted) subtotal (qty * price) as a float.
     *
     * @return float
     */
    public function getRawSubtotal(): float
    {
        return $this->qty * $this->price;
    }

    /**
     * Returns the raw (unformatted) total tax for this line as a float.
     *
     * @return float
     */
    public function getRawTaxTotal(): float
    {
        return $this->getRawTax() * $this->qty;
    }

    /**
     * Returns the raw (unformatted) line total as a float.
     *
     * @return float
     */
    public function getRawTotal(): float
    {
        if ($this->taxIncluded === true) {
            return $this->getRawSubtotal();
        }

        return $this->getRawSubtotal() + $this->getRawTaxTotal();
    }

    /**
     * Set the quantity for this cart item.
     *
     * @param $qty
     * @return $this
     */
    public function setQuantity($qty): self
    {
        if(empty($qty) || ! is_numeric($qty))
            throw new \InvalidArgumentException('Please supply a valid quantity.');

        $this->qty = $qty;

        return $this;
    }

    /**
     * Update the cart item from a Buyable.
     *
     * @param \Gloudemans\Shoppingcart\Contracts\Buyable $item
     * @return void
     */
    public function updateFromBuyable(Buyable $item): void
    {
        $this->id       = $item->getBuyableIdentifier($this->options);
        $this->name     = $item->getBuyableDescription($this->options);
        $this->price    = $item->getBuyablePrice($this->options);
    }

    /**
     * Update the cart item from an array.
     *
     * @param array $attributes
     * @return void
     */
    public function updateFromArray(array $attributes): void
    {
        $this->id       = Arr::get($attributes, 'id', $this->id);
        $this->qty      = Arr::get($attributes, 'qty', $this->qty);
        $this->name     = Arr::get($attributes, 'name', $this->name);
        $this->price    = Arr::get($attributes, 'price', $this->price);
        $this->options  = new CartItemOptions(Arr::get($attributes, 'options', $this->options));

        $this->rowId = $this->generateRowId($this->id, $this->options->all());
    }

    /**
     * Associate the cart item with the given model.
     *
     * @param mixed $model
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    public function associate($model): self
    {
        $this->associatedModel = is_string($model) ? $model : get_class($model);

        return $this;
    }

    /**
     * Set the tax rate.
     *
     * @param int|float $taxRate
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    public function setTaxRate($taxRate): self
    {
        $this->taxRate = $taxRate;

        return $this;
    }

    /**
     * Set the tax rate.
     *
     * @param bool $taxIncluded
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    public function setTaxIncluded(bool $taxIncluded): self
    {
        $this->taxIncluded = $taxIncluded;

        return $this;
    }

    /**
     * Set saved state.
     *
     * @param bool $bool
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    public function setSaved($bool): self
    {
        $this->isSaved = $bool;

        return $this;
    }

    /**
     * Get an attribute from the cart item or get the associated model.
     *
     * @param string $attribute
     * @return mixed
     */
    public function __get($attribute): mixed
    {
        if (property_exists($this, $attribute)) {
            return $this->{$attribute};
        }

        if ($attribute === 'priceTax') {
            return $this->priceTax();
        }

        if ($attribute === 'subtotal') {
            return $this->subtotal();
        }

        if ($attribute === 'subtotalTax') {
            return $this->subtotalTax();
        }

        if ($attribute === 'total') {
            return $this->total();
        }

        if ($attribute === 'tax') {
            return $this->tax();
        }

        if ($attribute === 'taxTotal') {
            return $this->taxTotal();
        }

        if ($attribute === 'model' && isset($this->associatedModel)) {
            return with(new $this->associatedModel)->find($this->id);
        }

        return null;
    }

    /**
     * Create a new instance from a Buyable.
     *
     * @param \Gloudemans\Shoppingcart\Contracts\Buyable $item
     * @param array                                      $options
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    public static function fromBuyable(Buyable $item, array $options = []): self
    {
        return new self($item->getBuyableIdentifier($options), $item->getBuyableDescription($options), $item->getBuyablePrice($options), $options);
    }

    /**
     * Create a new instance from the given array.
     *
     * @param array $attributes
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    public static function fromArray(array $attributes): self
    {
        $options = Arr::get($attributes, 'options', []);

        return new self($attributes['id'], $attributes['name'], $attributes['price'], $options);
    }

    /**
     * Create a new instance from the given attributes.
     *
     * @param int|string $id
     * @param string     $name
     * @param float      $price
     * @param array      $options
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    public static function fromAttributes($id, $name, $price, array $options = []): self
    {
        return new self($id, $name, $price, $options);
    }

    /**
     * Generate a unique id for the cart item.
     *
     * @param string $id
     * @param array  $options
     * @return string
     */
    protected function generateRowId($id, array $options): string
    {
        ksort($options);

        $uniqueString = '';

        if (config('cart.allow_multiple_same_id') === true) {
            $uniqueString = microtime(true);
        }

        return md5($id . $uniqueString . serialize($options));
    }

    /**
     * Get the instance as an array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'rowId'    => $this->rowId,
            'id'       => $this->id,
            'name'     => $this->name,
            'qty'      => $this->qty,
            'price'    => $this->price,
            'options'  => $this->options->toArray(),
            'tax'      => $this->tax,
            'isSaved'  => $this->isSaved,
            'subtotal' => $this->subtotal
        ];
    }

    /**
     * Convert the object to its JSON representation.
     *
     * @param int $options
     * @return string
     */
    public function toJson($options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }
}
