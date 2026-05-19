<?php

require_once __DIR__ . '/vendor/autoload.php';

use Hitrov\OciApi;
use Hitrov\OciConfig;

$config = new OciConfig(
    getenv('OCI_USER_ID'),
    getenv('OCI_TENANCY_ID'),
    getenv('OCI_REGION'),
    getenv('OCI_FINGERPRINT'),
    getenv('OCI_PRIVATE_KEY')
);

$api = new OciApi($config);

$shape = 'VM.Standard.A1.Flex';
$ocpus = 4;
$memoryInGBs = 24;

$availabilityDomain = getenv('OCI_AVAILABILITY_DOMAIN');
if (!$availabilityDomain) {
    $availabilityDomains = $api->getAvailabilityDomains();
    if (empty($availabilityDomains)) {
        echo "Error: Could not retrieve availability domains. Check your credentials.\n";
        exit(1);
    }
    $availabilityDomain = $availabilityDomains[0]['name'];
}

echo "Attempting to create instance in domain: " . $availabilityDomain . "\n";

$res = $api->createInstance(
    getenv('OCI_SUBNET_ID'),
    'Minecraft-Server-FreeTier', 
    $shape,
    $availabilityDomain,
    [
        'ocpus' => $ocpus,
        'memoryInGBs' => $memoryInGBs,
    ]
);

if (empty($res)) {
    echo "Failed to communicate with Oracle API or empty response.\n";
    exit(1);
}

if (isset($res['code']) && $res['code'] === 'LimitExceeded') {
    echo "Error: Limit Exceeded. You might already have instances or maxed out resources.\n";
    exit(1);
}

if (isset($res['message'])) {
    echo "Oracle Response: " . $res['message'] . "\n";
    if (strpos($res['message'], 'Out of host capacity') !== false) {
        exit(0); // Mark as success so logs stay clean, it will retry in 5 mins
    }
    exit(1);
}

echo "SUCCESS! Instance created. Check your Oracle Cloud Dashboard!\n";
