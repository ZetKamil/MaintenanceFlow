# MaintenanceFlow — Korte uitleg (1 A4)
**Case:** Verlinde Beveiliging BV (Zwevegem) · **Stack:** Laravel 13, Vue 3 SPA, Tailwind CSS v4, SQLite · **Tests:** 14 PHPUnit tests (122 assertions)

---

## 1. Waarom het oude BuildDesk Pro niet werkte (CSV-analyse)
Na het nakijken van de originele BuildDesk-bestanden (`BD_EXP_*_20261109.csv`) werd meteen duidelijk waarom Dirk het overzicht verloor:
* **Geen betrouwbare onderhoudsdatums:** **185 van de 193 installaties (96%)** hadden een leeg veld `volgende` (en bij 4 andere stond `volgende` zelfs vóór `laatste` — in totaal waren er dus maar 4 geldige datums). Daardoor hadden **56 branddetectie-installaties (`BRAND` / `BRANDDET`)** geen duidelijke jaarlijkse opvolging, terwijl dat wettelijk verplicht is.
* **Vervuilde en tegenstrijdige data:** Vrije tekst bij frequenties (`1J`, `12M`, `jaarlijks`, `?`), **49 werkbonnen met foute uren** (einduur vóór startuur, bv. `13:00–10:00`), **6 verschillende telefoonformaten**, **7 inactieve klanten (`actief=N`)** met toch actieve plannen en 4 geplande werkbonnen, **3 dubbele boekingen** in de planning en **14 installaties zonder klant**.
* **Geen werkbare flow voor techniekers:** Op **559 werkbonnen** waren er maar **53 uitgevoerde uurregels**, omdat het oude systeem niet handig was op de gsm.

---

## 2. Mijn oplossing en belangrijkste keuzes
* **Propere en snelle opstart (`php artisan migrate --seed`):** Alle fictieve CSV-bestanden zitten mee in de repo (`database/data/builddesk/`), zodat het project op macOS en Windows meteen opstart met volledige data. De importer (`BuildDeskNormalizer`) zet datums en frequenties recht, geeft **elke `BRAND`-installatie automatisch een cyclus van 12 maanden**, herstelt omgekeerde uren (`+2u`), zet Belgische nummers om naar één formaat (**E.164 `+32...`**), zet installaties van inactieve klanten op inactief en logt **117 waarschuwingen** in plaats van fouten te verbergen.
* **Rustige interface zonder "SAP-drukte":**
  * **Voor Dirk (zaakvoerder):** Hij ziet direct de lijst **"Needs Contact"** (branddetectie en achterstallige klanten eerst) naast de teambezetting om vlot in te plannen. In **Planning** toont een overzichtelijke 5-daagse **Technieker × Weekdag-matrix** zonder onnodige kaders meteen wie nog vrij is. Oude dubbele boekingen krijgen een **`Overlap`**-label en nieuwe conflicten worden door de backend geblokkeerd (`hasScheduleConflict`).
  * **Voor techniekers (op gsm):** Ze zien enkel hun eigen bezoeken, kunnen met één tik bellen, openen enkel indien nodig de lijst van onderdelen (**1.010 componenten** netjes ingeklapt), klikken op `Start Visit` en vullen een kort **Service Report** in. Alles gebeurt in één **`DB::transaction()`**: rapport + materialen opslaan, werkbon op `completed` zetten en de volgende onderhoudsdatum automatisch vooruitzetten.
* **Veiligheid & tests:** Native Laravel-sessiecookies (Same-Origin, geen extra token-complexiteit) en `WorkOrderPolicy` zodat een technieker geen werkbon van een collega kan openen (`403 Forbidden`), getest met **14 automatische PHPUnit-tests (122 assertions)**.

---

## 3. Wat ik bewust heb weggelaten & eerste vragen voor Dirk
* **Bewust buiten deze MVP gelaten:** Volledige **klanten- en factuur-CRUD** (BuildDesk/boekhouding blijft in fase 1 de bron voor master data en facturatie), PDF-attesten, extra rollen zoals *Dispatcher/Sales* (later eenvoudig toe te voegen via `UserRole`) en het automatisch samenvoegen van dubbele klanten (*Kapsalon Mieke* / *VZW De Klaproos* met verschillende codes).
* **Eerste vragen voor Dirk voor we live gaan:**
  1. Bij welke klant horen de **14 overgeslagen installaties** zonder `klant`-code (en 16 historische werkbonnen)?
  2. Mogen de **4 geplande werkbonnen** van inactieve klanten (`actief=N`) geannuleerd worden, en hoe verplaatsen we de **3 gemarkeerde `Overlap`-afspraken**?
  3. Welke dubbele klantenkaarten mogen we samenvoegen zonder gevolgen voor de boekhouding?

