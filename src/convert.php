#!/usr/bin/env php
<?php
require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SingleCommandApplication;

const TRANSACTION_TEMPLATE = array(
    'date' => "Date",
    'sent_amount' => "Sent Amount",
    'sent_currency' => "Sent Currency",
    'received_amount' => "Received Amount",
    'received_currency' => "Received Currency",
    'fee_amount' => "Fee Amount",
    'fee_currency' => "Fee Currency",
    'net_worth_amount' => "Net Worth Amount",
    'net_worth_currency' => "Net Worth Currency",
    'label' => "Label",
    'description' => "Description",
    'txhash' => "TxHash"
);

const REALIZED_GAIN_LABEL = 'realized gain';

const WITHDRAW_LABEL = 'withdraw';
const DEPOSIT_LABEL = 'deposit';
const REWARD_LABEL = 'reward';

const BASE_URL = 'https://indexer.dydx.trade/v4/';

(new SingleCommandApplication())
    ->setName('dYdX v4 to Koinly') // Optional
    ->setVersion('2.0.0') // Optional
    ->addArgument('address', InputArgument::REQUIRED, 'The dYdX address!')
    ->setCode(function (InputInterface $input, OutputInterface $output): int {


        $address = $input->getArgument('address');

        $transactions = generateTransactions($address, $output);
        $rewards = generateRewards($address, $output);
        $transfers = generateTransfers($address, $output);

        writeTransactions('./output/transactions.csv', $transactions);
        //writeTransactions('./output/funding.csv', $funding);
        writeTransactions('./output/rewards.csv', $rewards);
        writeTransactions('./output/transfers.csv', $transfers);

        $output->writeln('<info>Conversion completed</info>');

        return 0;
    })
    ->run();

function writeTransactions($file, $transactions)
{
    $fp = @fopen($file, "w");
    $headings = TRANSACTION_TEMPLATE;
    fputcsv($fp, $headings);
    foreach ($transactions as $transaction) {
        fputcsv($fp, $transaction);
    }
    fclose($fp);
}

function generateRewards(string $address, OutputInterface $output, bool $verbose = false): array
{
    $transactions = array();

    if ($rewards = _getHistoricalBlockTradingRewards($address)) {
        foreach ($rewards as $reward) {
            $output->writeln("<info>Processing reward: " . print_r($reward, true) . "</info>");

            $rewardTransaction = TRANSACTION_TEMPLATE;

            $date = date_create($reward['createdAt']);
            $date = $date->setTime(23, 59, 59)->format('Y-m-d H:i:s');
            $rewardTransaction['date'] = $date;
            if (isset($transactions[$date])) {
                $rewardTransaction = $transactions[$date];
            } else {
                $rewardTransaction['received_amount'] = 0;
                $rewardTransaction['txhash'] = "";
            }

            $amount = $reward['tradingReward'];
            $rewardTransaction['sent_amount'] = 0;
            $rewardTransaction['sent_currency'] = 'DYDX';
            $rewardTransaction['received_amount'] += abs($amount);
            $rewardTransaction['received_currency'] = 'DYDX';

            $rewardTransaction['description'] = "Trading reward received";

            $rewardTransaction['fee_amount'] = 0;
            $rewardTransaction['fee_currency'] = 'USDC';
            $rewardTransaction['net_worth_amount'] = '';
            $rewardTransaction['net_worth_currency'] = '';
            $rewardTransaction['label'] = REWARD_LABEL;
            $rewardTransaction['txhash'] .= $reward['createdAtHeight'] . " - ";
            $transactions[$date] = $rewardTransaction;
        }
    }

    return $transactions;
}

function generateTransfers(string $address, OutputInterface $output, bool $verbose = false): array
{
    $transactions = array();
    $transfers = _getTransfers($address, $output);

    foreach ($transfers as $transfer) {
        if (!in_array($transfer['type'], array('deposit', 'withdrawal'))) {
            $output->writeln("<warning>Skipping unknown transfer type: " . print_r($transfer, true) . "</warning>");
            continue;
        }

        $output->writeln("<info>Processing transfer: " . print_r($transfer, true) . "</info>");

        $transaction = TRANSACTION_TEMPLATE;

        $amount = stripos($transfer['type'], 'deposit') !== false ? $transfer['size'] : -1 * $transfer['size'];

        $transaction['date'] = date_create($transfer['createdAt'])->format('Y-m-d H:i:s');
        $transaction['sent_amount'] = $amount < 0 ? abs($amount) : 0;
        $transaction['sent_currency'] = $transfer['symbol'];
        $transaction['received_amount'] = $amount > 0 ? abs($amount) : 0;;
        $transaction['received_currency'] = $transfer['symbol'];

        $transaction['description'] = '';

        $transaction['fee_amount'] = 0;
        $transaction['fee_currency'] = 'USDC';
        $transaction['net_worth_amount'] = '';
        $transaction['net_worth_currency'] = '';
        $transaction['label'] = $amount > 0 ? DEPOSIT_LABEL : WITHDRAW_LABEL;
        $transaction['txhash'] = $transfer['transactionHash'];

        $transactions[] = $transaction;
    }

    return $transactions;
}

function generateTransactions(string $address, OutputInterface $output, bool $verbose = false): array
{
    $transactions = array();

    // we fetch the status of our account, so all fees and funding are already included in PNL transactions
    $historicalPnls = _getHistoricalPnl($address, $output);

    $firstEntry = array_shift($historicalPnls);

    // the first chain entry is our initial deposit
    $totalPnl = $firstEntry['totalPnl'];

    foreach ($historicalPnls as $historicalPnl) {

        $output->writeln("<info>Processing historicalPnl: " . print_r($historicalPnl, true) . "</info>");

        if ($totalPnl != $historicalPnl['totalPnl']) {
            $pnlTransaction = TRANSACTION_TEMPLATE;

            $date = date_create($historicalPnl['createdAt']);
            $date->setTime(23, 59, 59);
            $pnlForDay = $date->format('Y-m-d H:i:s');
            if (isset($transactions[$pnlForDay])) {
                $pnlTransaction = $transactions[$pnlForDay];
            }

            // get the previous day totalPnl
            $createdAt = date_create($historicalPnl['createdAt']);
            $createdAt->setTime(23, 59, 59);
            $previousDay = $createdAt->sub(DateInterval::createFromDateString('24 hours'));
            $pnlForPreviousDay = $previousDay->format('Y-m-d H:i:s');
            if (isset($transactions[$pnlForPreviousDay])) {
                $totalPnl = $transactions[$pnlForPreviousDay]['totalPnl'];
            }

            $diff = abs($totalPnl - $historicalPnl['totalPnl']);
            $profit = $totalPnl > $historicalPnl['totalPnl'] ? -1 * $diff : $diff;

            $pnlTransaction['date'] = date_create($historicalPnl['createdAt'])->format('Y-m-d H:i:s');
            $pnlTransaction['sent_amount'] = $profit < 0 ? abs($profit) : 0;
            $pnlTransaction['sent_currency'] = 'USDC';
            $pnlTransaction['received_amount'] = $profit > 0 ? abs($profit) : 0;;
            $pnlTransaction['received_currency'] = 'USDC';

            $pnlTransaction['description'] = '';

            $pnlTransaction['fee_amount'] = 0;
            $pnlTransaction['fee_currency'] = 'USDC';
            $pnlTransaction['net_worth_amount'] = '';
            $pnlTransaction['net_worth_currency'] = '';
            $pnlTransaction['label'] = REALIZED_GAIN_LABEL;
            $pnlTransaction['txhash'] = $historicalPnl['id'];
            $pnlTransaction['totalPnl'] = $historicalPnl['totalPnl'];
            $transactions[$pnlForDay] = $pnlTransaction;
        }
    }

    foreach ($transactions as $index => $transaction) {
        // we group all transactions to the last minute of the day
        $transactions[$index]['date'] = $index;
    }

    return $transactions;
}

function _getTransfers(string $address, OutputInterface $output, float $subaccountNumber = 0): ?array
{
    /** @var \Symfony\Contracts\HttpClient\HttpClientInterface $client */
    $client = \Symfony\Component\HttpClient\HttpClient::create();
    $url = BASE_URL . 'transfers';
    $limit = 1000;
    $page = 1;
    $response = $client->request(
        'GET',
        $url,
        [
            'query' => [
                'address' => $address,
                'subaccountNumber' => $subaccountNumber,
                'limit' => $limit,
                'page' => $page,
            ]
        ]
    );

    $content = $response->toArray();

    $transfers = array();
    while (isset($content['transfers']) && count($content['transfers']) > 0) {

        $apiData = $content['transfers'];
        foreach ($apiData as $transfer) {
            $output->writeln("<info>Processing transfer: " . print_r($transfer, true) . "</info>");
            $transfer['size'] = floatval($transfer['size']);
            $transfer['type'] = strtolower($transfer['type']);
            $transfers[] = $transfer;
        }

        $page++;
        $response = $client->request(
            'GET',
            $url,
            [
                'query' => [
                    'address' => $address,
                    'subaccountNumber' => $subaccountNumber,
                    'limit' => $limit,
                    'page' => $page,
                ]
            ]
        );
        $content = $response->toArray();
    }

    if (count($transfers) > 0) {
        return $transfers;
    }

    return null;
}

/**
 * Hier sind die Einzeltransaktionen die für die Positionsberechnung verwendet werden muss.
 * TODO
 *
 * @param string $address
 * @param int $limit
 * @param int $page
 * @param float $subaccountNumber
 * @return array|null
 * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
 * @throws \Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface
 * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
 * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
 * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
 */
function _getFills(string $address, int $limit = 1, int $page = 1, float $subaccountNumber = 0): ?array
{
    /** @var \Symfony\Contracts\HttpClient\HttpClientInterface $client */
    $client = \Symfony\Component\HttpClient\HttpClient::create();
    $url = BASE_URL . 'fills';
    $response = $client->request(
        'GET',
        $url,
        [
            'query' => [
                'address' => $address,
                'subaccountNumber' => $subaccountNumber,
                'limit' => $limit,
                'page' => $page,
            ]
        ]
    );

    $content = $response->toArray();

    if (isset($content['fills']) && count($content['fills']) > 0) {
        return $content['fills'];
    }

    return null;
}

function _getHistoricalPnl(string $address, OutputInterface $output, float $subaccountNumber = 0, bool $excludeCurrentDay = true): ?array
{
    $format = "Y-m-d\TH:i:s.999\Z";
    $createdBeforeOrAt = date_create();
    $createdBeforeOrAt->setTime(23, 59, 59);
    if ($excludeCurrentDay) {
        $createdBeforeOrAt->sub(DateInterval::createFromDateString('24 hours'));
    }
    $createdBeforeOrAt = $createdBeforeOrAt->format($format);

    /** @var \Symfony\Contracts\HttpClient\HttpClientInterface $client */
    $client = \Symfony\Component\HttpClient\HttpClient::create();
    $url = BASE_URL . 'historical-pnl';
    $limit = 1000;
    $page = 1;
    $response = $client->request(
        'GET',
        $url,
        [
            'query' => [
                'address' => $address,
                'subaccountNumber' => $subaccountNumber,
                'limit' => $limit,
                'page' => $page,
                'createdBeforeOrAt' => $createdBeforeOrAt,
            ]
        ]
    );

    $content = $response->toArray();

    $pnl = array();
    while (isset($content['historicalPnl']) && count($content['historicalPnl']) > 0) {

        $historicalPnls = $content['historicalPnl'];
        foreach ($historicalPnls as $historicalPnl) {
            $output->writeln("<info>Processing PNL: " . print_r($historicalPnl, true) . "</info>");
            $date = date_create($historicalPnl['createdAt'])->format('Y-m-d H:i:s');
            $historicalPnl['equity'] = floatval($historicalPnl['equity']);
            $historicalPnl['netTransfers'] = floatval($historicalPnl['netTransfers']);
            $historicalPnl['totalPnl'] = floatval($historicalPnl['totalPnl']);
            $pnl[$date] = $historicalPnl;
        }

        $page++;
        $response = $client->request(
            'GET',
            $url,
            [
                'query' => [
                    'address' => $address,
                    'subaccountNumber' => $subaccountNumber,
                    'limit' => $limit,
                    'page' => $page,
                ]
            ]
        );
        $content = $response->toArray();
    }

    if (count($pnl) > 0) {
        ksort($pnl);
        return $pnl;
    }

    return null;
}

/**
 * @param string $address
 * @param int $subaccountNumber
 * @param string $status
 * @return array
 * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
 * @throws \Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface
 * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
 * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
 * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
 */
function _getPerpetualPositions(string $address, float $subaccountNumber = 0, string $status = 'CLOSED'): ?array
{
    /** @var \Symfony\Contracts\HttpClient\HttpClientInterface $client */
    $client = \Symfony\Component\HttpClient\HttpClient::create();
    $url = BASE_URL . 'perpetualPositions';
    $response = $client->request(
        'GET',
        $url,
        [
            'query' => [
                'address' => $address,
                'subaccountNumber' => $subaccountNumber,
                'status' => $status,
            ]
        ]
    );

    $content = $response->toArray();

    if (isset($content['positions'])) {
        return $content['positions'];
    }

    return null;
}

function _getHistoricalBlockTradingRewards(string $address): ?array
{
    /** @var \Symfony\Contracts\HttpClient\HttpClientInterface $client */
    $client = \Symfony\Component\HttpClient\HttpClient::create();
    $url = BASE_URL . 'historicalBlockTradingRewards/' . $address;
    $response = $client->request(
        'GET',
        $url,
        [
            'query' => [
            ]
        ]
    );

    $content = $response->toArray();

    if (isset($content['rewards'])) {
        return $content['rewards'];
    }

    return null;
}
