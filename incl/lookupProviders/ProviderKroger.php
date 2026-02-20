<?php

if (!defined('IS_included')) {
    exit();
}

/**
 * Provider for Kroger API
 * * SETUP INSTRUCTION:
 * In the Barcode Buddy UI settings for this provider, enter your credentials
 * in the following format:
 * CLIENT_ID:CLIENT_SECRET
 * * (Separate the two values with a single colon and no spaces)
 */
class ProviderKroger implements ILookupProvider
{
    // API Endpoints
    private $tokenUrl = 'https://api.kroger.com/v1/connect/oauth2/token';
    private $productUrl = 'https://api.kroger.com/v1/products';

    /**
     * Main lookup function called by BarcodeBuddy
     */
    public function lookup($barcode)
    {
        // 1. Retrieve Credentials from Barcode Buddy Settings
        // We expect the user to input "ClientID:ClientSecret" in the UI key field.
        $configString = getSetting('LOOKUP_PROVIDER_KROGER_KEY');

        if (empty($configString) || strpos($configString, ':') === false) {
            error_log("ProviderKroger: Invalid or missing API credentials. Format must be ClientID:ClientSecret");
            return false;
        }

        list($clientId, $clientSecret) = explode(':', $configString, 2);
        
        // Trim whitespace just in case user added spaces
        $clientId = trim($clientId);
        $clientSecret = trim($clientSecret);

        // 2. Authenticate and get Access Token
        $accessToken = $this->getAccessToken($clientId, $clientSecret);
        
        if (!$accessToken) {
            error_log("ProviderKroger: Failed to retrieve access token.");
            return false;
        }

        // 3. Lookup Product by UPC
        $url = $this->productUrl . '?filter.productId=' . $barcode;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Authorization: Bearer ' . $accessToken
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            // Only log errors if it's not a 404 (product not found)
            if ($httpCode !== 404) {
                error_log("ProviderKroger: API request failed with code $httpCode");
            }
            return false;
        }

        $data = json_decode($response, true);

        // 4. Parse Response
        if (!empty($data['data'])) {
            $product = $data['data'][0];
            
            $name = isset($product['description']) ? $product['description'] : 'Unknown Product';
            if (isset($product['brand'])) {
                $name = $product['brand'] . ' - ' . $name;
            }
            
            // Image parsing logic
            $imageUrl = '';
            if (!empty($product['images'])) {
                foreach ($product['images'] as $imgObj) {
                    $perspective = isset($imgObj['perspective']) ? $imgObj['perspective'] : '';
                    if ($perspective === 'front' && !empty($imgObj['sizes'])) {
                        foreach ($imgObj['sizes'] as $sizeObj) {
                            if ($sizeObj['size'] === 'medium' || $sizeObj['size'] === 'large') {
                                $imageUrl = $sizeObj['url'];
                                break 2;
                            }
                        }
                    }
                }
                // Fallback
                if (empty($imageUrl) && !empty($product['images'][0]['sizes'][0]['url'])) {
                    $imageUrl = $product['images'][0]['sizes'][0]['url'];
                }
            }

            return [
                'name' => $name,
                'description' => $name,
                'image' => $imageUrl
            ];
        }

        return false;
    }

    /**
     * Helper to get OAuth2 Access Token
     */
    private function getAccessToken($id, $secret)
    {
        // Simple file-based caching to prevent hitting Kroger rate limits (tokens last 30 mins)
        // We use the system temp directory or a specific cache location if available
        $cacheFile = sys_get_temp_dir() . '/bb_kroger_token_' . md5($id) . '.json';
        
        if (file_exists($cacheFile)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if ($cached && isset($cached['expires_at']) && time() < $cached['expires_at']) {
                return $cached['access_token'];
            }
        }

        $ch = curl_init();
        $credentials = base64_encode($id . ':' . $secret);
        
        $postData = http_build_query([
            'grant_type' => 'client_credentials',
            'scope'      => 'product.compact' 
        ]);

        curl_setopt($ch, CURLOPT_URL, $this->tokenUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . $credentials
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $json = json_decode($response, true);
            if (isset($json['access_token'])) {
                // Save to cache
                $json['expires_at'] = time() + ($json['expires_in'] - 60); // Expire 1 min early
                file_put_contents($cacheFile, json_encode($json));
                return $json['access_token'];
            }
        }

        return false;
    }
}
