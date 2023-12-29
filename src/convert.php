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
        $trades = readCsv($trades);

        $funding = $input->getArgument('funding');
        $funding = readCsv($funding);

        $transfers = $input->getArgument('transfers');
        $transfers = readCsv($transfers);

        $positions = generatePositions($trades, $funding);

        writeCsv('./output/positions.csv', $positions);

        return 0;
    })
    ->run();

function writeCsv($file, $positions)
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

function readCsv($file): array
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

function generatePositions($trades, $funding)
{
    $positions = array();
    $realizedGains = array();
    $marginFee = array();
    $trades = array_reverse($trades);
    $funding = array_reverse($funding);
    foreach ($trades as $trade) {
        $market = $trade['market'];
        $side = $trade['side'];
        if (!isset($positions[$market])) {
            $positions[$market] = array(
                'size' => 0,
                'fee' => 0,
                'initial_amount' => $trade['size'] * $trade['price'],
                'total_amount' => $trade['size'] * $trade['price'],
            );
        }
        if ($side == 'BUY') {
            $positions[$market]['size'] += $trade['size'];
            $positions[$market]['total_amount'] += $trade['size'] * $trade['price'];
            $positions[$market]['fee'] += $trade['fee'];
        } else {
            $positions[$market]['size'] -= $trade['size'];
            $positions[$market]['total_amount'] -= $trade['size'] * $trade['price'];
            $positions[$market]['fee'] += $trade['fee'];
        }

        if ($positions[$market]['size'] == 0) {
            $realizedGains[$trade['createdAt']] = array(
                'date' => $trade['createdAt'],
                'amount' => $positions[$market]['total_amount'] - $positions[$market]['initial_amount'],
                'currency' => 'USDC',
                'label' => 'realized gain',
                'tx_hash' => ''
            );
        }
    }
    return $realizedGains;
}
