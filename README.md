# Simplicity Cart

A simple library for shopping cart management.

## Install
**composer**
```php 
composer require mmdm/sim-cart
```

Or you can simply download zip file from github and extract it, 
then put file to your project library and use it like other libraries.

Just add line below to autoload files:

```php
require_once 'path_to_library/autoloader.php';
```

and you are good to go.

## Features

- Multiple cart management

- Simple cart management

- This is not just a simple cart, it has some structures to create 
a simple yet nice online shops. 

## Architecture

This library use database to have its best performance

**Collation:**

It should be `utf8mb4_unicode_ci` because it is a very nice collation. 
For more information about differences between `utf8` and `utf8mb4` 
in `general` and `unicode` please see 
[this link][utf8_or_utf8mb4] from `stackoverflow`

**Table:**

- users

    This table contains all users.
    
    Least columns of this table should be:
        
    - id (INT(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT)

- products

    This table contains all products.
    
    Least columns of this table should be:
        
    - id (INT(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT)

- product_property

    This table contains all product's properties.
    
    Least columns of this table should be:
        
    - id (INT(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT)

    - code (VARCHAR(20) NOT NULL)
    
    - product_id (INT(11) UNSIGNED NOT NULL)
    
    - stock_count (INT(11) UNSIGNED NOT NULL)
    
    - max_cart_count (INT(11) UNSIGNED NOT NULL)
    
    - price (BIGINT(20) UNSIGNED NOT NULL)
    
    - discounted_price (BIGINT(20) UNSIGNED NOT NULL)
    
    - tax_rate (DECIMAL (5, 2) UNSIGNED NOT NULL DEFAULT 0)
    
    - is_available (TINYINT(1) UNSIGNED NOT NULL DEFAULT 1)
    
    **Constraints:**
    
    - ADD CONSTRAINT UC_Code UNIQUE (code)
    
    - ADD CONSTRAINT fk_pp_p FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE ON UPDATE CASCADE
    
- carts

    This table contains all carts.
    
    Least columns of this table should be:
        
    - id (INT(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT)
    
    - user_id (INT(11) UNSIGNED NOT NULL)
    
    - name (VARCHAR(255) NOT NULL)
    
    - created_at (INT(11) UNSIGNED NOT NULL)
    
    - expire_at (INT(11) UNSIGNED NOT NULL)
    
    **Constraints:**
    
    - ADD CONSTRAINT UC_UserID UNIQUE (user_id,name)

- cart_item

    This table contains all cart's items.
    
    Least columns of this table should be:
    
    - id (INT(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT)
    
    - cart_id (INT(11) UNSIGNED NOT NULL)
    
    - product_property_id (INT(11) UNSIGNED NOT NULL)
    
    - qnt (INT(11) UNSIGNED NOT NULL)
    
    **Constraints:**
    
    - ADD CONSTRAINT fk_ci_c FOREIGN KEY(cart_id) REFERENCES carts(id) ON DELETE CASCADE ON UPDATE CASCADE
    
    - ADD CONSTRAINT fk_ci_pp FOREIGN KEY(product_property_id) REFERENCES product_property(id) ON DELETE CASCADE ON UPDATE CASCADE
    
## How to use

First of all you need a `PDO` connection like below:

```php
$host = '127.0.0.1';
$db = 'database name';
$user = 'username';
$pass = 'password';
// this is very nice collation to use
$charset = 'utf8mb4';

$dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
$options = [
    // add this option to show exception on any bad condition
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
];
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}
```

Second you need a `cookie` class like below:

```php
$cookieStorage = new \Sim\Cookie\Cookie();
```

[Optionally But Highly Recommended] You need two keys to protect 
stored data in cookie. These two keys should be two base64 coded 
strings. Just generate two passwords and encode them to base64 
strings. For more info about these two keys see 
[this link][crypt_library]

```php
// this is what you have for protecting your data
$cookieStorage = new \Sim\Cookie\Cookie(
    new \Sim\Crypt\Crypt(
        $mainCryptKey,
        $assuredCryptKey
    )
);
```

### Instantiate cart library

```php
$cookieStorage = new \Sim\Cookie\Cookie(new \Sim\Crypt\Crypt($mainCryptKey, $assuredCryptKey));

// we don't need a user, so we don't pass it
$cart = new \Sim\Cart\Cart($pdo, $cookieStorage);

// use cart methods
$cart->add('product_code');
```

## Cart methods

#### `setCartName(string $name)`

You can name your cart. This will use when you want store a cart 
in your database. Default name is `default`.

#### `getCartName(): string`

Get cart's name.

#### `setExpiration(int $expire_time)`

**Note:** You need to send duration of cart to be expired like 
`86400` that is `1 day` in seconds. After call `store` method, 
current time will add to this expire time.

**Note:** you must know this expire time is **Different** from 
expire time in database fields.

Set cart expiration time.

#### `getExpiration(): int`

Get cart expiration time. Default expiration time is `31536000` 
that is `1 year`.

**Note:** This method just returns the time like `31536000` that 
is `1 year` not how much it has to be expired but if you need to 
know expiration time until, store cart to database and get 
`expire_at` field of carts.

#### `utils(): ICartsUtil`

Utilities that can do with current cart and current user.

To see information about this utils, see 
[CartUtil](#cartutil-methods) section.

**Note:** This only works if you pass a pdo connection and a 
user id to work with.

#### `add(string $item_code, array $item_info = null)`

Add an item to cart with product's code from `product_property` 
table. You can add extra information to item with `$item_info` 
parameter. At last the stored item has all 
`product_property` table's columns fields value and `qnt` parameter 
and your extra information.

**Note:** All item's keys are from keys of config file and as 
commented in config file too, **DO NOT CHANGE** keys please.

**Note:** By default items will not store in cookie and you should 
do it yourself using `store` method.

#### `update(string $item_code, array $item_info = null)`

It is like `add` method with extra functionality that is checking 
if item is added or it'll add it internally.

#### `remove(string $item_code): bool`

Remove an item from cart.

#### `getItem(string $item_code): array`

Get a specific item from cart.

#### `getItems(): array`

Get all cart's items.

#### `clearItems()`

Delete all items from cart.

#### `hasItemWithCode(string $item_code): bool`

Check if an item has been set in cart or not.

#### `totalPrice(): float`

Get total price of items.

#### `totalDiscountedPrice(): float`

Get total discounted price of items.

#### `totalPriceWithTax(): float`

Get total price of items with considering tax of each item.

#### `totalDiscountedPriceWithTax(): float`

Get total discounted price of items with considering tax of each item.

#### `totalAttributeValue(string $key): float`

Get total of a specific key of all items.

#### `discountedPercentage(string $item_code, int $decimal_numbers = 2, bool $round = false): float`

Get discount percentage of an specific item.

#### `totalDiscountedPercentage(int $decimal_numbers = 2, bool $round = false): float`

Get discount percentage of all items.

#### `totalDiscountedPercentageWithTax(int $decimal_numbers = 2, bool $round = false): float`

Get discount percentage of all items with considering items' tax.

#### `store()`

Store cart items to storage(cookie).

#### `restore()`

Restore cart items from storage(cookie) to cart items.

#### `destroy()`

Destroy cart from storage(cookie).

## CartUtil methods

### Instantiate

If you need to instantiate utils externally(YOU DON'T NEED)

```php
$utils = new CartsUtil(
    PDO $pdo_instance,
    ICart &$cart,
    ?int $user_id,
    ?array $config = null
);
```

#### `runConfig()`

Create tables structure.

#### `getCart(): ICart`

Get used cart in class.

#### `setUserId(int $user_id)`

Set user id to work with for database.

#### `getUserId(): int`

Get used user id.

#### `save(int $max_stored_cart = PHP_INT_MAX, array $extra_parameters = [], string $extra_where = null, array $bind_values = []): bool`

Save cart to database.

You can specify how many cart can store in database for a specific 
user. Also you can pass extra parameters and parameterized where 
clause for customize storing (table is `carts` here).

**Note:** You should use actual cart table columns name.

**Note:** Columns below will unset and use cart information instead:

- user_id

- name

- created_at

```php
// simple usage (unnecessary)
$cartUtil->save(
    2, // max stored cart count
    [
        'extra_column' => extra value,
        ...
    ],
    "some_extra_where=:extra_where_param1",
    [
        'extra_where_param1' => actual value
    ]
);

// if you want use from cart
$cart->utils()->save(
    2, // max stored cart count
    [
        'extra_column' => extra value,
        ...
    ],
    "some_extra_where=:extra_where_param1",
    [
        'extra_where_param1' => actual value
    ]
)
```

To get exception of max stored cart count, and database errors, put 
your codes in `try {...} catch() {...}` block like:

```php
try {
    // try to save or other thing
    // ...
    
    $cart->utils()->save(1);
} catch (\Sim\Cart\Exceptions\CartMaxCountException $e) {
    // if cart count is at maximum count of it
    // ...
} catch (\Sim\Cart\Interfaces\IDBException $e) {
    // database error
    // ...
} catch (\Sim\Crypt\Exceptions\CryptException $e) {
    // encryption or decryption has error
    // ...
}
```

#### `fetch(bool $append_to_previous_items = false)`

Fetch cart items from database and store them to provided cart class.

**Note:** It'll delete all items first, if you want to prevent this 
default behavior, send `true` as second parameter.

#### `delete(string $cart_name): bool`

Delete specific cart for specific user.

#### `deleteExpiredCarts(string $cart_name): bool`

Delete expired cart for specific user.

#### `changeName(string $old_cart_name, string $new_cart_name): bool`

Change cart name from `old name` to `new name` for a specific user.

#### `getItem(string $item_code, $columns = '*'): array`

Get specific item with specific columns.

#### `getStockCount(string $item_code): int`

Get stock count for a specific item.

#### `getMaxCartCount(string $item_code): int`

Get max cart count for a specific item.

## Examples

#### Create all tables

```php
// after instantiate cart class or even cart util class,
// you can use util's methods. For convenient in examples,
// we will use cart class utils
$cart->utils()->runConfig();
```

#### Add item to cart

```php
$cart->add('item_id_from_product_property');

// with extra information
$cart->add('item_id_from_product_property', [
    'type' => 'service',
    ...
]);
```

#### Update item in cart

**Very Important Note:** You can't change an item value that has 
value in database. Just quantity and your extra information.

```php
// keys are from product_property in 
// config NOT actual column of table
$cart->update('item_id_from_product_property', [
    'qnt' => 4
]);
```

#### Cart and storage

```php
// store cart items in cookie
$cart->store();

// restore cart items
$cart->restore();

// delete and destroy cookie for cart
$cart->destroy();
```

#### Report

```php
echo 'total price: ' . $cart->totalPrice() . PHP_EOL;
echo 'total price with tax: ' . $cart->totalPriceWithTax() . PHP_EOL;
echo 'total discount price: ' . $cart->totalDiscountedPrice() . PHP_EOL;
echo 'total discount price with tax: ' . $cart->totalDiscountedPriceWithTax() . PHP_EOL;
echo 'discount percentage specific item: ' . $cart->discountedPercentage('item_id_from_product_property') . PHP_EOL;
echo 'total discount percentage: ' . $cart->totalDiscountedPercentage() . PHP_EOL;
echo 'total discount percentage with tax: ' . $cart->totalDiscountedPercentageWithTax() . PHP_EOL;
echo 'total rounded discount percentage: ' . $cart->totalDiscountedPercentage(2, true) . PHP_EOL;
echo 'total rounded discount percentage with tax: ' . $cart->totalDiscountedPercentageWithTax(2, true) . PHP_EOL;
```

# Dependencies

There is some dependencies here including:

[Crypt][crypt_library] library. With this feature, if any session/cookie 
hijacking happens, they can't see actual data because it 
is encrypted.

[Cookie][cookie_library] library to manipulate cookies.

# License

Under MIT license

[utf8_or_utf8mb4]: https://stackoverflow.com/questions/766809/whats-the-difference-between-utf8-general-ci-and-utf8-unicode-ci
[crypt_library]: https://github.com/mmdm95/sim-crypt
[cookie_library]: https://github.com/mmdm95/sim-cookie
[auth_library]: https://github.com/mmdm95/sim-auth