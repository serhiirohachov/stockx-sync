<?php

$apiToken = 'l4wPC0phmnox6CemBHR6c82gPq6mABpWtp6HLTHszCBoFJ7dqYL1uoZxFGOwa6mt';
$floatingIpId = '88534354'; // ID for 5.161.23.124
if (empty($serverIds)) {
    die("❌ No server IDs provided in \$serverIds\n");
}

$targetServerId = (int) $serverIds[array_rand($serverIds)];
if ($targetServerId <= 0) {
    die("❌ Invalid server ID selected\n");
}

$ch = curl_init("https://api.hetzner.cloud/v1/floating_ips/{$floatingIpId}/actions/assign");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $apiToken",
        "Content-Type: application/json",
    ],
    CURLOPT_POSTFIELDS => json_encode(['server' => (int)$targetServerId]),
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($httpCode === 201) {
    echo "✅ Floating IP {$floatingIpId} assigned to server {$targetServerId} successfully.\n";
} else {
    echo "❌ Failed to assign Floating IP. Response:\n$response\n";
}

curl_close($ch);
