<?php

namespace Gloudemans\Tests\Shoppingcart;

use Gloudemans\Shoppingcart\Cart;
use Gloudemans\Shoppingcart\CartFee;
use Gloudemans\Shoppingcart\CartItem;
use Gloudemans\Shoppingcart\ShoppingcartServiceProvider;
use Gloudemans\Tests\Shoppingcart\Fixtures\BuyableProduct;
use Orchestra\Testbench\TestCase;

/**
 * Exhaustive math coverage for CartItem and CartFee.
 *
 * - Numeric assertions are made against raw float methods (getRawTax, getRawTotal, ...) so
 *   formatting decimals don't muddy the math.
 * - Formatted-string assertions explicitly set decimals to pin number_format's behavior.
 * - Mirrors CartTest's environment: cart.tax = 21, session.driver = array.
 */
class CartMathTest extends TestCase
{
    use CartAssertions;

    protected function getPackageProviders($app)
    {
        return [ShoppingcartServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('cart.tax', 21);
        $app['config']->set('session.driver', 'array');
    }

    private function getCart(): Cart
    {
        return new Cart($this->app->make('session'), $this->app->make('events'));
    }

    // ---------------------------------------------------------------
    // CartItem — additive (tax-excluded) path
    // ---------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\Test]
    public function additive_tax_is_zero_when_tax_rate_is_zero()
    {
        $item = (new CartItem(1, 'X', 10.00))->setTaxRate(0);
        $item->setQuantity(3);

        $this->assertEquals(0.0, $item->getRawTax());
        $this->assertEquals(0.0, $item->getRawTaxTotal());
        $this->assertEquals(30.0, $item->getRawSubtotal());
        $this->assertEquals(30.0, $item->getRawTotal());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function additive_tax_scales_linearly_with_quantity()
    {
        $item = (new CartItem(1, 'X', 7.50))->setTaxRate(10);

        foreach ([1, 5, 12, 100] as $qty) {
            $item->setQuantity($qty);
            $this->assertEqualsWithDelta(0.75 * $qty, $item->getRawTaxTotal(), 1e-9);
            $this->assertEqualsWithDelta(7.50 * $qty, $item->getRawSubtotal(), 1e-9);
            $this->assertEqualsWithDelta(8.25 * $qty, $item->getRawTotal(), 1e-9);
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function additive_tax_handles_high_rates()
    {
        $item = (new CartItem(1, 'X', 100.00))->setTaxRate(150);
        $item->setQuantity(1);

        $this->assertEquals(150.0, $item->getRawTax());
        $this->assertEquals(250.0, $item->getRawTotal());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function additive_tax_handles_fractional_rates()
    {
        $item = (new CartItem(1, 'X', 100.00))->setTaxRate(8.5);
        $item->setQuantity(1);

        $this->assertEqualsWithDelta(8.5, $item->getRawTax(), 1e-9);
        $this->assertEqualsWithDelta(108.5, $item->getRawTotal(), 1e-9);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function additive_tax_handles_fractional_quantities()
    {
        $item = (new CartItem(1, 'X', 4.00))->setTaxRate(20);
        $item->setQuantity(2.5);

        $this->assertEqualsWithDelta(0.80, $item->getRawTax(), 1e-9);     // unit tax
        $this->assertEqualsWithDelta(2.00, $item->getRawTaxTotal(), 1e-9); // 0.80 * 2.5
        $this->assertEqualsWithDelta(10.0, $item->getRawSubtotal(), 1e-9);
        $this->assertEqualsWithDelta(12.0, $item->getRawTotal(), 1e-9);
    }

    // ---------------------------------------------------------------
    // CartItem — inclusive (tax-already-in-price) path
    //
    // Per CLAUDE.md the inclusive formula divides by (1 + rate), NOT (1 + rate/100).
    // These tests pin that behavior so nobody silently changes the formula.
    // ---------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\Test]
    public function inclusive_tax_uses_the_price_over_one_plus_rate_formula()
    {
        $item = (new CartItem(1, 'X', 110.00))
            ->setTaxRate(10)
            ->setTaxIncluded(true);
        $item->setQuantity(1);

        // 110 / (1 + 10) = 10.0, NOT 110 - 110/1.10 = 10.0 (the additive-formula equivalent).
        $this->assertEquals(10.0, $item->getRawTax());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function inclusive_tax_total_does_not_add_tax_to_subtotal()
    {
        $item = (new CartItem(1, 'X', 22.00))
            ->setTaxRate(21)
            ->setTaxIncluded(true);
        $item->setQuantity(3);

        // Total stays at qty * price; tax is already inside it.
        $this->assertEquals(66.0, $item->getRawTotal());
        $this->assertEquals(66.0, $item->getRawSubtotal());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function inclusive_price_tax_returns_the_gross_price_unchanged()
    {
        $item = (new CartItem(1, 'X', 50.00))
            ->setTaxRate(20)
            ->setTaxIncluded(true);
        $item->setQuantity(1);

        $this->assertEquals(50.00, $item->priceTax(2));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function inclusive_tax_total_scales_with_quantity()
    {
        $item = (new CartItem(1, 'X', 22.00))
            ->setTaxRate(21)
            ->setTaxIncluded(true);
        $item->setQuantity(4);

        // unit tax = 22/22 = 1.0, line tax = 1.0 * 4 = 4.0
        $this->assertEquals(1.0, $item->getRawTax());
        $this->assertEquals(4.0, $item->getRawTaxTotal());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function inclusive_tax_with_zero_rate_returns_full_price_as_tax()
    {
        // Edge case derived from the formula: price / (1 + 0) = price.
        // Documents the current behavior — flag if this changes.
        $item = (new CartItem(1, 'X', 50.00))
            ->setTaxRate(0)
            ->setTaxIncluded(true);
        $item->setQuantity(1);

        $this->assertEquals(50.0, $item->getRawTax());
        $this->assertEquals(50.0, $item->getRawTotal());
    }

    // ---------------------------------------------------------------
    // CartItem — formatting (number_format application)
    // ---------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\Test]
    public function formatted_output_rounds_to_the_requested_decimals()
    {
        // Pick numbers that exercise rounding: 1/3-style fractions.
        $item = (new CartItem(1, 'X', 9.99))->setTaxRate(7);
        $item->setQuantity(3);

        // Raw: tax = 9.99 * 0.07 = 0.6993, taxTotal = 0.6993 * 3 = 2.0979
        $this->assertEquals('0.70', $item->tax(2));
        $this->assertEquals('0.6993', $item->tax(4));
        $this->assertEquals('2.10', $item->taxTotal(2));
        $this->assertEquals('2.0979', $item->taxTotal(4));
        $this->assertEquals('29.97', $item->subtotal(2));
        $this->assertEquals('32.0679', $item->total(4));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function formatted_output_respects_thousand_separator_and_decimal_point()
    {
        $item = (new CartItem(1, 'X', 1234.56))->setTaxRate(0);
        $item->setQuantity(2);

        $this->assertEquals('2.469,12', $item->subtotal(2, ',', '.'));
        $this->assertEquals('2,469.12', $item->subtotal(2, '.', ','));
        $this->assertEquals('2469.12', $item->subtotal(2, '.', ''));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function formatted_output_respects_per_method_config_decimals()
    {
        // Default config: tax_decimals = 4, total_decimals = 4, price_inc_tax_decimals = 2.
        $item = (new CartItem(1, 'X', 10.00))->setTaxRate(21);
        $item->setQuantity(1);

        $this->assertEquals('2.1000', $item->tax(null, '.', ''));
        $this->assertEquals('12.1000', $item->total(null, '.', ''));
        $this->assertEquals('12.10', $item->priceTax(null, '.', ''));
    }

    // ---------------------------------------------------------------
    // CartFee — math
    // ---------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\Test]
    public function fee_raw_tax_is_amount_times_rate_over_one_hundred()
    {
        foreach ([
            [10.00, 0,    0.00],
            [10.00, 10,   1.00],
            [10.00, 21,   2.10],
            [123.45, 8.5, 10.49325],
            [0.00, 50,    0.00],
        ] as [$amount, $rate, $expected]) {
            $fee = new CartFee($amount, $rate);
            $this->assertEqualsWithDelta($expected, $fee->getRawTax(), 1e-9, "amount={$amount}, rate={$rate}");
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function fee_amount_tax_is_amount_plus_raw_tax()
    {
        $fee = new CartFee(10.00, 21);

        $this->assertEqualsWithDelta(12.10, $fee->amount + $fee->getRawTax(), 1e-9);
        $this->assertEquals('12.10', $fee->amountTax(2, '.', ''));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function fee_get_amount_with_tax_matches_amount_tax()
    {
        // Both should agree post-fix; pin them together so future changes can't desync.
        $fee = new CartFee(10.00, 21);

        $this->assertEqualsWithDelta(
            (float) str_replace(',', '', $fee->amountTax(4, '.', '')),
            (float) str_replace(',', '', $fee->getAmount(true, true)),
            1e-9
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function fee_amount_without_tax_equals_fee_amount()
    {
        $fee = new CartFee(7.77, 21);

        $this->assertEquals(7.77, $fee->amountWithouTax(2));
        $this->assertEquals(7.77, $fee->amountWithouTax);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function fee_negative_amount_passes_through_math()
    {
        // Discounts are sometimes modeled as negative-amount fees in calling code.
        // Ensure the math doesn't blow up; not a contract — just a current-behavior pin.
        $fee = new CartFee(-5.00, 21);

        $this->assertEqualsWithDelta(-1.05, $fee->getRawTax(), 1e-9);
    }

    // ---------------------------------------------------------------
    // Cart — multi-item totals
    // ---------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\Test]
    public function cart_subtotal_is_the_sum_of_per_item_subtotals()
    {
        $cart = $this->getCart();
        $cart->add(new BuyableProduct(1, 'A', 10.00), 2); // subtotal 20.00
        $cart->add(new BuyableProduct(2, 'B', 5.50), 3);  // subtotal 16.50
        $cart->add(new BuyableProduct(3, 'C', 0.99), 7);  // subtotal 6.93

        $this->assertEqualsWithDelta(43.43, (float) $cart->subtotal(2, '.', ''), 1e-9);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function cart_tax_is_the_sum_of_per_item_tax_totals_when_no_fees()
    {
        $cart = $this->getCart();
        $cart->add(new BuyableProduct(1, 'A', 10.00), 2); // 21% * 20 = 4.20
        $cart->add(new BuyableProduct(2, 'B', 5.00), 4);  // 21% * 20 = 4.20

        $this->assertEqualsWithDelta(8.40, (float) $cart->tax(2, '.', '', false), 1e-9);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function cart_total_equals_subtotal_plus_tax_when_no_fees_no_inclusive()
    {
        $cart = $this->getCart();
        $cart->add(new BuyableProduct(1, 'A', 10.00), 2);
        $cart->add(new BuyableProduct(2, 'B', 5.00), 4);

        $subtotal = (float) $cart->subtotal(4, '.', '');
        $tax      = (float) $cart->tax(4, '.', '', false);
        $total    = (float) $cart->total(4, '.', '', false);

        $this->assertEqualsWithDelta($subtotal + $tax, $total, 1e-9);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function cart_total_with_mixed_inclusive_and_exclusive_items()
    {
        $cart = $this->getCart();

        // Inclusive: price 22, rate 21 → tax 1.00, total 22 (no extra add).
        $cart->add(1, 'Inc', 1, 22.00, 21, true);
        // Exclusive: price 10, rate 21 → tax 2.10, total 12.10.
        $cart->add(2, 'Exc', 1, 10.00, 21, false);

        $this->assertEqualsWithDelta(34.10, (float) $cart->total(4, '.', '', false), 1e-9);
        $this->assertEqualsWithDelta(3.10,  (float) $cart->tax(4, '.', '', false),   1e-9);
        $this->assertEqualsWithDelta(32.00, (float) $cart->subtotal(4, '.', ''),     1e-9);
    }

    // ---------------------------------------------------------------
    // Cart — totals with fees
    // ---------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\Test]
    public function cart_total_with_fees_includes_taxable_and_non_taxable_fees()
    {
        $cart = $this->getCart();
        $cart->add(new BuyableProduct(1, 'A', 10.00));   // total 12.10, tax 2.10
        $cart->addFee('shipping',  5.00, 0);             // no tax
        $cart->addFee('handling',  2.00, 21);            // tax 0.42, gross 2.42

        // Total: 12.10 + 5.00 + 2.42 = 19.52
        $this->assertEqualsWithDelta(19.52, (float) $cart->total(4, '.', ''),  1e-9);
        // Tax (with fees): 2.10 + 0.00 + 0.42 = 2.52
        $this->assertEqualsWithDelta(2.52,  (float) $cart->tax(4, '.', ''),    1e-9);
        // Without fees: just the item.
        $this->assertEqualsWithDelta(12.10, (float) $cart->total(4, '.', '', false), 1e-9);
        $this->assertEqualsWithDelta(2.10,  (float) $cart->tax(4, '.', '', false),   1e-9);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function fee_total_with_tax_matches_sum_of_amount_plus_raw_tax_per_fee()
    {
        $cart = $this->getCart();
        $cart->addFee('a', 3.33, 21);   // gross 4.0293
        $cart->addFee('b', 7.77, 10);   // gross 8.547
        $cart->addFee('c', 1.00, 0);    // gross 1.00

        $expected = (3.33 + 3.33 * 0.21) + (7.77 + 7.77 * 0.10) + 1.00;

        $this->assertEqualsWithDelta($expected, (float) $cart->feeTotal(4, '.', '', true),  1e-9);
        $this->assertEqualsWithDelta(3.33 + 7.77 + 1.00, (float) $cart->feeTotal(4, '.', '', false), 1e-9);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function fee_tax_equals_sum_of_per_fee_raw_tax()
    {
        $cart = $this->getCart();
        $cart->addFee('a', 10.00, 21); // 2.10
        $cart->addFee('b', 20.00, 5);  // 1.00

        $this->assertEqualsWithDelta(3.10, (float) $cart->feeTax(4, '.', ''), 1e-9);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function removing_fees_brings_cart_back_to_item_only_totals()
    {
        $cart = $this->getCart();
        $cart->add(new BuyableProduct(1, 'A', 10.00));
        $cart->addFee('a', 5.00, 21);
        $cart->addFee('b', 2.00, 0);
        $cart->removeFees();

        $this->assertEqualsWithDelta(12.10, (float) $cart->total(4, '.', ''), 1e-9);
        $this->assertEqualsWithDelta(2.10,  (float) $cart->tax(4, '.', ''),   1e-9);
        $this->assertEqualsWithDelta(0.00,  (float) $cart->feeTotal(4, '.', '', true), 1e-9);
    }

    // ---------------------------------------------------------------
    // Cart — quantity merging math (Cart::add merges by rowId)
    // ---------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\Test]
    public function adding_the_same_item_multiple_times_sums_quantity_for_totals()
    {
        $cart = $this->getCart();
        $cart->add(new BuyableProduct(1, 'A', 10.00), 2);
        $cart->add(new BuyableProduct(1, 'A', 10.00), 3);

        $this->assertItemsInCart(5, $cart);
        $this->assertRowsInCart(1, $cart);

        // 5 * 10 = 50 subtotal; 5 * 2.10 = 10.50 tax; 60.50 total.
        $this->assertEqualsWithDelta(50.00, (float) $cart->subtotal(4, '.', ''),     1e-9);
        $this->assertEqualsWithDelta(10.50, (float) $cart->tax(4, '.', '', false),   1e-9);
        $this->assertEqualsWithDelta(60.50, (float) $cart->total(4, '.', '', false), 1e-9);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function update_via_array_qty_changes_totals()
    {
        $cart = $this->getCart();
        $cart->add(new BuyableProduct(1, 'A', 10.00), 1);
        $rowId = $cart->content()->keys()->first();

        $cart->update($rowId, ['qty' => 4]);

        $this->assertEqualsWithDelta(40.00, (float) $cart->subtotal(4, '.', ''), 1e-9);
        $this->assertEqualsWithDelta(8.40,  (float) $cart->tax(4, '.', '', false), 1e-9);
    }

    // ---------------------------------------------------------------
    // Cart — empty-cart math
    // ---------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\Test]
    public function empty_cart_returns_zero_for_all_totals()
    {
        $cart = $this->getCart();

        $this->assertEquals(0.0, (float) $cart->subtotal(4, '.', ''));
        $this->assertEquals(0.0, (float) $cart->subtotalTax(4, '.', ''));
        $this->assertEquals(0.0, (float) $cart->total(4, '.', ''));
        $this->assertEquals(0.0, (float) $cart->tax(4, '.', ''));
        $this->assertEquals(0.0, (float) $cart->feeTotal(4, '.', '', true));
        $this->assertEquals(0.0, (float) $cart->feeTax(4, '.', ''));
    }
}
