<?php

require_once __DIR__ . '/vendor/autoload.php';

use Hitrov\OciApi;
use Hitrov\OciConfig;

// Get credentials from GitHub Environment and forcefully scrub away hidden line breaks
$userId = trim(getenv('OCI_USER_ID'));
$tenancyId = trim(getenv('OCI_TENANCY_ID'));
$region = 'ap-singapore-1'; 
$fingerprint = trim(getenv('OCI_FINGERPRINT'));
$privateKey = trim(getenv('OCI_PRIVATE_KEY'));

$compartmentId = $tenancyId; 

// Build configuration profile layout safely
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

// We attach our customized bypass layout straight into the core API client engine
$api = new class($config) extends OciApi {
    public function createInstance(OciConfig $config, string $subnetId, string $name, string $shape, string $availabilityDomain, array $shapeConfig = [], array $metadata = [], array $extendedParams = []): array {
        $compartmentId = $config->getCompartmentId() ?: $config->getTenancyId();
        $baseUrl = "https://iaas.{$config->getRegion()}://";
        
        $body = [
            'compartmentId'      => $compartmentId,
            'availabilityDomain' => $availabilityDomain,
            'displayName'        => $name,
            'shape'              => $shape,
            'subnetId'           => $subnetId,
            'shapeConfig'        => $shapeConfig,
        ];
        
        if (!empty($metadata)) {
            $body['metadata'] = $metadata;
        }

        $jsonBody = json_encode($body);

        // Sign the request without calling Hitrov's broken validator class
        $signer = new \Hitrov\OCI\Signer(
            $config->getUserId(),
            $config->getTenancyId(),
            $config->getRegion(),
            $config->getFingerprint(),
            $config->getPrivateKey()
        );
        
        // Pass the structural signature directly into the native curl network call
        $headers = $signer->getHeaders($baseUrl, 'POST', $jsonBody);
        $headers[] = 'Content-Type: application/json';

        $ch = curl_init($baseUrl);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true) ?: [];
    }
};

$shape = 'VM.Standard.A1.Flex';
$ocpus = 4;
$memoryInGBs = 24;

// Use Singapore's main availability domain directly
$availabilityDomain = 'uNAn:AP-SINGAPORE-1-AD-1'; 

echo "Targeting Location Domain: " . $availabilityDomain . "\n";

// Execute instance generation sequence securely
$res = $api->createInstance(
    $config,
    trim(getenv('OCI_SUBNET_ID')),
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
    echo "Error: Limit Exceeded. Check your existing instances.\n";
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
