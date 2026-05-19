<?php

require_once __DIR__ . '/vendor/autoload.php';

use Hitrov\OciApi;
use Hitrov\OciConfig;

// FORCEFULLY DEACTIVATE THE BROKEN HITROV VALIDATOR SYSTEM VIA THE RUNTIME CONTEXT
// This patches the URL validation crash so the script can communicate directly with Oracle
class SignerUrlBypass extends \Hitrov\OCI\Signer {
    protected function validateParameters(): void {
        // By leaving this completely empty, we forcefully skip the broken filter_var check entirely!
    }
}

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
    protected function call(string $method, string $url, string $body = '', array $headers = []): array {
        $signer = new SignerUrlBypass(
            $this->config->getUserId(),
            $this->config->getTenancyId(),
            $this->config->getRegion(),
            $this->config->getFingerprint(),
            $this->config->getPrivateKey()
        );
        $headers = array_merge($headers, $signer->getHeaders($url, $method, $body));
        return $this->client->call($method, $url, $body, $headers);
    }
};

$shape = 'VM.Standard.A1.Flex';
$ocpus = 4;
$memoryInGBs = 24;

// Use Singapore's main availability domain directly
$availabilityDomain = 'uNAn:AP-SINGAPORE-1-AD-1'; 

echo "Targeting Location Domain: " . $availabilityDomain . "\n";

// Request instance generation sequence securely
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
