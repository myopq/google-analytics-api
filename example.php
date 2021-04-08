<?php
include "vendor/autoload.php";
require "GoogleAnalyticsUtil.php";

$keyPath = __DIR__ . '/secure-proxy-308208-cdda8d640776.json';

// 如果不需要代理，则不需要传该参数
$options = [
    'proxy' => '127.0.0.1:7890'
];
$gau = new GoogleAnalyticsUtil($keyPath, $options);

// 按天返回
$report = $gau->getReport('000000', 'ga:date', ["ga:sessions", "ga:pageviews", "ga:users"], '7daysAgo', 'today');
var_dump($report);
// 按天返回traffic channel
$report = $gau->getTrafficChannelReport('000000', '2021-01-01', '2021-01-10');
var_dump($report);
