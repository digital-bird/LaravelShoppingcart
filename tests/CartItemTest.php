<?php

namespace Gloudemans\Tests\Shoppingcart;

use Orchestra\Testbench\TestCase;
use Gloudemans\Shoppingcart\CartItem;
use Gloudemans\Shoppingcart\ShoppingcartServiceProvider;
use Gloudemans\Tests\Shoppingcart\Fixtures\BuyableProduct;
use Gloudemans\Tests\Shoppingcart\Fixtures\ProductModel;

class CartItemTest extends TestCase
{
    /**
     * Set the package service provider.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return array
     */
    protected function getPackageProviders($app)
    {
        return [ShoppingcartServiceProvider::class];
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_be_cast_to_an_array()
    {
        $cartItem = new CartItem(1, 'Some item', 10.00, ['size' => 'XL', 'color' => 'red']);
        $cartItem->setQuantity(2);

        $this->assertEquals([
            'id' => 1,
            'name' => 'Some item',
            'price' => 10.00,
            'rowId' => '07d5da5550494c62daf9993cf954303f',
            'qty' => 2,
            'options' => [
                'size' => 'XL',
                'color' => 'red'
            ],
            'tax' => 0.0,
            'subtotal' => 20.00,
            'isSaved' => false
        ], $cartItem->toArray());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_be_cast_to_json()
    {
        $cartItem = new CartItem(1, 'Some item', 10.00, ['size' => 'XL', 'color' => 'red']);
        $cartItem->setQuantity(2);

        $this->assertJson($cartItem->toJson());

        $json = '{"rowId":"07d5da5550494c62daf9993cf954303f","id":1,"name":"Some item","qty":2,"price":10,"options":{"size":"XL","color":"red"},"tax":"0.0000","isSaved":false,"subtotal":"20.0000"}';

        $this->assertEquals($json, $cartItem->toJson());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_throws_when_constructed_with_an_empty_id()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Please supply a valid identifier.');

        new CartItem(null, 'Some item', 10.00);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_throws_when_constructed_with_an_empty_name()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Please supply a valid name.');

        new CartItem(1, '', 10.00);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_throws_when_constructed_with_a_non_numeric_price()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Please supply a valid price.');

        new CartItem(1, 'Some item', 'not-a-number');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_be_built_from_attributes()
    {
        $cartItem = CartItem::fromAttributes(1, 'Some item', 10.00, ['size' => 'XL']);

        $this->assertInstanceOf(CartItem::class, $cartItem);
        $this->assertEquals(1, $cartItem->id);
        $this->assertEquals('Some item', $cartItem->name);
        $this->assertEquals(10.00, $cartItem->price);
        $this->assertEquals('XL', $cartItem->options->size);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_be_built_from_an_array()
    {
        $cartItem = CartItem::fromArray([
            'id' => 1,
            'name' => 'Some item',
            'price' => 10.00,
            'options' => ['color' => 'red'],
        ]);

        $this->assertEquals(1, $cartItem->id);
        $this->assertEquals('red', $cartItem->options->color);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_be_built_from_a_buyable()
    {
        $cartItem = CartItem::fromBuyable(new BuyableProduct(42, 'Buyable name', 9.99));

        $this->assertEquals(42, $cartItem->id);
        $this->assertEquals('Buyable name', $cartItem->name);
        $this->assertEquals(9.99, $cartItem->price);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_generates_the_same_rowid_regardless_of_option_order()
    {
        $a = new CartItem(1, 'Some item', 10.00, ['size' => 'XL', 'color' => 'red']);
        $b = new CartItem(1, 'Some item', 10.00, ['color' => 'red', 'size' => 'XL']);

        $this->assertEquals($a->rowId, $b->rowId);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_generates_distinct_rowids_when_allow_multiple_same_id_is_enabled()
    {
        $this->app['config']->set('cart.allow_multiple_same_id', true);

        $a = new CartItem(1, 'Some item', 10.00);
        // microtime(true)'s float precision can collide on consecutive calls;
        // sleep so the second rowId is guaranteed to differ.
        usleep(1000);
        $b = new CartItem(1, 'Some item', 10.00);

        $this->assertNotEquals($a->rowId, $b->rowId);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_self_from_set_quantity()
    {
        $cartItem = new CartItem(1, 'Some item', 10.00);

        $this->assertSame($cartItem, $cartItem->setQuantity(2));
        $this->assertEquals(2, $cartItem->qty);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_throws_when_set_quantity_is_non_numeric()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Please supply a valid quantity.');

        (new CartItem(1, 'Some item', 10.00))->setQuantity('nope');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_throws_when_set_quantity_is_empty()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Please supply a valid quantity.');

        (new CartItem(1, 'Some item', 10.00))->setQuantity(0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_self_from_set_tax_rate()
    {
        $cartItem = new CartItem(1, 'Some item', 10.00);

        $this->assertSame($cartItem, $cartItem->setTaxRate(15));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_self_from_set_tax_included()
    {
        $cartItem = new CartItem(1, 'Some item', 10.00);

        $this->assertSame($cartItem, $cartItem->setTaxIncluded(true));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_self_from_set_saved()
    {
        $cartItem = new CartItem(1, 'Some item', 10.00);

        $this->assertSame($cartItem, $cartItem->setSaved(true));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_exposes_is_saved_through_to_array()
    {
        $cartItem = new CartItem(1, 'Some item', 10.00);
        $cartItem->setQuantity(1)->setSaved(true);

        $this->assertTrue($cartItem->toArray()['isSaved']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_calculates_tax_using_the_additive_formula_when_tax_is_not_included()
    {
        $cartItem = new CartItem(1, 'Some item', 10.00);
        $cartItem->setQuantity(1)->setTaxRate(20);

        $this->assertEquals(2.0, $cartItem->getRawTax());
        $this->assertEquals(12.0, $cartItem->getRawTotal());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_calculates_tax_using_the_inclusive_formula_when_tax_is_included()
    {
        $cartItem = new CartItem(1, 'Some item', 22.00);
        $cartItem->setQuantity(1)->setTaxRate(21)->setTaxIncluded(true);

        // Inclusive formula divides by (1 + rate), not (1 + rate/100). See CLAUDE.md.
        $this->assertEquals(1.0, $cartItem->getRawTax());

        // When tax is already in the price, total equals the gross price (no second add).
        $this->assertEquals(22.0, $cartItem->getRawTotal());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function price_tax_returns_the_gross_price_unchanged_when_tax_is_included()
    {
        $cartItem = new CartItem(1, 'Some item', 22.00);
        $cartItem->setQuantity(1)->setTaxRate(21)->setTaxIncluded(true);

        // priceTax should not double-add tax when it is already in the price.
        $this->assertEquals(22.00, $cartItem->priceTax(2));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_regenerates_the_rowid_when_updating_options()
    {
        $cartItem = new CartItem(1, 'Some item', 10.00, ['color' => 'red']);
        $original = $cartItem->rowId;

        $cartItem->updateFromArray(['options' => ['color' => 'blue']]);

        $this->assertNotEquals($original, $cartItem->rowId);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_updates_fields_from_a_buyable_without_changing_rowid()
    {
        $cartItem = new CartItem(1, 'Original', 10.00);
        $original = $cartItem->rowId;

        $cartItem->updateFromBuyable(new BuyableProduct(1, 'Updated', 25.00));

        $this->assertEquals('Updated', $cartItem->name);
        $this->assertEquals(25.00, $cartItem->price);
        $this->assertEquals($original, $cartItem->rowId);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function magic_get_returns_null_for_unknown_attributes()
    {
        $cartItem = new CartItem(1, 'Some item', 10.00);

        $this->assertNull($cartItem->somethingThatDoesNotExist);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function magic_get_returns_null_for_model_when_not_associated()
    {
        $cartItem = new CartItem(1, 'Some item', 10.00);

        $this->assertNull($cartItem->model);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_the_associated_model_via_magic_get()
    {
        $cartItem = new CartItem(1, 'Some item', 10.00);
        $cartItem->associate(ProductModel::class);

        $this->assertInstanceOf(ProductModel::class, $cartItem->model);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_exposes_priceTax_subtotal_total_tax_taxTotal_through_magic_get()
    {
        $cartItem = new CartItem(1, 'Some item', 10.00);
        $cartItem->setQuantity(2)->setTaxRate(20);

        // Loose comparison — magic accessors return formatted strings.
        $this->assertEquals(12.00, $cartItem->priceTax);
        $this->assertEquals(20.00, $cartItem->subtotal);
        $this->assertEquals(24.00, $cartItem->subtotalTax);
        $this->assertEquals(24.00, $cartItem->total);
        $this->assertEquals(2.00, $cartItem->tax);
        $this->assertEquals(4.00, $cartItem->taxTotal);
    }
}
