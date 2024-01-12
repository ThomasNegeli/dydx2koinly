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
        $date->setTime(23, 59, 59);
        //$date = $date->format('Y-m-t');
        $date = $date->format('Y-m-d H:i:s');

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
    $trades = array_reverse($trades);
    foreach ($trades as $tradeIndex => $trade) {
        $trade['size'] = round(floatval($trade['size']), 4);
        $trade['price'] = round(floatval($trade['price']), 4);
        $trade['fee'] = round(floatval($trade['fee']), 4);

        $market = $trade['market'];
        if (!isset($positions[$market])) {
            $positions[$market]['direction'] = $trade['side'] == 'BUY' ? 1 : -1;
            $positions[$market]['size'] = $trade['size'] * $positions[$market]['direction'];
            $positions[$market]['amount'] = $positions[$market]['size'] * $trade['price'];
            $positions[$market]['average_entry_price'] = $trade['price'];
            $positions[$market]['fee'] = $trade['fee'] * -1;
            $positions[$market]['entry_date'] = $trade['createdAt'];
            $positions[$market]['profit'] = 0;
            $positions[$market]['market'] = $market;
        } else {
            $positions[$market]['size'] = round($positions[$market]['size'], 4);
            $positions[$market]['amount'] = round($positions[$market]['amount'], 4);
            $positions[$market]['fee'] -= $trade['fee'];
            if ($positions[$market]['direction'] == 1) {
                // handling long positions
                if ($trade['side'] == 'BUY') {
                    // buy in an existing long trade
                    $amountBought = $trade['size'] * $trade['price'];
                    $positions[$market]['amount'] += $amountBought;
                    $positions[$market]['size'] += $trade['size'];
                    $positions[$market]['average_entry_price'] = $positions[$market]['amount'] / $positions[$market]['size'];
                } else {
                    // sell in a long trade
                    if ($positions[$market]['size'] - $trade['size'] == 0) {
                        // closing a long position here
                        $amountBought = $trade['size'] * $trade['price'];
                        $positions[$market]['profit'] = $amountBought - $positions[$market]['amount'];
                        $positions[$market]['exit_date'] = $trade['createdAt'];
                        $realizedGains[$positions[$market]['exit_date']] = $positions[$market];
                        unset($positions[$market]);
                    } else {
                        if ($positions[$market]['size'] - $trade['size'] > 0) {
                            // partial profit taking
                            $amountAtAverageEntry = $trade['size'] * $positions[$market]['average_entry_price'];
                            $amountSold = $trade['size'] * $trade['price'];
                            $profit = $amountSold - $amountAtAverageEntry;
                            $positions[$market]['profit'] += $profit;
                            $positions[$market]['size'] -= $trade['size'];
                            $positions[$market]['amount'] -= $amountSold;
                        } else {
                            // flipping long into a short trade

                            // close the existing long position
                            $amountSoldToCloseLong = abs($positions[$market]['size']) * $trade['price'];
                            $positions[$market]['profit'] = $positions[$market]['amount'] - $amountSoldToCloseLong;
                            $positions[$market]['exit_date'] = $trade['createdAt'];
                            $realizedGains[$positions[$market]['exit_date']] = $positions[$market];

                            // open up a fresh short position
                            $trade['size'] = abs($positions[$market]['size'] - $trade['size']);  // calculate the right trade size, becauase we first closed the short
                            unset($positions[$market]);
                            $positions[$market]['direction'] = $trade['side'] == 'BUY' ? 1 : -1;
                            $positions[$market]['size'] = $trade['size'] * $positions[$market]['direction'];
                            $positions[$market]['amount'] = $positions[$market]['size'] * $trade['price'];
                            $positions[$market]['average_entry_price'] = $trade['price'];
                            $positions[$market]['fee'] = $trade['fee'] * -1;
                            $positions[$market]['entry_date'] = $trade['createdAt'];
                            $positions[$market]['profit'] = 0;
                            $positions[$market]['market'] = $market;
                        }
                    }
                }
            } else {
                // handling short positions
                if ($trade['side'] == 'BUY') {
                    if ($positions[$market]['size'] + $trade['size'] == 0) {
                        // closing a short position here
                        $amountBought = $trade['size'] * $trade['price'];
                        $positions[$market]['profit'] = $positions[$market]['amount'] + $amountBought;
                        $positions[$market]['exit_date'] = $trade['createdAt'];
                        $realizedGains[$positions[$market]['exit_date']] = $positions[$market];
                        unset($positions[$market]);
                    } else {
                        if ($positions[$market]['size'] + $trade['size'] < 0) {
                            // partial profit taking
                            $amountAtAverageEntry = $trade['size'] * $positions[$market]['average_entry_price'];
                            $amountBought = $trade['size'] * $trade['price'];
                            $profit = $amountBought - $amountAtAverageEntry;
                            $positions[$market]['profit'] += $profit;
                            $positions[$market]['size'] += $trade['size'];
                            $positions[$market]['amount'] += $amountBought;
                        } else {
                            // flipping short into a long trade

                            // close the existing short position
                            $amountBoughtToCloseShort = abs($positions[$market]['size']) * $trade['price'];
                            $positions[$market]['profit'] = $positions[$market]['amount'] + $amountBoughtToCloseShort;
                            $positions[$market]['exit_date'] = $trade['createdAt'];
                            $realizedGains[$positions[$market]['exit_date']] = $positions[$market];

                            // open up a fresh long position
                            $trade['size'] = abs($positions[$market]['size'] + $trade['size']);  // calculate the right trade size, becauase we first closed the short
                            unset($positions[$market]);
                            $positions[$market]['direction'] = $trade['side'] == 'BUY' ? 1 : -1;
                            $positions[$market]['size'] = $trade['size'] * $positions[$market]['direction'];
                            $positions[$market]['amount'] = $positions[$market]['size'] * $trade['price'];
                            $positions[$market]['average_entry_price'] = $trade['price'];
                            $positions[$market]['fee'] = $trade['fee'] * -1;
                            $positions[$market]['entry_date'] = $trade['createdAt'];
                            $positions[$market]['profit'] = 0;
                            $positions[$market]['market'] = $market;
                        }
                    }
                } else {
                    // sell in an existing short trade
                    $amountSold = $trade['size'] * $trade['price'];
                    $positions[$market]['amount'] -= $amountSold;
                    $positions[$market]['size'] -= $trade['size'];
                    $positions[$market]['average_entry_price'] = $positions[$market]['amount'] / $positions[$market]['size'];
                }
            }
        }
    }

    $profits = array();
    $fees = array();
    foreach ($realizedGains as $tradeDate => $trade) {

        $date = date_create($tradeDate);
        $date->setTime(23, 59, 59);
        //$date = $date->format('Y-m-t');
        $date = $date->format('Y-m-d H:i:s');

        if (!isset($profits[$date])) {
            $profits[$date]['date'] = $date;
            $profits[$date]['amount'] = floatval($trade['profit']);
            $profits[$date]['currency'] = 'USDC';
            $profits[$date]['label'] = 'realized gain';
        } else {
            $profits[$date]['amount'] += floatval($trade['profit']);
        }

        if (!isset($fees[$date])) {
            $fees[$date]['date'] = $date;
            $fees[$date]['amount'] = floatval($trade['fee']);
            $fees[$date]['currency'] = 'USDC';
            $fees[$date]['label'] = 'cost';
        } else {
            $fees[$date]['amount'] += floatval($trade['fee']);
        }
    }

    $gains = array();
    foreach ($profits as $profit) {
        $gains[] = $profit;
    }
    foreach ($fees as $fee) {
        $gains[] = $fee;
    }

    return $gains;
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
