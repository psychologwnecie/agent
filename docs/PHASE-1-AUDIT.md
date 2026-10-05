# PWN Recovery Agent: faza 1 (audyt i architektura)

Status: **faza 1 wstrzymana.** Audyt z 2026-10-05 pokazał, że LatePoint nie jest zainstalowany na tej instalacji (sekcja „Wyniki audytu”).
Implementacja wtyczki PWN Recovery Agent **nie została rozpoczęta.**

Oznaczenia w dokumencie:

- ✅ zweryfikowane,
- 🔶 hipoteza: do potwierdzenia raportem z Twojej instalacji,
- ❓ potrzebuję decyzji lub informacji od Ciebie.

---

## Wyniki audytu z 2026-10-05 (raport z produkcji)

### ✅ Zweryfikowane

- **Środowisko:** WordPress 7.1.2, PHP 8.2.33, MariaDB 11.8.9, hosting Hostinger, prefiks tabel `wp_`, strefa czasowa Europe/Warsaw.
  Baza danych działa w UTC.
- **LatePoint NIE jest zainstalowany ani aktywny na tej instalacji.** Na liście wtyczek (aktywnych i nieaktywnych)
  nie ma żadnej wtyczki LatePoint, w `wp-content/plugins` nie ma katalogu LatePoint (0 plików, 0 hooków).
  Zostały po nim **44 tabele `wp_latepoint_*`** z danymi oraz dwa zadania CRON `latepoint_*`.
- **Dane LatePoint kończą się 2025-06-20.** Pierwszy intent pochodzi z 2025-03-10. Od ponad roku nic nowego nie przybyło.
- **WooCommerce nie jest zainstalowany.** Płatności w danych LatePoint szły przez `stripe_connect` (292 transakcje `succeeded`).
- **Tabela intentów nazywa się `wp_latepoint_order_intents`, a nie `booking_intents`.** Ma kolumnę `status`:
  `converted` 362, `new` 149. Liczba `new` równa się liczbie intentów bez `order_id` (149).
- **Struktura `cart_items_data`:** `{<klucz>: {variant, subtotal, total, coupon_code, coupon_discount, tax_total,
  item_data: {customer_id, service_id, agent_id, location_id, start_date, start_time, end_date, end_time, duration, ...}}}`.
  Pole `item_data` jest obiektem, nie zakodowanym tekstem. Prawie zawsze jest 1 pozycja na intent.
- **Łańcuch powiązań:** `order_intents.order_id → orders.id → order_items.order_id → bookings.order_item_id`.
  Wszystkie 435 rezerwacji mają `order_item_id`. Jest też `carts.order_intent_id`.
- **29 intentów wskazuje na nieistniejące zamówienia** (np. usunięte). ConversionChecker musi to obsłużyć.
- **Statusy rezerwacji:** `approved` 362, `cancelled` 48, `completed` 25. Statusy płatności zamówień: `fully_paid` 358, `not_paid` 79.
- **LatePoint ma własne kupony** (`wp_latepoint_coupons`, 5 kuponów procentowych). Kupony WooCommerce odpadają, bo WooCommerce nie ma.
- **CRON działa:** 0 zaległych zdarzeń, Action Scheduler 3.9.3 (dostarczany przez inną wtyczkę) uruchamia się co minutę.
  W historii Action Scheduler jest 218 akcji `failed`. Nie dotyczą naszego projektu, ale warto je kiedyś sprawdzić.
- **Poczta:** brak wtyczki SMTP, `wp_mail` korzysta z domyślnej funkcji PHP serwera. To ryzyko dla dostarczalności maili recovery.
- **Symulacja porzuceń:** 0 kandydatów, bo okno 90 dni nie obejmuje danych sprzed roku.

### ❓ Blokujące pytanie

Skoro LatePoint nie działa na tej instalacji od czerwca 2025, **gdzie dziś klienci rezerwują wizyty?**
Dopóki nie wiadomo, gdzie powstają aktualne rezerwacje, nie da się zbudować wykrywania porzuceń.

---

## 0. Co udało się zweryfikować (stan przed audytem)

Ze środowiska, w którym pracuję, **nie mam dostępu** do psychologwnecie.pl, jej bazy danych ani plików.
Polityka sieci zablokowała też wordpress.org, trac i latepoint.com, więc nie mogłem przejrzeć nawet
publicznego kodu LatePoint. W konsekwencji:

- ✅ **nie znam** wersji WordPress, LatePoint ani WooCommerce na Twojej stronie,
- ✅ **nie potwierdziłem** żadnej nazwy tabeli, klasy ani hooka LatePoint,
- 🔶 wszystko, co piszę niżej o wewnętrznej budowie LatePoint, pochodzi z mojej ogólnej wiedzy.
  Może być nieaktualne albo nie pasować do wersji Pro i dodatków. Nie będę na tym budował kodu,
  dopóki raport tego nie potwierdzi.

Zgodnie z zasadą „nie zakładaj nazw, zweryfikuj w instalacji” przygotowałem narzędzie
**`pwn-recovery-audit/`**. To osobna, tylko-do-odczytu wtyczka, która zbiera dokładnie te informacje, których potrzebuję
(opis w `pwn-recovery-audit/README.md`).

Jak przetestowałem audytor: na MariaDB 10.11 z **syntetycznymi** tabelami, których schemat sam wymyśliłem.
Wszystkie 15 sekcji wykonało się bez błędów. W raporcie nie pojawiły się dane osobowe (imiona i e-maile
z danych testowych). Symulacja poprawnie rozróżniła intent porzucony, intent dokończony później
i intent z pustym koszykiem. Test dowodzi tylko, że narzędzie działa i nie ujawnia danych osobowych.
**Nie dowodzi niczego o strukturze LatePoint.**

---

## 1. Jak uruchomić audyt

Szczegóły są w `pwn-recovery-audit/README.md`. W skrócie: najlepiej na stagingu,
*Narzędzia → PWN Recovery Audit → Pobierz raport JSON* albo `wp pwn-audit run > audit.json`.
Następnie przejrzyj plik, prześlij mi go i usuń wtyczkę.

Instalacja wtyczki na produkcji to zmiana na produkcji, więc to Twoja decyzja. Audytor niczego nie zapisuje.

---

## 2. Które pytanie audytu odpowiada której sekcji raportu

| Punkt audytu | Sekcja raportu |
|---|---|
| 1–3. Wersje WP / LatePoint / WooCommerce | `environment`, `plugins`, `latepoint_constants`, `woocommerce.version` |
| 4. Struktura LatePoint | `latepoint_classes`, `latepoint_source_keywords` |
| 5. Tabele customers / intents / bookings / orders / services / agents / locations | `latepoint_tables` (kolumny, indeksy, liczność, rozkłady statusów) |
| 6. Hooki | `latepoint_hooks`: każdy `do_action`/`apply_filters` z plikiem, linią i przykładowymi argumentami |
| 7. Publiczne PHP API | `latepoint_classes`: publiczne metody modeli i helperów, `table_name` |
| 8. Jak sprawdzić, czy intent zakończył się rezerwacją | `completion_linkage`, `abandonment_simulation`, `booking_intents_profile` |
| 9. WooCommerce jako dodatkowe źródło potwierdzenia | `woocommerce`: klucze meta `%latepoint%`, statusy powiązanych zamówień, HPOS, hooki WC podpięte przez LatePoint |
| Struktura `cart_items_data`, `payment_data` | `booking_intents_profile.json`: ścieżki kluczy, typy, wartości tylko dla pól typu `payment_processor` |
| Konfiguracja WP-Cron / Action Scheduler | `cron` |
| Infrastruktura poczty | `mail`: która wtyczka obsługuje `wp_mail` / `phpmailer_init` |
| Czy LatePoint ma własny mechanizm „abandoned” | `latepoint_source_keywords.abandon` |
| Wstępna liczba porzuceń | `abandonment_simulation` (tylko liczniki) |

---

## 3. Proponowana architektura wtyczki

```
pwn-recovery-agent/
  pwn-recovery-agent.php            bootstrap, stałe, autoload
  includes/
    Source/LatePointSource.php      JEDYNE miejsce znające LatePoint (adapter)
    Source/WooCommerceSource.php    odczyt statusów zamówień WC (tylko odczyt)
    Detection/Detector.php          wyszukiwanie kandydatów
    Detection/ConversionChecker.php dopasowanie intent → rezerwacja (sekcja 4)
    Queue/RecoveryRepository.php    tabele wtyczki, blokady, przejścia stanów
    Queue/StateMachine.php          etapy 1–3, stop, completed_no_conversion
    Scheduler/Scheduler.php         Action Scheduler, z WP-Cron jako zapasem
    Mail/Mailer.php                 wp_mail + TEST MODE + zapis wyniku
    Mail/Templates.php              edytowalne szablony, zmienne {{...}}
    Replies/                        faza 6
    AI/                             faza 7 (Draft Only)
    Discounts/                      faza 8
    Admin/                          Dashboard, Leads, Timeline, Settings, Suppression
    Log/Logger.php                  logi techniczne bez treści klinicznych
```

Zasady:

- **Adapter LatePoint.** Cała wiedza o tabelach, modelach i JSON-ach LatePoint jest w `LatePointSource`.
  Po aktualizacji LatePoint zmienia się jeden plik. Adapter przy każdym uruchomieniu sprawdza wersję
  LatePoint i obecność wymaganych kolumn. Gdy czegoś brakuje, **zatrzymuje wykrywanie i wysyłkę**
  i pokazuje ostrzeżenie w panelu, zamiast działać na zgadywanych danych.
- **Oficjalne modele przed SQL.** Jeśli raport pokaże modele z metodami wyszukiwania (🔶 np. `OsBookingModel`),
  użyję ich. Bezpośredni SQL tylko do odczytu i tylko tam, gdzie modele nie wystarczą (np. wydajne wyszukiwanie kandydatów).
- **Recovery nigdy nie zapisuje do tabel LatePoint ani WooCommerce.** Wyjątek to faza 8: utworzenie kuponu,
  po osobnej akceptacji.
- **Hooki jako przyspieszenie, nie źródło prawdy.** Jeśli raport pokaże hook utworzenia rezerwacji lub zamówienia,
  wtyczka użyje go do natychmiastowego zatrzymania recovery. Przed każdą wysyłką i tak nastąpi pełne ponowne sprawdzenie
  w bazie, bo hooki nie wywołują się np. przy imporcie albo przy bezpośrednich zmianach w bazie.

---

## 4. Identyfikacja porzuconej rezerwacji

### 4.1 Kandydat (etap wykrywania)

Intent jest kandydatem, gdy spełnia wszystkie warunki:

1. `customer_id > 0`, a klient istnieje i ma poprawny e-mail,
2. co najmniej jeden cart item ma `service_id` oraz termin (`start_date`, `start_time`); 🔶 dokładne ścieżki kluczy potwierdzi `booking_intents_profile.json.cart_items_data`,
3. termin w koszyku **nie minął**, a do terminu zostało co najmniej X godzin (konfigurowalne). Nie ma sensu pisać „dokończ rezerwację” na termin, który już był,
4. ostatnia aktywność (`max(created_at, updated_at)`) była ≥ 3 h temu (konfigurowalne) i ≤ N dni temu, żeby nie odkopywać starych intentów przy pierwszym uruchomieniu,
5. intent nie jest już w kolejce wtyczki,
6. adres nie jest na liście suppression i nie trwa dla niego cooldown (sekcja 5),
7. `ConversionChecker` (4.2) zwraca `NOT_CONVERTED`.

Samo `order_id = NULL` nie wystarcza (tak jak wymagałeś). To tylko jeden z sygnałów.

### 4.2 ConversionChecker: warstwy dowodu

Sprawdzenie wykonywane jest przy wykryciu i **ponownie, pod blokadą, bezpośrednio przed każdą wiadomością**.

| # | Sygnał | Wynik |
|---|---|---|
| L1 | `intent.order_id` wskazuje istniejące zamówienie LatePoint, które nie jest anulowane | `CONVERTED` (ten sam proces) |
| L2a | Rezerwacja tego samego klienta, utworzona po `intent.created_at` (−1 min tolerancji), z tą samą usługą, tym samym specjalistą i tym samym terminem | `CONVERTED` (dopasowanie dokładne) |
| L2b | jw., ta sama usługa, inny termin lub inny specjalista | `CONVERTED` (ta sama usługa) |
| L2c | jw., inna usługa | `CONVERTED_OTHER`: stop recovery ❓ |
| L2d | Rezerwacja na ten sam slot utworzona **przed** intentem (klient wrócił do formularza po rezerwacji) | `ALREADY_BOOKED`: nie startujemy |
| L3 | Istnieje zamówienie WC powiązane z LatePoint o statusie `pending` / `on-hold` | `PAYMENT_PENDING`: wstrzymanie, bez maila „dokończ rezerwację” ❓ |
| L3b | Zamówienie WC `failed` / `cancelled` | kandydat, ale z flagą `payment_failed` (osobny szablon lub obsługa ręczna ❓) |
| L4 | Żaden z powyższych | `NOT_CONVERTED` |

Szczegóły:

- **Statusy rezerwacji, które liczą się jako „istnieje rezerwacja”**, będą konfiguracją. Wartości domyślne ustalę po zobaczeniu rozkładu
  `bookings_profile.enum_distributions.status` (🔶 prawdopodobnie `approved`, `pending`, `payment_pending`; anulowane nie).
- **Rezerwacja bez płatności** i **rezerwacja utworzona ręcznie przez administratora** zatrzymują recovery. Liczy się fakt rezerwacji, nie jej źródło.
- Rezerwacje sprawdzamy **po `customer_id` oraz po e-mailu klienta**, bo administrator mógł utworzyć drugi rekord klienta z tym samym adresem.
- **Wartość konwersji** bierzemy z rezerwacji lub zamówienia (L1/L2), a nie z intentu, bo klient mógł wybrać inną usługę albo użyć kuponu.
- Okno atrybucji (np. konwersja do 14 dni od pierwszego maila) jest konfigurowalne. Dashboard rozdziela
  „konwersje po mailu” i „konwersje przed wysłaniem maila” (te drugie to nie zasługa recovery).

### 4.3 Wstępna liczba porzuceń

`abandonment_simulation` w raporcie poda przybliżenie, np. liczbę intentów z 90 dni bez rezerwacji,
liczbę unikalnych klientów i liczbę grup po deduplikacji. Pełny test na danych robimy w fazie 2 („system znalazł N…”),
z ręczną weryfikacją kilku rekordów.

---

## 5. Deduplikacja

1. **Klucz sekwencji to znormalizowany e-mail** (trim, lowercase). Na jeden adres przypada najwyżej **jedna aktywna sekwencja**.
2. Nowy intent tego samego klienta w trakcie trwania sekwencji nie tworzy nowej sekwencji. Jest dopinany do istniejącej
   (tabela `pwn_recovery_intents`), a dane do maila są brane z **najnowszego** intentu (usługa, termin).
   Uzasadnienie: ostatni wybór klienta najlepiej odzwierciedla jego intencję.
3. W jednej paczce wykrywania kilka intentów tego samego adresu jest grupowanych. Wygrywa najnowszy.
4. **Cooldown:** po zakończeniu sekwencji, niezależnie od wyniku, nowa sekwencja dla tego adresu może wystartować
   najwcześniej po N dniach (domyślnie 30, konfigurowalne).
5. **Ten sam e-mail dla kilku osób** (np. rodzic i dziecko): dla nas to jeden adresat, więc jedna sekwencja.
   Rezerwacja dowolnej osoby na ten adres zatrzymuje sekwencję. Taki błąd jest bezpieczniejszy niż dublowanie maili.

---

## 6. Tabele wtyczki (projekt)

Wszystkie z prefiksem `$wpdb->prefix`, tworzone przez `dbDelta` przy aktywacji w fazie 2.

**`pwn_recovery`**: pola z Twojej specyfikacji oraz:
`email_hash`, `latest_intent_id`, `lock_token`, `locked_until`, `attempts`, `last_error_code`, `conversion_type`, `updated_at`.
Indeksy: `UNIQUE(intent_id)`, `UNIQUE(intent_key)`, `KEY(status, next_action_at)`, `KEY(email)`, `KEY(customer_id)`, `KEY(converted, converted_at)`.

**`pwn_recovery_intents`**: `recovery_id`, `intent_id` **UNIQUE**, `intent_key`, `seen_at`.
Dzięki temu żaden intent nie trafi do dwóch sekwencji.

**`pwn_recovery_messages`**: `recovery_id`, `direction` (out/in), `stage`, `status`
(queued/sending/sent/failed/test_redirected), `to_hash`, `subject`, `error_code`, `created_at`, `sent_at`.
**`UNIQUE(recovery_id, direction, stage)` dla maili wychodzących** gwarantuje, że ten sam etap nie zostanie wysłany dwukrotnie.
Treści odpowiedzi klientów trafią do osobnej kolumny lub tabeli z krótszą retencją (faza 6).

**`pwn_recovery_events`**: oś czasu do widoku rekordu: `recovery_id`, `type`, `created_at`, `meta` (JSON bez treści klinicznych).

**`pwn_recovery_suppression`**: `email_hash` (SHA-256 znormalizowanego adresu z solą witryny) **UNIQUE**, `reason`, `source`, `created_at`.
Przechowujemy hash, a nie adres, żeby lista wypisanych nie była kolejną bazą e-maili.

**`pwn_recovery_log`**: logi techniczne: `level`, `event`, `recovery_id`, `context` (zanonimizowany JSON), `created_at`. Z rotacją.

### Idempotencja i równoległe uruchomienia CRON

1. **Atomowe przejęcie rekordu:**
   `UPDATE … SET lock_token=%s, locked_until=NOW()+INTERVAL 5 MINUTE WHERE id=%d AND recovery_stage=%d AND status='active' AND (locked_until IS NULL OR locked_until<NOW())`.
   Dalej przechodzi tylko proces, który dostał `affected_rows = 1`.
2. Pod blokadą następuje ponowne sprawdzenie konwersji i suppression.
3. `INSERT` wiersza `queued` do `pwn_recovery_messages`. Naruszenie `UNIQUE` oznacza, że etap już był obsługiwany, więc proces przerywa.
4. `wp_mail()`, a potem `sent` lub `failed`.
5. Jeśli proces padnie między wysłaniem a zapisem statusu (timeout), wiadomość zostaje w stanie `sending`. **Nie wysyłamy jej ponownie
   automatycznie**, tylko oznaczamy do przeglądu. Świadomie wybieram „najwyżej raz” zamiast „co najmniej raz”:
   zdublowany mail do osoby szukającej pomocy psychologicznej jest gorszy niż jeden niewysłany.
6. `failed` (np. `wp_mail` zwrócił `false`): ponowienie z rosnącym odstępem, maksymalnie 3 próby, potem `human_required`.

---

## 7. Scheduler (CRON)

- 🔶 WooCommerce zwykle zawiera **Action Scheduler**. Jego obecność i wersję potwierdzi sekcja `cron.action_scheduler`.
  Jeśli jest dostępny, użyję go: daje trwałą kolejkę, historię wykonań, ponowienia i widok w *Narzędzia → Zaplanowane akcje*.
- Action Scheduler również uruchamia się przez WP-Cron, a WP-Cron domyślnie startuje tylko przy odwiedzinach strony.
  **Rekomendacja:** `define('DISABLE_WP_CRON', true);` w `wp-config.php` oraz systemowy cron co 5 minut
  (`wp cron event run --due-now` albo wywołanie `wp-cron.php`). ❓ Czy hosting na to pozwala?
- Wtyczka zapisuje „heartbeat” ostatniego uruchomienia. Dashboard ostrzega, gdy od ostatniego uruchomienia minęło więcej niż 30 minut.
- Bez systemowego crona wtyczka też będzie działać, tylko z opóźnieniami. Poprawności pilnują blokady opisane w sekcji 6.

---

## 8. Odpowiedzi klientów (faza 6): porównanie wariantów

Każdy mail ma losowy, nieodgadywalny token (np. `R-7f3k9q`) w temacie lub w adresie Reply-To, powiązany z `recovery_id`.
To nie jest numeryczne ID rekordu.

| Wariant | Jak działa | Plusy | Minusy |
|---|---|---|---|
| **A. Istniejąca skrzynka + ręczne wklejenie** | Reply-To to Twoja skrzynka. Odpowiedź wklejasz w panelu rekordu, a AI ją klasyfikuje | zero nowej infrastruktury, pełna kontrola, brak nowych podmiotów przetwarzających | ręczna praca |
| B. Dedykowana skrzynka + IMAP | `recovery@…`, wtyczka odpytuje skrzynkę | automatyzacja | polling (wykluczony bez Twojej zgody), hasło do skrzynki w WP, kruchość |
| C. Inbound webhook dostawcy poczty | subdomena np. `reply.psychologwnecie.pl`, rekord MX u dostawcy obsługującego pocztę przychodzącą, POST na endpoint REST z weryfikacją podpisu | automatyzacja w czasie rzeczywistym, bez pollingu | nowy podmiot przetwarzający (umowa powierzenia), konfiguracja DNS |

**Rekomendacja:** w fazie 6 zacząć od **A**. Endpoint REST zaprojektować tak, żeby później przejść na **C** bez zmian w reszcie systemu.
❓ Z jakiego dostawcy poczty korzystasz? Sekcja `mail` raportu pokaże wtyczkę SMTP, ale nie dostawcę skrzynki.

---

## 9. AI, eskalacja i rabaty: najważniejsze decyzje (szczegóły w fazach 7–8)

- **Deterministyczny filtr bezpieczeństwa przed AI.** Lista fraz (np. dotyczących samobójstwa, samookaleczenia, przemocy,
  skargi, zwrotu, spraw prawnych) ustawia `human_required = true` niezależnie od tego, czy AI zadziała i co zwróci.
  Niedostępność OpenAI także daje `human_required`, nigdy „cichy” brak klasyfikacji.
- **Do OpenAI trafia wyłącznie treść odpowiedzi**, po automatycznym usunięciu e-maili, telefonów i imienia klienta.
  Nie wysyłamy danych rezerwacji.
- **Klucz API w `wp-config.php`** (stała) lub w zmiennej środowiskowej, nigdy w repozytorium ani w bazie danych.
- Dokładne parametry API OpenAI (structured outputs, retencja danych, umowa powierzenia) **sprawdzę w aktualnej dokumentacji
  na początku fazy 7**. Nie podaję ich teraz z pamięci.
- **Rabat: decyduje wyłącznie logika deterministyczna.** 🔶 Ryzyko do rozstrzygnięcia po audycie: intent ma własne pola `coupon_code`
  i `coupon_discount`, co sugeruje, że LatePoint ma własny mechanizm kuponów. Kupon WooCommerce użyty przy kasie WC może nie przenieść się
  do kwot zapisanych w LatePoint. Grozi to rozjazdem wartości zamówienia i błędnym „recovered revenue”. Wybór między kuponem LatePoint
  a kuponem WC zrobimy na podstawie `orders_profile.coupons` i `woocommerce.coupon_api`.

---

## 10. Ryzyka

| Ryzyko | Skutek | Środek zaradczy |
|---|---|---|
| Aktualizacja LatePoint zmienia schemat lub JSON | błędne wykrycia, maile do klientów, którzy już zarezerwowali | adapter + kontrola wersji i kolumn, automatyczne wstrzymanie, test regresji na raporcie z audytu |
| False positive: mail do osoby, która już zarezerwowała | zła obsługa klienta | wielowarstwowy ConversionChecker, sprawdzenie pod blokadą tuż przed wysyłką, ostrożne domyślne statusy |
| Dwa procesy CRON jednocześnie | zdublowany mail | atomowa blokada + `UNIQUE(recovery_id, stage)` |
| Mail „wysłany”, ale niedostarczony (`wp_mail` zwraca `true` przy przyjęciu przez serwer) | fałszywe statystyki | w v1 raportujemy „przekazane do wysyłki”, dostarczalność zależy od SPF/DKIM/DMARC ❓ |
| Brak systemowego crona | opóźnienia etapów | heartbeat + ostrzeżenie + rekomendacja systemowego crona |
| Pierwsze uruchomienie na zaległych danych | fala maili do starych leadów | limit wieku intentu, limit maili na uruchomienie, faza 2 bez wysyłki, faza 5 kontrolowana |
| Dane szczególnej kategorii (sekcja 11) | ryzyko prawne i reputacyjne | minimalizacja danych, retencja, konsultacja prawna przed fazą 5 |
| AI błędnie sklasyfikuje treść kryzysową | brak reakcji na zagrożenie | filtr deterministyczny, tryb Draft Only, `SAFETY_CONCERN` → natychmiastowe wyróżnienie w panelu (❓ i ewentualnie powiadomienie e-mail do Ciebie) |
| TEST MODE wyłączony przez pomyłkę | maile do prawdziwych klientów w trakcie testów | TEST MODE domyślnie ON, wyraźny baner w panelu, osobna flaga „Recovery Enabled” domyślnie OFF |

---

## 11. Prywatność: wstępny rejestr (do uzupełnienia przed fazą 5)

| Dane | Gdzie | Retencja (propozycja ❓) | Zewnętrzne usługi |
|---|---|---|---|
| e-mail, imię, `customer_id`, usługa, specjalista, termin, wartość | `pwn_recovery` | 12 mies. od zakończenia sekwencji, potem anonimizacja | dostawca poczty (wysyłka) |
| treść odpowiedzi klienta | `pwn_recovery_messages` (in) | 90 dni, potem usunięcie treści, zostaje tylko kategoria | OpenAI (faza 7): tylko zanonimizowana treść |
| kategoria powodu, `human_required` | `pwn_recovery` | jw. | brak |
| hash e-maila (suppression) | `pwn_recovery_suppression` | bezterminowo (potrzebne do respektowania sprzeciwu) | brak |
| logi techniczne | `pwn_recovery_log` | 30 dni | brak |

Mechanizmy: link „nie chcę więcej wiadomości” w każdym mailu (token, bez logowania), ręczna blokada w panelu,
usunięcie danych klienta na żądanie (integracja z narzędziem WordPress *Usuń dane osobowe*), automatyczna retencja przez CRON.

**⚠️ Wymaga weryfikacji prawnej przed fazą 5, nie rozstrzygam tego:**

- podstawa prawna wysyłki maili recovery (czy i na jakiej podstawie wolno wysłać wiadomość osobie, która nie dokończyła rezerwacji;
  czy wiadomość ma charakter informacji handlowej lub marketingu bezpośredniego i czy wymaga zgody),
- czy sam fakt rozpoczęcia rezerwacji usługi psychologicznej stanowi dane dotyczące zdrowia w rozumieniu art. 9 RODO.
  Od tego zależy m.in. zakres informacji w mailu (np. czy wolno w nim wymieniać nazwę usługi) i przekazywanie treści do OpenAI,
- obowiązek informacyjny (polityka prywatności) i umowy powierzenia z dostawcą poczty oraz z OpenAI.

---

## 12. Plan implementacji

| Faza | Zakres | Warunek zakończenia |
|---|---|---|
| **1** | audytor (✅ gotowy) → **raport z Twojej instalacji** → potwierdzenie lub korekta tego dokumentu | Twoja akceptacja architektury |
| 2 | tabele wtyczki, adapter LatePoint, Detector, ConversionChecker, Dashboard i Leads (tylko odczyt), ustawienia i feature flagi, log. **Zero wysyłki**: moduł poczty w ogóle nie istnieje | „System znalazł N potencjalnych porzuceń”, ręczna weryfikacja kilku rekordów |
| 3 | Mailer, szablony, TEST MODE (domyślnie ON), zapis wyniku wysyłki | maile tylko na Twój adres testowy |
| 4 | śledzenie konwersji, testy scenariuszy z sekcji 16 specyfikacji | poprawnie wykryte późniejsze rezerwacje |
| 5 | kontrolowana produkcja: limit dzienny, stopniowe włączanie etapów | **po weryfikacji prawnej** i Twojej zgodzie |
| 6 | odpowiedzi klientów (wariant A), suppression z linku | powiązanie odpowiedzi z `recovery_id` |
| 7 | filtr bezpieczeństwa + klasyfikacja OpenAI + Draft Only | AI Auto Send pozostaje OFF |
| 8 | reguły rabatowe + kupony | po decyzji LatePoint vs WC |
| 9 | analityka | — |

Każda faza będzie osobnym commitem lub PR z opisem testów. Nie łączę faz.

---

## 13. Czego potrzebuję od Ciebie, żeby zamknąć fazę 1

1. **Raport JSON z audytora** (najlepiej ze stagingu). Przejrzyj go przed wysłaniem.
2. ❓ Czy masz staging? Jeśli tak, na nim zrobimy fazy 2–4.
3. ❓ Czy hosting pozwala ustawić systemowy cron (albo czy już jest ustawiony)?
4. ❓ Przez co wychodzi poczta ze strony (wtyczka SMTP / dostawca) i czy domena ma SPF/DKIM/DMARC?
5. ❓ Wariant obsługi odpowiedzi: A (rekomendowany na start), B czy C?
6. ❓ Czy rezerwacja **innej** usługi ma zatrzymywać recovery (rekomenduję: tak, jako `CONVERTED_OTHER`)?
7. ❓ Jak traktować `PAYMENT_PENDING` / `payment_failed`: wstrzymanie, osobny szablon czy tylko ręcznie?
8. ❓ Adres e-mail do TEST MODE.
9. Konsultacja prawna z sekcji 11, najpóźniej przed fazą 5.
