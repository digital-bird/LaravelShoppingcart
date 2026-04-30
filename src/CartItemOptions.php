<?php

namespace Gloudemans\Shoppingcart;

use Illuminate\Support\Collection;

class CartItemOptions extends Collection
{
    /**
     * Get the option by the given key.
     *
     * @param string $key
     * @return mixed
     */
    public function __get($key): mixed
    {
        return $this->get($key);
    }
}
