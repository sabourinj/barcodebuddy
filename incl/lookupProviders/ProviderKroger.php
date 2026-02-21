<?php

require_once __DIR__ . "/../api.inc.php";

class ProviderKroger extends LookupProvider {

    private $token = null;

    function __construct(string $apiKey = null) {
        parent::__construct($apiKey);
        $this->providerName      = "Kroger";
        $this->providerConfigKey = "LOOKUP_USE_KROGER";
    }

    /**
     * Looks up a barcode
     * @param string $barcode The barcode to lookup
     * @return array|null Name of product, null if none found
     */
    public function lookupBarcode(string $barcode): ?array {
        if (!$this->isProviderEnabled())
            return null;

        // Ensure we have an API key configured (format: CLIENT_ID:CLIENT_SECRET)
        if (empty($this->apiKey) || strpos($this->apiKey, ':') === false) {
            error_log("KrogerProvider: API Key must be formatted as CLIENT_ID:CLIENT_SECRET in the Web UI.");
            return null;
        }

        // Get the OAuth2 token
        $token = $this->getAccessToken();
        if (!$token) {
            return null; 
        }

        // Kroger expects 13-digit UPCs, so we pad it
        $paddedBarcode = str_pad($barcode, 13, '0', STR_PAD_LEFT);
        $url = 'https://api.kroger.com/v1/products?filter.term=' . urlencode($paddedBarcode);

        // We use cURL here instead of $this->execute() to pass the custom Bearer Token
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Authorization: Bearer ' . $token
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            
            if (!empty($data['data']) && count($data['data']) > 0) {
                // Grab the description of the first matched product
                $productName = $data['data'][0]['description'] ?? null;
                
                if ($productName) {
                    // Use BarcodeBuddy's native return helper
                    return self::createReturnArray(sanitizeString($productName));
                }
            }
        }

        return null;
    }

    /**
     * Authenticates with Kroger and returns a Bearer Token.
     */
    private function getAccessToken(): ?string {
        if ($this->token !== null) {
            return $this->token;
        }

        // Split the API key from BarcodeBuddy's UI settings into ID and Secret
        list($clientId, $clientSecret) = explode(':', $this->apiKey, 2);
        
        // Kroger requires the basic auth credentials to be base64 encoded
        $credentials = base64_encode(trim($clientId) . ':' . trim($clientSecret));

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.kroger.com/v1/connect/oauth2/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials&scope=product.compact');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . $credentials
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (isset($data['access_token'])) {
                $this->token = $data['access_token'];
                return $this->token;
            }
        }

        return null;
    }
}
