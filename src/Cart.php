<?php

namespace Gloudemans\Shoppingcart;

use Closure;
use DateTime;
use Gloudemans\Shoppingcart\Traits\CartHelper;
use Illuminate\Support\Collection;
use Illuminate\Session\SessionManager;
use Illuminate\Database\DatabaseManager;
use Illuminate\Contracts\Events\Dispatcher;
use Gloudemans\Shoppingcart\Contracts\Buyable;
use Gloudemans\Shoppingcart\Exceptions\UnknownModelException;
use Gloudemans\Shoppingcart\Exceptions\InvalidRowIDException;
use Gloudemans\Shoppingcart\Exceptions\CartAlreadyStoredException;

class Cart
{
    use CartHelper;

    const DEFAULT_INSTANCE = 'default';

    public $items;
    public $fees;

    /**
     * Instance of the session manager.
     *
     * @var \Illuminate\Session\SessionManager
     */
    protected $session;

    /**
     * Instance of the event dispatcher.
     *
     * @var \Illuminate\Contracts\Events\Dispatcher
     */
    private $events;

    /**
     * Holds the current cart instance.
     *
     * @var string
     */
    private $instance;

    /**
     * Cart constructor.
     *
     * @param \Illuminate\Session\SessionManager      $session
     * @param \Illuminate\Contracts\Events\Dispatcher $events
     */
    public function __construct(SessionManager $session, Dispatcher $events)
    {
        $this->items = new Collection;
        $this->fees = new Collection;
        $this->session = $session;
        $this->events = $events;

        $this->instance(self::DEFAULT_INSTANCE);
    }

    /**
     * Set the current cart instance.
     *
     * @param string|null $instance
     * @return \Gloudemans\Shoppingcart\Cart
     */
    public function instance($instance = null): self
    {
        $instance = $instance ?: self::DEFAULT_INSTANCE;

        $this->instance = sprintf('%s.%s', 'cart', $instance);

        return $this;
    }

    /**
     * Get the current cart instance.
     *
     * @return string
     */
    public function currentInstance(): string
    {
        return str_replace('cart.', '', $this->instance);
    }

    /**
     * Add an item to the cart.
     *
     * @param $id
     * @param $name
     * @param $qty
     * @param $price
     * @param $taxRate
     * @param $taxIncluded
     * @param array $options
     * @param array $eventOptions
     * @return array|array[]|CartItem|CartItem[]
     */
    public function add($id, $name = null, $qty = null, $price = null, $taxRate = null, $taxIncluded = false, array $options = [], array $eventOptions = [])
    {
        if ($this->isMulti($id)) {
            return array_map(function ($item) {
                return $this->add($item);
            }, $id);
        }

        if ($id instanceof CartItem) {
            $cartItem = $id;
        } else {
            $cartItem = $this->createCartItem($id, $name, $qty, $price, $taxRate, $taxIncluded, $options);
        }

        $content = $this->getContent();

        if ($content->has($cartItem->rowId)) {
            $cartItem->qty += $content->get($cartItem->rowId)->qty;
        }

        $content->put($cartItem->rowId, $cartItem);

        $this->items = $content;

        $this->session->put($this->instance, $this->toArray());

        $eventOptions = array_merge([
            'cartInstance' => $this->currentInstance(),
            'cartItem' => $cartItem,
        ], $eventOptions);

        $this->events->dispatch('cart.added', [
            $eventOptions,
        ]);

        return $cartItem;
    }

    /**
     * Update the cart item with the given rowId.
     *
     * @param $rowId
     * @param $qty
     * @param array $eventOptions
     * @return CartItem|void
     */
    public function update($rowId, $qty, array $eventOptions = []): ?CartItem
    {
        $cartItem = $this->get($rowId);

        if ($qty instanceof Buyable) {
            $cartItem->updateFromBuyable($qty);
        } elseif (is_array($qty)) {
            $cartItem->updateFromArray($qty);
        } else {
            $cartItem->qty = $qty;
        }

        $content = $this->getContent();

        if ($rowId !== $cartItem->rowId) {
            if ($content->has($cartItem->rowId)) {
                $existingCartItem = $this->get($cartItem->rowId);
                $cartItem->setQuantity($existingCartItem->qty + $cartItem->qty);
            }

            $content = $content->mapWithKeys(function ($val, $key) use ($rowId, $cartItem) {
                if ($key === $rowId) {
                    return [ $cartItem->rowId => $cartItem ];
                }

                return [ $key => $val ];
            });

            $this->items = $content;
        }

        if ($cartItem->qty <= 0) {
            $this->remove($cartItem->rowId);

            return null;
        }

        $this->session->put($this->instance, $this->toArray());

        $eventOptions = array_merge([
            'cartInstance' => $this->currentInstance(),
            'cartItem' => $cartItem,
        ], $eventOptions);

        $this->events->dispatch('cart.updated', [
            $eventOptions
        ]);

        return $cartItem;
    }

    /**
     * Remove the cart item with the given rowId from the cart.
     *
     * @param $rowId
     * @param array $eventOptions
     * @return void
     */
    public function remove($rowId, array $eventOptions = []): void
    {
        $cartItem = $this->get($rowId);

        $content = $this->getContent();

        $content->pull($cartItem->rowId);

        $this->items = $content;

        $this->session->put($this->instance, $this->toArray());

        $eventOptions = array_merge([
            'cartInstance' => $this->currentInstance(),
            'cartItem' => $cartItem,
        ], $eventOptions);

        $this->events->dispatch('cart.removed', [
            $eventOptions
        ]);
    }

    /**
     * Get a cart item from the cart by its rowId.
     *
     * @param string $rowId
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    public function get($rowId): CartItem
    {
        $content = $this->getContent();

        if ($content->has($rowId) === false) {
            throw new InvalidRowIDException("The cart does not contain rowId {$rowId}.");
        }

        return $content->get($rowId);
    }

    /**
     * Destroy the current cart instance.
     *
     * @return void
     */
    public function destroy(): void
    {
        $this->session->remove($this->instance);
    }

    /**
     * Get the content of the cart.
     *
     * @return \Illuminate\Support\Collection
     */
    public function content(): Collection
    {
        return $this->getContent();
    }

    /**
     * Get the number of items in the cart.
     *
     * @return int|float
     */
    public function count(): int|float
    {
        $content = $this->getContent();

        return $content->sum('qty');
    }

    /**
     * Get the total price of the items in the cart.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return string
     */
    public function total($decimals = null, $decimalPoint = null, $thousandSeperator = null, $withFees = true): string
    {
        $content = $this->getContent();

        $total = $content->reduce(function ($total, CartItem $cartItem) {
            return $total + $cartItem->getRawTotal();
        }, 0);

        if ($withFees === true) {
            $total = $total + $this->getRawFeeTotal(true);
        }

        $decimals = is_null($decimals) ? config('cart.format.total_decimals') : $decimals;

        return $this->numberFormat($total, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Get the total tax of the items in the cart.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return float
     */
    public function tax($decimals = null, $decimalPoint = null, $thousandSeperator = null, $withFees = true): string
    {
        $content = $this->getContent();

        $tax = $content->reduce(function ($tax, CartItem $cartItem) {
            return $tax + $cartItem->getRawTaxTotal();
        }, 0);

        if ($withFees === true) {
            $tax = $tax + $this->getRawFeeTax();
        }

        $decimals = is_null($decimals) ? config('cart.format.tax_decimals') : $decimals;

        return $this->numberFormat($tax, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Get the total tax of the items in the cart.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return float
     */
    public function feeTax($decimals = null, $decimalPoint = null, $thousandSeperator = null): string
    {
        $tax = $this->getRawFeeTax();

        $decimals = is_null($decimals) ? config('cart.format.fee_total_tax_decimals') : $decimals;

        return $this->numberFormat($tax, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Get the raw (unformatted) total tax across all fees.
     *
     * @return float
     */
    protected function getRawFeeTax(): float
    {
        $tax = 0;

        foreach ($this->getFees() as $fee) {
            $tax += $fee->getRawTax();
        }

        return $tax;
    }

    /**
     * Get the raw (unformatted) total of fees, optionally including tax.
     *
     * @param bool $withTax
     * @return float
     */
    protected function getRawFeeTotal(bool $withTax = true): float
    {
        $feeTotal = 0;

        foreach ($this->getFees() as $fee) {
            $feeTotal += $fee->amount;

            if ($withTax === true && $fee->taxRate > 0) {
                $feeTotal += $fee->getRawTax();
            }
        }

        return $feeTotal;
    }

    /**
     * Get the subtotal (ex tax) of the items in the cart.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return float
     */
    public function subtotal($decimals = null, $decimalPoint = null, $thousandSeperator = null): string
    {
        $content = $this->getContent();

        $subTotal = $content->reduce(function ($subTotal, CartItem $cartItem) {
            return $subTotal + $cartItem->getRawSubtotal();
        }, 0);

        $decimals = is_null($decimals) ? config('cart.format.subtotal_ex_tax_decimals') : $decimals;

        return $this->numberFormat($subTotal, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Get the subtotal (total - tax) of the items in the cart.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return float
     */
    public function subtotalTax($decimals = null, $decimalPoint = null, $thousandSeperator = null): string
    {
        $content = $this->getContent();

        $subTotal = $content->reduce(function ($subTotal, CartItem $cartItem) {
            return $subTotal + $cartItem->getRawTotal();
        }, 0);

        $decimals = is_null($decimals) ? config('cart.format.subtotal_inc_tax_decimals') : $decimals;

        return $this->numberFormat($subTotal, $decimals, $decimalPoint, $thousandSeperator);
    }

    /**
     * Search the cart content for a cart item matching the given search closure.
     *
     * @param Closure $search
     * @return \Illuminate\Support\Collection
     */
    public function search(Closure $search): Collection
    {
        $content = $this->getContent();

        return $content->filter($search);
    }

    /**
     * Associate the cart item with the given rowId with the given model.
     *
     * @param string $rowId
     * @param mixed  $model
     * @return void
     */
    public function associate($rowId, $model): void
    {
        if (
            is_string($model) === true &&
            class_exists($model) === false
        ) {
            throw new UnknownModelException("The supplied model {$model} does not exist.");
        }

        $cartItem = $this->get($rowId);

        $cartItem->associate($model);

        $content = $this->getContent();

        $content->put($cartItem->rowId, $cartItem);

        $this->session->put($this->instance, $this->toArray());
    }

    /**
     * Set the tax rate for the cart item with the given rowId.
     *
     * @param string    $rowId
     * @param int|float $taxRate
     * @return void
     */
    public function setTax($rowId, $taxRate): void
    {
        $cartItem = $this->get($rowId);

        $cartItem->setTaxRate($taxRate);

        $content = $this->getContent();

        $content->put($cartItem->rowId, $cartItem);

        $this->items = $content;

        $this->session->put($this->instance, $this->toArray());
    }

    /**
     * Store an the current instance of the cart.
     *
     * @param $identifier
     * @param array $eventOptions
     * @return void
     */
    public function store($identifier, array $eventOptions = []): void
    {
        // Remove any existing identifiers
        $recordExists = $this
            ->getConnection()
            ->table($this->getTableName())
            ->where('identifier', $identifier)
            ->exists();

        if ($recordExists === false) {
            // Insert into the database with the new cart
            $this
                ->getConnection()
                ->table($this->getTableName())
                ->insert([
                    'identifier' => $identifier,
                    'instance' => $this->currentInstance(),
                    'content' => serialize($this->toArray()),
                    'created_at'=> new DateTime(),
                ]);
        } else {
            // Or update if set
            $this
                ->getConnection()
                ->table($this->getTableName())
                ->where('identifier', $identifier)
                ->where('instance', $this->currentInstance())
                ->update([
                    'content' => serialize($this->toArray()),
                ]);
        }

        $eventOptions = array_merge([
            'cartInstance' => $this->currentInstance(),
        ], $eventOptions);

        $this->events->dispatch('cart.stored', [
            $eventOptions,
        ]);
    }

    /**
     * Restore the cart with the given identifier.
     *
     * @param $identifier
     * @param array $eventOptions
     * @return void
     */
    public function restore($identifier, array $eventOptions = []): void
    {
        if ($this->storedCartWithIdentifierExists($identifier) === false) {
            return;
        }

        // Find any existing carts by identifier
        $stored = $this
            ->getConnection()
            ->table($this->getTableName())
            ->where('identifier', $identifier)
            ->first();

        // Unserialize the content (either array if new, or collection if old)
        $storedContent = unserialize($stored->content);

        $currentInstance = $this->currentInstance();

        $this->instance($stored->instance);

        $content = $this->getContent();

        // If the new approach and is array, set this class up.
        // Note that it overrides any existing items in cart
        // Does not add to existing.
        if (is_array($storedContent)) {
            $this->fromArray($storedContent);
        }

        // If the old approach and is Collection, push into existing items
        if ($storedContent instanceof Collection) {
            foreach ($storedContent as $cartItem) {
                $content->put($cartItem->rowId, $cartItem);
            }
        }

        $eventOptions = array_merge([
            'cartInstance' => $this->currentInstance(),
        ], $eventOptions);

        $this->events->dispatch('cart.restored', [
            $eventOptions,
        ]);

        $this->session->put($this->instance, $this->toArray());

        $this->instance($currentInstance);
    }

    /**
     * Gets a specific fee from the fees array.
     *
     * @param $name
     *
     * @return mixed
     */
    public function getFee($name): CartFee
    {
        return $this->fees->get($name, new CartFee(null, null));
    }

    /**
     * Allows to charge for additional fees that may or may not be taxable
     * ex - service fee , delivery fee, tips.
     *
     * Because it uses ->put, the name must be unique otherwise will be overwritten.
     *
     * @param            $name
     * @param            $amount
     * @param            $taxRate
     * @param array      $options
     */
    public function addFee($name, $amount, $taxRate = null, array $options = []): void
    {
        $this->fees->put($name, new CartFee($amount, $taxRate, $options));

        $this->session->put($this->instance, $this->toArray());
    }

    /**
     * Removes a fee from the fee array.
     *
     * @todo test to see if i need to restore this
     *
     * @param $name
     */
    public function removeFee($name): void
    {
        $this->fees->forget($name);

        $this->session->put($this->instance, $this->toArray());
    }

    /**
     * Removes all the fees set in the cart.
     */
    public function removeFees(): void
    {
        $this->fees = new Collection;

        $this->session->put($this->instance, $this->toArray());
    }

    /**
     * Gets all the fee totals.
     *
     * @param bool $format
     * @param bool $withTax
     *
     * @return string
     */
    public function feeTotal($decimals = null, $decimalPoint = null, $thousandSeperator = null, $withTax = true): string
    {
        return $this->numberFormat($this->getRawFeeTotal($withTax), null, null, null);
    }

    /**
     * Gets all the fees on the cart object.
     *
     * @return mixed
     */
    public function getFees(): Collection
    {
        return $this->fees;
    }

    /**
     * Magic method to make accessing the total, tax and subtotal properties possible.
     *
     * @param string $attribute
     * @return float|null
     */
    public function __get($attribute): mixed
    {
        if ($attribute === 'total') {
            return $this->total();
        }

        if ($attribute === 'feeTotal') {
            return $this->feeTotal(null, null, null, false);
        }

        if ($attribute === 'feeTotalTax') {
            return $this->feeTotal(null, null, null, true);
        }

        if ($attribute === 'tax') {
            return $this->tax();
        }

        if ($attribute === 'feeTax') {
            return $this->feeTax();
        }

        if ($attribute === 'subtotal') {
            return $this->subtotal();
        }

        if ($attribute === 'subtotalTax') {
            return $this->subtotalTax();
        }

        return null;
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'fees' => $this->fees,
        ];
    }

    /**
     * @param $array
     * @return $this
     */
    public function fromArray($array): self
    {
        $this->items = $array['items'];
        $this->fees = $array['fees'];

        return $this;
    }

    /**
     * Deletes the stored cart with given identifier
     *
     * @param mixed $identifier
     */
    protected function deleteStoredCart($identifier): void
    {
        $this
            ->getConnection()
            ->table($this->getTableName())
            ->where('identifier', $identifier)
            ->delete();
    }

    /**
     * Get the carts content, if there is no cart content set yet, return a new empty Collection
     *
     * @return \Illuminate\Support\Collection
     */
    protected function getContent(): Collection
    {
        $instanceExists = $this->session->has($this->instance);

        if ($instanceExists === false) {
            $this->items = new Collection;

            return $this->items;
        }

        $instance = $this->session->get($this->instance);

        // If new approach, set $this variables
        if (is_array($instance) === true) {
            $this->items = $instance['items'];
            $this->fees = $instance['fees'];
        }

        if ($instance instanceof Collection) {
            $this->items = $instance;
        }

        return $this->items;
    }

    /**
     * Create a new CartItem from the supplied attributes.
     *
     * @param mixed     $id
     * @param mixed     $name
     * @param int|float $qty
     * @param float     $price
     * @param array     $options
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    private function createCartItem($id, $name, $qty, $price, $taxRate, bool $taxIncluded, array $options): CartItem
    {
        if ($id instanceof Buyable) {
            $cartItem = CartItem::fromBuyable($id, $qty ?: []);
            $cartItem->setQuantity($name ?: 1);
            $cartItem->associate($id);
        } elseif (is_array($id)) {
            $cartItem = CartItem::fromArray($id);
            $cartItem->setQuantity($id['qty']);
        } else {
            $cartItem = CartItem::fromAttributes($id, $name, $price, $options);
            $cartItem->setQuantity($qty);
        }

        $taxRate = is_int($taxRate) ? $taxRate : config('cart.tax');

        $cartItem->setTaxRate($taxRate);
        $cartItem->setTaxIncluded($taxIncluded);

        return $cartItem;
    }

    /**
     * Check if the item is a multidimensional array or an array of Buyables.
     *
     * @param mixed $item
     * @return bool
     */
    private function isMulti($item): bool
    {
        if (is_array($item) === false) {
            return false;
        }

        return is_array(head($item)) || head($item) instanceof Buyable === true;
    }

    /**
     * @param $identifier
     * @return bool
     */
    protected function storedCartWithIdentifierExists($identifier): bool
    {
        return $this
            ->getConnection()
            ->table($this->getTableName())
            ->where('identifier', $identifier)
            ->exists();
    }

    /**
     * Get the database connection.
     *
     * @return \Illuminate\Database\Connection
     */
    protected function getConnection(): \Illuminate\Database\Connection
    {
        $connectionName = $this->getConnectionName();

        return app(DatabaseManager::class)->connection($connectionName);
    }

    /**
     * Get the database table name.
     *
     * @return string
     */
    protected function getTableName(): string
    {
        return config('cart.database.table', 'shoppingcart');
    }

    /**
     * Get the database connection name.
     *
     * @return string
     */
    private function getConnectionName(): string
    {
        $connection = config('cart.database.connection');

        return is_null($connection) ? config('database.default') : $connection;
    }
}
