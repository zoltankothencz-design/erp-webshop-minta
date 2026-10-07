# Projekt-szabályok (Claude Code számára)

PHP 8.4, MySQL/MariaDB, vanilla JS (bolt) és jQuery (admin). Keretrendszer nélkül, szándékosan.

## Parancsok
- Indítás/leállítás: `.\scripts\dev.ps1 start|stop|status`
- Tesztek: `.\scripts\dev.ps1 test` (minden módosítás után; zöld nélkül nem commitolunk)
- Séma: új SQL fájl a `database/migrations/` alatt (sorszámozva), majd `console migrate`. Meglévő migrációt soha ne szerkessz.
- Integrációs feladat kézzel: `.\scripts\dev.ps1 console job <név>`

## Szabályok
- **SQL**: csak paraméterezett lekérdezés (`Db::all/one/run`, `?` helyőrző). Szövegösszefűzés SQL-be tilos. A `LIMIT` szám előtte `(int)`-re kényszerítve.
- **Kimenet**: HTML-ben mindig `e()`. JS-ben `textContent` / jQuery `.text()`, soha `innerHTML` / `.html()` felhasználói vagy külső adattal.
- **Bemenet**: a böngészőtől jövő adatnak nem hiszünk. Ár, név, készlet mindig az adatbázisból jön. Mennyiség, azonosító szigorúan validálva.
- **Titkok**: csak `.env`-ben, soha kódban, naplóban vagy hibaüzenetben. A `.env` nincs gitben.
- **Külső hívás**: kizárólag az `Integration\HttpClient`-en át (időkorlát, újrapróbálkozás, titok-kitakarás). Új integráció: kliens (csak kommunikáció) + service (üzleti logika) + job a `JobRunner`-ben + teszt hamis transporttal.
- **Idempotencia**: minden integrációs feladatnak bármikor újra futtathatónak kell lennie (egyedi kulcsok, `ON DUPLICATE KEY UPDATE`, kurzor az adatból).
- **Készlet/rendelés**: tranzakció, `SELECT ... FOR UPDATE`, id szerinti zárolási sorrend.
- **Naplózás**: integrációs futás = `sync_log` sor (a `JobRunner` intézi). A részletes hiba az `error_log`-ba, a felhasználónak általános üzenet.
- **AI**: az AI kimenete külső adat. Csak piszkozat lehet, jóváhagyás után publikus, mindig megtisztítva.

## Mielőtt kész
1. Tesztek zöldek. 2. Új viselkedéshez új teszt. 3. Biztonsági átnézés a fenti szabályok ellen. 4. Kis, leíró commit.

## Ismert határok
A Google Ads kliens a dokumentáció alapján, szimulált szerverrel tesztelt, éles fiókkal nem. A `ClaudeClient` valódi API-hívása kulcs hiányában nem volt kipróbálva.
