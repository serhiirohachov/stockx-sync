<?php

namespace StockXSync;

class CaptchaSolver {
    protected $apiKey;

    public function __construct(string $apiKey) {
        $this->apiKey = $apiKey;
    }

    public function solve_recaptcha_v2(string $siteKey, string $pageUrl): string {
        return $this->solve_generic('userrecaptcha', $siteKey, $pageUrl);
    }

    public function solve_hcaptcha(string $siteKey, string $pageUrl): string {
        return $this->solve_generic('hcaptcha', $siteKey, $pageUrl);
    }

    protected function solve_generic(string $method, string $siteKey, string $pageUrl): string {
        $submitUrl = "http://2captcha.com/in.php?" . http_build_query([
            'key'       => $this->apiKey,
            'method'    => $method,
            'googlekey' => $siteKey,
            'sitekey'   => $siteKey, // for hcaptcha compatibility
            'pageurl'   => $pageUrl,
            'json'      => 1
        ]);

        $response = file_get_contents($submitUrl);
        $result = json_decode($response, true);

        if (!isset($result['status']) || $result['status'] != 1) {
            throw new \Exception('2Captcha submit error: ' . ($result['request'] ?? 'Unknown error'));
        }

        $captchaId = $result['request'];
        $maxTries = 20;
        $waitSeconds = 5;

        for ($i = 0; $i < $maxTries; $i++) {
            sleep($waitSeconds);
            $pollUrl = "http://2captcha.com/res.php?" . http_build_query([
                'key'    => $this->apiKey,
                'action' => 'get',
                'id'     => $captchaId,
                'json'   => 1
            ]);

            $pollResponse = file_get_contents($pollUrl);
            $pollResult = json_decode($pollResponse, true);

            if (isset($pollResult['status']) && $pollResult['status'] == 1) {
                return $pollResult['request']; // Captcha token
            }
        }

        throw new \Exception('2Captcha polling timed out after ' . ($maxTries * $waitSeconds) . ' seconds.');
    }
}