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
     * Gets the formatted amount.
     *
     * @param bool $format
     * @param bool $withTax
     *
     * @return string
     */
    public function getAmount($format = true, $withTax = false)
    {
        $total = $this->amount;

        if ($withTax) {
            $total += $this->taxRate * $total;
        }

        return $this->numberFormat($total);
    }

    /**
     * @return string
     */
    public function tax()
    {
        $tax = $this->amount * $this->taxRate / 100;

        return $this->numberFormat($tax);
    }

    /**
     * Magic method to make accessing the total, tax and subtotal properties possible.
     *
     * @param string $attribute
     * @return float|null
     */
    public function __get($attribute)
    {
        if ($attribute === 'tax') {
            return $this->tax();
        }

        return null;
    }
}