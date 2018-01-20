<?php

namespace Kbergha\Utils;

//use GuzzleHttp\Client;
use Larislackers\BinanceApi\Enums\ConnectionDetails;
//use Larislackers\BinanceApi\Exception\BinanceApiException;
use Larislackers\BinanceApi\Exception\LarislackersException;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\MessageInterface;
use Carbon\Carbon;
use DateTimeZone;

class Webservice {

    protected $movingAveragePrice10 = 0;
    protected $movingAveragePrice = 0;
    protected $movingAverageData10 = [];
    protected $movingAverageData = [];
    protected $movingAverageMaxLength = 400;
    protected $tradeBuyPrice = null;
    protected $tradeInProgress = false;
    protected $tradeEnabled = false;
    protected $startCapital = 0.2; // BTC
    protected $capitalDiff = 0.0; // BTC
    protected $capital = 0.0; // BTC
    protected $trades = [];
    protected $totalProfit = 0.0; // USD
    protected $notSoldDueToProfit = 0;
    protected $notSoldDueToProfitLimit = 8;
    // protected $fee = 0.001;  // 0.1% BTC
    protected $fee = 0.0005; // 0.05% BNB
    protected $btcDecimals = 4;
    protected $btcDecimalsLong = 8;
    protected $usdDecimals = 2;
    protected $iterations = 0;
    protected $minimumProfit = 1.75; // USD
    protected $totalFees = 0.0; // USD
    protected $opportunitySellFactor = 6; // Always sell if profit >= (factor x min profit)
    protected $opportunitySales = 0;
    protected $startTime = null;
    protected $showAllIncomingTrades = true;

    // @todo: can't assume you can buy and sell for previous price. Get order book. Assuming +small % for now?
    // @todo: can't assume you can buy with whole capital for previous price
    // @todo: If capital is over starting, withdraw/reduce working capital ?
    // @todo: never user more than starting capital for buys?
    // @todo: DRY
    // @todo: Structure a lot better.
    // @todo: more data in trade array (profit, capital gain, type, timestamp)
    // @todo: props from .env
    // @todo: Both BTC and USD accounts/props that fees are taken and calculated from when buying/selling.
    // @todo: remove capital diff prop, calculate directly from (current - start)


    public function formatUsd($number) {
        return number_format($number, $this->usdDecimals, '.', '');
    }

    public function formatBtc($number) {
        return number_format($number, $this->btcDecimals, '.', '');
    }

    public function formatBtcLong($number) {
        return number_format($number, ($this->btcDecimalsLong), '.', '');
    }

    public function run($params)
    {
        $once = false;
        $url = ConnectionDetails::WEBSOCKET_URL . strtolower($params['symbol']) . '@aggTrade';

        $this->startTime = Carbon::now();
        $this->startTime->setTimezone(new DateTimeZone('Europe/Oslo'));

        // $this->showAllIncomingTrades = false;


        \Ratchet\Client\connect($url)->then(function (WebSocket $conn) use ($once) {

            $conn->on('message', function (MessageInterface $msg) use ($conn, $once) {

                $this->iterations++;

                $trade = json_decode($msg, true);
                $time = $trade['T'];
                $time = floor($time / 1000);
                $price = $trade['p'];

                $date = Carbon::createFromTimestampUTC($time);
                $date->setTimezone(new DateTimeZone('Europe/Oslo'));

                $time = $date->format("H:i:s");

                if ($this->showAllIncomingTrades === true) {
                    echo $time.": ".$this->formatBtc($price).". ".$this->getMovingAverage($price).", ".$this->getMovingAverage10($price)."\n";
                } else {
                    $this->getMovingAverage($price);
                    $this->getMovingAverage10($price);
                }

                if ($this->tradeEnabled == false && count($this->movingAverageData) >= $this->movingAverageMaxLength) {
                    $this->tradeEnabled = true;
                    $this->capital = $this->startCapital;
                    echo "\n\n\n".$time.": Enough data gathered. Trading enabled!\n\n\n\n";
                } else if ($this->tradeEnabled == false && count($this->movingAverageData) < $this->movingAverageMaxLength) {
                    echo $time.": Gathering data for moving average. ".count($this->movingAverageData)."/".$this->movingAverageMaxLength."\n";
                }

                if($this->tradeEnabled === true && $this->capital <= 0) {
                    echo "Game over man!\n";
                    $conn->close();
                }

                // Print status every 50 trades.
                if ($this->iterations > $this->movingAverageMaxLength && ($this->iterations % 50) == 1) {

                    $inProgress = "buy pending.";
                    if ($this->tradeInProgress == true) {
                        $inProgress = "sale pending (last buy @ ".$this->formatBtc($this->tradeBuyPrice).").";
                    }

                    echo $time.": ==========================\n";
                    echo $time.": Status: ".$inProgress."\n";
                    echo $time.": Trades: ".count($this->trades).", profit: ".$this->formatUsd($this->totalProfit).", fees: ".$this->formatUsd($this->totalFees)."\n";
                    echo $time.": Opportunity sales: ".$this->opportunitySales."\n";
                    echo $time.": Capital: ".$this->formatBtcLong($this->capital).", capital diff: ".$this->formatBtcLong($this->capitalDiff)."\n";
                    echo $time.": Running time ".$date->diffForHumans($this->startTime, true)."\n";
                    echo $time.": ==========================\n";
                }

                // Fee in USD for selling right now.
                $fee = ($price * $this->fee) * $this->capital;

                // Do not wait for moving averages if profit is >= than (factor * min profit).
                if ($this->tradeEnabled === true && $this->tradeInProgress === true) {

                    $profit = (($price - $this->tradeBuyPrice) * $this->capital) - $fee;
                    $opportunitySellProfit = ($this->opportunitySellFactor * $this->minimumProfit);

                    if ($profit >= $opportunitySellProfit) {
                        // Capital diff
                        $sellCapitalDiff = ($profit / $price);
                        $this->totalProfit += $profit;

                        echo $time.": Opportunity sell @ ".$this->formatBtc($price).", profit: ".$this->formatUsd($profit).", capital diff: ".$this->formatBtcLong($sellCapitalDiff)."\n";

                        $this->capitalDiff += $sellCapitalDiff;
                        $this->capital += $sellCapitalDiff;
                        $this->trades[] = ['sell', $price];
                        $this->totalFees += $fee;
                        $this->tradeBuyPrice = null;
                        $this->tradeInProgress = false;
                        $this->notSoldDueToProfit = 0;
                        $this->opportunitySales++;

                    }
                }

                // Sell
                if ($this->tradeEnabled === true
                    && $price > $this->movingAveragePrice
                    && $price > $this->movingAveragePrice10
                    && $this->tradeInProgress === true
                )
                {

                    $profit = (($price - $this->tradeBuyPrice) * $this->capital) - $fee;

                    // Capital diff
                    $sellCapitalDiff = ($profit / $price);

                    if ($profit >= $this->minimumProfit) {

                        $this->totalProfit += $profit;

                        echo $time.": Sell @ ".$this->formatBtc($price).", profit: ".$this->formatUsd($profit).", capital diff: ".$this->formatBtcLong($sellCapitalDiff)."\n";

                        $this->capitalDiff += $sellCapitalDiff;
                        $this->capital += $sellCapitalDiff;
                        $this->trades[] = ['sell', $price];
                        $this->totalFees += $fee;
                        $this->tradeBuyPrice = null;
                        $this->tradeInProgress = false;
                        $this->notSoldDueToProfit = 0;

                    } else if ($this->notSoldDueToProfit >= $this->notSoldDueToProfitLimit) {

                        $this->totalProfit += $profit;
                        echo $time.": Force sell @ ".$this->formatBtc($price).", profit: ".$this->formatUsd($profit).", capital diff: ".$this->formatBtcLong($sellCapitalDiff)."\n";

                        $this->capitalDiff += $sellCapitalDiff;
                        $this->capital += $sellCapitalDiff;
                        $this->trades[] = ['sell', $price];
                        $this->totalFees += $fee;
                        $this->tradeBuyPrice = null;
                        $this->tradeInProgress = false;
                        $this->notSoldDueToProfit = 0;
                    } else {
                        $this->notSoldDueToProfit++;
                        echo $time.": Sell profit ".$this->formatUsd($profit)." less than min. ".$this->minimumProfit.". Force sell in ".($this->notSoldDueToProfitLimit - $this->notSoldDueToProfit)."\n";
                    }
                }

                // Buy
                if ($this->tradeEnabled === true
                    && $price < $this->movingAveragePrice
                    && $price < $this->movingAveragePrice10
                    && $this->tradeInProgress === false
                )
                {
                    if($this->tradeBuyPrice === null) {

                        // $price += $fee;
                        // @todo: probably very wrong
                        // Remove buy fee
                        $this->capital = $this->capital * (1 - $this->fee);

                        // @todo: Check order book
                        // Temp: Add 1/2 fee directly as percent
                        $price = $price * (1 + ($this->fee / 2));

                        echo $time.": Buy @ ".$this->formatBtc($price).", including fee: ".$this->formatUsd($fee)."!\n";

                        $this->trades[] = ['buy', $price];
                        //$this->totalFees += $fee; // ??
                        $this->tradeBuyPrice = $price;
                        $this->tradeInProgress = true;
                    }
                }

                if ($once) $conn->close();
            });

            $conn->on('close', function ($code = null, $reason = null) {
                echo 'Connection closed (' . $code . ' - ' . $reason . ')';
            });

            $conn->on('error', function () {
                throw new LarislackersException('[ERROR|Websocket] Could not establish a connection.');
            });
        });
    }


    public function getMovingAverage($price)
    {
        $this->movingAverageData[] = $price;
        if(count($this->movingAverageData) > $this->movingAverageMaxLength) {
            array_shift($this->movingAverageData);
        }
        $total = array_sum($this->movingAverageData);
        $this->movingAveragePrice = round($total / count($this->movingAverageData), 8);

        return "MA".$this->movingAverageMaxLength.": ".$this->formatBtc($this->movingAveragePrice);
    }

    public function getMovingAverage10($price)
    {
        $this->movingAverageData10[] = $price;
        if(count($this->movingAverageData10) > floor($this->movingAverageMaxLength / 10)) {
            array_shift($this->movingAverageData10);
        }
        $total = array_sum($this->movingAverageData10);
        $this->movingAveragePrice10 = round($total / count($this->movingAverageData10), 8);

        return "MA".floor($this->movingAverageMaxLength / 10).": ".$this->formatBtc($this->movingAveragePrice10);
    }
}