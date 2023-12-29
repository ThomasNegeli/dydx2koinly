#!/usr/bin/env php
<?php
require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SingleCommandApplication;

(new SingleCommandApplication())
    ->setName('My Super Command') // Optional
    ->setVersion('1.0.0') // Optional
    ->addArgument('trades', InputArgument::REQUIRED, 'The dYdX trades export file!')
    ->addArgument('funding', InputArgument::REQUIRED, 'The dYdX funding export file!')
    ->addArgument('transfers', InputArgument::REQUIRED, 'The dYdX transfers export file!')
    ->setCode(function (InputInterface $input, OutputInterface $output): int {

        return 0;
    })
    ->run();
