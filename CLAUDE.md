# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository

A Laravel package (`digital-bird/shoppingcart`, namespace `Gloudemans\Shoppingcart`) providing a session-backed shopping cart with optional database persistence. This is a fork of the original Crinsane/LaravelShoppingcart, updated for Laravel 11/12/13 and PHPUnit 11/12. Published via Composer; consumers install it into a Laravel app — there is no application shell here.

## Commands

- Install dev deps: `composer install`
- Run full test suite: `vendor/bin/phpunit`
- Run a single test class: `vendor/bin/phpunit tests/CartTest.php`
- Run a single test method: `vendor/bin/phpunit --filter it_has_a_default_instance`
- The user runs Pest tests manually — do not invoke them yourself.

Tests use `orchestra/testbench` to boot a minimal Laravel app, with the session driver set to `array` and an in-memory SQLite connection (see `tests/CartTest.php::getEnvironmentSetUp`). The package migration is loaded automatically in `setUp` via `$migrator->path(...)`.

## Architecture

The package binds `cart` in the container to a single `Cart` instance per request (`ShoppingcartServiceProvider::register`), so the `Cart` facade and DI both resolve the same object. The cart's state is **not** held on the object — it lives in the session under a key like `cart.<instance>` and is re-hydrated on every read via `Cart::getContent()`. Anything that mutates state must call `$this->session->put($this->instance, $this->toArray())` to persist; forgetting this is the most common bug to watch for.

Key collaborators:

- `Cart` — public API (`add`, `update`, `remove`, `content`, `total`, `tax`, `subtotal`, `store`, `restore`, fees). Holds `items` and `fees` Collections, but treat them as caches of session data.
- `CartItem` — value object for a single line. `rowId` is `md5(id + serialize(options))` (see `generateRowId`), so two adds with the same id+options merge into one row by quantity. Setting `cart.allow_multiple_same_id = true` mixes `microtime()` into the rowId so every add becomes a distinct row.
- `CartFee` / `CartFeeOptions` — cart-level surcharges (delivery, service fee, tips). Stored in `$cart->fees` and included in `total()`/`tax()` by default (`$withFees = true`).
- `Buyable` interface (`Contracts/Buyable.php`) — when an Eloquent model implements it, `Cart::add($model, $qty, $options)` auto-extracts id/name/price and calls `associate()` so `$cartItem->model` later re-fetches the model by id.
- `CartHelper` trait — shared `numberFormat()` used by both `Cart` and `CartItem` for output formatting.

Two state shapes coexist for backward compatibility (see `Cart::getContent` and `Cart::restore`):

1. **New shape:** session/serialized value is `['items' => Collection, 'fees' => Collection]` (an array). This is what `toArray()`/`fromArray()` round-trip.
2. **Legacy shape:** session/serialized value is a bare `Collection` of CartItems (no fees). `getContent` and `restore` detect `instanceof Collection` and load it into `$items` only.

When changing serialization, preserve both branches — old persisted carts in the `shoppingcart` table may still be in the legacy shape.

### Tax model

`CartItem` has both `taxRate` (percentage, e.g. `10` for 10%) and `taxIncluded` (bool). When `taxIncluded` is true, `price` is treated as gross and tax is extracted with `price / (1 + taxRate)` — note this divides by `1 + rate`, **not** `1 + rate/100`, which differs from the additive path. If you touch `CartItem::tax()`, keep both branches consistent and check `CartItemTest` expectations before changing the formula.

### Decimal precision convention

`config/cart.php` distinguishes "unit price shown to user" decimals (2) from "intermediate / post-multiplication" decimals (4). The intent is: keep 4 decimals through `qty *` and aggregation, only round to 2 at the final display. When adding a new total/format method, follow the existing per-method config keys (`price_ex_tax_decimals`, `tax_total_decimals`, etc.) rather than reusing the deprecated `format.decimals`.

## Conventions

- Public package namespace stays `Gloudemans\Shoppingcart\...` even though the Composer name is `digital-bird/shoppingcart` — do not rename, it would break consumers.
- `Cart::__get` and `CartItem::__get` expose computed values (`total`, `tax`, `subtotal`, `subtotalTax`, `taxTotal`, `priceTax`, `model`, `feeTotal`, `feeTotalTax`, `feeTax`) as virtual properties. New computed values should be added there as well as their corresponding method, so both `$cart->total` and `$cart->total()` work.
- Events dispatched: `cart.added`, `cart.updated`, `cart.removed`, `cart.stored`, `cart.restored`. Each receives an `eventOptions` array merged with `cartInstance` and (where applicable) `cartItem`. Tests assert event names with `Event::fake()` — keep names stable.
- Migration filename uses the `0000_00_00_000000_` prefix so the publish step in `ShoppingcartServiceProvider` can rewrite it with the current timestamp on the consumer side.
