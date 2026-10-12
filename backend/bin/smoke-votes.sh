#!/bin/bash
# Smoke test Step 4.b4 (ADR-0007), pe stack-ul de dev pornit. Rulare, din backend/: ./bin/smoke-votes.sh
#
# Operații concurente reale prin HTTP, cu verificarea FIECĂRUI răspuns, nu doar a stării finale:
#   A. voturi simultane ale aceluiași utilizator + editări simultane ale subiectului (revizii);
#   B. voturi simultane cu retrageri/reacordări de consimțământ ale aceluiași utilizator;
#   C. voturi simultane cu înghețarea subiectului; retragere permisă după; redeschidere fără reactivare;
#   D. limita de scriere (429 + Retry-After), cu retragerea consimțământului încă disponibilă.
# La final: app:votes:verify (lanțuri, agregate, consimțământ).
#
# Creează un admin și trei utilizatori temporari (@smoke-votes.test) și șterge la final tot ce a creat.
# Curățarea dezactivează temporar, într-o tranzacție, triggerul append-only al jurnalului — doar pe dev.
set -u
API=${API:-http://localhost:8020/api}; PW=smoke-votes-parola-123; DOMAIN=smoke-votes.test; P=SMOKEV
TMP=$(mktemp -d)
dc(){ docker compose "$@" 2>/dev/null; }
sql(){ dc exec -T db psql -U validez -d validez -qtAc "$1"; }
fail=0; ok(){ if [ "$2" = "$3" ]; then echo "  OK   $1"; else echo "  FAIL $1 (așteptat: $3, primit: $2)"; fail=1; fi; }

SUBJECTS="SELECT s.id FROM civic_subject s JOIN app_user u ON u.id = s.author_id WHERE u.email LIKE '%@$DOMAIN'"
USERS="SELECT id FROM app_user WHERE email LIKE '%@$DOMAIN'"
cleanup(){
  sql "BEGIN;
    ALTER TABLE integrity_event_log DISABLE TRIGGER trg_integrity_event_log_append_only;
    DELETE FROM user_vote_history WHERE subject_id IN ($SUBJECTS) OR user_id IN ($USERS);
    DELETE FROM vote WHERE subject_id IN ($SUBJECTS) OR user_id IN ($USERS);
    DELETE FROM vote_result WHERE subject_id IN ($SUBJECTS);
    DELETE FROM integrity_event_log WHERE subject_id IN ($SUBJECTS);
    DELETE FROM subject_vote_type WHERE subject_id IN ($SUBJECTS);
    DELETE FROM subject_revision WHERE subject_id IN ($SUBJECTS);
    DELETE FROM civic_subject WHERE id IN ($SUBJECTS);
    ALTER TABLE integrity_event_log ENABLE TRIGGER trg_integrity_event_log_append_only;
    DELETE FROM tree_node WHERE name = '$P Drumuri';
    DELETE FROM vote_consent WHERE user_id IN ($USERS);
    DELETE FROM refresh_token WHERE username LIKE '%@$DOMAIN';
    DELETE FROM app_user WHERE email LIKE '%@$DOMAIN';
    COMMIT;" >/dev/null
  rm -rf "$TMP"
}
trap cleanup EXIT; cleanup; TMP=$(mktemp -d)

HASH=$(dc exec -T php bin/console security:hash-password "$PW" 'App\Entity\User' -n | grep -oE '\$2y\$[^ ]+' | head -1)
for who in admin u1 u2 u3; do
  roles='[]'; [ $who = admin ] && roles='["ROLE_ADMIN"]'
  sql "INSERT INTO app_user (id,email,display_name,roles,password,created_at,email_verified) VALUES (gen_random_uuid(),'$who@$DOMAIN','Smoke $who','$roles','$HASH',now(),true)" >/dev/null
done
login(){
  local proof; proof=$(curl -fsS "$API/captcha/challenge" | dc exec -T php php bin/solve-altcha.php)
  curl -s -X POST "$API/auth" -H 'Content-Type: application/json' -d "{\"email\":\"$1@$DOMAIN\",\"password\":\"$PW\",\"altcha\":\"$proof\"}" | jq -r .token
}
declare -A T; for who in admin u1 u2 u3; do T[$who]=$(login $who); done

# req METODĂ URL UTILIZATOR [CORP] [CONTENT-TYPE] → fișier: corpul, apoi pe ultima linie codul HTTP
req(){ local ct=${5:-application/json} accept=application/json; [ "$ct" != application/json ] && accept=application/ld+json
  curl -s -X "$1" "$API$2" -H "Authorization: Bearer ${T[$3]}" -H "Accept: $accept" \
  -H "Content-Type: $ct" ${4:+-d "$4"} -w '\n%{http_code}'; }
code(){ printf '%s\n' "$(tail -n1 "$1")"; }
body(){ sed '$d' "$1"; }
NOTICE=$(req GET /me/vote-consent u1 | sed '$d' | jq -r .currentNoticeVersion)
consent(){ req PUT /me/vote-consent "$1" "{\"noticeVersion\":\"$NOTICE\"}" > "$TMP/c"; code "$TMP/c"; }
vote(){ req PUT "/civic_subjects/$S/votes/$2" "$1" "{\"value\":$3}"; }
patch(){ req PATCH "/civic_subjects/$S" admin "$1" application/merge-patch+json; }
events(){ sql "SELECT COUNT(*) FROM integrity_event_log WHERE subject_id = (SELECT id FROM civic_subject WHERE id::text = '$SUUID') AND kind = '$1'"; }

NODE=$(req POST /tree_nodes admin "{\"name\":\"$P Drumuri\"}" application/ld+json | sed '$d' | jq -r .id)
S=$(req POST /civic_subjects admin "{\"node\":\"$NODE\",\"type\":\"ISSUE\",\"title\":\"$P groapă\",\"description\":\"DN1\"}" application/ld+json | sed '$d' | jq -r .id)
[ -n "$S" ] && [ "$S" != null ] || { echo "Nu am putut crea subiectul (nod: $NODE)"; exit 1; }
SUUID=$(dc exec -T php php -r "require 'vendor/autoload.php'; echo Symfony\Component\Uid\Ulid::fromString('$S')->toRfc4122();")
for who in u1 u2 u3; do ok "consimțământ $who" "$(consent $who)" 200; done

echo "== A. 12 voturi u1 simultane cu 6 editări ale subiectului"
for i in $(seq 1 12); do vote u1 VALIDITY $((i % 2)) > "$TMP/a.$i" & done
for j in $(seq 1 6); do patch "{\"description\":\"DN1 revizia $j\"}" > "$TMP/e.$j" & done
wait
ok "toate voturile 200" "$(for i in $(seq 1 12); do code "$TMP/a.$i"; done | sort -u | tr '\n' ' ')" "200 "
ok "toate editările 200" "$(for j in $(seq 1 6); do code "$TMP/e.$j"; done | sort -u | tr '\n' ' ')" "200 "
for i in $(seq 1 12); do body "$TMP/a.$i" | jq -r '.action // "null"'; done > "$TMP/actions"
ok "exact un VOTE (primul vot)" "$(grep -cx VOTE "$TMP/actions")" 1
ok "doar acțiuni valide" "$(grep -cvxE 'VOTE|CHANGE|RECONFIRM|null' "$TMP/actions")" 0
ok "RECONFIRM ≤ numărul editărilor" "$([ "$(grep -cx RECONFIRM "$TMP/actions")" -le 6 ] && echo da)" da
ok "evenimente VOTE_SET = acțiuni cu scriere" "$(events VOTE_SET)" "$(grep -cvx null "$TMP/actions")"
ok "revizii consecutive 1..7" "$(sql "SELECT string_agg(number::text, ',' ORDER BY number) FROM subject_revision WHERE subject_id = '$SUUID'")" "1,2,3,4,5,6,7"
SUM=$(req GET /civic_subjects/$S/votes u1 | sed '$d')
ok "votul u1 numărat o singură dată (curent sau nereconfirmat)" "$(echo "$SUM" | jq '.types[] | select(.code=="VALIDITY") | .result.total + .result.staleTotal')" 1
ok "mine.current corespunde reviziei" "$(echo "$SUM" | jq '.types[] | select(.code=="VALIDITY") | (.mine.revisionNumber == $r) == .mine.current' --argjson r "$(echo "$SUM" | jq .revisionNumber)")" true

echo "== B. 10 voturi u2 simultane cu 3 retrageri/reacordări de consimțământ"
ok "u2 votează înainte" "$(vote u2 VALIDITY 1 | tail -n1)" 200
for i in $(seq 1 10); do vote u2 VALIDITY $((i % 2)) > "$TMP/b.$i" & done
( for k in 1 2 3; do req DELETE /me/vote-consent u2 > "$TMP/w.$k"; req PUT /me/vote-consent u2 "{\"noticeVersion\":\"$NOTICE\"}" > "$TMP/g.$k"; done ) &
wait
ok "consimțământ: toate 200" "$(for k in 1 2 3; do code "$TMP/w.$k"; code "$TMP/g.$k"; done | sort -u | tr '\n' ' ')" "200 "
bad=0; for i in $(seq 1 10); do c=$(code "$TMP/b.$i"); r=$(body "$TMP/b.$i" | jq -r '.reason // ""')
  { [ "$c" = 200 ] || { [ "$c" = 403 ] && [ "$r" = consent_required ]; }; } || bad=$((bad+1)); done
ok "fiecare vot u2: 200 sau 403 consent_required" $bad 0
ok "retragerea a șters voturile de dinainte (≥1 retrase în total)" "$([ $(( $(body "$TMP/w.1" | jq .withdrawnVotes) + $(body "$TMP/w.2" | jq .withdrawnVotes) + $(body "$TMP/w.3" | jq .withdrawnVotes) )) -ge 1 ] && echo da)" da
ok "u2: cel mult un vot activ" "$([ "$(sql "SELECT COUNT(*) FROM vote v JOIN app_user u ON u.id = v.user_id WHERE u.email = 'u2@$DOMAIN'")" -le 1 ] && echo da)" da

echo "== C. 8 voturi u3 simultane cu închiderea subiectului"
for i in $(seq 1 8); do vote u3 IMPORTANCE $(( (i % 5) + 1 )) > "$TMP/f.$i" & done
patch '{"stage":"CLOSED"}' > "$TMP/close" &
wait
ok "închiderea 200" "$(code "$TMP/close")" 200
bad=0; for i in $(seq 1 8); do c=$(code "$TMP/f.$i"); r=$(body "$TMP/f.$i" | jq -r '.reason // ""')
  { [ "$c" = 200 ] || { [ "$c" = 409 ] && [ "$r" = subject_frozen ]; }; } || bad=$((bad+1)); done
ok "fiecare vot u3: 200 sau 409 subject_frozen" $bad 0
ok "după închidere: schimbarea refuzată" "$(vote u1 VALIDITY 1 | sed '$d' | jq -r .reason)" subject_frozen
ok "după închidere: retragerea permisă" "$(req DELETE /civic_subjects/$S/votes/VALIDITY u1 | sed '$d' | jq -c .)" '{"withdrawn":true}'
patch '{"stage":"OPEN"}' > /dev/null
ok "redeschis: votul retras nu revine" "$(req GET /civic_subjects/$S/votes u1 | sed '$d' | jq -c '.types[] | select(.code=="VALIDITY") | .mine')" null
ok "redeschis: vot nou = VOTE" "$(vote u1 VALIDITY 0 | sed '$d' | jq -r .action)" VOTE

echo "== D. limita de scriere (u3)"
for i in $(seq 1 30); do vote u3 IMPORTANCE $(( (i % 5) + 1 )) > "$TMP/r.$i"; done
ok "ultimul vot: 429 rate_limited" "$(code "$TMP/r.30") $(body "$TMP/r.30" | jq -r .reason)" "429 rate_limited"
ok "Retry-After prezent" "$(curl -s -o /dev/null -D - -X PUT "$API/civic_subjects/$S/votes/IMPORTANCE" -H "Authorization: Bearer ${T[u3]}" -H 'Content-Type: application/json' -d '{"value":3}' | grep -ci '^retry-after:')" 1
ok "retragerea consimțământului rămâne accesibilă" "$(req DELETE /me/vote-consent u3 | tail -n1)" 200

echo "== verificare finală"
dc exec -T php bin/console app:votes:verify > "$TMP/verify"; v=$?
ok "app:votes:verify" $v 0; [ $v = 0 ] || cat "$TMP/verify"
[ $fail = 0 ] && echo "SMOKE OK" || echo "SMOKE ESUAT"; exit $fail
