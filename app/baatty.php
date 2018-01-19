<?php

require __DIR__.'/../vendor/autoload.php';

use Larislackers\BinanceApi\BinanceApiContainer;
use Dotenv\Dotenv;

$dotenv = new Dotenv(__DIR__ . '/..');
$dotenv->load();

$bac = new BinanceApiContainer(getenv('api_key'), getenv('api_secret'));

//$test = $bac->getTickers();
//$test = $bac->getOrderBook(['symbol' => 'BTCUSDT', 'limit' => 5]);
//$test = $bac->getAggTrades(['symbol' => 'BTCUSDT', 'limit' => 20]);

$bac->tradesWebsocket(['symbol' => 'BTCUSDT']);

//$test = $test->getBody()->getContents();
//$test = json_decode($test, true);

//var_dump($test);
//var_dump($test->getBody()->getMetadata());