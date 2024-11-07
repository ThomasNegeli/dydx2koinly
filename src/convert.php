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
        $trades = read($trades, $output);

        $funding = $input->getArgument('funding');
        $funding = read($funding, $output);

        $transfers = $input->getArgument('transfers');
        $transfers = read($transfers, $output);

        $trades = generateTrades($trades);
        $funding = generateFunding($funding);
        $transfers = generateTransfers($transfers);

        write('./output/trades.csv', $trades);
        write('./output/funding.csv', $funding);
        write('./output/transfers.csv', $transfers);

        $output->writeln('<info>Conversion completed</info>');

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
        'TxHash',
        'Description'
    );
    fputcsv($fp, $headings);
    foreach ($positions as $position) {
        fputcsv($fp, $position);
    }
    fclose($fp);
}

function read($file, $output): array
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
            $output->writeln('<error>Error: unexpected fgets() fail</error>');
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
        //$date = $date->format('Y-m-t H:i:s');
        $date = $date->format('Y-m-d H:i:s');

        $payment = -1 * floatval($payment['rate']) * floatval($payment['positionSize']) * floatval($payment['price']);
        if (!isset($payments[$date])) {
            $payments[$date]['date'] = $date;
            $payments[$date]['amount'] = $payment;
            $payments[$date]['currency'] = 'USDC';
        } else {
            $payments[$date]['amount'] += $payment;
        }
        $payments[$date]['label'] = 'margin fee';
        $payments[$date]['transactionHash'] = '';
        $payments[$date]['description'] = 'Daily funding payment';

        if ($payments[$date]['amount'] > 0) {
            $payments[$date]['label'] = 'fee refund';
        }
    }

    return $payments;
}

// https://docs.google.com/spreadsheets/d/1jdc52yJ1swpODLTfPaCpFYzsUQ692HwFjje0-IE5fGw/edit#gid=375453467

function generateTrades($trades): array
{
    $realizedGains = array();
    $positions = array();
    $trades = array_reverse($trades);
    $fees = array();
    foreach ($trades as $tradeIndex => $trade) {
        $newPosition = array();
        $trade['size'] = floatval($trade['size']);
        $trade['price'] = floatval($trade['price']);
        $trade['fee'] = floatval($trade['fee']);
        $date = date_create($trade['createdAt'])->format('Y-m-d H:i:s');
        $trade['createdAt'] = $date;
        $trade['direction'] = $trade['side'] == 'BUY' ? 1 : -1;
        unset($trade['type']);
        unset($trade['liquidity']);

        if (isset($trade['fee']) and $trade['fee'] != 0) {
            if (!isset($fees[$date])) {
                $fees[$date]['date'] = $date;
                $fees[$date]['amount'] = floatval($trade['fee']);
                $fees[$date]['currency'] = 'USDC';
                $fees[$date]['label'] = 'cost';
                $fees[$date]['transactionHash'] = '';
                $fees[$date]['description'] = $trade['market'] . ' ' . ($trade['direction'] == 1 ? 'Long' : 'Short') . ' - Trade Fee';
            } else {
                $fees[$date]['amount'] += floatval($trade['fee']);
                $fees[$date]['description'] .= '; ' . $trade['market'] . ' ' . ($trade['direction'] == 1 ? 'Long' : 'Short') . ' - Trade Fee';
            }
        }
        unset($trade['fee']);

        $market = $trade['market'];
        $amountTraded = $trade['size'] * $trade['price'] * $trade['direction'];
        if (!isset($positions[$market])) {
            $positions[$market] = $trade;
            $positions[$market]['size'] = $trade['size'] * $trade['direction'];
            $positions[$market]['amount'] = $amountTraded;
            $positions[$market]['entry_price_average'] = $positions[$market]['entry_price_initial'] = $trade['price'];
            $positions[$market]['entry_date'] = $trade['createdAt'];
            $positions[$market]['profit'] = 0.0;
            $positions[$market]['market'] = $market;
            $positions[$market]['trades'][] = $trade;
        } else {
            $positions[$market]['trades'][] = $trade;
            if ($positions[$market]['direction'] > 0) {
                // we trade a long position
                if ($trade['direction'] > 0) {
                    // buy trade in a long position to increase position size
                    $positions[$market]['amount'] += $amountTraded;
                    $positions[$market]['size'] += $trade['size'];
                    $positions[$market]['entry_price_average'] = $positions[$market]['amount'] / $positions[$market]['size'];
                } else {
                    // sell trade in a long position to reduce or close position
                    if ($positions[$market]['size'] - $trade['size'] < 0) {
                        // long trade reversal
                        $newPosition = $trade;
                        $newPosition['size'] = $positions[$market]['size'] - $trade['size'];
                        $newPosition['entry_price_average'] = $newPosition['entry_price_initial'] = $trade['price'];
                        $newPosition['amount'] = $newPosition['size'] * $newPosition['entry_price_average'];
                        $newPosition['entry_date'] = $trade['createdAt'];
                        $newPosition['profit'] = 0.0;
                        $newPosition['trades'][] = $trade;

                        $trade['size'] = abs($positions[$market]['size']);
                        $amountTraded = $trade['size'] * $trade['price'] * $trade['direction'];
                        $profit = -1 * ($positions[$market]['entry_price_average'] * $trade['size'] + $amountTraded);
                        $positions[$market]['profit'] += $profit;
                        $positions[$market]['amount'] += $amountTraded + $profit;
                        $positions[$market]['size'] -= $trade['size'];
                        $positions[$market]['exit_date'] = $trade['createdAt'];
                    } else {
                        $profit = -1 * ($positions[$market]['entry_price_average'] * $trade['size'] + $amountTraded);
                        $positions[$market]['profit'] += $profit;
                        $positions[$market]['amount'] += $amountTraded + $profit;
                        $positions[$market]['size'] -= $trade['size'];
                        $positions[$market]['exit_date'] = $trade['createdAt'];
                    }
                }
            } else {
                // we trade a short position
                if ($trade['direction'] > 0) {
                    // buy trade in short position to reduce or close position
                    if ($positions[$market]['size'] + $trade['size'] > 0) {
                        // short trade reversal
                        $newPosition = $trade;
                        $newPosition['size'] = $positions[$market]['size'] + $trade['size'];
                        $newPosition['entry_price_average'] = $newPosition['entry_price_initial'] = $trade['price'];
                        $newPosition['amount'] = $newPosition['size'] * $newPosition['entry_price_average'];
                        $newPosition['entry_date'] = $trade['createdAt'];
                        $newPosition['profit'] = 0.0;
                        $newPosition['trades'][] = $trade;

                        $trade['size'] = abs($positions[$market]['size']);
                        $amountTraded = $trade['size'] * $trade['price'] * $trade['direction'];
                        $profit = $positions[$market]['entry_price_average'] * $trade['size'] - $amountTraded;
                        $positions[$market]['profit'] += $profit;
                        $positions[$market]['amount'] += $amountTraded + $profit;
                        $positions[$market]['size'] += $trade['size'];
                        $positions[$market]['exit_date'] = $trade['createdAt'];
                    } else {
                        // reduce position size
                        $profit = $positions[$market]['entry_price_average'] * $trade['size'] - $amountTraded;
                        $positions[$market]['profit'] += $profit;
                        $positions[$market]['amount'] += $amountTraded + $profit;
                        $positions[$market]['size'] += $trade['size'];
                        $positions[$market]['exit_date'] = $trade['createdAt'];
                    }
                } else {
                    // sell trade in short position to increase position size
                    $positions[$market]['amount'] += $amountTraded;
                    $positions[$market]['size'] -= $trade['size'];
                    $positions[$market]['entry_price_average'] = $positions[$market]['amount'] / $positions[$market]['size'];
                }
            }
            $positions[$market]['amount'] = round($positions[$market]['amount'], 10); // in some cases the amount of a trade gets too small, so we cap it at 10 digits
            $positions[$market]['size'] = round($positions[$market]['size'], 10); // in some cases the size of a trade gets too small, so we cap it at 10 digits
        }

        if (isset($positions[$market]) && $positions[$market]['size'] == 0) {
            // we closed the position
            $realizedGains[$positions[$market]['exit_date']] = $positions[$market];
            if (count($newPosition) > 0) {
                $positions[$market] = $newPosition;
            } else {
                unset($positions[$market]);
            }
        }
    }

    $profits = array();
    $fees = array();
    foreach ($realizedGains as $tradeDate => $trade) {

        $date = date_create($tradeDate);
        //$date->setTime(23, 59, 59);
        //$date = $date->format('Y-m-t');
        $date = $date->format('Y-m-d H:i:s');

        if (!isset($profits[$date])) {
            $profits[$date]['date'] = $date;
            $profits[$date]['amount'] = floatval($trade['profit']);
            $profits[$date]['currency'] = 'USDC';
            $profits[$date]['label'] = 'realized gain';
            $profits[$date]['transactionHash'] = '';
            $profits[$date]['description'] = $trade['market'] . ' ' . ($trade['direction'] == 1 ? 'Long' : 'Short');
        } else {
            $profits[$date]['amount'] += floatval($trade['profit']);
            $profits[$date]['description'] .= '; ' . $trade['market'] . ' ' . ($trade['direction'] == 1 ? 'Long' : 'Short');
        }
    }

    $gains = array();
    foreach ($profits as $profit) {
        $profit['amount'] = round($profit['amount'], 4);
        $gains[] = $profit;
    }
    foreach ($fees as $fee) {
        $fee['amount'] = round($fee['amount'], 4);
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
            'label' => '',
            'transactionHash' => $transfer['transactionHash'],
            'description' => $transfer['type'],
        );
    }

    return $positions;
}
