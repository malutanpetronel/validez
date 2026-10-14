# ADR-0010: Jurnalele de acces și rutele de vot

**Status:** Accepted
**Date:** 2026-10-14

## Context

[ADR-0007](0007-votes-revisions-and-privacy.md), punctul deschis 1: IP-ul, momentul și ruta unei cereri de vot, păstrate în logurile de acces, permit corelarea cu jurnalul de integritate (subiect, moment, ordine) și, prin IP, cu o persoană. Valoarea votului nu apare în loguri (e în corpul cererii), dar faptul că o adresă IP a votat pe un subiect, la un moment dat, este el însuși o dată personală sensibilă.

Inventarul pe producție (14.10.2026):

| Sursă | Ce scrie | Retenție înainte de această decizie |
|---|---|---|
| Nginx_Proxy (`proxy-web`, comun tuturor site-urilor) | IP real, moment, metodă, URL complet | `json-file` 10 × 4 MB, ~9–10 zile la traficul curent |
| nginx BE (`validez_be_nginx`) | IP-ul proxy-ului, moment, metodă, URL complet | nelimitat între două recreări ale containerului |
| php-fpm (`validez_be_php`) | IP-ul nginx-ului, moment, metodă, `/index.php` | nelimitat între două recreări |
| Monolog (prod) | doar la erori (`fingers_crossed`), fără IP; URI-ul cererii | ca php-fpm (stderr) |
| nginx FE (`validez_fe_web`) | pagini și fișiere statice; nicio rută de API | nelimitat între două recreări |

Copiile de siguranță (`pg_dump`) nu includ logurile.

## Decizie

1. **Rutele care dezvăluie un vot nu se scriu în logurile de acces**, nici în nginx-ul BE, nici în Nginx_Proxy:
   - `PUT`/`DELETE /api/civic_subjects/{id}/votes/{type}` (exprimare, schimbare, retragere);
   - `/api/me/vote-consent`, orice metodă (acordul și retragerea lui);
   - `GET /api/me/votes` (exportul propriu).

   Restul cererilor se loghează normal, pentru securitate și limitarea abuzurilor. `GET /api/civic_subjects/{id}/votes` (rezultatele și votul propriu) rămâne logat: îl face oricine deschide subiectul, deci nu arată că persoana a votat.

   Implementare: un `map "$request_method $uri"` și `access_log … if=`; în nginx-ul BE în `backend/nginx/default.conf`, în proxy prin `backend/nginx/proxy-log-map.conf.prod` (contextul `http`, variabila `$validez_be_access_log`) și `backend/nginx/vhost.d_location.prod` (inclus de șablonul docker-gen în `location /` al `validez-be.webnou.ro`, unde înlocuiește logul moștenit de la server). Cele două liste se modifică împreună.

2. **Logul de acces al php-fpm este oprit** (`backend/docker/php-fpm/zz-no-access-log.conf`): nu conține ruta, dar momentul unui `PUT` se poate corela cu jurnalul, iar nginx are deja logul de acces.

3. **Retenție limitată după mărime** (varianta aleasă de Petro): `json-file` cu `max-size: 4m`, `max-file: 3` pe toate serviciile validez; proxy-ul rămâne la configurarea lui (10 × 4 MB). Nu introducem o limită în zile: după punctul 1, logurile rămase sunt loguri obișnuite de securitate, iar termenul lor intră în verificarea de specialitate.

4. **Monolog rămâne neschimbat**: scrie doar la erori, fără IP, iar URI-ul este necesar pentru depanare.

## Consecințe

- O cerere de vot nu mai lasă în loguri nicio urmă care să lege IP-ul de subiect și moment. Scrierile logate înainte de această decizie (testele de pe 14.10.2026) ies prin rotația proxy-ului.
- La depanarea unei probleme de vot nu avem logul de acces al cererii; avem răspunsul API-ului, Monolog la erori și jurnalul de integritate.
- Blocul HTTP (portul 80) al proxy-ului, care doar redirecționează spre HTTPS, nu include fișierul `_location`. O cerere de vot trimisă pe HTTP ar fi logată înainte de redirect; aplicația folosește numai HTTPS, deci riscul este neglijabil.
- Dacă `vhost.d/validez-be.webnou.ro_location` ajunge pe server fără `conf.d/validez-be-log.conf`, `nginx -t` eșuează pentru tot proxy-ul. Playbook-ul copiază întâi map-ul.
- O rută nouă care dezvăluie un vot trebuie adăugată în ambele liste.
- Retenția după mărime depinde de trafic; nu putem promite un număr de zile. Pagina de confidențialitate spune doar că jurnalele se rotesc automat și au dimensiune limitată.

## Alternative respinse

- **Logarea rutelor de vot fără IP** — momentul și subiectul rămân, iar în proxy formatul e comun tuturor site-urilor.
- **Oprirea completă a logurilor pentru `validez-be.webnou.ro`** — pierdem urmele necesare securității pentru toate celelalte cereri.
- **Golirea zilnică a logurilor mai vechi de N zile (cron)** — limită în timp garantată, dar pierde istoricul de depanare; reconsiderăm dacă verificarea de specialitate o cere.
