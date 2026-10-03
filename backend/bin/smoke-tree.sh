#!/bin/bash
# Smoke test Step 1 (criteriul de încheiere din docs/implementation-plan.md), pe stack-ul de dev pornit:
# admin creează Drumuri > Cluj > Calitate > DN1, redenumește, mută o ramură, ordonează frații,
# structura e corectă la o citire nouă; vizitatorul navighează fără drept de modificare.
# Folosește un admin temporar și șterge la final tot ce a creat. Rulare, din backend/: ./bin/smoke-tree.sh
set -u
API=${API:-http://localhost:8020/api}; EMAIL=smoke-tree@validez.test; PW=smoke-tree-parola-123; P=SMOKE1
dc(){ docker compose "$@" 2>/dev/null; }
sql(){ dc exec -T db psql -U validez -d validez -qtAc "$1"; }
fail=0; ok(){ if [ "$2" = "$3" ]; then echo "  OK   $1"; else echo "  FAIL $1 (așteptat: $3, primit: $2)"; fail=1; fi; }
cleanup(){
  for l in 6 5 4 3 2; do sql "DELETE FROM tree_node WHERE path <@ (SELECT path FROM tree_node WHERE name='$P Drumuri') AND nlevel(path)=$l" >/dev/null; done
  sql "DELETE FROM tree_node WHERE name='$P Drumuri'; DELETE FROM refresh_token WHERE username='$EMAIL'; DELETE FROM app_user WHERE email='$EMAIL'" >/dev/null
}
trap cleanup EXIT; cleanup
HASH=$(dc exec -T php bin/console security:hash-password "$PW" 'App\Entity\User' -n | grep -oE '\$2y\$[^ ]+' | head -1)
sql "INSERT INTO app_user (id,email,display_name,roles,password,created_at) VALUES (gen_random_uuid(),'$EMAIL','Smoke','[\"ROLE_ADMIN\"]','$HASH',now())" >/dev/null
T=$(curl -s -X POST "$API/auth" -H 'Content-Type: application/json' -d "{\"email\":\"$EMAIL\",\"password\":\"$PW\"}" | python3 -c 'import sys,json;print(json.load(sys.stdin)["token"])')
H=(-H 'Accept: application/ld+json' -H "Authorization: Bearer $T")
post(){ curl -s -X POST "$API/tree_nodes" "${H[@]}" -H 'Content-Type: application/ld+json' -d "$1" | python3 -c 'import sys,json;print(json.load(sys.stdin)["id"])'; }
mv(){ curl -s -o /dev/null -w '%{http_code}' -X POST "$API/tree_nodes/$1/move" "${H[@]}" -H 'Content-Type: application/ld+json' -d "$2"; }
names(){ curl -s "$API/tree_nodes?parent=$1" -H 'Accept: application/ld+json' | python3 -c 'import sys,json;print(",".join(x["name"] for x in json.load(sys.stdin)["member"]))'; }

echo "== admin: Drumuri > Cluj > Calitate > DN1 (+ Bistrita)"
D=$(post "{\"name\":\"$P Drumuri\"}"); C=$(post "{\"name\":\"Cluj\",\"parent\":\"$D\"}")
Q=$(post "{\"name\":\"Calitate\",\"parent\":\"$C\"}"); N=$(post "{\"name\":\"DN1\",\"parent\":\"$Q\"}"); B=$(post "{\"name\":\"Bistrita\",\"parent\":\"$D\"}")
ok "copiii lui Drumuri" "$(names $D)" "Cluj,Bistrita"
echo "== redenumire, mutare ramură, ordonare"
ok "PATCH redenumire" "$(curl -s -o /dev/null -w '%{http_code}' -X PATCH "$API/tree_nodes/$Q" "${H[@]}" -H 'Content-Type: application/merge-patch+json' -d '{"name":"Starea drumurilor"}')" 200
ok "mutare Starea drumurilor (cu DN1) sub Bistrita" "$(mv $Q "{\"parent\":\"$B\",\"position\":0}")" 200
ok "ordonare: Bistrita prima" "$(mv $B "{\"parent\":\"$D\",\"position\":0}")" 200
ok "mutare în propriul descendent respinsă" "$(mv $D "{\"parent\":\"$N\"}")" 422
echo "== citire nouă (ca după refresh), fără token"
ok "Drumuri" "$(names $D)" "Bistrita,Cluj"
ok "Bistrita" "$(names $B)" "Starea drumurilor"
ok "Starea drumurilor (DN1 a venit cu ea)" "$(names $Q)" "DN1"
ok "Cluj rămas fără copii" "$(names $C)" ""
ok "path DN1 = Drumuri.Bistrita.Starea.DN1" "$(sql "SELECT nlevel(path) FROM tree_node WHERE id=(SELECT id FROM tree_node t WHERE t.name='DN1' AND t.path <@ (SELECT path FROM tree_node WHERE name='$P Drumuri'))")" 4
echo "== vizitator"
ok "vizitator: citire" "$(curl -s -o /dev/null -w '%{http_code}' "$API/tree_nodes")" 200
ok "vizitator: creare" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$API/tree_nodes" -H 'Content-Type: application/ld+json' -d '{"name":"x"}')" 401
ok "vizitator: mutare" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$API/tree_nodes/$B/move" -H 'Content-Type: application/ld+json' -d '{"parent":null}')" 401
[ $fail = 0 ] && echo "SMOKE OK" || echo "SMOKE ESUAT"; exit $fail
