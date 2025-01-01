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

const BASE_URL = 'https://indexer.dydx.trade/v4/';

(new SingleCommandApplication())
    ->setName('dYdX v4 to Koinly') // Optional
    ->setVersion('2.0.0') // Optional
    ->addArgument('address', InputArgument::REQUIRED, 'The dYdX address!')
    ->setCode(function (InputInterface $input, OutputInterface $output): int {


        $address = $input->getArgument('address');

        $transactions = generateTransactions($address, $output);
        $fees = generateFeeTransactions($address, $output);
        $rewards = array();
        //$rewards = generateRewards($address);

        writeTransactions('./output/transactions.csv', $transactions);
        writeTransactions('./output/fees.csv', $fees);
        writeTransactions('./output/rewards.csv', $rewards);

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
                $header[0] = 'Time';
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

function generateFundings($fundings): array
{
    $payments = array();
    $fundings = array_reverse($fundings);
    foreach ($fundings as $funding) {

        $funding['payment'] = floatval($funding['payment']);

        $payment = TRANSACTION_TEMPLATE;
        $payment['payment'] = 0.0;
        $payment['description'] = "Daily funding payment";
        $payment['fee_amount'] = 0.0;
        $payment['fee_currency'] = 'USDC';
        $payment['label'] = REALIZED_GAIN_LABEL;
        $payment['net_worth_amount'] = '';
        $payment['net_worth_currency'] = '';
        $payment['received_amount'] = 0;
        $payment['sent_amount'] = 0;
        $payment['received_currency'] = $payment['sent_currency'] = 'USDC';
        $payment['txhash'] = '';

        $date = date_create($funding['effectiveAt']);
        $date->setTime(23, 59, 59);

        $payment['date'] = $date->format('Y-m-d H:i:s');
        if (!isset($payments[$payment['date']])) {
            $payments[$payment['date']] = $payment;
        }
        $payments[$payment['date']]['payment'] += $funding['payment'];

    }

    $realPayments = array();
    foreach ($payments as $index => $payment) {
        if ($payment['payment'] > 0) {
            $payment['received_amount'] = $payment['payment'];
        } else {
            $payment['sent_amount'] = abs($payment['payment']);
        }
        unset($payment['payment']);
        $realPayments[$index] = $payment;
    }
    return $realPayments;
}

function _mapV4Trades(array $originalTrades): array
{
    $tradeTimesLocked = array();
    $trades = array();
    foreach ($originalTrades as $originalTrade) {
        $trade = array();
        $format = "m/j/y, H:i a";
        $createdAt = DateTime::createFromFormat($format, $originalTrade['Time']);
        $trade['createdAt'] = $createdAt->format('c');
        while (isset($tradeTimesLocked[$trade['createdAt']])) {
            $createdAt->add(DateInterval::createFromDateString('1 second'));
            $trade['createdAt'] = $createdAt->format('c');
        }
        $tradeTimesLocked[$trade['createdAt']] = $trade['createdAt'];
        $trade['market'] = $originalTrade['Market'];
        $trade['side'] = strtoupper($originalTrade['Side']);
        $trade['size'] = floatval($originalTrade['Amount']);
        $trade['price'] = floatval(str_replace(',', '', trim($originalTrade['Price'], '$')));
        $trade['total'] = $trade['size'] * $trade['price'];
        $trade['fee'] = floatval(str_replace(',', '', trim($originalTrade['Fee'], '$')));
        $trade['type'] = $originalTrade['Type'];
        $trade['liquidity'] = $originalTrade['Liquidity'];
        $trades[] = $trade;
    }

    return $trades;
}

function generateFeeTransactions(string $address, OutputInterface $output, bool $verbose = false): array
{

    $transactions = array();

    $limit = 100;
    $page = 1;
    while ($fills = _getFills($address, $limit, $page)) {
        foreach ($fills as $fill) {
            $output->writeln("<info>Processing fill: " . print_r($fill, true) . "</info>");

            $pnlTransaction = TRANSACTION_TEMPLATE;

            $pnlTransaction['date'] = date_create($fill['createdAt'])->format('Y-m-d H:i:s');

            $pnlTransaction['sent_amount'] = $fill['fee'];
            $pnlTransaction['sent_currency'] = 'USDC';
            $pnlTransaction['received_amount'] = 0;;
            $pnlTransaction['received_currency'] = 'USDC';

            $pnlTransaction['description'] = "Trade fee to " . $fill['side'] . " " . $fill['size'] . " " . $fill['market'];

            $pnlTransaction['fee_amount'] = 0;
            $pnlTransaction['fee_currency'] = 'USDC';
            $pnlTransaction['net_worth_amount'] = '';
            $pnlTransaction['net_worth_currency'] = '';
            $pnlTransaction['label'] = REALIZED_GAIN_LABEL;
            $pnlTransaction['txhash'] = $fill['createdAtHeight'] . "-" . hash('sha256', $pnlTransaction['date']);
            $transactions[] = $pnlTransaction;
        }
        $page++;
    }

    return $transactions;

}

function generateTransactions(string $address, OutputInterface $output, bool $verbose = false): array
{
    $transactions = array();

    $positions = _getPerpetualPositions($address);
    foreach ($positions as $position) {

        $output->writeln("<info>Processing position: " . print_r($position, true) . "</info>");

        $pnlTransaction = TRANSACTION_TEMPLATE;

        $pnlTransaction['date'] = date_create($position['closedAt'])->format('Y-m-d H:i:s');
        $pnl = $position['realizedPnl'];
        $funding = $position['netFunding'];
        $profit = $pnl + $funding;

        $pnlTransaction['sent_amount'] = $profit < 0 ? abs($profit) : 0;
        $pnlTransaction['sent_currency'] = 'USDC';
        $pnlTransaction['received_amount'] = $profit > 0 ? abs($profit) : 0;;
        $pnlTransaction['received_currency'] = 'USDC';

        $pnlTransaction['description'] = $position['sumClose'] . " " . $position['market'];
        $pnlTransaction['description'] .= " - PNL " . $pnl . " USDC";
        $pnlTransaction['description'] .= " - Funding " . $funding . " USDC";

        $pnlTransaction['fee_amount'] = 0;
        $pnlTransaction['fee_currency'] = 'USDC';
        $pnlTransaction['net_worth_amount'] = '';
        $pnlTransaction['net_worth_currency'] = '';
        $pnlTransaction['label'] = REALIZED_GAIN_LABEL;
        $pnlTransaction['txhash'] = $position['createdAtHeight'] . "-" . hash('sha256', $pnlTransaction['date']);
        $transactions[] = $pnlTransaction;
    }

    return $transactions;
}

function _filterAllNonPnlTransactions(array $originalTransactions): array
{

    $transactions = array();

    foreach ($originalTransactions as $originalTransaction) {
        if ($originalTransaction['label'] == REALIZED_GAIN_LABEL) {
            $transactions[] = $originalTransaction;
        }
    }

    return $transactions;
}

function _generatePnlTransactions(array $position, DateTime $pnlTransactionDate): array
{
    $transactions = array();
    $pnlTransaction = TRANSACTION_TEMPLATE;

    $pnlTransaction['description'] = "";
    $profit = $position['profit'];
    if ($position['fee'] > 0) {
        $profit -= $position['fee'];
        $pnlTransaction['description'] = "Position fee of " . $position['fee'] . " USDC - ";
    }

    $pnlTransaction['date'] = $pnlTransactionDate->add(DateInterval::createFromDateString('1 second'))->format('Y-m-d H:i:s');
    $pnlTransaction['sent_amount'] = $profit < 0 ? abs($profit) : 0;
    $pnlTransaction['sent_currency'] = 'USDC';
    $pnlTransaction['received_amount'] = $profit > 0 ? abs($profit) : 0;;
    $pnlTransaction['received_currency'] = 'USDC';
    $pnlTransaction['fee_amount'] = 0;
    $pnlTransaction['fee_currency'] = 'USDC';
    $pnlTransaction['net_worth_amount'] = '';
    $pnlTransaction['net_worth_currency'] = '';
    $pnlTransaction['label'] = REALIZED_GAIN_LABEL;
    $pnlTransaction['description'] .= "Realized PNL of " . $position['profit'] . " USDC";
    $pnlTransaction['txhash'] = '';
    $transactions[] = $pnlTransaction;

    if ($profit > 0) {
        $quoteCleanTransaction = $pnlTransaction;
        $quoteCleanTransaction['sent_amount'] = $pnlTransaction['received_amount'];
        $quoteCleanTransaction['received_amount'] = 0;
        $quoteCleanTransaction['label'] = 'Margin repayment';
        $quoteCleanTransaction['description'] = "Clean quote currency by PNL";
        $transactions[] = $quoteCleanTransaction;
    } else {
        $quoteCleanTransaction = $pnlTransaction;
        $quoteCleanTransaction['sent_amount'] = 0;
        $quoteCleanTransaction['received_amount'] = $pnlTransaction['sent_amount'];
        $quoteCleanTransaction['label'] = 'Margin loan';
        $quoteCleanTransaction['description'] = "Clean quote currency by PNL";
        $transactions[] = $quoteCleanTransaction;
    }

    return $transactions;
}

/**
 * @param array $trade
 * @param DateTime $borrowDate
 * @param bool $inQuoteCurrency
 * @return string[]
 */
function _generateBorrowTransaction(array $trade, DateTime $borrowDate, bool $inQuoteCurrency = false): array
{
    $borrowTransaction = TRANSACTION_TEMPLATE;
    $borrowTransaction['date'] = $borrowDate->sub(DateInterval::createFromDateString('1 second'))->format('Y-m-d H:i:s');
    $borrowTransaction['sent_amount'] = '';
    $borrowTransaction['sent_currency'] = '';
    $borrowTransaction['received_amount'] = $inQuoteCurrency ? $trade['size'] * $trade['price'] : $trade['size'];
    $borrowTransaction['received_currency'] = $inQuoteCurrency ? 'USDC' : $trade['market_symbol'];
    $borrowTransaction['fee_amount'] = 0.0;
    $borrowTransaction['fee_currency'] = 'USDC';
    $borrowTransaction['net_worth_amount'] = '';
    $borrowTransaction['net_worth_currency'] = '';
    $borrowTransaction['label'] = 'Margin loan';
    $borrowTransaction['description'] = 'Borrow ' . $trade['size'] . ' ' . $trade['market_symbol'];
    if ($inQuoteCurrency) {
        $borrowTransaction['description'] = 'Borrow ' . $trade['size'] * $trade['price'] . ' USDC';
    }
    $borrowTransaction['txhash'] = '';

    return $borrowTransaction;
}

function _generateSellTransaction(array $trade): array
{
    $sellTransaction = array();

    $sellTransaction['date'] = $trade['createdAt'];
    $sellTransaction['sent_amount'] = $trade['size'];
    $sellTransaction['sent_currency'] = $trade['market_symbol'];
    $sellTransaction['received_amount'] = $trade['size'] * $trade['price'];
    $sellTransaction['received_currency'] = 'USDC';
    $sellTransaction['fee_amount'] = 0.0;   // fee is already moved to position
    $sellTransaction['fee_currency'] = 'USDC';
    $sellTransaction['net_worth_amount'] = '';
    $sellTransaction['net_worth_currency'] = '';
    $sellTransaction['label'] = '';
    $sellTransaction['description'] = 'Sell ' . $trade['size'] . ' ' . $trade['market_symbol'];
    $sellTransaction['txhash'] = '';

    return $sellTransaction;
}

/**
 * @param array $trade
 * @return array
 */
function _generateBuyTransaction(array $trade)
{

    $buyTransaction = array();

    $buyTransaction['date'] = $trade['createdAt'];
    $buyTransaction['sent_amount'] = $trade['size'] * $trade['price'];
    $buyTransaction['sent_currency'] = 'USDC';
    $buyTransaction['received_amount'] = $trade['size'];
    $buyTransaction['received_currency'] = $trade['market_symbol'];
    $buyTransaction['fee_amount'] = 0.0;    // fee is already moved to position
    $buyTransaction['fee_currency'] = 'USDC';
    $buyTransaction['net_worth_amount'] = '';
    $buyTransaction['net_worth_currency'] = '';
    $buyTransaction['label'] = '';
    $buyTransaction['description'] = 'Buy ' . $trade['size'] . ' ' . $trade['market_symbol'];
    $buyTransaction['txhash'] = '';

    return $buyTransaction;
}

function _generateFreshPosition($trade): array
{
    $position = array();
    $position['size'] = 0.0;
    $position['profit'] = 0.0;
    $position['amount'] = 0.0;
    $position['restart'] = true;
    $position['fee'] = 0.0;
    $position['direction'] = $trade['side'] == 'BUY' ? 1 : -1;
    return $position;
}

function _sellTradeInAFreshOrExistingShortPosition($trade, &$positions, $date, &$transactions): void
{
    if (!isset($positions[$trade['market']])) {
        $positions[$trade['market']] = _generateFreshPosition($trade);
    }

    // check if we have to borrow the amount first
    if ($positions[$trade['market']]['size'] < $trade['size']) {
        $borrowTransaction = _generateBorrowTransaction($trade, clone $date);
        $transactions[] = $borrowTransaction;

        $positions[$trade['market']]['size'] -= $trade['size'];
        $positions[$trade['market']]['restart'] = false;
        $positions[$trade['market']]['borrowTransactions'][] = $borrowTransaction;
    }
    $transaction = _generateSellTransaction($trade);
    $transactions[] = $transaction;

    $positions[$trade['market']]['amount'] += $transaction['received_amount'];
    $positions[$trade['market']]['trades'][] = $transaction;
}

function _sellTradeInAFreshOrExistingLongPosition($trade, &$positions, &$transactions): void
{
    if (!isset($positions[$trade['market']])) {
        $positions[$trade['market']] = _generateFreshPosition($trade);
    }

    $transaction = _generateSellTransaction($trade);
    $transactions[] = $transaction;

    $positions[$trade['market']]['amount'] += $trade['size'] * $trade['price'];
    $positions[$trade['market']]['trades'][] = $transaction;
    $positions[$trade['market']]['size'] -= $trade['size'];

    $positions[$trade['market']]['size'] = round($positions[$trade['market']]['size'], 10);
}

function _buyTradeInAFreshOrExistingLongPosition($trade, $date, &$positions, &$transactions): void
{
    if (!isset($positions[$trade['market']])) {
        $positions[$trade['market']] = _generateFreshPosition($trade);
    }
    // buy trade in a fresh or existing long position
    $borrowTransaction = _generateBorrowTransaction($trade, clone $date, true);
    $transactions[] = $borrowTransaction;

    $positions[$trade['market']]['size'] += $trade['size'];
    $positions[$trade['market']]['restart'] = false;
    $positions[$trade['market']]['borrowTransactions'][] = $borrowTransaction;

    $transaction = _generateBuyTransaction($trade);
    $transactions[] = $transaction;

    $positions[$trade['market']]['amount'] -= $transaction['sent_amount'];
    $positions[$trade['market']]['trades'][] = $transaction;

}

/**
 * @param array $position
 * @param DateTime $repayDate
 * @return array
 */
function _generateRepayTransactions(array $borrowTransactions, DateTime $repayDate): array
{
    $transactions = array();

    $borrowTransaction = array_shift($borrowTransactions);
    if ($borrowTransaction) {
        $repayTransaction = $borrowTransaction;
        $repayTransaction['label'] = 'Margin repayment';
        $repayTransaction['sent_amount'] = $repayTransaction['received_amount'];
        $repayTransaction['sent_currency'] = $repayTransaction['received_currency'];
        $repayTransaction['received_amount'] = "";
        $repayTransaction['received_currency'] = "";
        $repayTransaction['description'] = "Repay " . $repayTransaction['sent_amount'] . ' ' . $repayTransaction['sent_currency'];
        $repayTransaction['date'] = $repayDate->add(DateInterval::createFromDateString('1 second'))->format('Y-m-d H:i:s');

        foreach ($borrowTransactions as $borrowTransaction) {
            $repayTransaction['sent_amount'] += $borrowTransaction['received_amount'];
            $repayTransaction['description'] = "Repay " . $repayTransaction['sent_amount'] . ' ' . $repayTransaction['sent_currency'];
        }
        $transactions[] = $repayTransaction;
    }

    return $transactions;
}

function generateTransfers(string $address, OutputInterface $output): array
{
    $positions = array();
    $transfers = array_reverse($transfers);
    $transfers = _mapV4Transfers($transfers);
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

    $statusCode = $response->getStatusCode();
    // $statusCode = 200
    $contentType = $response->getHeaders()['content-type'][0];
    // $contentType = 'application/json'
    $content = $response->getContent();
    // $content = '{"id":521583, "name":"symfony-docs", ...}'
    $content = $response->toArray();
    // $content = ['id' => 521583, 'name' => 'symfony-docs', ...]

    if (isset($content['fills']) && count($content['fills']) > 0) {
        return $content['fills'];
    }

    return null;
}

/**
 * Funding kann von hier geholt werden.
 * Die Positions berechnen leider PNL falsch. Also stimmt die Summe nicht.
 * Z.b. am 7.11.24, wo wir laut den Positions einen Profit von 1188 USDC Profit gehabt hätten!!!
 * TODO
 *
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
function _getPerpetualPositions(string $address, float $subaccountNumber = 0, string $status = 'CLOSED'): array
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

    $statusCode = $response->getStatusCode();
    // $statusCode = 200
    $contentType = $response->getHeaders()['content-type'][0];
    // $contentType = 'application/json'
    $content = $response->getContent();
    // $content = '{"id":521583, "name":"symfony-docs", ...}'
    $content = $response->toArray();
    // $content = ['id' => 521583, 'name' => 'symfony-docs', ...]

    if (isset($content['positions'])) {
        return $content['positions'];
    }

    return array();
}

const TRANSFER_TEMPLATE = array(
    'date',
    'amount',
    'currency',
    'label',
    'transactionHash',
    'descriptions'
);

function _mapV4Transfers($originalTransfers): array
{
    $transfers = array();

    $transferTimesLocked = array();
    foreach ($originalTransfers as $originalTransfer) {
        $transfer = array();
        $format = "m/j/y, H:i a";
        $createdAt = DateTime::createFromFormat($format, $originalTransfer['Time']);
        $transfer['createdAt'] = $createdAt->format('c');
        while (isset($transferTimesLocked[$transfer['createdAt']])) {
            $createdAt->add(DateInterval::createFromDateString('1 second'));
            $transfer['createdAt'] = $createdAt->format('c');
        }
        $transferTimesLocked[$transfer['createdAt']] = $transfer['createdAt'];
        $transfer['type'] = $originalTransfer['Action'];
        $transfer['fromAddress'] = $originalTransfer['Sender'];
        $transfer['toAddress'] = $originalTransfer['Recipient'];
        $transfer['creditAmount'] = floatval(trim($originalTransfer['Amount'], '$'));
        $transfer['transactionHash'] = $originalTransfer['Transaction'];
        $transfers[] = $transfer;
    }

    return $transfers;
}
