<?php

// === Configuration ===
$apiToken = 'l4wPC0phmnox6CemBHR6c82gPq6mABpWtp6HLTHszCBoFJ7dqYL1uoZxFGOwa6mt';
$floatingIpId = '88534354';
$floatingIp = '5.161.23.124';
$serverIds = [63885427]; // Add more if needed
$maxRetries = 3;

// // === Telegram Configuration ===
// function sendTelegramMessage($message) {
//     $botToken = 'YOUR_BOT_TOKEN_HERE';
//     $chatId = 'YOUR_CHAT_ID_HERE';
//     $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
//     $data = ['chat_id' => $chatId, 'text' => $message];

//     $ch = curl_init($url);
//     curl_setopt_array($ch, [
//         CURLOPT_RETURNTRANSFER => true,
//         CURLOPT_POST => true,
//         CURLOPT_POSTFIELDS => $data,
//     ]);
//     curl_exec($ch);
//     curl_close($ch);
// }

// === External IP Check ===
function getExternalIp(): ?string {
    $ip = trim(@file_get_contents("https://api.ipify.org"));
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
}

// === Main IP Assignment Loop ===
$attempt = 1;
$success = false;

while ($attempt <= $maxRetries && !$success) {
    $targetServerId = (int) $serverIds[array_rand($serverIds)];

    echo "🔁 Attempt $attempt: assigning Floating IP to server $targetServerId...\n";

    $ch = curl_init("https://api.hetzner.cloud/v1/floating_ips/{$floatingIpId}/actions/assign");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $apiToken",
            "Content-Type: application/json",
        ],
        CURLOPT_POSTFIELDS => json_encode(['server' => $targetServerId]),
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    sleep(5); // Wait before checking IP

    $externalIp = getExternalIp();

    if ($httpCode === 201 && $externalIp === $floatingIp) {
        echo "✅ Floating IP assigned to server $targetServerId, external IP OK: $externalIp\n";
        sendTelegramMessage("✅ Floating IP {$floatingIp} assigned to server {$targetServerId} ✔ External IP confirmed.");
        $success = true;
        break;
    }

    echo "⚠️ IP mismatch or API failure (HTTP $httpCode, external IP = $externalIp). Retrying...\n";
    sendTelegramMessage("⚠️ Retry $attempt: Floating IP {$floatingIp} assigned to server {$targetServerId}, but external IP is {$externalIp} (expected {$floatingIp})");

    $attempt++;
    sleep(10);
}
