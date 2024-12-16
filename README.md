# dydx2koinly

Install the required components
    
    composer install

Download Trades, Transfers and Funding CSV from dYdX. 
Start the app with the following command:

    ./convert.php ./input/trades.csv ./input/transfers.csv

    Usage:
    ./convert.php <trades> <transfers> [<funding> [<verbose>]]

    Arguments:
    trades                The dYdX trades export file!
    transfers             The dYdX transfers export file!
    funding               The dYdX funding export file! [default: false]
    verbose               Export all trades and PNL values [default: false]
    
    Options:
    -h, --help            Display help for the given command. When no command is given display help for the ./convert.php command
    -q, --quiet           Do not output any message
    -V, --version         Display this application version
    --ansi|--no-ansi  Force (or disable --no-ansi) ANSI output
    -n, --no-interaction  Do not ask any interactive question
    -v|vv|vvv, --verbose  Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug


You can use the verbose parameter to export all borrow, repay and trade transactions if you want.
Due to the fact that dYdX is a perpetual exchange, the full trade export is typically not relevant for taxes. 
Only PNL values are.