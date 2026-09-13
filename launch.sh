#!/usr/bin/env bash

set -u

echo "=============================================="
echo " Oracle Always Free A1 Capacity Grabber"
echo "=============================================="
echo

REGION="${OCI_REGION:-ap-singapore-1}"
AD="${OCI_AD:-uNAn:AP-SINGAPORE-1-AD-1}"

SHAPE="VM.Standard.A1.Flex"
OCPUS=2
MEMORY_GB=12
DISPLAY_NAME="Minecraft-Server-FreeTier"

OCI_DIR="${RUNNER_TEMP}/oci"
CONFIG_FILE="${OCI_DIR}/config"
KEY_FILE="${OCI_DIR}/oci_api_key.pem"
PUBLIC_KEY_FILE="${OCI_DIR}/minecraft_authorized_key.pub"

mkdir -p "$OCI_DIR"

echo "[1/6] Checking secrets..."

for VAR in OCI_USER_ID OCI_TENANCY_ID OCI_FINGERPRINT OCI_PRIVATE_KEY OCI_SUBNET_ID
do
    if [ -z "${!VAR:-}" ]; then
        echo "ERROR: Missing GitHub secret: $VAR"
        exit 1
    fi
done

echo "All required secrets are present."
echo

echo "[2/6] Preparing OCI API key..."

printf '%s\n' "$OCI_PRIVATE_KEY" > "$KEY_FILE"
chmod 600 "$KEY_FILE"

if ! openssl pkey -in "$KEY_FILE" -noout >/dev/null 2>&1; then
    echo "ERROR: OCI_PRIVATE_KEY is not a valid private key."
    exit 1
fi

echo "OCI API key is valid."
echo

echo "[3/6] Preparing SSH key..."

if ! ssh-keygen -y -f "$KEY_FILE" > "$PUBLIC_KEY_FILE" 2>/dev/null; then
    echo "ERROR: Could not convert OCI_PRIVATE_KEY into an SSH public key."
    echo
    echo "The OCI API key and SSH key may be different keys."
    exit 1
fi

chmod 600 "$PUBLIC_KEY_FILE"

echo "SSH public key prepared."
echo

echo "[4/6] Creating OCI CLI configuration..."

cat > "$CONFIG_FILE" <<EOF
[DEFAULT]
user=${OCI_USER_ID}
fingerprint=${OCI_FINGERPRINT}
tenancy=${OCI_TENANCY_ID}
region=${REGION}
key_file=${KEY_FILE}
EOF

chmod 600 "$CONFIG_FILE"

export OCI_CLI_CONFIG_FILE="$CONFIG_FILE"

echo "Testing OCI authentication..."

if ! oci iam region list \
    --config-file "$CONFIG_FILE" \
    >/dev/null 2>&1
then
    echo "ERROR: OCI authentication failed."
    exit 1
fi

echo "OCI authentication successful."
echo

echo "[5/6] Checking for an existing Minecraft instance..."

INSTANCE_COUNT=$(
    oci compute instance list \
        --compartment-id "$OCI_TENANCY_ID" \
        --availability-domain "$AD" \
        --display-name "$DISPLAY_NAME" \
        --all \
        --query 'length(data)' \
        --raw-output \
        --config-file "$CONFIG_FILE" \
        2>/dev/null
)

if [ -z "$INSTANCE_COUNT" ]; then
    INSTANCE_COUNT=0
fi

if [ "$INSTANCE_COUNT" -gt 0 ]; then
    echo
    echo "=============================================="
    echo " INSTANCE ALREADY EXISTS"
    echo "=============================================="
    echo
    echo "Minecraft-Server-FreeTier already exists."
    exit 0
fi

echo "No existing Minecraft instance found."
echo

echo "[6/6] Finding an Ubuntu ARM64 image..."

IMAGE_ID=$(
    oci compute image list \
        --compartment-id "$OCI_TENANCY_ID" \
        --operating-system "Canonical Ubuntu" \
        --operating-system-version "22.04" \
        --shape "$SHAPE" \
        --sort-by TIMECREATED \
        --sort-order DESC \
        --limit 1 \
        --query 'data[0].id' \
        --raw-output \
        --config-file "$CONFIG_FILE" \
        2>/dev/null
)

if [ -z "$IMAGE_ID" ] || [ "$IMAGE_ID" = "null" ]; then

    echo "Ubuntu 22.04 image not found."
    echo "Trying Ubuntu 24.04..."

    IMAGE_ID=$(
        oci compute image list \
            --compartment-id "$OCI_TENANCY_ID" \
            --operating-system "Canonical Ubuntu" \
            --operating-system-version "24.04" \
            --shape "$SHAPE" \
            --sort-by TIMECREATED \
            --sort-order DESC \
            --limit 1 \
            --query 'data[0].id' \
            --raw-output \
            --config-file "$CONFIG_FILE" \
            2>/dev/null
    )
fi

if [ -z "$IMAGE_ID" ] || [ "$IMAGE_ID" = "null" ]; then
    echo
    echo "ERROR: Could not find a compatible Ubuntu ARM64 image."
    exit 1
fi

echo "Ubuntu image found."
echo

echo "=============================================="
echo " ATTEMPTING TO GRAB A1 CAPACITY"
echo "=============================================="
echo
echo "Region:    $REGION"
echo "AD:        $AD"
echo "Shape:     $SHAPE"
echo "OCPUs:     $OCPUS"
echo "Memory:    ${MEMORY_GB} GB"
echo

set +e

OUTPUT=$(
    oci compute instance launch \
        --availability-domain "$AD" \
        --compartment-id "$OCI_TENANCY_ID" \
        --shape "$SHAPE" \
        --shape-config "{\"ocpus\":${OCPUS},\"memoryInGBs\":${MEMORY_GB}}" \
        --image-id "$IMAGE_ID" \
        --subnet-id "$OCI_SUBNET_ID" \
        --display-name "$DISPLAY_NAME" \
        --assign-public-ip true \
        --ssh-authorized-keys-file "$PUBLIC_KEY_FILE" \
        --config-file "$CONFIG_FILE" \
        2>&1
)

EXIT_CODE=$?

set -e

echo "$OUTPUT"
echo

if [ "$EXIT_CODE" -eq 0 ]; then
    echo
    echo "=============================================="
    echo " 🔥🔥🔥 CAPACITY GRABBED 🔥🔥🔥"
    echo "=============================================="
    echo
    echo "Minecraft server successfully created!"
    echo
    exit 0
fi

if echo "$OUTPUT" | grep -qiE "out of host capacity|out of capacity|out.of.host.capacity"; then
    echo
    echo "=============================================="
    echo " NO A1 CAPACITY"
    echo "=============================================="
    echo
    echo "Oracle is currently out of A1 capacity."
    echo "The next scheduled run will try again."
    echo
    exit 0
fi

if echo "$OUTPUT" | grep -qiE "LimitExceeded|service limits|quota|limit exceeded"; then
    echo
    echo "=============================================="
    echo " OCI SERVICE LIMIT"
    echo "=============================================="
    echo
    echo "Oracle rejected the request because of a quota/limit."
    echo
    exit 1
fi

echo
echo "=============================================="
echo " UNEXPECTED OCI ERROR"
echo "=============================================="
echo
echo "$OUTPUT"
echo

exit "$EXIT_CODE"
