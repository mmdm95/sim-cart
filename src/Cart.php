<?php

namespace Sim\Cart;

use PDO;
use Sim\Cart\Interfaces\ICart;
use Sim\Cart\Interfaces\ICartsUtil;
use Sim\Cart\Interfaces\IDBException;
use Sim\Cookie\Interfaces\ICookie;
use Sim\Cookie\SetCookie;

class Cart implements ICart
{
    /**
     * @var ICartsUtil
     */
    protected $cart_util;

    /**
     * @var array - array of ICartItem
     */
    protected $items = [];

    /**
     * @var ICookie
     */
    protected $storage;

    /**
     * @var int
     */
    protected $storage_expiration_time = 31536000 /* 1 year */
    ;

    /**
     * @var string
     */
    protected $cart_name = 'default';

    /**
     * Cart constructor.
     * @param PDO $pdo_instance
     * @param ICookie $cookie_storage
     * @param array|null $config
     * @throws IDBException
     */
    public function __construct(PDO $pdo_instance, ICookie $cookie_storage, ?array $config = null)
    {
        $this->storage = $cookie_storage;
        $this->cart_util = new CartsUtil($pdo_instance, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function setCartName(string $name)
    {
        if ('' !== $name) {
            $this->cart_name = $name;
        }
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getCartName(): string
    {
        return $this->cart_name;
    }

    /**
     * {@inheritdoc}
     */
    public function setExpiration(int $expire_time)
    {
        if ($this->isValidTimestamp($expire_time)) {
            $this->storage_expiration_time = $expire_time;
        }
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getExpiration(): int
    {
        return $this->storage_expiration_time;
    }

    public function utils(): ICartsUtil
    {
        return $this->cart_util;
    }

    /**
     * {@inheritdoc}
     * @throws IDBException
     */
    public function add(string $item_code, array $item_info = null)
    {
        $item = $this->cart_util->getItem($item_code);
        if (!empty($item)) {
            $item_info = $this->normalizeQuantity($item, $item_info);
            if ($item_info['qnt'] > 0) {
                $item['qnt'] = $item_info['qnt'];
                $item_info = array_merge($item_info, $item);
                $this->items[$item_code] = $item_info;
            } else {
                $this->remove($item_code);
            }
        }
        return $this;
    }

    /**
     * {@inheritdoc}
     * @throws IDBException
     */
    public function update(string $item_code, array $item_info = null)
    {
        $item = $this->getItem($item_code);
        $item_info = $this->normalizeQuantity($item, $item_info);
        if ($item_info['qnt'] > 0) {
            $item['qnt'] = $item_info['qnt'];
            $this->items[$item_code] = array_merge($item_info, $item);
        } else {
            $this->remove($item_code);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function remove(string $item_code): bool
    {
        unset($this->items[$item_code]);
        return true;
    }

    /**
     * {@inheritdoc}
     * @throws IDBException
     */
    public function getItem(string $item_code): array
    {
        if (!$this->hasItemWithCode($item_code)) {
            $this->add($item_code);
        }
        return $this->items[$item_code];
    }

    /**
     * {@inheritdoc}
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * {@inheritdoc}
     */
    public function clearItems()
    {
        $this->items = [];
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function hasItemWithCode(string $item_code): bool
    {
        return isset($this->items[$item_code]);
    }

    /**
     * {@inheritdoc}
     */
    public function store()
    {
        $setCookie = new SetCookie(
            $this->getCartName(),
            json_encode($this->getItems()),
            time() + $this->storage_expiration_time,
            '/',
            null,
            null,
            true
        );
        $this->storage->set($setCookie);
        return $this;
    }

    /**
     * {@inheritdoc}
     * @throws IDBException
     */
    public function restore()
    {
        $data = $this->storage->get($this->getCartName());

        // if there is a cookie value
        if (is_null($data)) return $this;

        // there is something there but we don't know what it is
        $data = json_decode($data, true);

        // it should array or we have nothing to do with it
        if (!is_array($data)) return $this;

        // add it to cart class
        foreach ($data as $code => $info) {
            $this->add($code, is_array($info) ? $info : []);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function destroy()
    {
        $this->storage->remove($this->getCartName());
        $this->clearItems();
        return $this;
    }

    /**
     * @param array $item
     * @param array $item_info
     * @return array
     */
    protected function normalizeQuantity(array $item, ?array $item_info): array
    {
        // normalize product quantity
        if (!isset($item_info['qnt'])) {
            $item_info['qnt'] = 1;
        } else {
            $item_info['qnt'] = (int)$item_info['qnt'];

            if (
                $item_info['qnt'] > $item['stock_count'] ||
                $item_info['qnt'] > $item['max_cart_count']
            ) {
                if ($item_info['qnt'] > $item['stock_count']) {
                    $item_info['qnt'] = (int)$item['stock_count'];
                } else {
                    $item_info['qnt'] = (int)$item['max_cart_count'];
                }
            }
        }

        return $item_info;
    }

    /**
     * @param $timestamp
     * @return bool
     */
    protected function isValidTimestamp($timestamp): bool
    {
        return ($timestamp <= PHP_INT_MAX)
            && ($timestamp >= ~PHP_INT_MAX);
    }
}