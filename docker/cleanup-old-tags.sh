#!/bin/bash
# Sterge tagurile validez mai vechi de N zile din Docker Hub si local.
# Folosire: cleanup-old-tags.sh <prefix> <tag_curent> [zile=10] [pastreaza_minim=3]
# DRY_RUN=1 -> doar afiseaza.
# Tokenul Docker Hub (PAT cu Read, Write, Delete) se citeste din /home/petro/.docker-hub-token
set -u
PREFIX="$1"; CURRENT="$2"; DAYS="${3:-10}"; KEEP="${4:-3}"
REPO_NS="malutanpetronel"; REPO_NAME="api"
TOKEN_FILE="/home/petro/.docker-hub-token"
CUTOFF=$(date -d "-${DAYS} days" +%s)
run() { if [ "${DRY_RUN:-0}" = "1" ]; then echo "   [dry-run] $*"; else "$@"; fi; }

echo "=> Curatenie taguri ${PREFIX}* mai vechi de ${DAYS} zile (pastrez minim ${KEEP})"

# --- Docker Hub ---
if [ -f "$TOKEN_FILE" ]; then
  JWT=$(curl -s -H "Content-Type: application/json" \
    -d "{\"username\":\"${REPO_NS}\",\"password\":\"$(cat "$TOKEN_FILE")\"}" \
    https://hub.docker.com/v2/users/login/ | jq -r .token)
  if [ -n "$JWT" ] && [ "$JWT" != "null" ]; then
    URL="https://hub.docker.com/v2/repositories/${REPO_NS}/${REPO_NAME}/tags/?page_size=100&name=${PREFIX}"
    ALL=""
    while [ -n "$URL" ] && [ "$URL" != "null" ]; do
      PAGE=$(curl -s -H "Authorization: JWT ${JWT}" "$URL")
      ALL+=$(echo "$PAGE" | jq -r '.results[] | "\(.tag_last_pushed // .last_updated) \(.name)"')$'\n'
      URL=$(echo "$PAGE" | jq -r .next)
    done
    echo "$ALL" | grep "^[^ ]* ${PREFIX}" | sort -r | tail -n +$((KEEP+1)) | while read -r TS NAME; do
      [ "$NAME" = "$CURRENT" ] && continue
      [ "$(date -d "$TS" +%s)" -ge "$CUTOFF" ] && continue
      echo "   Hub: sterg ${NAME} (${TS})"
      run curl -s -o /dev/null -X DELETE -H "Authorization: JWT ${JWT}" \
        "https://hub.docker.com/v2/repositories/${REPO_NS}/${REPO_NAME}/tags/${NAME}/"
    done
  else
    echo "   Hub: login esuat, sar peste stergerea din Hub"
  fi
else
  echo "   Hub: lipseste ${TOKEN_FILE}, sar peste stergerea din Hub"
fi

# --- Local ---
docker images --format '{{.CreatedAt}}|{{.Repository}}:{{.Tag}}' "${REPO_NS}/${REPO_NAME}" \
  | grep "|${REPO_NS}/${REPO_NAME}:${PREFIX}" | sort -r | tail -n +$((KEEP+1)) \
  | while IFS='|' read -r CREATED REF; do
      [ "${REF##*:}" = "$CURRENT" ] && continue
      [ "$(date -d "${CREATED% *}" +%s)" -ge "$CUTOFF" ] && continue
      echo "   Local: sterg ${REF}"
      run docker image rm "$REF" >/dev/null
    done
exit 0
