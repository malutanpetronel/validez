#!/bin/bash
# Build, tag si push imaginea PHP de PROD pentru Validez BE (php-fpm din Dockerfile).
# Imaginea contine doar runtime-ul; codul se monteaza din backend/ (vezi docker-compose.prod.yml).
# Folosire, din backend/:
#   ./docker-build-push.sh                    (suffix = data+ora)
#   ./docker-build-push.sh 20261003_01
set -e

REGISTRY="malutanpetronel/api"
DATE=$(date +%Y%m%d_%H%M)
SUFFIX=${1:-$DATE}
TAG="validez_be_${SUFFIX}"
FULL_TAG="${REGISTRY}:${TAG}"

echo "=> Building: ${FULL_TAG}"
# Dockerfile-ul nu copiaza fisiere din proiect: build fara context
# (altfel docker ar urca tot vendor/ ca build context).
docker build -t "${FULL_TAG}" - < Dockerfile

echo "=> Pushing: ${FULL_TAG}"
docker push "${FULL_TAG}"

echo ""
echo "Gata. Pune pe server in /var/www/validez-be/shared/backend/.env:"
echo "   DOCKER_IMAGE=${FULL_TAG}"
