<?php

include_once __DIR__ . '/../../vendor/autoload.php';
//include_once __DIR__ . '/../../autoloader.php';

$host = '127.0.0.1';
$db = 'test';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
];
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}

try {
    $mainCryptKey = 'ZjZvO0toUytAMTpbcXo4Q2Ezc0E5JDUtVVJkdGNqMlc0bTA3aS4=';
    $assuredCryptKey = 'dFxFMyw0OklBdjlrITI/LTgrZ3JuZTZfWjUvJnBSVy4wTyQpMTdxc04lfkhdUUB4';
    //-----
    $cookieStorage = new \Sim\Cookie\Cookie(new \Sim\Crypt\Crypt($mainCryptKey, $assuredCryptKey));
    //-----
    $item1 = '1234';
    $cart = new \Sim\Cart\Cart($pdo, $cookieStorage);

//    $cart->utils()->runConfig();

//    $cart->add($item1);
//    var_dump($cart->getItems());

//    $cart->update($item1, [
//        'qnt' => 4
//    ]);
//    var_dump($cart->getItems());

    // test store cart items in cookie storage
//    $cart->store();

    // test restore cart items from cookie storage
//    $cart->restore();
//    var_dump($cart->getItems());

    // test destroy items for cookie storage
//    $cart->destroy();
//    var_dump($cart->getItems());

//    $cart->utils()->save($cart, 1);
//    $cart->setCartName('second');
//    $cart->utils()->save($cart, 1);
//    $cart->setCartName('third');
//    $cart->utils()->save($cart, 1, 1);
//    $cart->setCartName('forth');
//    $cart->utils()->save($cart, 1, 1);

//    $cart->utils()->fetch('default', 1, $cart);
//    var_dump($cart->getItems());
} catch (\Sim\Cart\Exceptions\CartMaxCountException $e) {
    echo $e->getMessage();
} catch (\Sim\Cart\Interfaces\IDBException $e) {
    echo $e->getMessage();
} catch (\Sim\Crypt\Exceptions\CryptException $e) {
    echo $e->getMessage();
}
