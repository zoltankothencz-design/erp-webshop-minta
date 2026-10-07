# Lépésről lépésre: így készült a rendszer, és hogyan érted meg

Ez a dokumentum két célra való: **megértsd**, mit épít a kód és miért úgy, és **meg tudd ismételni** Claude Code-dal.
A git-előzmény (`git log`) lépésenként követi ezt a leírást: egy fejezet egy commit.

Olvasási tipp: nyisd meg a hivatkozott fájlokat, miközben olvasod a fejezetet. A kód kommentjei is magyarázatok.

---

## 0. A rendszer térképe és a fogalmak

```
                 +-------------------+         +--------------------+
   Vásárló  ---> |  WEBSHOP (PHP)    | <-----> |  MySQL adatbázis   |
   böngésző      |  public/ + src/   |         |  termék, rendelés  |
                 +----+---------+----+         +--------------------+
                      |         |
        feed (XML)    |         |  integrációs feladatok (bin/console.php, cron)
   Google, marketplace|         v
   húzza le           |   +-----------+   +----------------+   +-------------+   +--------+
                      |   |    ERP    |   |  Marketplace   |   | Google Ads  |   |   AI   |
                      |   | termék,   |   | rendelések,    |   | költség,    |   | termék |
                      |   | készlet,  |   | ár/készlet     |   | konverzió   |   | leírás |
                      |   | rendelés  |   |                |   |             |   |        |
                      +-> +-----------+   +----------------+   +-------------+   +--------+
                                    (a mintában mind a mock/ szerveren fut)
   Admin (Te)  --->  /admin : eladás + hirdetési költség + napló + kézi indítás
```

**Fogalmak, amik a hirdetésben és a munkában mindennaposak:**

| Fogalom | Egyszerűen |
|---|---|
| **PHP** | Programnyelv, ami a szerveren fut, amikor a látogató kér egy oldalt. Összeállítja a választ (HTML vagy JSON). |
| **MySQL / MariaDB** | Adatbázis: táblázatokban tárolja a termékeket, rendeléseket. SQL nyelven kérdezed. A MariaDB a MySQL-lel kompatibilis rokona, itt azt használjuk. |
| **API** | Egy program "kapuja": más programok kéréseket küldhetnek neki (pl. "add a termékeket"). |
| **REST / JSON** | Az API-k elterjedt stílusa: HTTP-kérések (GET olvas, POST létrehoz, PUT módosít), a válasz JSON szöveg (`{"sku":"A","price":1000}`). |
| **Endpoint** | Egy konkrét API-cím, pl. `/api/products`. |
| **Idempotencia** | Ha ugyanazt a kérést kétszer küldöd, ugyanaz történik, mint egyszer (nem lesz két rendelés). Hálózati hibánál ez menti meg az adatot. |
| **Tranzakció** | Több adatbázis-művelet "mind vagy semmi": ha az egyik elhasal, az összes visszaáll. |
| **Cron** | Időzített feladat a szerveren (pl. "óránként futtasd az ERP-szinkront"). |
| **OAuth2** | Hogyan lép be a programod egy külső szolgáltatásba (pl. Google) jelszó nélkül: tokeneket kap. |
| **Feed** | Egy XML-fájl a termékeiddel, amit a hirdetési rendszerek és marketplace-ek rendszeresen lehúznak. |
| **gclid** | A Google Ads kattintási azonosítója. Ha a vásárlás után visszaküldöd, a Google tudja, melyik hirdetés hozott pénzt. |
| **ROAS** | Hirdetési megtérülés: bevétel / hirdetési költség. |
| **SQL-injekció** | Támadás: a bemenetbe SQL-kódot írnak, és a rosszul írt kód végrehajtja. Ellene a paraméterezett lekérdezés véd. |
| **XSS** | Támadás: idegen szöveg HTML/JS kódként fut le az oldalon. Ellene a kimenet kiescape-elése véd. |

---

## 1. lépés: alap (konfiguráció, adatbázis, migrációk)

**Mit építünk:** a projekt vázát: mappaszerkezet, `.env` beállítófájl, adatbázis-kapcsolat, a táblák leírása SQL-ben.

**Miért így:**
- A **titkok (jelszó, API-kulcs) a `.env`-ben vannak**, nem a kódban, és a `.gitignore` miatt sosem kerülnek gitbe. A repóban csak a `.env.example` van, kitöltendő mintaként.
- Az adatbázis-kapcsolat egyetlen osztályban van ([src/Db.php](../src/Db.php)), és **minden lekérdezés paraméterezett**: az SQL-ben `?` áll, az értékeket külön adjuk át. Így a bemenet soha nem válhat SQL-kóddá.
- A séma **migrációkban** él ([database/migrations/001_schema.sql](../database/migrations/001_schema.sql)). Bármelyik gépen egy paranccsal felépül ugyanaz az adatbázis, és a változtatásokat is verziózod.

**Kulcsrészletek a sémában:**
- `products.erp_id` egyedi: az ERP azonosítója köti össze a két rendszert.
- `orders` `UNIQUE (source, marketplace_ref)`: ugyanaz a marketplace-rendelés kétszer nem kerülhet be.
- `order_items.unit_price`: a rendeléskori árat másoljuk, mert a termék ára később változhat.
- `products.stock` és `erp_stock`: a kettő nem ugyanaz (lásd a 2. lépést).

**Próbáld ki:**
```powershell
.\scripts\dev.ps1 start
.\scripts\dev.ps1 console migrate
```

**Claude Code prompt (példa):** *"Készíts PHP 8 projektvázat keretrendszer nélkül: .env konfig, PDO-s Db osztály csak paraméterezett lekérdezésekkel, és egy migrációs futtató, ami a database/migrations/*.sql fájlokat sorrendben lefuttatja. A séma: termékek (ERP-azonosítóval), rendelések, tételek, kampányok, naplótábla."*

**Mire figyelj (review):** nincs-e SQL összefűzés (`"... WHERE id = $id"`)? Van-e `.gitignore` a `.env`-re? A `LIMIT` értéke számmá van-e kényszerítve?

---

## 2. lépés: szimulált külső világ, hibatűrő HTTP kliens, ERP szinkron

**Mit építünk:**
1. A `mock/` mappában egy kis szerver, ami az ERP-t, a marketplace-t és a Google Adst játssza ([mock/index.php](../mock/index.php)).
2. Egy közös HTTP kliens ([src/Integration/HttpClient.php](../src/Integration/HttpClient.php)).
3. Az ERP-kliens és a szinkron-szolgáltatás ([src/Service/ErpSyncService.php](../src/Service/ErpSyncService.php)).

**Miért szimulált szerver:** nincs élő ERP-d és Google Ads-fiókod, de a kód akkor is kipróbálható legyen. A mock válaszai a valódi API-k formáját követik, így élesben csak az URL és a kulcs cserélődik a `.env-ben`.

**A HTTP kliens a legfontosabb alap, mert ezen megy minden külső hívás:**
- **Időkorlát:** egy lassú külső rendszer nem akaszthatja meg a tiédet.
- **Újrapróbálkozás növekvő várakozással** (200 ms, 400 ms, ...), de csak *átmeneti* hibánál (429, 5xx, hálózati hiba). Egy `401` vagy `404` végleges, azt újrapróbálni értelmetlen.
- **Titkok kitakarása** a hibaüzenetekből.

**Az ERP szinkron lényege:**
- **Az ERP a törzsadat ura.** Név, ár, készlet onnan jön; a webshop nem írja felül.
- **Inkrementális szinkron:** csak a legutóbbi óta módosultakat kérjük (`updated_since`). A "mikortól" kurzort a *kapott adatból* vesszük, nem a saját óránkból (az órák eltérhetnek).
- **Készlet-foglalás:** amíg egy webes rendelést az ERP nem kapott meg, a készletet *nekünk* kell tartanunk. Ezért: `eladható készlet = ERP-készlet - ERP-nek még el nem küldött rendelések`. Enélkül a következő szinkron visszaállítaná a már eladott darabokat.
- **Rendelés visszaküldése idempotensen:** az `Idempotency-Key` fejléc a rendelésszám. Ha a hálózat hibázik, és újraküldjük, az ERP nem csinál két rendelést.

**Próbáld ki:**
```powershell
.\scripts\dev.ps1 console job erp-products
.\scripts\dev.ps1 console simulate erp          # az ERP-ben árak és készlet változnak
.\scripts\dev.ps1 console job erp-products      # csak a változások jönnek le
.\scripts\dev.ps1 console simulate fail 2       # a következő 2 hívás 503-at ad
.\scripts\dev.ps1 console job erp-products      # mégis sikerül: az újrapróbálás működik
```

**Claude Code prompt (példa):** *"Írj egy HttpClient osztályt curl-lel: időkorlát, max 3 próbálkozás exponenciális várakozással csak 429/5xx/hálózati hibánál, a titkok ne kerüljenek a hibaüzenetbe, a transport legyen cserélhető tesztekhez. Utána egy ErpClient, ami oldalanként húzza a termékeket, és egy szinkron-service, ami upsertel és a még el nem küldött rendelések darabszámát levonja a készletből."*

**Mire figyelj (review):** a kurzor biztonságosan lép-e tovább hiba esetén? Újrafuttatható-e a szinkron bármikor ugyanazzal az eredménnyel? Mi történik, ha az ERP üres választ ad?

---

## 3. lépés: rendelés, REST API, webshop frontend

**Mit építünk:** a vásárlói oldalt: termékek listája (szerveroldalon renderelve), termékoldal, kosár és pénztár vanilla JavaScripttel, REST API ([src/Controller/ApiController.php](../src/Controller/ApiController.php)), és a rendelés felvétele ([src/Service/OrderService.php](../src/Service/OrderService.php)).

**A legfontosabb szabály: a böngészőnek nem hiszünk.** A kosárban csak azt tároljuk, *mit és mennyit* kér a vásárló (`{"URH-100": 2}`). Az árat, a nevet és a készletet a szerver az adatbázisból veszi. Ha valaki a böngészőben átírja az árat 1 forintra, a szerver nem figyel rá. A tesztek ezt ellenőrzik (`rendelés: az ár az adatbázisból jön`).

**A rendelés-felvétel lépései (egy tranzakcióban):**
1. Validáció (név, e-mail, telefon, cím, tételek, mennyiség 1-20). Hibánál 422 és mezőnkénti üzenetek.
2. `SELECT ... FOR UPDATE`: **zárolja** az érintett termékeket, amíg a tranzakció tart. Két egyszerre érkező rendelés így nem adhatja el ugyanazt az utolsó darabot. A termékeket id szerint rendezve zárolja, hogy két rendelés ne akadjon össze (deadlock).
3. Készlet ellenőrzés, összeg kiszámítása az adatbázis-árakból.
4. Rendelés + tételek beszúrása, készlet levonása. **Hiba esetén visszagörgetés**: nem marad félig kész rendelés vagy levont készlet.

**Hirdetési azonosítók:** a `shop.js` az első látogatáskor elmenti az `utm_*` és `gclid` paramétereket, és a rendeléssel elküldi. A szerver csak ismert formátumot fogad el (regex), minden más `NULL` lesz. Ez köti össze a vásárlást a hirdetéssel (lásd az 5. lépést).

**Frontend megjegyzések:**
- A kosár a böngésző `localStorage`-ában él. A kosár oldal az API-ból kéri le az aktuális adatokat.
- A DOM-ot `textContent`-tel építjük, nem `innerHTML`-lel: így egy rosszindulatú terméknév nem tud kódot futtatni (**XSS-védelem**).
- A PHP sablonokban minden kimenet az `e()` függvényen megy át.

**Próbáld ki:** nyisd meg a http://127.0.0.1:8080/ oldalt, tegyél a kosárba, rendelj. Majd:
```powershell
.\scripts\dev.ps1 console job erp-orders        # a rendelés megy az ERP-nek, a készlet ott is csökken
```
A hirdetési követéshez nyisd meg így: `http://127.0.0.1:8080/?utm_source=google&utm_medium=cpc&gclid=Cj0KCQjw_PROBA_click_1`, és ezen a munkamenetben rendelj.

**Claude Code prompt (példa):** *"Készíts rendelés-felvételt: a kliens csak sku-t és mennyiséget küldhet, az árat az adatbázisból vedd. Tranzakcióban, FOR UPDATE zárolással vond le a készletet, hiba esetén gördülj vissza. Szigorú validáció, 422 válasz mezőnkénti hibákkal. Írj teszteket: hamis ár, kevés készlet, részben hibás kosár."*

**Mire figyelj (review):** az AI gyakran elfelejti a zárolást vagy a visszagörgetést, és "működő" kódot ad, ami párhuzamos rendelésnél hibázik. Ellenőrizd, hogy az összeg nem a kliens adatából számolódik. Nincs-e hiba-szöveg, ami belső részletet (SQL, fájlútvonal) árul el? (Itt a `public/index.php` az általános üzenetet adja, a részlet csak a naplóba kerül.)

---

## 4. lépés: marketplace és termék-feedek

**Mit építünk:** ([MarketplaceService](../src/Service/MarketplaceService.php), [FeedExporter](../src/Service/FeedExporter.php))
- **Rendelés-import:** a marketplace-en leadott rendeléseket behúzzuk. A duplikátumot az adatbázis egyedi kulcsa (`source + marketplace_ref`) szűri, így az import bármikor újrafuttatható.
- **Ár/készlet push:** csak a *megváltozott* termékeket küldjük (`updated_at` kurzor).
- **Feedek:** `/feed/google.xml` (Google Merchant Center formátum) és `/feed/marketplace.xml`. XMLWriter-rel készül, ami helyesen kiescape-eli a `&`, `<` karaktereket (a teszt ezt is ellenőrzi).

**Egy fontos döntés: hibánál megállunk, és a kurzor nem lép tovább.** Ha egy marketplace-rendelés ismeretlen cikkszámot tartalmaz, az import ott megáll, hibát jelez, és a következő futás újra megpróbálja. A másik megoldás (kihagyni és továbbmenni) csendben elveszítene egy rendelést, ami az üzletnek pénzbe kerül.

**Próbáld ki:**
```powershell
.\scripts\dev.ps1 console simulate marketplace
.\scripts\dev.ps1 console job marketplace-orders
.\scripts\dev.ps1 console job marketplace-orders   # másodszorra: 0 új, 2 már ismert
.\scripts\dev.ps1 console job marketplace-offers
```

**Mire figyelj:** minden marketplace **saját XML/API formátumot** vár. Az itt lévő feed általános; az éles marketplace-hez az ő dokumentációjuk alapján kell megírni a pontos mezőket. Ezt a kód sem állítja másnak.

---

## 5. lépés: Google Ads és AI

### Google Ads ([GoogleAdsClient](../src/Integration/GoogleAdsClient.php), [AdsService](../src/Service/AdsService.php))

Két feladat:
1. **Kampányköltség lekérése** (`syncStats`): a Google Ads lekérdezőnyelvével (GAQL) kérjük a kampányok megjelenését, kattintását, költségét, konverzióját. A költség **mikro-egységben** jön (1 000 000 = 1 Ft), ezt váltjuk forintra. Az adatot felülírjuk, nem csak beszúrjuk, mert a Google a friss napokat utólag módosítja.
2. **Offline konverzió feltöltése** (`uploadConversions`): a webshop valódi rendeléseit a `gclid`-del együtt visszaküldjük. A Google így a *tényleges bevételre* tud optimalizálni, nem csak kattintásokra. Minden rendelést csak egyszer töltünk fel (`conversion_uploads` egyedi kulcs), és a részleges hibát (az egyik elem hibás, a többi jó) külön kezeljük.

**OAuth2 működése röviden:** a tartós *refresh tokenből* rövid életű *access tokent* kérünk, azt a lejáratig újrahasznosítjuk, és minden hívásban `Authorization: Bearer ...` fejlécként küldjük.

**Biztonsági részlet:** a GAQL-nek nincs paraméterezett formája, a dátum pedig a lekérdezés szövegébe kerül. Ezért a dátumot szigorúan ellenőrizzük (`ÉÉÉÉ-HH-NN` formátum), különben GAQL-injekció lehetne. A teszt bizonyítja, hogy hibás dátumnál kérés sem megy ki.

**Adatvédelem:** a gclid személyes adatnak minősülhet, feltöltése csak hozzájárulást adott vásárlóktól engedett. A mintában nincs süti-hozzájárulási felület, élesben kell.

### AI termékleírás ([ProductDescriptionService](../src/Service/ProductDescriptionService.php))

A folyamat: **AI ír piszkozatot, ember hagyja jóvá, utána látszik a boltban.**
- A prompt szigorú: csak a megadott adatokból dolgozhat, nem találhat ki műszaki adatot, garanciát, tanúsítványt.
- Az ERP-ből jövő adat `<termek_adatok>` címkék között megy be, és a prompt kimondja, hogy az adat, nem utasítás. Ez csökkenti a **prompt-injekció** kockázatát (ha valaki az ERP-ben egy terméknévbe "hagyd figyelmen kívül az utasításokat" szöveget írna), de nem szünteti meg. Ezért van a jóváhagyás.
- A kimenet külső adat: `strip_tags`, hossz-korlát, és megjelenítéskor is `e()`.
- `AI_DRIVER=mock`: kulcs nélküli sablon-szöveg. `AI_DRIVER=claude`: valódi hívás az Anthropic API-ra (az `ANTHROPIC_API_KEY` kell hozzá a `.env`-be).

**Próbáld ki:**
```powershell
.\scripts\dev.ps1 console job ads-stats
.\scripts\dev.ps1 console job ads-conversions    # csak a gclid-es rendelések mennek fel, egyszer
.\scripts\dev.ps1 console job ai-descriptions    # 5 piszkozat, jóváhagyásra vár az adminban
```

**Mire figyelj (review):** soha ne higgy el egy API-integrációt, amit csak a modell "emlékezetére" írtak. A végpontokat, mezőneveket és verziókat a szolgáltató *aktuális* dokumentációjából kell ellenőrizni. A Google Ads API-verziói rendszeresen lejárnak.

---

## 6. lépés: admin / marketing információs felület

**Mit építünk:** [AdminController](../src/Controller/AdminController.php), [ReportService](../src/Service/ReportService.php), [dashboard](../templates/admin/dashboard.php), [admin.js](../public/assets/js/admin.js) (jQuery).

**Mit mutat:** rendelések és bevétel forrásonként, hirdetési költség, **két ROAS-szám egymás mellett**: a Google szerinti és a saját rendeléseinkből számolt. A két érték eltérése maga is információ (hiányzó konverzió-követés, késve érkező adat, attribúciós különbség). *A mintában a Google-adat szimulált, ezért a két szám eleve nem egyezik. Ez nem hiba, a mock véletlen adatokat ad.*

**Biztonság (a legfontosabb a gombnyomás-szerű műveleteknél):**
- Belépés `password_verify`-vel (jelszó-hash, nem sima szöveg). A sikeres belépéskor új munkamenet-azonosító (`session_regenerate_id`).
- **CSRF-védelem:** minden módosító kérés (feladat indítása, jóváhagyás, kilépés) egy titkos tokent vár. Enélkül egy idegen oldal a te böngésződből, a te bejelentkezéseddel indíthatna feladatokat.
- Munkamenet-süti: `HttpOnly` (JS nem olvassa), `SameSite=Lax`.
- A feladat neve csak az ismert listából lehet (nincs "fut bármit").
- Hibás belépésnél nem derül ki, hogy a név vagy a jelszó volt rossz.

**Próbáld ki:** `http://127.0.0.1:8080/admin` (jelszó: `.env`, `ADMIN_PASSWORD`). Nyomd meg a feladat-gombokat, nézd az integrációs naplót, hagyj jóvá egy AI-piszkozatot, és nézd meg a termék oldalát a boltban.

**Mire figyelj:** a belépés-korlátozás itt munkamenetenként számol, ez egy támadót nem állít meg (új munkamenetet nyit). Élesben IP-alapú korlát kell (webszerver vagy WAF szintjén).

---

## 7. lépés: tesztek

[tests/run.php](../tests/run.php): 24 teszt, saját kis futtatóval (nincs külső függőség). Hamis "transporttal" fut, tehát nincs hálózat, gyors és mindig ugyanazt adja.

**Mit védenek:** az újrapróbálás szabályai, hamis ár a rendelésben, készlet-védelem és visszagörgetés, SQL-injekció és LIKE-joker, ERP-foglalás, duplikátum-védelem, XML helyessége, mikro-egység átváltás, GAQL-injekció, részleges feltöltési hiba, AI kimenet megtisztítása és jóváhagyási folyamat.

**A tesztek értékét mutációval ellenőriztem:** szándékosan elrontottam két helyet (az 5xx-újrapróbálást kikapcsoltam, és a rendelés összegét a kliens adatából számoltattam). A tesztek mindkettőt elkapták (3 hibás teszt), a visszaállítás után mind zöld. Egy zöld teszt, ami hibás kódnál is zöld maradna, nem ér semmit.

```powershell
.\scripts\dev.ps1 test
```

**Claude Code-dal:** a legjobb sorrend: *előbb kérj tesztet a kívánt viselkedésre, aztán a kódot.* A teszt a "megbízás": ha zöld, a modell dolgozott jól, nem a hangja.

---

## Hogyan dolgozz Claude Code-dal egy ilyen munkában

1. **Git minden előtt.** Külön ág, kis commitok. A visszavonás ingyenes.
2. **`CLAUDE.md`** ([a projekté](../CLAUDE.md)): ide írd a szabályokat (csak paraméterezett SQL, `e()` kimenet, titok csak `.env`-ben, külső hívás csak a HttpClient-en át). A modell így a cég szabályai szerint dolgozik.
3. **Tervvel kezdj.** "Először csak a tervet írd le, ne kódolj." A terv átnézése olcsóbb, mint a kész kód javítása.
4. **Kis lépések, mindegyik tesztelve.** Egy integráció = kliens + szolgáltatás + job + teszt hamis transporttal.
5. **Te vagy a felelős szem.** Kérdezd magadtól minden kódnál: *Mi történik, ha ez kétszer fut? Ha a hálózat megszakad a közepén? Ha a bemenet rosszindulatú? Ha a külső rendszer lassú vagy üres választ ad?*
6. **Biztonsági átnézés külön menetben** (pl. `/code-review`): SQL-injekció, XSS, jogosultság, titkok.
7. **Éles adaton soha.** Staging környezet és csak-olvasható adatbázis-felhasználó, ha éles adat kell.

## Mi hiányzik (élesítés előtt kötelező)

- **Hozzájárulás-kezelés** (süti-banner) a hirdetési követéshez és a gclid feltöltéshez.
- **Sebességkorlát** a belépésre és a rendelés végpontra (IP szinten), esetleg CAPTCHA.
- **HTTPS, HSTS, Content-Security-Policy.** (A mintában csak az alap biztonsági fejlécek vannak.) A curl HTTPS-hez CA-tanúsítványcsomag kell (`curl.cainfo` a php.ini-ben).
- **Éles hitelesítők és API-verziók:** a Google Ads verzió, developer token, conversion action; a marketplace saját formátuma; az ERP tényleges API-ja.
- **Cron/ütemezés** a feladatokra, és riasztás, ha egy feladat `error` státuszba kerül.
- **Adatbázis-mentés és visszaállítás-próba.**
- **Teljesítmény:** nagy katalógusnál lapozás és indexek áttekintése; a mintában 24 termék van.
- **A webszerver gyökere** csak a `public/` mappa lehet. A `src/`, `.env`, `storage/` nem lehet elérhető kívülről.
- **Jogosultságkezelés:** itt egyetlen admin szerep van.

## Mi igazolt és mi nem

| Állítás | Állapot |
|---|---|
| Rendelés, készlet-tranzakció, API, admin, CSRF | Futtatva és tesztelve ezen a gépen (24 teszt, böngészős végigpróbálás: kosár, hirdetési azonosító megőrzése, rendelés, admin dashboard) |
| ERP, marketplace, Google Ads | **Csak a saját szimulált szerverrel** tesztelve. Az éles szolgáltatók formátumát dokumentáció alapján írtam, éles fiókkal nem próbáltam |
| `ClaudeClient` valódi API-hívás | A kód elkészült, a hívás **kulcs hiányában nem volt kipróbálva**. A mock driver tesztelt |
| Terheléses teszt, párhuzamos rendelés valódi versenyhelyzettel | **Nem készült.** A zárolás elve helyes, de tényleges egyidejű terheléssel nem mértem |
| Biztonsági audit | Átnéztem az alapvető hibatípusokat (SQL-injekció, XSS, CSRF, titkok), de ez nem független biztonsági vizsgálat |

