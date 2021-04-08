<?php

use Carbon\Carbon;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Client as GuzzleClient;

class GoogleAnalyticsUtil
{
    private $analytics;

    public function __construct($keyPath, array $options = [])
    {
        // Use the developers console and download your service account
        // credentials in JSON format. Place them in this directory or
        // change the key file location if necessary.
        // $KEY_FILE_LOCATION = __DIR__ . '/secure-proxy-308208-cdda8d640776.json';

        // Create and configure a new client object.
        $client = new Google_Client();
        $client->setApplicationName("Hello Analytics Reporting");
        $client->setAuthConfig($keyPath);
        $client->setScopes(['https://www.googleapis.com/auth/analytics.readonly']);

        $httpClientOption = [];
        if (!empty($options['proxy'])) {
            $httpClientOption['proxy'] = $options['proxy'];
        }

        // 默认的demo不支持代理，需要单独设置一个http client才支持
        $httpClient = $this->getHttpClient($httpClientOption);
        $client->setHttpClient($httpClient);

        $this->analytics = new Google_Service_AnalyticsReporting($client);
    }


    function getReport(
        $viewId, 
        $demension = "ga:date", // ga:acquisitionTrafficChannel
        $metrics = ["ga:sessions", "ga:pageviews", "ga:users"], 
        $startDate = "today", 
        $endDate = "today")
    {
        // Create the DateRange object.
        $dateRange = new Google_Service_AnalyticsReporting_DateRange();
        $dateRange->setStartDate($startDate);
        $dateRange->setEndDate($endDate);

        // Create the Metrics object.
        $metricObjs = [];
        foreach ($metrics as $_metric) {
            $metric = new Google_Service_AnalyticsReporting_Metric();
            $metric->setExpression($_metric);
            $metric->setAlias(explode(":", $_metric)[1]);
            $metricObjs[] = $metric;
        }

        // Create the Dimensions object.
        $demensionObjs = [];
        $dem = new Google_Service_AnalyticsReporting_Dimension();
        $dem->setName($demension);
        $demensionObjs[] = $dem;


        // Create the ReportRequest object.
        $request = new Google_Service_AnalyticsReporting_ReportRequest();
        $request->setViewId($viewId);
        $request->setDateRanges($dateRange);
        $request->setMetrics($metricObjs);
        $request->setDimensions($demensionObjs);

        $body = new Google_Service_AnalyticsReporting_GetReportsRequest();
        $body->setReportRequests(array($request));
        $response = $this->analytics->reports->batchGet($body);
        if (empty($response)) {
            return false;
        }

        return $this->formatResponse($response);
    }

    // 获取来源用户的接口不一样，要分组去取
    // 时间必须是yyyy-mm-dd格式
    // 如果groupByDay为true, 则要求时间跨度不能大于10天
    function getTrafficChannelReport(
        $viewId, 
        $startDate, 
        $endDate,
        $groupByDay = true)
    {
        // Create the ReportRequest object.
        $request = new Google_Service_AnalyticsReporting_ReportRequest();
        $request->setViewId($viewId);

        $cohortDimension = new Google_Service_AnalyticsReporting_Dimension();
        $cohortDimension->setName("ga:cohort");

        $acquisitionTrafficChannel = new Google_Service_AnalyticsReporting_Dimension();
        $acquisitionTrafficChannel->setName("ga:acquisitionTrafficChannel");

        // Set the cohort dimensions
        $request->setDimensions(array($cohortDimension, $acquisitionTrafficChannel));

        $cohortActiveUsers = new Google_Service_AnalyticsReporting_Metric();
        $cohortActiveUsers->setExpression("ga:cohortActiveUsers");

        // Set the cohort metrics
        $request->setMetrics(array($cohortActiveUsers));

        $cohorts = [];
        if ($groupByDay) {
            $startDate = Carbon::parse($startDate);
            $endDate = Carbon::parse($endDate);
            while (!$startDate->gt($endDate)) {
                $dateRange1 = new Google_Service_AnalyticsReporting_DateRange();
                $dateRange1->setStartDate($startDate->toDateString());
                $dateRange1->setEndDate($startDate->toDateString());

                // Create the first cohort
                $cohort1 = new Google_Service_AnalyticsReporting_Cohort();
                $cohort1->setName($startDate->toDateString());
                $cohort1->setType("FIRST_VISIT_DATE");
                $cohort1->setDateRange($dateRange1);
                $cohorts[] = $cohort1;
                $startDate = $startDate->addDay();
            }
        } else {
            $dateRange1 = new Google_Service_AnalyticsReporting_DateRange();
            $dateRange1->setStartDate($startDate);
            $dateRange1->setEndDate($endDate);

            // Create the first cohort
            $cohort1 = new Google_Service_AnalyticsReporting_Cohort();
            $cohort1->setName("all");
            $cohort1->setType("FIRST_VISIT_DATE");
            $cohort1->setDateRange($dateRange1);
            $cohorts[] = $cohort1;
        }

        // Create the cohort group
        $cohortGroup = new Google_Service_AnalyticsReporting_CohortGroup();
        $cohortGroup->setCohorts($cohorts);

        $request->setCohortGroup($cohortGroup);

        $body = new Google_Service_AnalyticsReporting_GetReportsRequest();
        $body->setReportRequests(array($request));

        $response = $this->analytics->reports->batchGet($body);
        if (empty($response)) {
            return false;
        }

        $report = $response->reports[0];
        $data = [];
        foreach ($report->data->rows as $row) {
            $cohortKey = $row->dimensions[0];
            $data[$cohortKey]['cohort'] = $cohortKey;
            if ($row->dimensions[1] == '(Other)') {
                $data[$cohortKey]['Other'] = $row->metrics[0]->values[0];
                $data[$cohortKey]['Other'] = $row->metrics[0]->values[0];
            } else {
                $data[$cohortKey][$row->dimensions[1]] = $row->metrics[0]->values[0];
            }
        }

        return $data;
    }

    public function formatResponse($response)
    {
        $reports = $response->reports ?? [];
        foreach ($reports as $report) {
            $data = [];
            $headers = [];
            foreach ($report->columnHeader->metricHeader->metricHeaderEntries as $header) {
                $headers[] = $header->name;
            }
            $demensionName = $report->columnHeader->dimensions[0];
            $demensionName = explode(":", $demensionName)[1];

            foreach ($report->data->rows as $row) {
                $demeValue = $row->dimensions[0];
                $rowItem = [
                    $demensionName => $demeValue,
                ];
                for ($i=0; $i < count($headers); $i++) { 
                    $rowItem[$headers[$i]] = $row->metrics[0]->values[$i];
                }
                $data[] = $rowItem;
            }

            return $data;
        }
    }

    private function getHttpClient($_options = [])
    {
        $guzzleVersion = null;
        if (defined('\GuzzleHttp\ClientInterface::MAJOR_VERSION')) {
            $guzzleVersion = ClientInterface::MAJOR_VERSION;
        } elseif (defined('\GuzzleHttp\ClientInterface::VERSION')) {
            $guzzleVersion = (int)substr(ClientInterface::VERSION, 0, 1);
        }

        if (5 === $guzzleVersion) {
            $options = [
                'base_url' => 'https://www.googleapis.com',
                'defaults' => ['exceptions' => false],
            ];
        } elseif (6 === $guzzleVersion || 7 === $guzzleVersion) {
            // guzzle 6 or 7
            $options = [
                'base_uri' => 'https://www.googleapis.com',
                'http_errors' => false,
            ];
        } else {
            throw new LogicException('Could not find supported version of Guzzle.');
        }

        $options = array_merge($options, $_options);

        return new GuzzleClient($options);
    }
}
