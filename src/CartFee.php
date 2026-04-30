<?php

namespace Gloudemans\Shoppingcart;

use Gloudemans\Shoppingcart\Traits\CartHelper;

/**
 * Class CartFee.
 */
class CartFee
{
    use CartHelper;

    public $amount;
    public $taxRate = 0;
    public $options = [];

    /**
     * CartFee constructor.
     *
     * @param $amount
     * @param $taxRate
     * @param array $options
     */
    public function __construct($amount, $taxRate = null, array $options = [])
    {
        $this->amount = floatval($amount);
        $this->taxRate = is_null($taxRate) ? config('cart.tax') : $taxRate;
        $this->options  = new CartFeeOptions($options);
    }

    /**
     * Returns the formatted fee amount without TAX.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return string
     */
    public function amountWithouTax($decimals = null, $decimalPoint = null, $thousandSeperator = null): string
    {
        $decimals = is_null($decimals) ? config('cart.format.fee_ex_tax_decimals') : $decimals;

        return $this->numberFormat($this->amount, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Returns the formatted fee amount with TAX.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return string
     */
    public function amountTax($decimals = null, $decimalPoint = null, $thousandSeperator = null): string
    {
        $priceTax = $this->amount + $this->getRawTax();
        $decimals = is_null($decimals) ? config('cart.format.fee_inc_tax_decimals') : $decimals;

        return $this->numberFormat($priceTax, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Gets the formatted amount.
     *
     * @param bool $format
     * @param bool $withTax
     *
     * @return string
     */
    public function getAmount($format = true, $withTax = false): string
    {
        $total = $this->amount;

        if ($withTax) {
            $total += $this->getRawTax();
        }

        return $this->numberFormat($total);
    }

    /**
     * @return string
     */
    public function tax(): string
    {
        return $this->numberFormat($this->getRawTax());
    }

    /**
     * Returns the raw (unformatted) fee tax as a float.
     *
     * @return float
     */
    public function getRawTax(): float
    {
        return $this->amount * $this->taxRate / 100;
    }

    /**
     * Magic method to make accessing the total, tax and subtotal properties possible.
     *
     * @param string $attribute
     * @return float|null
     */
    public function __get($attribute): mixed
    {
        if (property_exists($this, $attribute)) {
            return $this->{$attribute};
        }

        if ($attribute === 'amountWithouTax') {
            return $this->amountWithouTax();
        }

        if ($attribute === 'amountTax') {
            return $this->amountTax();
        }

        if ($attribute === 'tax') {
            return $this->tax();
        }

        return null;
    }
}
