# PWN Recovery Audit (read-only)

Jednorazowe narzędzie do **fazy 1** projektu PWN Recovery Agent. Zbiera informacje o instalacji
(WordPress, LatePoint, WooCommerce, WP-Cron, poczta) potrzebne do zaprojektowania wtyczki.

## Co robi, a czego nie robi

| Robi | Nie robi |
|---|---|
| `SELECT` / `SHOW` na tabelach LatePoint, WooCommerce i Action Scheduler | żadnych `INSERT/UPDATE/DELETE/CREATE/ALTER` |
| czyta pliki PHP wtyczek LatePoint (szuka hooków i klas) | nie modyfikuje plików |
| zwraca nazwy kolumn, klucze JSON, liczniki, rozkłady kolumn typu `status` | nie zwraca imion, e-maili, telefonów, treści, kwot pojedynczych osób |
| maskuje stałe o nazwach typu `*KEY*`, `*SECRET*`, `*LICENSE*` | nie czyta konfiguracji SMTP ani kluczy API |
| liczy raport na żądanie | nie zapisuje raportu w bazie, nie tworzy opcji ani zadań CRON |

Rozkład wartości jest pokazywany tylko dla kolumn, których nazwa kończy się na `status`, `processor`,
`method`, `portion`, `kind`, `type`, `source` lub `variant`, i tylko wtedy, gdy mają najwyżej 30 różnych wartości.

## Uruchomienie

Zalecane: **kopia stagingowa** serwisu. Instalacja wtyczki na produkcji to też zmiana na produkcji,
więc decyzja należy do Ciebie. Audyt wykonuje kilka pełnych skanów tabel (`COUNT`, `GROUP BY`,
wyszukiwanie `%latepoint%` w `postmeta`), dlatego na produkcji uruchom go poza godzinami ruchu.

1. Spakuj katalog `pwn-recovery-audit/` do ZIP i wgraj przez *Wtyczki → Dodaj nową → Wyślij wtyczkę na serwer*
   (albo skopiuj katalog do `wp-content/plugins/`).
2. Włącz wtyczkę.
3. **Narzędzia → PWN Recovery Audit → Pobierz raport JSON**,
   albo przez WP-CLI: `wp pwn-audit run > pwn-recovery-audit.json`.
4. Przejrzyj plik przed wysłaniem.
5. Dezaktywuj i usuń wtyczkę.

Wymagane uprawnienie: `manage_options`. Formularze są chronione nonce.

## Sekcje raportu

`environment`, `plugins`, `cron`, `latepoint_constants`, `latepoint_tables`, `latepoint_classes`,
`latepoint_hooks`, `latepoint_source_keywords`, `booking_intents_profile`, `bookings_profile`,
`orders_profile`, `completion_linkage`, `abandonment_simulation`, `woocommerce`, `mail`.

Ich znaczenie opisuje `docs/PHASE-1-AUDIT.md`, sekcja 2.
