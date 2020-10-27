<?php

namespace Sim\Cart;

use PDO;
use Sim\Cart\Config\ConfigParser;
use Sim\Cart\Exceptions\CartMaxCountException;
use Sim\Cart\Exceptions\ConfigException;
use Sim\Cart\Helpers\DB;
use Sim\Cart\Interfaces\ICart;
use Sim\Cart\Interfaces\ICartsUtil;
use Sim\Cart\Interfaces\IDBException;

class CartsUtil implements ICartsUtil
{
    /**
     * @var PDO
     */
    protected $pdo;

    /**
     * @var DB
     */
    protected $db;

    /**
     * @var array $default_config
     */
    protected $default_config = [];

    /**
     * @var array $tables
     */
    protected $tables = [];

    /**
     * @var ConfigParser
     */
    protected $config_parser;

    /********** table keys **********/

    /**
     * @var string
     */
    protected $users_key = 'users';

    /**
     * @var string
     */
    protected $products_key = 'products';

    /**
     * @var string
     */
    protected $product_property_key = 'product_property';

    /**
     * @var string
     */
    protected $carts_key = 'carts';

    /**
     * @var string
     */
    protected $cart_item_key = 'cart_item';

    /**
     * Carts constructor.
     * @param PDO $pdo_instance
     * @param array|null $config
     * @throws IDBException
     */
    public function __construct(PDO $pdo_instance, ?array $config = null)
    {
        $this->pdo = $pdo_instance;
        $this->db = new DB($pdo_instance);

        // load default config from _Config dir
        $this->default_config = include __DIR__ . '/_Config/config.php';
        if (!is_null($config)) {
            $this->setConfig($config);
        } else {
            $this->setConfig($this->default_config);
        }
    }

    /**
     * @param array $config
     * @param bool $merge_config
     * @return static
     * @throws IDBException
     */
    public function setConfig(array $config, bool $merge_config = false)
    {
        if ($merge_config) {
            if (!empty($config)) {
                $config = array_merge_recursive($this->default_config, $config);
            }
        }

        // parse config
        $this->config_parser = new ConfigParser($config, $this->pdo);

        // get tables
        $this->tables = $this->config_parser->getTables();

        return $this;
    }

    /**
     * @return static
     * @throws ConfigException
     */
    public function runConfig()
    {
        $this->config_parser->up();
        return $this;
    }

    /**
     * {@inheritdoc}
     * @throws IDBException
     * @throws CartMaxCountException
     */
    public function save(
        ICart $cart,
        int $user_id,
        int $max_stored_cart = PHP_INT_MAX,
        array $extra_parameters = [],
        string $extra_where = null,
        array $bind_values = []
    ): bool
    {
        $cartColumns = $this->config_parser->getTablesColumn($this->carts_key);
        $cartItemColumns = $this->config_parser->getTablesColumn($this->cart_item_key);
        $productPropertyColumns = $this->config_parser->getTablesColumn($this->product_property_key);

        $where = "{$this->db->quoteName($cartColumns['name'])}=:__cart_name_ AND " .
            "{$this->db->quoteName($cartColumns['user_id'])}=:__cart_user_id_";
        if (!empty($extra_where)) {
            $where = " AND ({$extra_where})";
        }

        $bindValues = [
            '__cart_name_' => $cart->getCartName(),
            '__cart_user_id_' => $user_id,
        ];

        // local global result status
        $status = true;

        // unset user id field
        unset($extra_parameters[$cartColumns['user_id']]);
        // unset name field
        unset($extra_parameters[$cartColumns['name']]);
        // unset created_at field
        unset($extra_parameters[$cartColumns['created_at']]);

        // delete all expired carts for specific user
        $this->deleteExpiredCarts($cart->getCartName(), $user_id);

        $isUpdate = $this->db->count(
                $this->tables[$this->carts_key],
                $where,
                array_merge($bindValues, $bind_values)
            ) > 0;

        if ($isUpdate) {
            if (!empty($extra_parameters)) {
                // update main cart table
                $status = $status && $this->db->update(
                        $this->tables[$this->carts_key],
                        $extra_parameters,
                        $where,
                        array_merge($bindValues, $bind_values)
                    );
            }
        } else {
            $userCartCount = $this->db->count(
                $this->tables[$this->carts_key],
                "{$this->db->quoteName($cartColumns['user_id'])}=:__cart_user_id_",
                [
                    '__cart_user_id_' => $user_id,
                ]
            );
            if ($userCartCount < $max_stored_cart) {
                // insert to main cart table
                $status = $status && $this->db->insert(
                        $this->tables[$this->carts_key],
                        array_merge([
                            $cartColumns['name'] => $cart->getCartName(),
                            $cartColumns['user_id'] => $user_id,
                            $cartColumns['created_at'] => time(),
                            $cartColumns['expire_at'] => $cart->getExpiration(),
                        ], $extra_parameters)
                    );
            } else {
                throw new CartMaxCountException('Cart has reached its maximum value');
            }
        }

        // if there is an error through previous operation
        if (!$status) {
            return false;
        }

        // again local global result status
        $status = true;

        // get cart id
        $cartId = $this->db->getFrom(
                $this->tables[$this->carts_key],
                $where,
                $cartColumns['id'],
                array_merge([
                    '__cart_name_' => $extra_parameters[$cartColumns['name']] ?? $cart->getCartName(),
                    '__cart_user_id_' => $user_id,
                ], $bind_values)
            )[0][$cartColumns['id']] ?? null;

        // if we can't fetch inserted cart we failed! :(
        if (is_null($cartId)) return false;

        // delete all cart items and insert them again
        $status = $status && $this->db->delete(
                $this->tables[$this->cart_item_key],
                "{$this->db->quoteName($cartItemColumns['cart_id'])}=:__cart_id_",
                [
                    '__cart_id_' => $cartId,
                ]
            );

        // insert cart items
        $items = $cart->getItems();

        foreach ($items as $id => $item) {
            // create where and bind values parameters
            $ppWhere = "";
            $ppBindValues = [];

            foreach ($productPropertyColumns as $key => $column) {
                if (isset($item[$column])) {
                    $k = "_bind_{$key}";
                    $ppWhere .= "{$this->db->quoteName($column)}=:$k AND ";
                    $ppBindValues[$k] = $item[$column];
                }
            }
            $ppWhere = trim($ppWhere, 'AND ');

            $productProperty = $this->db->getFrom(
                $this->tables[$this->product_property_key],
                $ppWhere,
                $productPropertyColumns['id'],
                $ppBindValues
            );

            // if there is a product with specific property
            if (count($productProperty)) {
                // get first product id
                $productPropertyId = $productProperty[0][$productPropertyColumns['id']];
                $status = $status && $this->db->insert(
                        $this->tables[$this->cart_item_key],
                        [
                            $cartItemColumns['cart_id'] => $cartId,
                            $cartItemColumns['product_property_id'] => $productPropertyId,
                            $cartItemColumns['qnt'] => $item['qnt'],
                        ]
                    );

                if (!$status) break;
            }
        }

        return $status;
    }

    /**
     * {@inheritdoc}
     * @throws IDBException
     */
    public function fetch(string $cart_name, int $user_id, ICart &$cart)
    {
        $cartColumns = $this->config_parser->getTablesColumn($this->carts_key);
        $cartItemColumns = $this->config_parser->getTablesColumn($this->cart_item_key);
        $productPropertyColumns = $this->config_parser->getTablesColumn($this->product_property_key);

        // a big fat join through carts, cart_item and product_property tables
        $sql = "SELECT {$this->db->quoteName('ci')}.{$cartItemColumns['qnt']} AS {$this->db->quoteName('qnt')}, ";
        $sql .= "{$this->db->quoteName('c')}.{$cartColumns['name']} AS {$this->db->quoteName('name')}, ";
        $sql .= "{$this->db->quoteName('pp')}.{$productPropertyColumns['code']} AS {$this->db->quoteName('code')}, ";
        $sql .= "{$this->db->quoteName('pp')}.{$productPropertyColumns['stock_count']} AS {$this->db->quoteName('stock_count')}, ";
        $sql .= "{$this->db->quoteName('pp')}.{$productPropertyColumns['max_cart_count']} AS {$this->db->quoteName('max_cart_count')}, ";
        $sql .= "{$this->db->quoteName('pp')}.{$productPropertyColumns['price']} AS {$this->db->quoteName('price')}, ";
        $sql .= "{$this->db->quoteName('pp')}.{$productPropertyColumns['discounted_price']} AS {$this->db->quoteName('discounted_price')}, pp.* ";
        $sql .= "FROM {$this->db->quoteName($this->cart_item_key)} AS {$this->db->quoteName('ci')} ";
        $sql .= "INNER JOIN {$this->db->quoteName($this->carts_key)} AS {$this->db->quoteName('c')} ";
        $sql .= "ON {$this->db->quoteName('c')}.{$cartColumns['id']}={$this->db->quoteName('ci')}.{$cartItemColumns['cart_id']} ";
        $sql .= "INNER JOIN {$this->db->quoteName($this->product_property_key)} AS {$this->db->quoteName('pp')} ";
        $sql .= "ON {$this->db->quoteName('ci')}.{$cartItemColumns['product_property_id']}={$this->db->quoteName('pp')}.{$productPropertyColumns['id']} ";
        $sql .= "WHERE {$this->db->quoteName('c')}.{$cartColumns['name']}=:__cart_name_ ";
        $sql .= "AND {$this->db->quoteName('c')}.{$cartColumns['user_id']}=:__cart_user_id_";

        // delete all expired carts for specific user
        $this->deleteExpiredCarts($cart->getCartName(), $user_id);

        $stmt = $this->db->exec($sql, [
            '__cart_name_' => $cart_name,
            '__cart_user_id_' => $user_id,
        ]);
        $cartResult = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // remove all items from cart
        $cart->clearItems();

        if (count($cartResult)) {
            foreach ($cartResult as $key => $cartItem) {
                $newCartItem = [];
                $newCartItem['stock_count'] = $cartItem['stock_count'];
                $newCartItem['max_cart_count'] = $cartItem['max_cart_count'];
                $newCartItem['price'] = $cartItem['price'];
                $newCartItem['discounted_price'] = $cartItem['discounted_price'];
                $newCartItem['qnt'] = $cartItem['qnt'];

                // get extra properties and set them to cart item
                $diff = array_diff_key($cartItem, [
                    'stock_count' => $cartItem['stock_count'],
                    'max_cart_count' => $cartItem['max_cart_count'],
                    'price' => $cartItem['price'],
                    'discounted_price' => $cartItem['discounted_price'],
                    'qnt' => $cartItem['qnt'],
                ]);
                foreach ($diff as $k => $d) {
                    $productPropertyKey = array_search($k, $productPropertyColumns);
                    if ('id' !== $k && false !== $productPropertyKey) {
                        $newCartItem[$productPropertyKey] = $d;
                    }
                }

                // add cart item to cart collection
                $cart->add($cartItem['code'], $newCartItem);
            }
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     * @throws IDBException
     */
    public function delete(string $cart_name, int $user_id): bool
    {
        $cartColumns = $this->config_parser->getTablesColumn($this->carts_key);

        return $this->db->delete(
            $this->tables[$this->carts_key],
            "{$cartColumns['name']}=:__cart_name_ AND {$cartColumns['user_id']}=:__cart_user_id_",
            [
                '__cart_name_' => $cart_name,
                '__cart_user_id_' => $user_id,
            ]
        );
    }

    /**
     * {@inheritdoc}
     * @throws IDBException
     */
    public function deleteExpiredCarts(string $cart_name, int $user_id): bool
    {
        $cartColumns = $this->config_parser->getTablesColumn($this->carts_key);

        return $this->db->delete(
            $this->tables[$this->carts_key],
            "{$cartColumns['name']}=:__cart_name_ " .
            "AND {$cartColumns['user_id']}=:__cart_user_id_" .
            "AND {$cartColumns['expire_at']}<:__cart_expired_",
            [
                '__cart_name_' => $cart_name,
                '__cart_user_id_' => $user_id,
                '__cart_expired_' => time(),
            ]
        );
    }

    /**
     * {@inheritdoc}
     * @throws IDBException
     */
    public function changeName(
        int $user_id,
        string $old_cart_name,
        string $new_cart_name
    ): bool
    {
        $cartColumns = $this->config_parser->getTablesColumn($this->carts_key);

        return $this->db->update(
            $this->tables[$this->carts_key],
            [
                $cartColumns['name'] => $new_cart_name,
            ],
            "{$cartColumns['name']}=:__cart_old_name_ AND {$cartColumns['user_id']}=:__cart_user_id_",
            [
                '__cart_old_name_' => $old_cart_name,
                '__cart_user_id_' => $user_id,
            ]
        );
    }

    /**
     * {@inheritdoc}
     * @throws IDBException
     */
    public function getItem(string $item_code, $columns = '*'): array
    {
        $productPropertyColumns = $this->config_parser->getTablesColumn($this->product_property_key);

        if (!is_string($columns) && !is_array($columns)) $columns = '*';

        $res = $this->db->getFrom(
            $this->tables[$this->product_property_key],
            "{$productPropertyColumns['code']}=:__cart_item_code_ AND " .
            "{$productPropertyColumns['is_available']}=:__cart_item_available_",
            $columns,
            [
                '__cart_item_code_' => $item_code,
                '__cart_item_available_' => 1,
            ]
        );

        $res = count($res) ? $res[0] : [];
        $newRes = [];
        if (!empty($res)) {
            foreach ($res as $k => $d) {
                $productPropertyKey = array_search($k, $productPropertyColumns);
                if ('id' !== $k && false !== $productPropertyKey) {
                    $newRes[$productPropertyKey] = $d;
                }
            }
        }

        return $newRes;
    }

    /**
     * {@inheritdoc}
     * @throws IDBException
     */
    public function getStockCount(string $item_code): int
    {
        $productPropertyColumns = $this->config_parser->getTablesColumn($this->product_property_key);

        $res = $this->getItem($item_code, $productPropertyColumns['stock_count']);

        $stockCount = $res['stock_count'] ?? 0;
        $stockCount = (int)$stockCount;

        return $stockCount;
    }

    /**
     * {@inheritdoc}
     * @throws IDBException
     */
    public function getMaxCartCount(string $item_code): int
    {
        $productPropertyColumns = $this->config_parser->getTablesColumn($this->product_property_key);

        $res = $this->getItem($item_code, $productPropertyColumns['max_cart_count']);

        $maxCartCount = $res['max_cart_count'] ?? 0;
        $maxCartCount = (int)$maxCartCount;

        return $maxCartCount;
    }
}