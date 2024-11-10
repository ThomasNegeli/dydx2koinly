# dydx2koinly

Install the required components
    
    composer install

Download Trades, Transfers and Funding CSV from dYdX. 
Start the app with the following command:

    ./convert.php ./input/Trades.csv ./input/Funding.csv ./input/Transfers.csv

    Usage:
    ./convert.php <trades> <funding> <transfers> [<verbose>]

    Arguments:
    trades                The dYdX trades export file!
    funding               The dYdX funding export file!
    transfers             The dYdX transfers export file!
    verbose               Export all trades and PNL values [default: false]

You can use the verbose parameter to export all borrow, repay and trade transactions if you want.
Due to the fact that dYdX is a perpetual exchange, the full trade export is typically not relevant for taxes. 
Only PNL values are.