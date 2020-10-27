<?php

namespace Sim\Cart\Interfaces;

interface ICartsUtil
{
    /**
     * @return static
     */
    public function runConfig();

    /**
     * @param ICart $cart
     * @param int $user_id
     * @param int $max_stored_cart
     * @param array $extra_parameters
     * @param string|null $extra_where
     * @param array $bind_values
     * @return bool
     */
    public function save(
        ICart $cart,
        int $user_id,
        int $max_stored_cart = PHP_INT_MAX,
        array $extra_parameters = [],
        string $extra_where = null,
        array $bind_values = []
    ): bool;

    /**
     * @param ICart $cart
     * @param int $user_id
     * @return static
     */
    public function fetch(ICart &$cart, int $user_id);

    /**
     * @param string $cart_name
     * @param int $user_id
     * @return bool
     */
    public function delete(string $cart_name, int $user_id): bool;

    /**
     * @param string $cart_name
     * @param int $user_id
     * @return bool
     */
    public function deleteExpiredCarts(string $cart_name, int $user_id): bool;

    /**
     * @param int $user_id
     * @param string $old_cart_name
     * @param string $new_cart_name
     * @return bool
     */
    public function changeName(
        int $user_id,
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