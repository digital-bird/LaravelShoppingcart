<?php

namespace Gloudemans\Tests\Shoppingcart;

use Orchestra\Testbench\TestCase;
use Gloudemans\Shoppingcart\CartFee;
use Gloudemans\Shoppingcart\CartFeeOptions;
use Gloudemans\Shoppingcart\ShoppingcartServiceProvider;

class CartFeeTest extends TestCase
{
    /**
     * Set the package service provider.
     */
    protected function getPackageProviders($app)
    {
        return [ShoppingcartServiceProvider::class];
    }

    /**
     * Default cart.tax = 21 mirrors CartTest so null taxRate falls back consistently.
     */
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('cart.tax', 21);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_constructs_with_an_explicit_tax_rate()
    {
        $fee = new CartFee(10.00, 15);

        $this->assertEquals(10.00, $fee->amount);
        $this->assertEquals(15, $fee->taxRate);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_falls_back_to_the_configured_tax_rate_when_none_is_supplied()
    {
        $fee = new CartFee(10.00);

        $this->assertEquals(21, $fee->taxRate);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_casts_amount_to_a_float()
    {
        $fee = new CartFee('12.50', 0);

        $this->assertSame(12.50, $fee->amount);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_calculates_raw_tax_from_amount_and_tax_rate()
    {
        $fee = new CartFee(10.00, 21);

        $this->assertEquals(2.10, $fee->getRawTax());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function raw_tax_is_zero_when_tax_rate_is_zero()
    {
        $fee = new CartFee(10.00, 0);

        $this->assertEquals(0.0, $fee->getRawTax());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_formats_the_tax_amount()
    {
        $fee = new CartFee(10.00, 21);

        // Default cart.format.decimals is 4.
        $this->assertEquals(2.1, $fee->tax());
        $this->assertEquals('2.1000', $fee->tax());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function amount_tax_returns_the_amount_plus_tax_formatted()
    {
        $fee = new CartFee(10.00, 21);

        // fee_inc_tax_decimals defaults to 4.
        $this->assertEquals(12.1, $fee->amountTax());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function magic_get_exposes_amount_tax_and_tax()
    {
        $fee = new CartFee(10.00, 21);

        $this->assertEquals(12.1, $fee->amountTax);
        $this->assertEquals(2.1, $fee->tax);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function magic_get_returns_amount_property_directly()
    {
        $fee = new CartFee(10.00, 21);

        $this->assertEquals(10.00, $fee->amount);
        $this->assertEquals(21, $fee->taxRate);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function magic_get_returns_null_for_unknown_attribute()
    {
        $fee = new CartFee(10.00, 21);

        $this->assertNull($fee->something_unknown);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_stores_arbitrary_options_on_a_fee()
    {
        $fee = new CartFee(10.00, 0, ['note' => 'rush delivery']);

        $this->assertInstanceOf(CartFeeOptions::class, $fee->options);
        $this->assertEquals('rush delivery', $fee->options->note);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function cart_fee_options_returns_null_for_missing_keys()
    {
        $options = new CartFeeOptions(['note' => 'rush delivery']);

        $this->assertNull($options->missing);
    }
}
