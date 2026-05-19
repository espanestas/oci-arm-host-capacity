<?php

require_once __DIR__ . '/vendor/autoload.php';

use Hitrov\OciApi;
use Hitrov\OciConfig;

// Forcefully hardcode your clean region and tenancy details directly
$region = 'ap-singapore-1';
$tenancyId = 'ocid1.tenancy.oc1..aaaaaaa'; // Replace this temporary string with your real Tenancy OCID text
$userId = 'ocid1.user.oc1..aaaaaaa';       // Replace this temporary string with your real User OCID text
$fingerprint = 'aa:bb:cc:dd...';           // Replace this temporary string with your real Fingerprint text

// Fetch the private key and subnet from GitHub secrets since they are long/secure
$privateKey = trim(getenv('OCI_PRIVATE_KEY'));
$subnetId = trim(getenv('OCI_SUBNET_ID'));

$compartmentId = $tenancyId; 

// Initialize the configuration layout safely
$config = new OciConfig(
    $userId,
    $tenancyId,
    $region,
    $fingerprint,
    $privateKey,
    $compartmentId,
    '', 
    ''  
);

$api = new OciApi($config);

$shape = 'VM.Standard.A1.Flex';
$ocpus = 4;
$memoryInGBs = 24;

// Connect to Oracle to parse your region domains
$availabilityDomains = $api->getAvailabilityDomains($config);
if (empty($availabilityDomains)) {
    echo "Error: Could not retrieve availability domains. Check your key configurations.\n";
    exit(1);
}

if (isset($availabilityDomains['name'])) {
    $availabilityDomain = $availabilityDomains['name'];
} else {
    $availabilityDomain = is_array($availabilityDomains) ? current($availabilityDomains)['name'] : '';
}

echo "Targeting Location Domain: " . $availabilityDomain . "\n";

// Request the Minecraft Instance creation
$res = $api->createInstance(
    $subnetId,
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
