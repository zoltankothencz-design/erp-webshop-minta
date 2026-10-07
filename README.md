# ERP-hez kapcsolódó webshop és marketing információs rendszer (mintaprojekt)

Egy kicsi, de teljes e-kereskedelmi rendszer **PHP 8 + MySQL/MariaDB + vanilla JavaScript + jQuery** alapon, keretrendszer nélkül.
Arra készült, hogy egy valódi, ERP-hez kapcsolódó webshop-fejlesztés tipikus feladatait mutassa meg: termékek és készlet az ERP-ből,
rendelések az ERP felé, marketplace-integráció, Google Ads, AI-támogatás, és mindez tesztelve, naplózva, verziókezelve.

A kód Claude Code-dal készült, lépésenként, git-előzménnyel. A teljes magyarázat: **[docs/LEPESEK.md](docs/LEPESEK.md)**.

> A cég, a márkák és a termékek kitaláltak. A külső rendszerek (ERP, marketplace, Google Ads) a helyi, szimulált szerveren futnak
> (`mock/`). A kód ugyanazt a formát használja, mint a valódi API-k, de **éles fiókkal nem volt kipróbálva**.

## Gyors indítás (Windows, PowerShell)

A futtatókörnyezet (hordozható PHP + MariaDB) a `.tools/` mappában van, rendszerszintű telepítés nem kell.

```powershell
.\scripts\dev.ps1 start                    # adatbázis + webshop (8080) + szimulált külső rendszerek (8081)
.\scripts\dev.ps1 console migrate          # adatbázis-séma
.\scripts\dev.ps1 console seed             # admin felhasználó (jelszó: .env, ADMIN_PASSWORD)
.\scripts\dev.ps1 console job erp-products # első termékek az ERP-ből
.\scripts\dev.ps1 test                     # 24 automatizált teszt
.\scripts\dev.ps1 stop
```

| Cím | Mi ez |
|---|---|
| http://127.0.0.1:8080/ | webshop |
| http://127.0.0.1:8080/admin | admin / marketing felület (belépés kell) |
| http://127.0.0.1:8080/feed/google.xml | Google Merchant termék-feed |
| http://127.0.0.1:8081/_state | a szimulált külső rendszerek állapota |

## Mit tud

| Terület | Megvalósítás |
|---|---|
| ERP-kapcsolat | termék/ár/készlet beolvasás (inkrementális), rendelés visszaírás idempotencia-kulccsal |
| Webshop | szerveroldali termékoldalak, kosár (vanilla JS), pénztár, készlet-zárolás tranzakcióval |
| REST API | termékek, kategóriák, rendelés (validációval, 422 hibaválaszokkal) |
| Marketplace | rendelés-import (duplikátum-védelem), ár/készlet push, XML termék-feed |
| Google Ads | OAuth2, kampányköltség-import (GAQL), offline konverzió feltöltés gclid-del |
| AI | termékleírás Claude API-val (vagy kulcs nélküli mock), emberi jóváhagyással |
| Admin | belépés, CSRF, jQuery dashboard: eladás + hirdetési költség + ROAS, integrációs napló, kézi feladat-indítás |
| Minőség | 24 teszt, szigorú bemenet-ellenőrzés, titkok `.env`-ben, naplózás, hibatűrő HTTP kliens |

## Felépítés

```
public/        a webszerver gyökere (index.php, css, js)
src/           PHP kód: Controller, Service (üzleti logika), Integration (külső API-k), Repository (adatbázis)
templates/     HTML sablonok
database/      verziózott SQL-migrációk
bin/console.php  parancssor: migrate, seed, job <név>, simulate
mock/          szimulált ERP, marketplace, Google Ads
tests/run.php  tesztek
docs/          lépésenkénti magyarázat
```

## Élesítés előtt kötelező (nincs benne)

Süti-hozzájárulási felület (a gclid követéséhez), IP-alapú sebességkorlát a belépésre és a rendelésre, HTTPS + HSTS + CSP,
éles API-hitelesítők és az API-verziók ellenőrzése a szolgáltatók dokumentációjából, cron a feladatokra, adatbázis-mentés,
monitorozás. Részletek: [docs/LEPESEK.md](docs/LEPESEK.md), "Mi hiányzik" fejezet.
