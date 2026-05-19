<?php

require_once __DIR__ . '/vendor/autoload.php';

use Hitrov\OciApi;
use Hitrov\OciConfig;

// Get credentials from GitHub Environment
$userId = getenv('OCI_USER_ID');
$tenancyId = getenv('OCI_TENANCY_ID');
$region = getenv('OCI_REGION');
$fingerprint = getenv('OCI_FINGERPRINT');
$privateKey = getenv('OCI_PRIVATE_KEY');

// In Oracle Cloud Free Tier, your Compartment ID is the exact same code as your Tenancy ID
$compartmentId = $tenancyId; 

// We supply all 8 expected arguments to the constructor
$config = new OciConfig(
    $userId,
    $tenancyId,
    $region,
    $fingerprint,
    $privateKey,
    $compartmentId,
    '', // availabilityDomain (leave blank, script auto-detects)
    ''  // subnetId (passed directly below instead)
);

$api = new OciApi($config);

$shape = 'VM.Standard.A1.Flex';
$ocpus = 4;
$memoryInGBs = 24;

// Auto-detect availability domain for Singapore
$availabilityDomains = $api->getAvailabilityDomains();
if (empty($availabilityDomains)) {
    echo "Error: Could not retrieve availability domains. Double-check your Oracle keys.\n";
    exit(1);
}

// Select the first available domain block in Singapore
$availabilityDomain = is_array($availabilityDomains) && isset($availabilityDomains[0]['name']) 
    ? $availabilityDomains[0]['name'] 
    : $availabilityDomains['name'];

echo "Targeting Location Domain: " . $availabilityDomain . "\n";

// Request the Minecraft Instance creation
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
    echo "Error: Limit Exceeded. Check if you already have existing instances.\n";
    exit(1);
}

if (isset($res['message'])) {
    echo "Oracle Cloud Response: " . $res['message'] . "\n";
    if (strpos($res['message'], 'Out of host capacity') !== false) {
        echo "Script successfully pinged Oracle. No slots open right now. Retrying in 5 minutes via cron loop...\n";
        exit(0); 
    }
    exit(1);
}

echo "SUCCESS! Your Free Tier Minecraft server has been provisioned! Check your Oracle Cloud Dashboard!\n";
