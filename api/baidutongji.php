<?php
header("Access-Control-Allow-Origin: *");

$apiKey = 'aGs7KPLYElUrI8SqzSG4YL9LGb6BBFm3';
$secretKey = 'ezswAvaBKiKgdxCck7YC7kFhsm7ROa6n';
$siteId = '18376956';
$code = '126e8607030f6e176496cd234a7e2f8e';

function getAccessToken($apiKey, $secretKey, $code) {
    $url = "https://openapi.baidu.com/oauth/2.0/token";
    $params = array(
        'grant_type' => 'authorization_code',
        'code' => $code,
        'client_id' => $apiKey,
        'client_secret' => $secretKey,
        'redirect_uri' => 'oob',
    );

    $query = http_build_query($params);
    $response = file_get_contents($url . '?' . $query);
    if ($response === false) {
        die('Error fetching access token.');
    }
    $data = json_decode($response, true);

    return $data;
}

function refreshAccessToken($apiKey, $secretKey, $refreshToken) {
    $url = "https://openapi.baidu.com/oauth/2.0/token";
    $params = array(
        'grant_type' => 'refresh_token',
        'refresh_token' => $refreshToken,
        'client_id' => $apiKey,
        'client_secret' => $secretKey,
    );

    $query = http_build_query($params);
    $response = file_get_contents($url . '?' . $query);
    if ($response === false) {
        die('Error refreshing access token.');
    }
    $data = json_decode($response, true);

    return $data;
}

function saveTokens($accessToken, $refreshToken) {
    file_put_contents('tokens.json', json_encode(array(
        'access_token' => $accessToken,
        'refresh_token' => $refreshToken,
    )));
}

function loadTokens() {
    if (!file_exists('tokens.json')) {
        return null;
    }
    return json_decode(file_get_contents('tokens.json'), true);
}

function checkAndRefreshTokens($apiKey, $secretKey) {
    $tokens = loadTokens();
    if ($tokens === null) {
        global $code;
        $tokens = getAccessToken($apiKey, $secretKey, $code);
        saveTokens($tokens['access_token'], $tokens['refresh_token']);
    } else {
        $tokens = refreshAccessToken($apiKey, $secretKey, $tokens['refresh_token']);
        saveTokens($tokens['access_token'], $tokens['refresh_token']);
    }
    return $tokens;
}

function getData($startDate, $endDate, $metrics, $accessToken, $siteId) {
    $url = "https://openapi.baidu.com/rest/2.0/tongji/report/getData";
    $params = array(
        'access_token' => $accessToken,
        'site_id' => $siteId,
        'method' => 'overview/getTimeTrendRpt',
        'start_date' => $startDate,
        'end_date' => $endDate,
        'metrics' => $metrics,
    );

    $query = http_build_query($params);
    $fullUrl = $url . '?' . $query;

    $response = file_get_contents($fullUrl);
    if ($response === false) {
        die('Error fetching data.');
    }
    return json_decode($response, true);
}

$cacheFile = 'data_cache.json';
$cacheTime = 60; 

$tokens = checkAndRefreshTokens($apiKey, $secretKey);
$accessToken = $tokens['access_token'];

if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTime)) {
    $data = json_decode(file_get_contents($cacheFile), true);
} else {

    $data = array(
        'today_uv' => null,
        'today_pv' => null,
        'yesterday_uv' => null,
        'yesterday_pv' => null,
        'last_month_pv' => null,
        'last_year_pv' => null,
    );

    $startDate = date('Ymd', strtotime('-31 days'));
    $endDate = date('Ymd');
    $monthData = getData($startDate, $endDate, 'pv_count,visitor_count', $accessToken, $siteId);

    $last31DaysPV = 0;
    if (isset($monthData['result']['items'][1])) {
        $dataPoints = $monthData['result']['items'][1];
        foreach ($dataPoints as $point) {
            $last31DaysPV += $point[0];
        }
    }

    $startDate = date('Ymd', strtotime('-1 year'));
    $endDate = date('Ymd');
    $yearData = getData($startDate, $endDate, 'pv_count,visitor_count', $accessToken, $siteId);

    if (isset($yearData['result']['items'][1])) {
        $dataPoints = $yearData['result']['items'][1];
        $today = date('Y/m/d');
        $yesterday = date('Y/m/d', strtotime('-1 day'));
        $lastMonth = date('Y/m/d', strtotime('-30 days'));
        
        foreach ($yearData['result']['items'][0] as $index => $date) {
            if ($date[0] == $today) {
                $data['today_uv'] = $dataPoints[$index][1];
                $data['today_pv'] = $dataPoints[$index][0];
            } elseif ($date[0] == $yesterday) {
                $data['yesterday_uv'] = $dataPoints[$index][1];
                $data['yesterday_pv'] = $dataPoints[$index][0];
            } elseif ($date[0] == $lastMonth) {
                $data['last_month_pv'] = $dataPoints[$index][0];
            }
        }
        
        $data['last_year_pv'] = array_sum(array_column($dataPoints, 0));
    }

    $data['last_month_pv'] = $last31DaysPV;

    file_put_contents($cacheFile, json_encode($data));
}

header('Content-Type: application/json');
echo json_encode($data);

?>
