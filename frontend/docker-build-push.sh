#!/bin/bash
# Build, tag si push imagine de PROD pentru Validez FE (React -> nginx), servita pe https://validez.webnou.ro
# Folosire, din frontend/:
#   ./docker-build-push.sh                    (suffix = data+ora)
#   ./docker-build-push.sh 20261003_01
set -e

REGISTRY="malutanpetronel/api"
DATE=$(date +%Y%m%d_%H%M)
SUFFIX=${1:-$DATE}
TAG="validez_fe_${SUFFIX}"
FULL_TAG="${REGISTRY}:${TAG}"
# Domeniul BE - de confirmat
API_BASE="https://validez-be.webnou.ro"

if [ -f src/version.json ]; then
  echo "=> Incrementez ultimul segment din src/version.json"
  python3 -c "
import json
with open('src/version.json') as f:
    data = json.load(f)
parts = data['version'].split('.')
parts[-1] = str(int(parts[-1]) + 1)
data['version'] = '.'.join(parts)
with open('src/version.json', 'w') as f:
    json.dump(data, f, indent=2)
    f.write('\n')
print(f\"  -> versiune noua: {data['version']}\")
"
fi

echo "=> Building: ${FULL_TAG}  (REACT_APP_API_BASE=${API_BASE})"
docker build \
  --build-arg REACT_APP_API_BASE=${API_BASE} \
  -f Dockerfile.prod \
  -t ${FULL_TAG} .

echo "=> Pushing: ${FULL_TAG}"
docker push ${FULL_TAG}

echo ""
echo "Gata. Pune pe server in /var/www/validez-fe/shared/frontend/.env:"
echo "   DOCKER_IMAGE=${FULL_TAG}"
