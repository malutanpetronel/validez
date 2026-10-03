-- Ruleaza o singura data, la initializarea volumului PostgreSQL (docker-entrypoint-initdb.d).
-- ltree pentru TreeNode.path (implementation-plan.md, Step 1).
-- In template1: orice baza creata ulterior (ex. doctrine:database:create --env=test) o are deja.
-- Migrarea Doctrine trebuie totusi sa contina CREATE EXTENSION IF NOT EXISTS ltree (sursa de adevar).
CREATE EXTENSION IF NOT EXISTS ltree;
\connect template1
CREATE EXTENSION IF NOT EXISTS ltree;
