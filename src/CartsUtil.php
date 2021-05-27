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

    /**
     * @var ICart
     */
    protected $cart;

    /**
     * @var int
     */
    protected $user_id = 0;

    /********** table keys **********/

    /**
     * @var string
     */
    protected $users_key = 'users';

    /**
     * @var string
     */
    protected $brands_key = 'brands';

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
     * @param ICart $cart
     * @param int $user_id
     * @param array|null $config
     * @throws IDBException
     */
    public function __construct(PDO $pdo_instance, ICart &$cart, ?int $user_id, ?array $config = null)
    {
        $this->pdo = $pdo_instance;
        $this->db = new DB($pdo_instance);

        // set cart and user id
        $this->cart = $cart;
        if (!is_null($user_id)) {
            $this->setUserId($user_id);
        }

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
     */
    public function getCart(): ICart
    {
        return $this->cart;
    }

    /**
     * {@inheritdoc}
     */
    public function setUserId(int $user_id)
    {
        $this->user_id = $user_id;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getUserId(): int
    {
        return $this->user_id;
    }

    /**
     * {@inheritdoc}
     * @throws IDBException
     * @throws CartMaxCountException
     */
    public function save(
        int $max_stored_cart = PHP_INT_MAX,
        array $extra_parameters = [],
        string $extra_where = null,
        array $bind_values = []
    ): bool
    {
        // if there no user, there is no reason to go through all conditions
        if ($this->getUserId() === 0) return false;

        $cartColumns = $this->config_parser->getTablesColumn($this->carts_key);
        $cartItemColumns = $this->config_parser->getTablesColumn($this->cart_item_key);
        $productPropertyColumns = $this->config_parser->getTablesColumn($this->product_property_key);

        $where = "{$this->db->quoteName($cartColumns['name'])}=:__cart_name_ AND " .
            "{$this->db->quoteName($cartColumns['user_id'])}=:__cart_user_id_";
        if (!empty($extra_where)) {
            $where = " AND ({$extra_where})";
        }

        $bindValues = [
            '__cart_name_' => $this->cart->getCartName(),
            '__cart_user_id_' => $this->user_id,
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
        $this->deleteExpiredCarts();

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
                    '__cart_user_id_' => $this->user_id,
                ]
            );
            if ($userCartCount < $max_stored_cart) {
                // insert to main cart table
                $status = $status && $this->db->insert(
                        $this->tables[$this->carts_key],
                        array_merge([
                            $this->db->quoteName($cartColumns['name']) => $this->cart->getCartName(),
                            $this->db->quoteName($cartColumns['user_id']) => $this->user_id,
                            $this->db->quoteName($cartColumns['created_at']) => time(),
                            $this->db->quoteName($cartColumns['expire_at']) => time() + $this->cart->getExpiration(),
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
                    '__cart_name_' => $extra_parameters[$cartColumns['name']] ?? $this->cart->getCartName(),
                    '__cart_user_id_' => $this->user_id,
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
        $items = $this->cart->getItems();

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
                            $this->db->quoteName($cartItemColumns['cart_id']) => $cartId,
                            $this->db->quoteName($cartItemColumns['product_property_id']) => $productPropertyId,
                            $this->db->quoteName($cartItemColumns['qnt']) => $item['qnt'],
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
    public function fetch(bool $append_to_previous_items = false)
    {
        // if there no user, there is no reason to go through all conditions
        if ($this->getUserId() === 0) return $this;

        $cartColumns = $this->config_parser->getTablesColumn($this->carts_key);
        $cartItemColumns = $this->config_parser->getTablesColumn($this->cart_item_key);
        $productPropertyColumns = $this->config_parser->getTablesColumn($this->product_property_key);

        // a big fat join through carts, cart_item and product_property tables
        $sql = "SELECT {$this->db->quoteName('ci')}.{$this->db->quoteName($cartItemColumns['qnt'])} AS {$this->db->quoteName('qnt')}, ";
        $sql .= "{$this->db->quoteName('c')}.{$this->db->quoteName($cartColumns['name'])} AS {$this->db->quoteName('name')}, ";
        $sql .= "{$this->db->quoteName('pp')}.{$this->db->quoteName($productPropertyColumns['code'])} AS {$this->db->quoteName('code')}, ";
        $sql .= "{$this->db->quoteName('pp')}.{$this->db->quoteName($productPropertyColumns['stock_count'])} AS {$this->db->quoteName('stock_count')}, ";
        $sql .= "{$this->db->quoteName('pp')}.{$this->db->quoteName($productPropertyColumns['max_cart_count'])} AS {$this->db->quoteName('max_cart_count')}, ";
        $sql .= "{$this->db->quoteName('pp')}.{$this->db->quoteName($productPropertyColumns['price'])} AS {$this->db->quoteName('price')}, ";
        $sql .= "{$this->db->quoteName('pp')}.{$this->db->quoteName($productPropertyColumns['discounted_price'])} AS {$this->db->quoteName('discounted_price')}, pp.* ";
        $sql .= "FROM {$this->db->quoteName($this->cart_item_key)} AS {$this->db->quoteName('ci')} ";
        $sql .= "INNER JOIN {$this->db->quoteName($this->carts_key)} AS {$this->db->quoteName('c')} ";
        $sql .= "ON {$this->db->quoteName('c')}.{$this->db->quoteName($cartColumns['id'])}={$this->db->quoteName('ci')}.{$this->db->quoteName($cartItemColumns['cart_id'])} ";
        $sql .= "INNER JOIN {$this->db->quoteName($this->product_property_key)} AS {$this->db->quoteName('pp')} ";
        $sql .= "ON {$this->db->quoteName('ci')}.{$this->db->quoteName($cartItemColumns['product_property_id'])}={$this->db->quoteName('pp')}.{$this->db->quoteName($productPropertyColumns['id'])} ";
        $sql .= "WHERE {$this->db->quoteName('c')}.{$this->db->quoteName($cartColumns['name'])}=:__cart_name_ ";
        $sql .= "AND {$this->db->quoteName('c')}.{$this->db->quoteName($cartColumns['user_id'])}=:__cart_user_id_";

        // delete all expired carts for specific user
        $this->deleteExpiredCarts();

        $stmt = $this->db->exec($sql, [
            '__cart_name_' => $this->cart->getCartName(),
            '__cart_user_id_' => $this->user_id,
        ]);
        $cartResult = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$append_to_previous_items) {
            // remove all items from cart
            $this->cart->clearItems();
        }

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

                // if we have item in cart, we should merge new and previous item together
                if ($this->cart->hasItemWithCode($cartItem['code'])) {
                    $newCartItem = array_merge($this->cart->getItem($cartItem['code']), $newCartItem);
                }

                // add cart item to cart collection
                $this->cart->add($cartItem['code'], $newCartItem);
            }
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     * @throws IDBException
     */
    public function delete(string $cart_name): bool
    {
        // if there no user, there is no reason to go through all conditions
        if ($this->getUserId() === 0) return false;

        $cartColumns = $this->config_parser->getTablesColumn($this->carts_key);

        return $this->db->delete(
            $this->tables[$this->carts_key],
            "{$this->db->quoteName($cartColumns['name'])}=:__cart_name_ AND {$this->db->quoteName($cartColumns['user_id'])}=:__cart_user_id_",
            [
                '__cart_name_' => $cart_name,
                '__cart_user_id_' => $this->user_id,
            ]
        );
    }

    /**
     * {@inheritdoc}
     * @throws IDBException
     */
    public function deleteExpiredCarts(): bool
    {
        // if there no user, there is no reason to go through all conditions
        if ($this->getUserId() === 0) return false;

        $cartColumns = $this->config_parser->getTablesColumn($this->carts_key);

        return $this->db->delete(
            $this->tables[$this->carts_key],
            "{$this->db->quoteName($cartColumns['user_id'])}=:__cart_user_id_ " .
            "AND {$this->db->quoteName($cartColumns['expire_at'])}<:__cart_expired_",
            [
                '__cart_user_id_' => $this->user_id,
                '__cart_expired_' => time(),
            ]
        );
    }

    /**
     * {@inheritdoc}
     * @throws IDBException
     */
    public function changeName(
        string $old_cart_name,
        string $new_cart_name
    ): bool
    {
        // if there no user, there is no reason to go through all conditions
        if ($this->getUserId() === 0) return false;

        $cartColumns = $this->config_parser->getTablesColumn($this->carts_key);

        return $this->db->update(
            $this->tables[$this->carts_key],
            [
                $this->db->quoteName($cartColumns['name']) => $new_cart_name,
            ],
            "{$this->db->quoteName($cartColumns['name'])}=:__cart_old_name_ " .
            "AND {$this->db->quoteName($cartColumns['user_id'])}=:__cart_user_id_",
            [
                '__cart_old_name_' => $old_cart_name,
                '__cart_user_id_' => $this->user_id,
            ]
        );
    }

    /**
     * {@inheritdoc}
     * @throws IDBException
     */
    public function getItem(string $item_code, array $columns = ['pp.*']): array
    {
        $brandColumns = $this->config_parser->getTablesColumn($this->brands_key);
        $productColumns = $this->config_parser->getTablesColumn($this->products_key);
        $productPropertyColumns = $this->config_parser->getTablesColumn($this->product_property_key);

        if (empty($columns)) $columns = ['pp.*'];

        foreach ($columns as &$col) {
            $col = $this->db->quoteNames($col);
        }

        $sql = "SELECT " . implode(',', $columns) . " ";
        $sql .= "FROM {$this->db->quoteName($this->tables[$this->product_property_key])} AS {$this->db->quoteName('pp')} ";
        $sql .= "INNER JOIN {$this->db->quoteName($this->products_key)} AS {$this->db->quoteName('p')} ";
        $sql .= "ON {$this->db->quoteName('p')}.{$this->db->quoteName($productColumns['id'])}={$this->db->quoteName('pp')}.{$this->db->quoteName($productPropertyColumns['product_id'])} ";
        $sql .= "INNER JOIN {$this->db->quoteName($this->brands_key)} AS {$this->db->quoteName('b')} ";
        $sql .= "ON {$this->db->quoteName('b')}.{$this->db->quoteName($brandColumns['id'])}={$this->db->quoteName('pp')}.{$this->db->quoteName($productPropertyColumns['brand_id'])} ";
        $sql .= "WHERE {$this->db->quoteName('p')}.{$this->db->quoteName($productPropertyColumns['code'])}=:__cart_item_code_ ";
        $sql .= "AND {$this->db->quoteName('pp')}.{$this->db->quoteName($productPropertyColumns['is_available'])}=:__cart_item_available_";
        $sql .= "AND {$this->db->quoteName('b')}.{$this->db->quoteName($brandColumns['publish'])}=:__cart_item_brand_pub_";

        $stmt = $this->db->exec($sql, [
            '__cart_item_code_' => $item_code,
            '__cart_item_available_' => 1,
            '__cart_item_brand_pub_' => 1,
        ]);
        $res = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $res = count($res) ? $res[0] : [];
        $newRes = [];
        if (!empty($res)) {
            foreach ($res as $k => $d) {
                $productPropertyKey = array_search($k, $productPropertyColumns);
                $brandKey = array_search($k, $brandColumns);
                $productKey = array_search($k, $productColumns);
                if ('id' !== $k) {
                    if (false !== $productPropertyKey) {
                        $newRes[$productPropertyKey] = $d;
                    } elseif (false !== $productKey) {
                        $newRes[$productKey] = $d;
                    } elseif (false !== $brandKey) {
                        $newRes[$brandKey] = $d;
                    } else {
                        $newRes[$k] = $d;
                    }
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