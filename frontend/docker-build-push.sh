#!/bin/bash
# Build, tag si push imagine de PROD pentru Validez FE (React -> nginx), servita pe https://validez.webnou.ro
# Folosire, din frontend/:
#   ./docker-build-push.sh                    (suffix = data+ora)
#   ./docker-build-push.sh 20261003_01
set -e

# Ruleaza din directorul scriptului, indiferent de directorul din care este apelat.
SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
cd "$SCRIPT_DIR"
REPO_ROOT=$(git rev-parse --show-toplevel)

if [ "$(git branch --show-current)" != "main" ]; then
  echo "Eroare: ruleaza buildul FE de pe ramura main pentru commit si push origin main." >&2
  exit 1
fi

REGISTRY="malutanpetronel/api"
DATE=$(date +%Y%m%d_%H%M)
SUFFIX=${1:-$DATE}
TAG="validez_fe_${SUFFIX}"
FULL_TAG="${REGISTRY}:${TAG}"
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
  VERSION=$(python3 -c 'import json; print(json.load(open("src/version.json"))["version"])')
  echo "=> Commit versiune FE ${VERSION}"
  git -C "$REPO_ROOT" add -- frontend/src/version.json
  git -C "$REPO_ROOT" commit --only -m "chore: versiune FE ${VERSION}" -- frontend/src/version.json
  echo "=> Push versiune catre origin main"
  git -C "$REPO_ROOT" push origin main
fi

echo "=> Building: ${FULL_TAG}  (REACT_APP_API_BASE=${API_BASE})"
docker build \
  --build-arg REACT_APP_API_BASE=${API_BASE} \
  -f Dockerfile.prod \
  -t ${FULL_TAG} .

echo "=> Pushing: ${FULL_TAG}"
docker push ${FULL_TAG}

../docker/cleanup-old-tags.sh validez_fe_ "${TAG}" || true

echo ""
echo "Gata. Pune pe server in /var/www/validez-fe/shared/frontend/.env:"
echo "   DOCKER_IMAGE=${FULL_TAG}"
