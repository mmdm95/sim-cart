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
     * @var ICartsUtil|null
     */
    protected $cart_util = null;

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
     * @param PDO|null $pdo_instance
     * @param ICookie $cookie_storage
     * @param int $user_id
     * @param array|null $config
     * @throws IDBException
     */
    public function __construct(
        ?PDO $pdo_instance,
        ICookie $cookie_storage,
        int $user_id,
        ?array $config = null
    )
    {
        $this->storage = $cookie_storage;

        // if pdo connection is not null
        if (!is_null($pdo_instance)) {
            $this->cart_util = new CartsUtil($pdo_instance, $this, $user_id, $config);
        }
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

    /**
     * {@inheritdoc}
     */
    public function utils(): ?ICartsUtil
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
    public function totalPrice(): float
    {
        return $this->getTotal('price');
    }

    /**
     * {@inheritdoc}
     */
    public function totalDiscountedPrice(): float
    {
        return $this->getTotal('discounted_price');
    }

    /**
     * {@inheritdoc}
     */
    public function totalAttributeValue(string $key): float
    {
        if ('' === $key) return 0.0;
        return $this->getTotal($key);
    }

    /**
     * {@inheritdoc}
     * @throws IDBException
     */
    public function discountedPercentage(string $item_code): float
    {
        $item = $this->getItem($item_code);
        $price = (float)($item['price'] ?? 0.0);
        $discountedPrice = (float)($item['discounted_price'] ?? 0.0);

        // it should be == instead of ===
        if (0 == $price) return 0;

        $priceDiff = abs($price - $discountedPrice);
        return ($priceDiff / $price) * 100;
    }

    /**
     * {@inheritdoc}
     * @throws IDBException
     */
    public function totalDiscountedPercentage(int $decimal_numbers = 2, bool $round = false): float
    {
        $items = $this->getItems();
        $itemsLen = count($items);

        // is there is no item, we have 0 percentage discount
        if (0 === $itemsLen) return 0.0;

        $totalPercentage = 0.0;
        foreach ($items as $code => $item) {
            $totalPercentage += $this->discountedPercentage($code);
        }

        $totalPercentageAvg = number_format($totalPercentage / $itemsLen, $decimal_numbers);

        // if it needed to round
        if ($round) {
            $totalPercentageAvg = round($totalPercentageAvg);
        }

        return $totalPercentageAvg;
    }

    /**
     * {@inheritdoc}
     */
    public function store()
    {
        $setCookie = new SetCookie(
            $this->getHashedName(),
            json_encode($this->getItems()),
            time() + $this->getExpiration(),
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
        $data = $this->storage->get($this->getHashedName());

        // if there is a cookie value
        if (is_null($data)) return $this;

        // there is something there but we don't know what it is
        $data = json_decode($data, true);

        // it should be array or we have nothing to do with it
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
     * @return string
     */
    protected function getHashedName(): string
    {
        return md5($this->getCartName() . '-' . $this->utils()->getUserId());
    }

    /**
     * @param $key
     * @return float
     */
    protected function getTotal($key): float
    {
        $total = 0.0;
        foreach ($this->getItems() as $item) {
            $total += (float)($item[$key] ?? 0.0);
        }

        return $total;
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