<?php

namespace Sim\Cart\Interfaces;

interface ICart
{
    /**
     * @param string $name
     * @return static
     */
    public function setCartName(string $name);

    /**
     * @return string
     */
    public function getCartName(): string;

    /**
     * @param int $expire_time
     * @return static
     */
    public function setExpiration(int $expire_time);

    /**
     * @return int
     */
    public function getExpiration(): int;

    /**
     * @return ICartsUtil
     */
    public function utils(): ICartsUtil;

    /**
     * @param string $item_code
     * @param array|null $item_info
     * @param array $columns - It has [products] and [product_property] joined tables that are aliased [p] and [pp] respectively.
     * @return static
     */
    public function add(string $item_code, ?array $item_info = null, array $columns = []);

    /**
     * Check if item is added or it'll add it internally
     *
     * @param string $item_code
     * @param array|null $item_info
     * @return static
     */
    public function update(string $item_code, array $item_info = null);

    /**
     * @param string $item_code
     * @return bool
     */
    public function remove(string $item_code): bool;

    /**
     * Check if an item has been added then get item's info,
     * or it'll add it first
     *
     * @param string $item_code
     * @return array
     */
    public function getItem(string $item_code): array;

    /**
     * @return array
     */
    public function getItems(): array;

    /**
     * @return static
     */
    public function clearItems();

    /**
     * @param string $item_code
     * @return bool
     */
    public function hasItemWithCode(string $item_code): bool;

    /**
     * @return float
     */
    public function totalPrice(): float;

    /**
     * @return float
     */
    public function totalDiscountedPrice(): float;

    /**
     * @return float
     */
    public function totalPriceWithTax(): float;

    /**
     * @return float
     */
    public function totalDiscountedPriceWithTax(): float;

    /**
     * @param string $key
     * @return float
     */
    public function totalAttributeValue(string $key): float;

    /**
     * @param string $item_code
     * @param int $decimal_numbers
     * @param bool $round
     * @return float
     */
    public function discountedPercentage(string $item_code, int $decimal_numbers = 2, bool $round = false): float;

    /**
     * @param int $decimal_numbers
     * @param bool $round
     * @return float
     */
    public function totalDiscountedPercentage(int $decimal_numbers = 2, bool $round = false): float;

    /**
     * @param int $decimal_numbers
     * @param bool $round
     * @return float
     */
    public function totalDiscountedPercentageWithTax(int $decimal_numbers = 2, bool $round = false): float;

    /**
     * Store cart to defined storage
     *
     * @return static
     */
    public function store();

    /**
     * Restore stored data in storage
     *
     * @param bool $validate - Works with sessions only because cookies will validate automatically
     * @return static
     */
    public function restore(bool $validate = false);

    /**
     * @return static
     */
    public function destroy();
}