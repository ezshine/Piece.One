<?php
/**
 * getbscassets.php - Proxy for BscScan Token Holdings
 * 
 * Supports filtering to only return standard/safe tokens (no tax tokens)
 */

require_once __DIR__ . '/../../vendor/autoload.php';

try {

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['errcode' => 1, 'errmsg' => 'Only POST method is allowed']);
    return;
}

// Standard tokens whitelist (known safe tokens without transfer restrictions)
// These are well-known stablecoins and major tokens on BSC
$STANDARD_TOKENS = [
    '0x55d398326f99059ff775485246999027b3197955' => 'USDT',    // Binance-Peg BSC-USD
    '0x8ac76a51cc950d9822d68b83fe1ad97b32cd580d' => 'USDC',    // Binance-Peg USD Coin
    '0xe9e7cea3dedca5984780bafc599bd69add087d56' => 'BUSD',    // Binance USD
    '0x2170ed0880ac9a755fd29b2688956bd959f933f8' => 'ETH',     // Binance-Peg Ethereum
    '0x7130d2a12b9bcbfae4f2634d864a1ee1ce3ead9c' => 'BTCB',    // Binance-Peg BTCB
    '0xbb4cdb9cbd36b01bd1cbaebf2de08d9173bc095c' => 'WBNB',    // Wrapped BNB
    '0x1af3f329e8be154074d8769d1ffa4ee058b1dbc3' => 'DAI',     // Binance-Peg Dai
    '0x8d0d000ee44948fc98c9b98a4fa4921476f08b0d' => 'USD1',    // USD1 Stablecoin
    // Add more standard tokens as needed
];

// Get input
$input = '';
if (isset($GLOBALS['request'])) {
    $request = $GLOBALS['request'];
    if (method_exists($request, 'rawBody')) {
        $input = $request->rawBody();
    } elseif (method_exists($request, 'body')) {
        $input = $request->body();
    }
}
if (empty($input)) {
    $input = file_get_contents('php://input');
}

$data = json_decode($input, true);
$address = isset($data['address']) ? $data['address'] : '';
$onlyStandard = isset($data['onlyStandard']) ? (bool)$data['onlyStandard'] : true; // Default to only standard tokens

if (empty($address) || !preg_match('/^0x[a-fA-F0-9]{40}$/', $address)) {
    echo json_encode(['errcode' => 2, 'errmsg' => 'Invalid address']);
    return;
}

$url = 'https://bscscan.com/address-token-holding.aspx/GetAssetDetails';

$headers = [
    'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'Content-Type: application/json',
    'Origin: https://bscscan.com',
    'Referer: https://bscscan.com/address-token-holding?a=' . $address,
    'Accept: application/json, text/javascript, */*; q=0.01',
    'X-Requested-With: XMLHttpRequest',
    // Minimal headers to mimic browser
];

// Construct payload typically sent by BscScan frontend
$payload = [
    'dataTableModel' => [
        'draw' => 1,
        'columns' => [
            ['data' => 'TokenName', 'name' => '', 'searchable' => true, 'orderable' => true, 'search' => ['value' => '', 'regex' => false]],
            ['data' => 'ContractAddress', 'name' => '', 'searchable' => true, 'orderable' => false, 'search' => ['value' => '', 'regex' => false]],
            ['data' => 'Price', 'name' => '', 'searchable' => true, 'orderable' => true, 'search' => ['value' => '', 'regex' => false]],
            ['data' => 'Change24H', 'name' => '', 'searchable' => true, 'orderable' => false, 'search' => ['value' => '', 'regex' => false]],
            ['data' => 'Balance', 'name' => '', 'searchable' => true, 'orderable' => true, 'search' => ['value' => '', 'regex' => false]],
            ['data' => 'Value', 'name' => '', 'searchable' => true, 'orderable' => true, 'search' => ['value' => '', 'regex' => false]],
            ['data' => 'More', 'name' => '', 'searchable' => true, 'orderable' => false, 'search' => ['value' => '', 'regex' => false]]
        ],
        'order' => [['column' => 5, 'dir' => 'desc']], // Sort by Value desc
        'start' => 0,
        'length' => 50, // Get top 50
        'search' => ['value' => '', 'regex' => false]
    ],
    'model' => [
        'address' => $address,
        'hideZeroAssets' => false,
        'filteredContract' => '',
        'showEthPrice' => false
    ]
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Simplify for dev, caution in prod

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($response === false) {
    echo json_encode(['errcode' => 500, 'errmsg' => 'Request failed: ' . $error]);
    return;
}

if ($httpCode !== 200) {
    echo json_encode(['errcode' => $httpCode, 'errmsg' => 'Upstream returned ' . $httpCode]);
    return;
}

// BscScan sometimes returns "d" wrapper (ASP.NET thing) or just JSON
$json = json_decode($response, true);
if (isset($json['d'])) {
    $rawList = $json['d']['data'] ?? [];
    $simplifiedList = [];

    foreach ($rawList as $item) {
        $contract = '';
        $symbol = '';
        $decimals = 18;
        $balance = 0;

        // 1. Try to extract from 'More' column (addToWallet JS call) - Most reliable
        if (isset($item['More']) && preg_match("/addToWallet\s*\(\s*'[^']+'\s*,\s*'([^']+)'\s*,\s*'([^']+)'\s*,\s*'([^']+)'/", $item['More'], $matches)) {
            $contract = $matches[1];
            $symbol = $matches[2];
            $decimals = (int)$matches[3];
        }

        // 2. Fallback for Contract Address
        if (empty($contract) && isset($item['ContractAddress'])) {
            if (preg_match('/0x[a-fA-F0-9]{40}/', $item['ContractAddress'], $matches)) {
                $contract = $matches[0];
            }
        }

        // Skip if no contract address (native BNB usually doesn't have one here or handled differently)
        if (empty($contract)) continue;

        // 3. Fallback for Symbol
        if (empty($symbol)) {
            if (isset($item['Symbol'])) {
                $symbol = trim(strip_tags($item['Symbol']));
            }
            if ((empty($symbol) || strpos($symbol, '...') !== false) && isset($item['TokenName'])) {
                $tokenName = trim(strip_tags($item['TokenName']));
                // Try extract "BEP-20: SYMBOL"
                if (preg_match('/BEP-20:\s*([^\s]+)/', $tokenName, $matches)) {
                    $symbol = $matches[1];
                }
            }
        }
        
        $symbol = trim(str_replace('...', '', $symbol));
        if (empty($symbol)) $symbol = 'UNKNOWN';

        // 4. Balance
        if (isset($item['Balance'])) {
            $balance = str_replace(',', '', $item['Balance']);
        }

        // 5. Filter by whitelist if onlyStandard is enabled
        $contractLower = strtolower($contract);
        $isStandard = isset($STANDARD_TOKENS[$contractLower]);
        
        if ($onlyStandard && !$isStandard) {
            continue; // Skip non-standard tokens
        }

        $simplifiedList[] = [
            'symbol' => $symbol,
            'balance' => $balance,
            'contract' => $contract,
            'decimals' => $decimals,
            'isStandard' => $isStandard
        ];
    }

    echo json_encode(['errcode' => 0, 'data' => $simplifiedList]);
} else {
    echo json_encode(['errcode' => 0, 'data' => []]);
}

} catch (Throwable $e) {
    echo json_encode([
        'errcode' => 1,
        'errmsg' => $e->getMessage(),
        'data' => null
    ]);
}
