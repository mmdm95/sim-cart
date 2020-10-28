<?php

namespace Sim\Cart\Interfaces;

interface ICartsUtil
{
    /**
     * @return static
     */
    public function runConfig();

    /**
     * @return ICart
     */
    public function getCart(): ICart;

    /**
     * @param int $user_id
     * @return static
     */
    public function setUserId(int $user_id);

    /**
     * @return int
     */
    public function getUserId(): int;

    /**
     * @param int $max_stored_cart
     * @param array $extra_parameters
     * @param string|null $extra_where
     * @param array $bind_values
     * @return bool
     */
    public function save(
        int $max_stored_cart = PHP_INT_MAX,
        array $extra_parameters = [],
        string $extra_where = null,
        array $bind_values = []
    ): bool;

    /**
     * @param bool $append_to_previous_items
     * @return static
     */
    public function fetch(bool $append_to_previous_items = false);

    /**
     * @param string $cart_name
     * @return bool
     */
    public function delete(string $cart_name): bool;

    /**
     * @return bool
     */
    public function deleteExpiredCarts(): bool;

    /**
     * @param string $old_cart_name
     * @param string $new_cart_name
     * @return bool
     */
    public function changeName(
        string $old_cart_name,
        string $new_cart_name
    ): bool;

    /**
     * @param string $item_code
     * @param string|array $columns
     * @return array
     */
    public function getItem(string $item_code, $columns = '*'): array;

    /**
     * @param string $item_code
     * @return int
     */
    public function getStockCount(string $item_code): int;

    /**
     * @param string $item_code
     * @return int
     */
    public function getMaxCartCount(string $item_code): int;
}