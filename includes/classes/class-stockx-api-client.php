<?php
// class-stockx-api-client.php
namespace StockXSync;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

class StockXApiClient {

    /**
     * Повертає останню продажну ціну (Last Sale) в USD або кидає Exception
     */
   // class-stockx-api-client.php
public function get_price(string $slug, string $size = ''): float {
    if (empty($slug)) {
        throw new \InvalidArgumentException('StockX slug is empty');
    }

    $client = new Client([
        'base_uri' => 'https://stockx.com/',
        'headers'  => [
            'Accept'     => 'application/json',
            'User-Agent' => 'Mozilla/5.0',
        ],
    ]);

    $query = [
        'currency' => 'USD',
        'includes' => 'market',
    ];
    if ($size) {
        $query['size'] = $size;
    }

    try {
        // Замінили "/api/products/{$slug}/market" на "/api/products/{$slug}"
        $response = $client->get("api/products/{$slug}", [
            'query' => $query
        ]);
    } catch (ClientException $e) {
        $code = $e->getResponse()->getStatusCode();
        $body = (string) $e->getResponse()->getBody();
        throw new \RuntimeException("StockX API error {$code}: {$body}");
    }

    $data = json_decode($response->getBody(), true);
    if (empty($data['Product']['market']['lastSale'])) {
        throw new \RuntimeException('No lastSale field in API response');
    }

    return (float) $data['Product']['market']['lastSale'];
}

}
