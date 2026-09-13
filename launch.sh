```bash
#!/usr/bin/env bash

set -u

echo "=============================================="
echo " Oracle Always Free A1 Capacity Grabber"
echo "=============================================="
echo

# ==================================================
# YOUR FREE INSTANCE TARGET
# ==================================================

REGION="${OCI_REGION:-ap-singapore-1}"
AD="${OCI_AD:-uNAn:AP-SINGAPORE-1-AD-1}"

SHAPE="VM.Standard.A1.Flex"

# Oracle's current Always Free A1 allocation
OCPUS=2
MEMORY_GB=12

DISPLAY_NAME="Minecraft-Server-FreeTier"

# ==================================================
# Temporary OCI files
# ==================================================

OCI_DIR="${RUNNER_TEMP}/oci"
CONFIG_FILE="${OCI_DIR}/config"
KEY_FILE="${OCI_DIR}/oci_api_key.pem"
PUBLIC_KEY_FILE="${OCI_DIR}/minecraft_authorized_key.pub"

mkdir -p "$OCI_DIR"

# ==================================================
# Check secrets
# ==================================================

for VAR in \
  OCI_USER_ID \
  OCI_TENANCY_ID \
  OCI_FINGERPRINT \
  OCI_PRIVATE_KEY \
  OCI_SUBNET_ID
do
  if [ -z "${!VAR:-}" ]; then
    echo "ERROR: Missing GitHub secret: $VAR"
    exit 1
  fi
done

# ==================================================
# Write OCI private key
# ==================================================

printf '%s\n' "$OCI_PRIVATE_KEY" > "$KEY_FILE"
chmod 600 "$KEY_FILE"

# ==================================================
# Generate the SSH public key from the private key
# ==================================================

echo "[1/5] Preparing SSH key..."

if ! ssh-keygen -y -f "$KEY_FILE" > "$PUBLIC_KEY_FILE" 2>/dev/null; then
  echo "ERROR: Could not read OCI_PRIVATE_KEY as an SSH private key."
  exit 1
fi

chmod 600 "$PUBLIC_KEY_FILE"

echo "SSH key OK."
echo

# ==================================================
# Create OCI CLI config
# ==================================================

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

# ==================================================
# Test authentication
# ==================================================

echo "[2/5] Testing OCI authentication..."

if ! oci iam region list \
    --config-file "$CONFIG_FILE" \
    >/dev/null 2>&1
then
  echo "ERROR: OCI authentication failed."
  exit 1
fi

echo "Authentication successful."
echo

# ==================================================
# Check whether we already created the server
# ==================================================

echo "[3/5] Checking for existing Minecraft instance..."

INSTANCE_COUNT=$(
  oci compute instance list \
    --compartment-id "$OCI_TENANCY_ID" \
    --availability-domain "$AD" \
    --display-name "$DISPLAY_NAME" \
    --all \
    --query 'length(data)' \
    --raw-output \
    --config-file "$CONFIG_FILE" \
    2>/dev/null || echo "0"
)

if [ "$INSTANCE_COUNT" -gt 0 ]; then

  echo
  echo "=============================================="
  echo " INSTANCE ALREADY EXISTS"
  echo "=============================================="
  echo
  echo "No further grab attempt is necessary."

  exit 0
fi

echo "No existing instance."
echo

# ==================================================
# Find the newest Ubuntu ARM64 image
# ==================================================

echo "[4/5] Finding Ubuntu ARM64 image..."

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

# Fallback to Ubuntu 24.04
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
  echo "ERROR: No compatible Ubuntu ARM64 image found."
  exit 1
fi

echo "Image found."
echo

# ==================================================
# ACTUAL CAPACITY GRAB
# ==================================================

echo "[5/5] ATTEMPTING TO LAUNCH INSTANCE..."
echo
echo "Shape:     $SHAPE"
echo "OCPUs:     $OCPUS"
echo "Memory:    ${MEMORY_GB} GB"
echo "Region:    $REGION"
echo "AD:        $AD"
echo
echo "=============================================="
echo " ATTACKING ORACLE CAPACITY..."
echo "=============================================="
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

# ==================================================
# SUCCESS
# ==================================================

if [ "$EXIT_CODE" -eq 0 ]; then

  echo
  echo "=============================================="
  echo " 🔥🔥🔥 CAPACITY GRABBED 🔥🔥🔥"
  echo "=============================================="
  echo
  echo "Minecraft server successfully created!"
  echo
  echo "Go to:"
  echo "Oracle Cloud → Compute → Instances"
  echo
  exit 0
fi

# ==================================================
# HOST CAPACITY
# ==================================================

if echo "$OUTPUT" | grep -qiE \
  "out of host capacity|out of capacity|capacity"; then

  echo
  echo "=============================================="
  echo " NO A1 CAPACITY"
  echo "=============================================="
  echo
  echo "Oracle is currently out of VM.Standard.A1.Flex"
  echo "capacity in the selected availability domain."
  echo
  echo "The next GitHub Actions run will try again."
  echo

  exit 0
fi

# ==================================================
# QUOTA / LIMIT
# ==================================================

if echo "$OUTPUT" | grep -qiE \
  "LimitExceeded|service limits|quota|limit exceeded"; then

  echo
  echo "=============================================="
  echo " OCI SERVICE LIMIT"
  echo "=============================================="
  echo
  echo "Oracle rejected the request because the tenancy"
  echo "does not currently have enough A1 quota."
  echo
  exit 1
fi

# ==================================================
# OTHER ERROR
# ==================================================

echo
echo "=============================================="
echo " UNEXPECTED OCI ERROR"
echo "=============================================="
echo
echo "$OUTPUT"
echo

exit "$EXIT_CODE"
```
