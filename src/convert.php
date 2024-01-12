#!/usr/bin/env php
<?php
require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SingleCommandApplication;

(new SingleCommandApplication())
    ->setName('My Super Command') // Optional
    ->setVersion('1.0.0') // Optional
    ->addArgument('trades', InputArgument::REQUIRED, 'The dYdX trades export file!')
    ->addArgument('funding', InputArgument::REQUIRED, 'The dYdX funding export file!')
    ->addArgument('transfers', InputArgument::REQUIRED, 'The dYdX transfers export file!')
    ->setCode(function (InputInterface $input, OutputInterface $output): int {


        $trades = $input->getArgument('trades');
        $trades = read($trades);

        $funding = $input->getArgument('funding');
        $funding = read($funding);

        $transfers = $input->getArgument('transfers');
        $transfers = read($transfers);

        $positions = generatePositions($trades);
        $funding = generateFunding($funding);
        $transfers = generateTransfers($transfers);

        write('./output/positions.csv', $positions);
        write('./output/funding.csv', $funding);
        write('./output/transfers.csv', $transfers);

        return 0;
    })
    ->run();

function write($file, $positions)
{
    $fp = @fopen($file, "w");
    $headings = array(
        'Koinly Date',
        'Amount',
        'Currency',
        'Label',
        'TxHash'
    );
    fputcsv($fp, $headings);
    foreach ($positions as $position) {
        fputcsv($fp, $position);
    }
    fclose($fp);
}

function read($file): array
{
    $fp = @fopen($file, "r");
    $lines = array();
    $header = array();
    if ($fp) {
        $row = 0;
        while (($buffer = fgets($fp)) !== false) {
            $buffer = trim($buffer, "\r\n");
            if ($row == 0) {
                $header = str_getcsv($buffer);
            } else {
                $columns = str_getcsv($buffer);
                $data = array();
                foreach ($columns as $index => $column) {
                    $data[$header[$index]] = $column;
                    $data['csv_row'] = $row - 1;
                }
                $lines[] = $data;
            }
            $row++;
        }
        if (!feof($fp)) {
            echo "Error: unexpected fgets() fail\n";
        }
        fclose($fp);
    }
    return $lines;
}

function generateFunding($funding): array
{
    $payments = array();
    $funding = array_reverse($funding);
    foreach ($funding as $paymentIndex => $payment) {
        $date = date_create($payment['effectiveAt']);
        $date = $date->format('Y-m-t');

        if (!isset($payments[$date])) {
            $payments[$date]['date'] = $date;
            $payments[$date]['amount'] = floatval($payment['payment']);
            $payments[$date]['currency'] = 'USDC';
        } else {
            $payments[$date]['amount'] += floatval($payment['payment']);
        }
        $payments[$date]['label'] = $payments[$date]['amount'] > 0 ? 'realized gain' : 'cost';
    }

    return $payments;
}

function generatePositions($trades): array
{
    $realizedGains = array();
    $positions = array();
    $marginFee = array();
    $trades = array_reverse($trades);
    foreach ($trades as $tradeIndex => $trade) {
        $trade['size'] = floatval($trade['size']);
        $trade['price'] = floatval($trade['price']);
        $trade['fee'] = floatval($trade['fee']);

        $market = $trade['market'];
        $side = $trade['side'];
        $scaleTrade = false;
        if (!isset($positions[$market])) {
            $positions[$market] = array(
                'direction' => $trade['side'] == "BUY" ? "long" : "short",
                'fee' => 0,
                'size' => 0,
                'avg_entry' => floatval($trade['price']),
                'entry' => $trade,
                'exit' => null,
                'scale' => null,
            );
        } else {
            $scaleTrade = true;
        }
        if ($side == 'BUY') {

            if ($positions[$market]['direction'] == "short" && ($positions[$market]['size'] + $trade['size']) > 0) {
                // a buy on the short side kann flip trade direction
                $positions[$market]['size'] += $trade['size'];
            } else {
                $positions[$market]['size'] += $trade['size'];
            }
        } else {
            if ($positions[$market]['direction'] == "long" && ($positions[$market]['size'] - $trade['size']) < 0) {
                // a sell on the long side kann flip trade direction
                $positions[$market]['size'] -= $trade['size'];
            } else {
                $positions[$market]['size'] -= $trade['size'];
            }
        }

        $positions[$market]['fee'] += floatval($trade['fee']);
        $positions[$market]['size'] = round($positions[$market]['size'], 4);

        if ($positions[$market]['size'] == 0) {
            $positions[$market]['exit'] = $trade;
            $date = date_create($positions[$market]['exit']['createdAt']);
            $date = $date->format('Y-m-d_H-i-s');
            $realizedGains[$market][$date] = $positions[$market];
            $scaleTrade = false;
            unset($positions[$market]);
        }

        if ($scaleTrade) {
            $positions[$market]['scale'][] = $trade;
        }
    }
    foreach ($realizedGains as $market => $marketGains) {
        foreach ($marketGains as $tradeDate => $trade) {
            if ($trade['scale'] == null) {

                // just a simple trade, no scaling

                $totalBought = floatval($trade['entry']['price']) * floatval($trade['entry']['size']);
                $totalSold = floatval($trade['exit']['price']) * floatval($trade['exit']['size']);

                if ($trade['direction'] == "long") {
                    $profit = $totalBought - $totalSold;
                } else {
                    $profit = $totalSold - $totalBought;
                }

                $trade['profit'] = $profit;
            } else {
                $profit = 0;
                if ($trade['direction'] == "long") {
                    $totalBought = floatval($trade['entry']['price']) * floatval($trade['entry']['size']);
                    $totalSold = 0;
                    foreach ($trade['scale'] as $scaleTrade) {
                        if ($scaleTrade['side'] == "SELL") {
                            $totalSold += floatval($scaleTrade['price']) * floatval($scaleTrade['size']);
                        } else {
                            $totalBought += floatval($scaleTrade['price']) * floatval($scaleTrade['size']);
                        }
                    }
                    $totalSold += floatval($trade['exit']['price']) * floatval($trade['exit']['size']);
                    $profit = $totalBought - $totalSold;
                } else {
                    $totalSold = floatval($trade['entry']['price']) * floatval($trade['entry']['size']);
                    $totalBought = 0;
                    foreach ($trade['scale'] as $scaleTrade) {
                        if ($scaleTrade['side'] == "SELL") {
                            $totalSold += floatval($scaleTrade['price']) * floatval($scaleTrade['size']);
                        } else {
                            $totalBought += floatval($scaleTrade['price']) * floatval($scaleTrade['size']);
                        }
                    }
                    $totalBought += floatval($trade['exit']['price']) * floatval($trade['exit']['size']);
                    $profit = $totalSold - $totalBought;
                }

                $trade['profit'] = round($profit, 4);
            }
            // TODO 24.7
            // TODO 9.8
            // TODO 13.11
            // TODO alle Fees berücksichtigen
            $realizedGains[$market][$tradeDate] = $trade;
        }
    }

    $lines = array();
    foreach ($realizedGains as $market => $positions) {
        foreach ($positions as $tradeDate => $trade) {

            if (!isset($lines[$tradeDate])) {
                $lines[$tradeDate]['date'] = $tradeDate;
                $lines[$tradeDate]['amount'] = 0;
                $lines[$tradeDate]['currency'] = 'USDC';
                $lines[$tradeDate]['label'] = 'realized gain';
            }

            $lines[$tradeDate]['amount'] += floatval($trade['profit']);
        }
    }

    return $lines;
}

function generateTransfers($transfers): array
{
    $positions = array();
    $transfers = array_reverse($transfers);
    foreach ($transfers as $transfer) {
        $date = $transfer['createdAt'];
        $amount = $transfer['type'] == 'DEPOSIT' ? floatval($transfer['creditAmount']) : -1 * floatval($transfer['creditAmount']);
        $positions[$date] = array(
            'date' => $date,
            'amount' => $amount,
            'currency' => 'USDC',
            'label' => ''
        );
    }

    return $positions;
}
