<?php

// Forcefully scrub away hidden line breaks from your secrets
$userId = trim(getenv('OCI_USER_ID'));
$tenancyId = trim(getenv('OCI_TENANCY_ID'));
$region = 'ap-singapore-1'; 
$fingerprint = trim(getenv('OCI_FINGERPRINT'));
$privateKey = trim(getenv('OCI_PRIVATE_KEY'));
$subnetId = trim(getenv('OCI_SUBNET_ID'));

$baseUrl = "https://iaas.{$region}://";
$dateStr = gmdate('D, d M Y H:i:s \G\M\T');

// Define the exact 4 OCPU and 24 GB RAM Minecraft machine setup
$body = [
    'compartmentId'      => $tenancyId,
    'availabilityDomain' => 'uNAn:AP-SINGAPORE-1-AD-1',
    'displayName'        => 'Minecraft-Server-FreeTier',
    'shape'              => 'VM.Standard.A1.Flex',
    'subnetId'           => $subnetId,
    'shapeConfig'        => [
        'ocpus' => 4,
        'memoryInGBs' => 24
    ]
];

$jsonBody = json_encode($body);
$sha256Hash = base64_encode(hash('sha256', $jsonBody, true));

// Build the request address path for signing signatures
$uriPath = "/20160918/instances/";
$signingText = "(request-target): post\ndate: {$dateStr}\nx-content-sha256: {$sha256Hash}";

// Sign the request using your secure private .pem key string
$pkeyId = openssl_pkey_get_private($privateKey);
if (!$pkeyId) {
    echo "Error: Your OCI_PRIVATE_KEY secret text format is invalid or broken.\n";
    exit(1);
}

openssl_sign($signingText, $signature, $pkeyId, OPENSSL_ALGO_SHA256);
openssl_free_key($pkeyId);
$base64Signature = base64_encode($signature);

// Build Oracle's exact security identification header layout
$keyId = "{$tenancyId}/{$userId}/{$fingerprint}";
$authHeader = "Signature version=\"1\",keyId=\"{$keyId}\",algorithm=\"rsa-sha256\",headers=\"(request-target) date x-content-sha256\",signature=\"{$base64Signature}\"";

// Assemble all communication headers securely
$headers = [
    "Authorization: {$authHeader}",
    "Date: {$dateStr}",
    "x-content-sha256: {$sha256Hash}",
    "Content-Type: application/json"
];

echo "Targeting Location Domain: uNAn:AP-SINGAPORE-1-AD-1\n";

// Execute direct curl request into Oracle Cloud endpoint nodes
$ch = curl_init($baseUrl);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$res = json_decode($response, true) ?: [];

// Process Oracle Cloud's server architecture responses
if ($httpCode === 200 || $httpCode === 201) {
    echo "SUCCESS! Your Free Tier Minecraft server has been provisioned! Check your Oracle Cloud Dashboard!\n";
    exit(0);
}

if (isset($res['code']) && $res['code'] === 'LimitExceeded') {
    echo "Error: Limit Exceeded. You have already claimed your Free Tier server capacity quota.\n";
    exit(1);
}

if (isset($res['message'])) {
    echo "Oracle Cloud Response: " . $res['message'] . "\n";
    if (strpos($res['message'], 'Out of host capacity') !== false) {
        echo "Script successfully pinged Oracle. No slots open right now. Retrying in 5 minutes via cron loop...\n'';
        exit(0);
    }
    exit(1);
}

echo "Oracle API returned HTTP Code {$httpCode}: " . print_r($res, true) . "\n";
