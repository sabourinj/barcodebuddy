<?php
/**
 * Kroger Lookup Provider for Barcode Buddy
 * Place this file in: /var/www/html/incl/lookupProviders/Kroger.php
 */

class Kroger implements ISearchProvider
{
    // ================= CONFIGURATION =================
    private $clientId     = 'barcodebuddy-bbccz0y7';
    private $clientSecret = '9Nq5i50VZv45XayL0zgtI04aMXzoo5iqYGV8XhO_';
    // We store the token in the /data folder so it persists across container rebuilds
    private $tokenFile    = '/addon_configs/d44967b1_bbuddy-grocy/kroger_token.json'; 
    // =================================================

    public function getProviderName(): string
    {
        return 'Kroger API';
    }

    public function search($barcode): ?array
    {
        try {
            $token = $this->getAccessToken();
            if (!$token) {
                return null;
            }
            return $this->lookupProduct($barcode, $token);
        } catch (Exception $e) {
            // Log error if needed, or return null to let other providers try
            return null;
        }
    }

    private function getAccessToken()
    {
        if (file_exists($this->tokenFile)) {
            $cache = json_decode(file_get_contents($this->tokenFile), true);
            if ($cache && isset($cache['expires_at']) && time() < $cache['expires_at']) {
                return $cache['access_token'];
            }
        }

        $ch = curl_init('https://api.kroger.com/v1/connect/oauth2/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'client_credentials',
            'scope'      => 'product.compact'
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            'Content-Type: application/x-www-form-urlencoded'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        if ($httpCode !== 200 || !isset($data['access_token'])) {
            return null;
        }

        $data['expires_at'] = time() + $data['expires_in'] - 60;
        
        // Ensure /data exists (it should in the Docker container)
        if(is_dir('/data') && is_writable('/data')) {
            file_put_contents($this->tokenFile, json_encode($data));
        }

        return $data['access_token'];
    }

    private function lookupProduct($upc, $token)
    {
        $url = "https://api.kroger.com/v1/products?filter.term=" . urlencode($upc);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $token",
            "Accept: application/json"
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return null;
        }

        $json = json_decode($response, true);
        if (empty($json['data'])) {
            return null;
        }

        $item = $json['data'][0];
        $name = $item['description'];
        $brand = $item['brand'] ?? '';
        
        // Basic Name formatting
        $finalName = trim("$brand $name");

        return ['name' => $finalName];
    }
}
?>
