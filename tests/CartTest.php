<?php

namespace Gloudemans\Tests\Shoppingcart;

use Mockery;
use PHPUnit\Framework\Assert;
use Gloudemans\Shoppingcart\Cart;
use Orchestra\Testbench\TestCase;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Collection;
use Gloudemans\Shoppingcart\CartItem;
use Illuminate\Support\Facades\Event;
use Illuminate\Session\SessionManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Gloudemans\Shoppingcart\ShoppingcartServiceProvider;
use Gloudemans\Tests\Shoppingcart\Fixtures\ProductModel;
use Gloudemans\Tests\Shoppingcart\Fixtures\BuyableProduct;

class CartTest extends TestCase
{
    use CartAssertions;

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

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('cart.database.connection', 'testing');
        $app['config']->set('cart.tax', 21);

        $app['config']->set('session.driver', 'array');

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    /**
     * Setup the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->afterResolving('migrator', function ($migrator) {
            $migrator->path(realpath(__DIR__.'/../database/migrations'));
        });
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_has_a_default_instance()
    {
        $cart = $this->getCart();

        $this->assertEquals(Cart::DEFAULT_INSTANCE, $cart->currentInstance());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_have_multiple_instances()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'First item'));

        $cart->instance('wishlist')->add(new BuyableProduct(2, 'Second item'));

        $this->assertItemsInCart(1, $cart->instance(Cart::DEFAULT_INSTANCE));
        $this->assertItemsInCart(1, $cart->instance('wishlist'));
    }
    
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_add_an_item()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProduct);

        $this->assertEquals(1, $cart->count());

        Event::assertDispatched('cart.added');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_will_return_the_cartitem_of_the_added_item()
    {
        Event::fake();

        $cart = $this->getCart();

        $cartItem = $cart->add(new BuyableProduct);

        $this->assertInstanceOf(CartItem::class, $cartItem);
        $this->assertEquals('027c91341fd5cf4d2579b49c4b6a90da', $cartItem->rowId);

        Event::assertDispatched('cart.added');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_add_multiple_buyable_items_at_once()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add([new BuyableProduct(1), new BuyableProduct(2)]);

        $this->assertEquals(2, $cart->count());

        Event::assertDispatched('cart.added');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_will_return_an_array_of_cartitems_when_you_add_multiple_items_at_once()
    {
        Event::fake();

        $cart = $this->getCart();

        $cartItems = $cart->add([new BuyableProduct(1), new BuyableProduct(2)]);

        $this->assertTrue(is_array($cartItems));
        $this->assertCount(2, $cartItems);
        $this->assertContainsOnlyInstancesOf(CartItem::class, $cartItems);

        Event::assertDispatched('cart.added');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_add_an_item_from_attributes()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add(1, 'Test item', 1, 10.00);

        $this->assertEquals(1, $cart->count());

        Event::assertDispatched('cart.added');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_add_an_item_from_an_array()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add(['id' => 1, 'name' => 'Test item', 'qty' => 1, 'price' => 10.00]);

        $this->assertEquals(1, $cart->count());

        Event::assertDispatched('cart.added');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_add_multiple_array_items_at_once()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add([
            ['id' => 1, 'name' => 'Test item 1', 'qty' => 1, 'price' => 10.00],
            ['id' => 2, 'name' => 'Test item 2', 'qty' => 1, 'price' => 10.00]
        ]);

        $this->assertEquals(2, $cart->count());

        Event::assertDispatched('cart.added');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_add_an_item_with_options()
    {
        Event::fake();

        $cart = $this->getCart();

        $options = ['size' => 'XL', 'color' => 'red'];

        $cart->add(new BuyableProduct, 1, $options);

        $cartItem = $cart->get('07d5da5550494c62daf9993cf954303f');

        $this->assertInstanceOf(CartItem::class, $cartItem);
        $this->assertEquals('XL', $cartItem->options->size);
        $this->assertEquals('red', $cartItem->options->color);

        Event::assertDispatched('cart.added');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_will_validate_the_identifier()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Please supply a valid identifier.');

        $cart = $this->getCart();

        $cart->add(null, 'Some title', 1, 10.00);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_will_validate_the_name()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Please supply a valid name.');

        $cart = $this->getCart();

        $cart->add(1, null, 1, 10.00);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_will_validate_the_quantity()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Please supply a valid quantity.');

        $cart = $this->getCart();

        $cart->add(1, 'Some title', 'invalid', 10.00);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_will_validate_the_price()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Please supply a valid price.');

        $cart = $this->getCart();

        $cart->add(1, 'Some title', 1, 'invalid');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_will_update_the_cart_if_the_item_already_exists_in_the_cart()
    {
        $cart = $this->getCart();

        $item = new BuyableProduct;

        $cart->add($item);
        $cart->add($item);

        $this->assertItemsInCart(2, $cart);
        $this->assertRowsInCart(1, $cart);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_will_keep_updating_the_quantity_when_an_item_is_added_multiple_times()
    {
        $cart = $this->getCart();

        $item = new BuyableProduct;

        $cart->add($item);
        $cart->add($item);
        $cart->add($item);

        $this->assertItemsInCart(3, $cart);
        $this->assertRowsInCart(1, $cart);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_update_the_quantity_of_an_existing_item_in_the_cart()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProduct);

        $cart->update('027c91341fd5cf4d2579b49c4b6a90da', 2);

        $this->assertItemsInCart(2, $cart);
        $this->assertRowsInCart(1, $cart);

        Event::assertDispatched('cart.updated');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_update_an_existing_item_in_the_cart_from_a_buyable()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProduct);

        $cart->update('027c91341fd5cf4d2579b49c4b6a90da', new BuyableProduct(1, 'Different description'));

        $this->assertItemsInCart(1, $cart);
        $this->assertEquals('Different description', $cart->get('027c91341fd5cf4d2579b49c4b6a90da')->name);

        Event::assertDispatched('cart.updated');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_update_an_existing_item_in_the_cart_from_an_array()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProduct);

        $cart->update('027c91341fd5cf4d2579b49c4b6a90da', ['name' => 'Different description']);

        $this->assertItemsInCart(1, $cart);
        $this->assertEquals('Different description', $cart->get('027c91341fd5cf4d2579b49c4b6a90da')->name);

        Event::assertDispatched('cart.updated');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_will_throw_an_exception_if_a_rowid_was_not_found()
    {
        $this->expectException(\Gloudemans\Shoppingcart\Exceptions\InvalidRowIDException::class);

        $cart = $this->getCart();

        $cart->add(new BuyableProduct);

        $cart->update('none-existing-rowid', new BuyableProduct(1, 'Different description'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_will_regenerate_the_rowid_if_the_options_changed()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct, 1, ['color' => 'red']);

        $cart->update('ea65e0bdcd1967c4b3149e9e780177c0', ['options' => ['color' => 'blue']]);

        $this->assertItemsInCart(1, $cart);
        $this->assertEquals('7e70a1e9aaadd18c72921a07aae5d011', $cart->content()->first()->rowId);
        $this->assertEquals('blue', $cart->get('7e70a1e9aaadd18c72921a07aae5d011')->options->color);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_will_add_the_item_to_an_existing_row_if_the_options_changed_to_an_existing_rowid()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct, 1, ['color' => 'red']);
        $cart->add(new BuyableProduct, 1, ['color' => 'blue']);

        $cart->update('7e70a1e9aaadd18c72921a07aae5d011', ['options' => ['color' => 'red']]);

        $this->assertItemsInCart(2, $cart);
        $this->assertRowsInCart(1, $cart);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_remove_an_item_from_the_cart()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProduct);

        $cart->remove('027c91341fd5cf4d2579b49c4b6a90da');

        $this->assertItemsInCart(0, $cart);
        $this->assertRowsInCart(0, $cart);

        Event::assertDispatched('cart.removed');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_will_remove_the_item_if_its_quantity_was_set_to_zero()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProduct);

        $cart->update('027c91341fd5cf4d2579b49c4b6a90da', 0);

        $this->assertItemsInCart(0, $cart);
        $this->assertRowsInCart(0, $cart);

        Event::assertDispatched('cart.removed');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_will_remove_the_item_if_its_quantity_was_set_negative()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProduct);

        $cart->update('027c91341fd5cf4d2579b49c4b6a90da', -1);

        $this->assertItemsInCart(0, $cart);
        $this->assertRowsInCart(0, $cart);

        Event::assertDispatched('cart.removed');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_get_an_item_from_the_cart_by_its_rowid()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct);

        $cartItem = $cart->get('027c91341fd5cf4d2579b49c4b6a90da');

        $this->assertInstanceOf(CartItem::class, $cartItem);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_get_the_content_of_the_cart()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1));
        $cart->add(new BuyableProduct(2));

        $content = $cart->content();

        $this->assertInstanceOf(Collection::class, $content);
        $this->assertCount(2, $content);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_will_return_an_empty_collection_if_the_cart_is_empty()
    {
        $cart = $this->getCart();

        $content = $cart->content();

        $this->assertInstanceOf(Collection::class, $content);
        $this->assertCount(0, $content);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_will_include_the_tax_and_subtotal_when_converted_to_an_array()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1));
        $cart->add(new BuyableProduct(2));

        $content = $cart->content();

        $this->assertInstanceOf(Collection::class, $content);
        $this->assertEquals([
            '027c91341fd5cf4d2579b49c4b6a90da' => [
                'rowId' => '027c91341fd5cf4d2579b49c4b6a90da',
                'id' => 1,
                'name' => 'Item name',
                'qty' => 1,
                'price' => 10.00,
                'tax' => 2.10,
                'subtotal' => 10.0,
                'isSaved' => false,
                'options' => [],
            ],
            '370d08585360f5c568b18d1f2e4ca1df' => [
                'rowId' => '370d08585360f5c568b18d1f2e4ca1df',
                'id' => 2,
                'name' => 'Item name',
                'qty' => 1,
                'price' => 10.00,
                'tax' => 2.10,
                'subtotal' => 10.0,
                'isSaved' => false,
                'options' => [],
            ]
        ], $content->toArray());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_destroy_a_cart()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct);

        $this->assertItemsInCart(1, $cart);

        $cart->destroy();

        $this->assertItemsInCart(0, $cart);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_get_the_total_price_of_the_cart_content()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'First item', 10.00));
        $cart->add(new BuyableProduct(2, 'Second item', 25.00), 2);

        $this->assertItemsInCart(3, $cart);
        $this->assertEquals(60.00, $cart->subtotal());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_return_a_formatted_total()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'First item', 1000.00));
        $cart->add(new BuyableProduct(2, 'Second item', 2500.00), 2);

        $this->assertItemsInCart(3, $cart);
        $this->assertEquals('6.000,00', $cart->subtotal(2, ',', '.'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_search_the_cart_for_a_specific_item()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'Some item'));
        $cart->add(new BuyableProduct(2, 'Another item'));

        $cartItem = $cart->search(function ($cartItem, $rowId) {
            return $cartItem->name == 'Some item';
        });

        $this->assertInstanceOf(Collection::class, $cartItem);
        $this->assertCount(1, $cartItem);
        $this->assertInstanceOf(CartItem::class, $cartItem->first());
        $this->assertEquals(1, $cartItem->first()->id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_search_the_cart_for_multiple_items()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'Some item'));
        $cart->add(new BuyableProduct(2, 'Some item'));
        $cart->add(new BuyableProduct(3, 'Another item'));

        $cartItem = $cart->search(function ($cartItem, $rowId) {
            return $cartItem->name == 'Some item';
        });

        $this->assertInstanceOf(Collection::class, $cartItem);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_search_the_cart_for_a_specific_item_with_options()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'Some item'), 1, ['color' => 'red']);
        $cart->add(new BuyableProduct(2, 'Another item'), 1, ['color' => 'blue']);

        $cartItem = $cart->search(function ($cartItem, $rowId) {
            return $cartItem->options->color == 'red';
        });

        $this->assertInstanceOf(Collection::class, $cartItem);
        $this->assertCount(1, $cartItem);
        $this->assertInstanceOf(CartItem::class, $cartItem->first());
        $this->assertEquals(1, $cartItem->first()->id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_will_associate_the_cart_item_with_a_model_when_you_add_a_buyable()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct);

        $cartItem = $cart->get('027c91341fd5cf4d2579b49c4b6a90da');

        $this->assertEquals(BuyableProduct::class, $this->getPrivateProperty($cartItem, 'associatedModel'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_associate_the_cart_item_with_a_model()
    {
        $cart = $this->getCart();

        $cart->add(1, 'Test item', 1, 10.00);

        $cart->associate('027c91341fd5cf4d2579b49c4b6a90da', new ProductModel);

        $cartItem = $cart->get('027c91341fd5cf4d2579b49c4b6a90da');

        $this->assertEquals(ProductModel::class, $this->getPrivateProperty($cartItem, 'associatedModel'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_will_throw_an_exception_when_a_non_existing_model_is_being_associated()
    {
        $this->expectException(\Gloudemans\Shoppingcart\Exceptions\UnknownModelException::class);
        $this->expectExceptionMessage('The supplied model SomeModel does not exist.');

        $cart = $this->getCart();

        $cart->add(1, 'Test item', 1, 10.00);

        $cart->associate('027c91341fd5cf4d2579b49c4b6a90da', 'SomeModel');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_get_the_associated_model_of_a_cart_item()
    {
        $cart = $this->getCart();

        $cart->add(1, 'Test item', 1, 10.00);

        $cart->associate('027c91341fd5cf4d2579b49c4b6a90da', new ProductModel);

        $cartItem = $cart->get('027c91341fd5cf4d2579b49c4b6a90da');

        $this->assertInstanceOf(ProductModel::class, $cartItem->model);
        $this->assertEquals('Some value', $cartItem->model->someValue);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_calculate_the_subtotal_of_a_cart_item()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'Some title', 9.99), 3);

        $cartItem = $cart->get('027c91341fd5cf4d2579b49c4b6a90da');

        $this->assertEquals(29.97, $cartItem->subtotal);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_return_a_formatted_subtotal()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'Some title', 500), 3);

        $cartItem = $cart->get('027c91341fd5cf4d2579b49c4b6a90da');

        $this->assertEquals('1.500,00', $cartItem->subtotal(2, ',', '.'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_calculate_tax_based_on_the_default_tax_rate_in_the_config()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'Some title', 10.00), 1);

        $cartItem = $cart->get('027c91341fd5cf4d2579b49c4b6a90da');

        $this->assertEquals(2.10, $cartItem->tax);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_calculate_tax_based_on_the_specified_tax()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'Some title', 10.00), 1);

        $cart->setTax('027c91341fd5cf4d2579b49c4b6a90da', 19);

        $cartItem = $cart->get('027c91341fd5cf4d2579b49c4b6a90da');

        $this->assertEquals(1.90, $cartItem->tax);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_return_the_calculated_tax_formatted()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'Some title', 10000.00), 1);

        $cartItem = $cart->get('027c91341fd5cf4d2579b49c4b6a90da');

        $this->assertEquals('2.100,00', $cartItem->tax(2, ',', '.'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_calculate_the_total_tax_for_all_cart_items()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'Some title', 10.00), 1);
        $cart->add(new BuyableProduct(2, 'Some title', 20.00), 2);

        $this->assertEquals(10.50, $cart->tax);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_return_formatted_total_tax()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'Some title', 1000.00), 1);
        $cart->add(new BuyableProduct(2, 'Some title', 2000.00), 2);

        $this->assertEquals('1.050,00', $cart->tax(2, ',', '.'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_return_the_subtotal()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'Some title', 10.00), 1);
        $cart->add(new BuyableProduct(2, 'Some title', 20.00), 2);

        $this->assertEquals(50.00, $cart->subtotal);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_return_formatted_subtotal()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'Some title', 1000.00), 1);
        $cart->add(new BuyableProduct(2, 'Some title', 2000.00), 2);

        $this->assertEquals('5000,00', $cart->subtotal(2, ',', ''));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_return_cart_formated_numbers_by_config_values()
    {
        $this->setConfigFormat(2, ',', '');

        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'Some title', 1000.00), 1);
        $cart->add(new BuyableProduct(2, 'Some title', 2000.00), 2);

        $this->assertEquals('5000,00', $cart->subtotal());
        $this->assertEquals('1050,00', $cart->tax());
        $this->assertEquals('6050,00', $cart->total());

        $this->assertEquals('5000,00', $cart->subtotal);
        $this->assertEquals('1050,00', $cart->tax);
        $this->assertEquals('6050,00', $cart->total);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_return_cartItem_formated_numbers_by_config_values()
    {
        $this->setConfigFormat(2, ',', '');

        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'Some title', 2000.00), 2);

        $cartItem = $cart->get('027c91341fd5cf4d2579b49c4b6a90da');

        $this->assertEquals('2000,00', $cartItem->price());
        $this->assertEquals('2420,00', $cartItem->priceTax());
        $this->assertEquals('4000,00', $cartItem->subtotal());
        $this->assertEquals('4840,00', $cartItem->total());
        $this->assertEquals('420,00', $cartItem->tax());
        $this->assertEquals('840,00', $cartItem->taxTotal());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_store_the_cart_in_a_database()
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
        ]);

        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProduct);

        $cart->store($identifier = 123);

        $serialized = serialize($cart->toArray());

        $this->assertDatabaseHas('shoppingcart', ['identifier' => $identifier, 'instance' => 'default', 'content' => $serialized]);

        Event::assertDispatched('cart.stored');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_update_the_cart_in_database()
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
        ]);

        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProduct);

        $cart->store($identifier = 123);

        $serialized = serialize($cart->toArray());

        $this->assertDatabaseHas('shoppingcart', ['identifier' => $identifier, 'instance' => 'default', 'content' => $serialized]);
        
        Event::assertDispatched('cart.stored');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_restore_a_cart_from_the_database()
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
        ]);

        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProduct);

        $cart->store($identifier = 123);

        $cart->destroy();

        $this->assertItemsInCart(0, $cart);

        $cart->restore($identifier);

        $this->assertItemsInCart(1, $cart);       

        Event::assertDispatched('cart.restored');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_will_just_keep_the_current_instance_if_no_cart_with_the_given_identifier_was_stored()
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
        ]);

        $cart = $this->getCart();

        $cart->restore($identifier = 123);

        $this->assertItemsInCart(0, $cart);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_calculate_all_values()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'First item', 10.00), 2);

        $cartItem = $cart->get('027c91341fd5cf4d2579b49c4b6a90da');

        $cart->setTax('027c91341fd5cf4d2579b49c4b6a90da', 19);

        $this->assertEquals(10.00, $cartItem->price(2));
        $this->assertEquals(11.90, $cartItem->priceTax(2));
        $this->assertEquals(20.00, $cartItem->subtotal(2));
        $this->assertEquals(23.80, $cartItem->total(2));
        $this->assertEquals(1.90, $cartItem->tax(2));
        $this->assertEquals(3.80, $cartItem->taxTotal(2));

        $this->assertEquals(20.00, $cart->subtotal(2));
        $this->assertEquals(23.80, $cart->total(2));
        $this->assertEquals(3.80, $cart->tax(2));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_will_destroy_the_cart_when_the_user_logs_out_and_the_config_setting_was_set_to_true()
    {
        $this->app['config']->set('cart.destroy_on_logout', true);

        $this->app->instance(SessionManager::class, Mockery::mock(SessionManager::class, function ($mock) {
            $mock->shouldReceive('forget')->once()->with('cart');
        }));

        $user = Mockery::mock(Authenticatable::class);

        $guard = $this->app->make('auth');

        event(new Logout($guard, $user));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function instance_falls_back_to_the_default_when_passed_null()
    {
        $cart = $this->getCart();

        $cart->instance('wishlist');
        $cart->instance(null);

        $this->assertEquals(Cart::DEFAULT_INSTANCE, $cart->currentInstance());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function instance_falls_back_to_the_default_when_passed_an_empty_string()
    {
        $cart = $this->getCart();

        $cart->instance('wishlist');
        $cart->instance('');

        $this->assertEquals(Cart::DEFAULT_INSTANCE, $cart->currentInstance());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function magic_get_returns_null_for_unknown_attributes()
    {
        $cart = $this->getCart();

        $this->assertNull($cart->something_unknown);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function magic_get_returns_subtotal_tax()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'Item', 10.00), 2);

        // Two items at price 10 with 21% tax = (10 + 2.10) * 2 = 24.20.
        $this->assertEquals(24.20, $cart->subtotalTax);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_add_an_item_with_tax_already_included_in_the_price()
    {
        Event::fake();

        $cart = $this->getCart();

        // Note: $taxRate must be an int for createCartItem to use it (see Cart::createCartItem).
        $cart->add(1, 'Inclusive item', 1, 22.00, 21, true);

        // Inclusive formula: tax = price / (1 + rate) = 22 / 22 = 1.00.
        $this->assertEquals(22.00, $cart->total(2, '.', '', false));
        $this->assertEquals(22.00, $cart->subtotal(2));
        $this->assertEquals(1.00, $cart->tax(2, '.', '', false));

        Event::assertDispatched('cart.added');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_add_remove_and_retrieve_a_fee()
    {
        $cart = $this->getCart();

        $cart->addFee('shipping', 5.00, 0);

        $fee = $cart->getFee('shipping');

        $this->assertEquals(5.00, $fee->amount);
        $this->assertEquals(0, $fee->taxRate);
        $this->assertCount(1, $cart->getFees());

        $cart->removeFee('shipping');

        $this->assertCount(0, $cart->getFees());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function get_fee_returns_an_empty_fee_when_the_name_is_unknown()
    {
        $cart = $this->getCart();

        $fee = $cart->getFee('does-not-exist');

        $this->assertInstanceOf(\Gloudemans\Shoppingcart\CartFee::class, $fee);
        $this->assertEquals(0.0, $fee->amount);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function adding_a_fee_with_an_existing_name_overwrites_the_previous_fee()
    {
        $cart = $this->getCart();

        $cart->addFee('shipping', 5.00, 0);
        $cart->addFee('shipping', 8.00, 0);

        $this->assertCount(1, $cart->getFees());
        $this->assertEquals(8.00, $cart->getFee('shipping')->amount);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_remove_all_fees_at_once()
    {
        $cart = $this->getCart();

        $cart->addFee('shipping', 5.00, 0);
        $cart->addFee('handling', 2.00, 0);

        $cart->removeFees();

        $this->assertCount(0, $cart->getFees());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function fees_rehydrate_from_the_session_when_content_is_loaded()
    {
        $cart = $this->getCart();

        $cart->addFee('shipping', 5.00, 0);

        // getFee() does not trigger session rehydration on its own; loading
        // content() does. This guards the rehydration path that store/restore relies on.
        $fresh = $this->getCart();
        $fresh->content();

        $this->assertEquals(5.00, $fresh->getFee('shipping')->amount);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function fees_default_to_the_configured_tax_rate_when_none_is_passed()
    {
        $cart = $this->getCart();

        $cart->addFee('shipping', 10.00);

        $this->assertEquals(21, $cart->getFee('shipping')->taxRate);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function total_includes_fees_and_their_tax_by_default()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'Item', 10.00));
        $cart->addFee('shipping', 5.00, 21);

        // Item: 10 + 2.10 tax = 12.10. Fee: 5 + 1.05 tax = 6.05. Total: 18.15.
        $this->assertEquals(18.15, $cart->total(2));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function total_can_exclude_fees_via_the_with_fees_argument()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'Item', 10.00));
        $cart->addFee('shipping', 5.00, 21);

        $this->assertEquals(12.10, $cart->total(2, '.', '', false));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function tax_includes_fee_tax_by_default()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'Item', 10.00));
        $cart->addFee('shipping', 5.00, 21);

        $this->assertEquals(3.15, $cart->tax(2));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function tax_can_exclude_fee_tax_via_the_with_fees_argument()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'Item', 10.00));
        $cart->addFee('shipping', 5.00, 21);

        $this->assertEquals(2.10, $cart->tax(2, '.', '', false));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function magic_fee_total_excludes_tax_and_fee_total_tax_includes_it()
    {
        $cart = $this->getCart();

        $cart->addFee('shipping', 5.00, 21);

        $this->assertEquals(5.00, $cart->feeTotal);
        $this->assertEquals(6.05, $cart->feeTotalTax);
        $this->assertEquals(1.05, $cart->feeTax);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_overwrites_existing_items_when_restoring_an_array_shaped_cart()
    {
        $this->artisan('migrate', ['--database' => 'testing']);

        $cart = $this->getCart();
        $cart->add(new BuyableProduct(1, 'Stored item', 10.00));
        $cart->store($identifier = 'restore-overwrite');
        $cart->destroy();

        // Add a different item before restoring.
        $cart->add(new BuyableProduct(2, 'Pre-existing', 10.00));
        $cart->restore($identifier);

        // The new (array) shape replaces existing items rather than merging.
        $this->assertItemsInCart(1, $cart);
        $this->assertEquals(1, $cart->content()->first()->id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_restore_a_legacy_collection_shaped_cart()
    {
        $this->artisan('migrate', ['--database' => 'testing']);

        $legacyItem = new CartItem(1, 'Legacy item', 10.00);
        $legacyItem->setQuantity(1);

        \Illuminate\Support\Facades\DB::table('shoppingcart')->insert([
            'identifier' => $identifier = 'legacy-id',
            'instance' => 'default',
            'content' => serialize(new Collection([$legacyItem->rowId => $legacyItem])),
            'created_at' => new \DateTime(),
        ]);

        $cart = $this->getCart();
        $cart->restore($identifier);

        $this->assertItemsInCart(1, $cart);
        $this->assertEquals('Legacy item', $cart->content()->first()->name);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function store_updates_an_existing_record_on_the_second_call()
    {
        $this->artisan('migrate', ['--database' => 'testing']);

        $cart = $this->getCart();
        $cart->add(new BuyableProduct(1, 'First', 10.00));
        $cart->store($identifier = 'update-test');

        $cart->add(new BuyableProduct(2, 'Second', 20.00));
        $cart->store($identifier);

        $rows = \Illuminate\Support\Facades\DB::table('shoppingcart')
            ->where('identifier', $identifier)
            ->get();

        $this->assertCount(1, $rows, 'Expected a single row — the second store() should update, not insert.');
        $this->assertEquals(serialize($cart->toArray()), $rows->first()->content);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_store_and_restore_a_non_default_instance()
    {
        $this->artisan('migrate', ['--database' => 'testing']);

        $cart = $this->getCart();
        $cart->instance('wishlist')->add(new BuyableProduct(1, 'Wishlist item', 10.00));
        $cart->store($identifier = 'wishlist-id');
        $cart->destroy();

        $this->assertItemsInCart(0, $cart->instance('wishlist'));

        // Switch back to default before restoring; restore should target the stored instance.
        $cart->instance(Cart::DEFAULT_INSTANCE);
        $cart->restore($identifier);

        // restore() preserves the caller's current instance after rehydrating.
        $this->assertEquals(Cart::DEFAULT_INSTANCE, $cart->currentInstance());
        $this->assertItemsInCart(1, $cart->instance('wishlist'));
    }

    /**
     * Get an instance of the cart.
     *
     * @return \Gloudemans\Shoppingcart\Cart
     */
    private function getCart()
    {
        $session = $this->app->make('session');
        $events = $this->app->make('events');

        return new Cart($session, $events);
    }

    /**
     * Set the config number format.
     * 
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     */
    private function setConfigFormat($decimals, $decimalPoint, $thousandSeperator)
    {
        foreach ([
            'cart.format.decimals',
            'cart.format.price_ex_tax_decimals',
            'cart.format.price_inc_tax_decimals',
            'cart.format.fee_ex_tax_decimals',
            'cart.format.fee_inc_tax_decimals',
            'cart.format.fee_total_tax_decimals',
            'cart.format.tax_decimals',
            'cart.format.tax_total_decimals',
            'cart.format.subtotal_ex_tax_decimals',
            'cart.format.subtotal_inc_tax_decimals',
            'cart.format.total_decimals',
        ] as $key) {
            $this->app['config']->set($key, $decimals);
        }

        $this->app['config']->set('cart.format.decimal_point', $decimalPoint);
        $this->app['config']->set('cart.format.thousand_seperator', $thousandSeperator);
    }
}
