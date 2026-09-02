# NS Bridge — Shopify to WordPress Bridge

Plugin WordPress che sincronizza il catalogo Shopify (prodotti, varianti,
colori, formati, prezzi) su prodotti WooCommerce in tempo reale via webhook,
con una riconciliazione giornaliera come rete di sicurezza. WordPress resta
lo storefront pubblico e indicizzabile (URL reali, dati strutturati);
Shopify resta l'unico punto di checkout — il carrello WooCommerce e'
disattivato.

**Richiede WooCommerce attivo.** WooCommerce fornisce il modello dati e
l'admin UI per prodotti semplici/variabili e le loro varianti; NS Bridge lo
usa solo come catalogo, non come motore di vendita.

```
SHOPIFY (webhook products/create|update|delete)
   |
   v
WORDPRESS  /wp-json/ns-bridge/v1/webhook  (HMAC verificato)
   |
   v
Prodotto WooCommerce (semplice o variabile + varianti)
   |
   v
/products/the-cure/  ->  Elementor + SEO/JSON-LD (nativo WooCommerce)  ->  Google

Bottone "Acquista su Shopify" -> https://shop.myshopify.com/cart/{variant_id}:1
   (mai il carrello WooCommerce: il checkout resta solo su Shopify)

Riconciliazione giornaliera (wp ns-bridge reconcile via cron di sistema)
   -> ripulisce eventuali webhook persi, disallineamenti, prodotti rimossi
```

## Perche' webhook + cron giornaliero (non solo polling)

- I webhook Shopify (`products/create`, `products/update`, `products/delete`)
  aggiornano WordPress in tempo quasi reale, senza il ritardo di un polling
  a intervalli fissi.
- Il job giornaliero (`NS_Bridge_Reconciliation::run()`) ripete l'intero pull
  del catalogo e fa da rete di sicurezza: recupera eventuali webhook persi
  (downtime, errori di consegna), disattiva su WP i prodotti non piu'
  presenti su Shopify (soft-delete: stato `draft`, mai cancellazione
  definitiva) e invia un report via email all'amministratore. Tocca solo
  i prodotti con il meta `_ns_bridge_shopify_id`, mai eventuali prodotti
  WooCommerce creati a mano.
- Ogni upsert e' idempotente e protetto contro consegne fuori ordine: un
  payload con `updated_at` piu' vecchio di quello gia' salvato viene
  ignorato.

## Varianti (colori, formati/ml, prezzi)

- Un prodotto Shopify senza opzioni reali (o con una sola variante) diventa
  un `WC_Product_Simple`.
- Un prodotto con opzioni (es. "Formato": 50ml/100ml, "Colore": ...) diventa
  un `WC_Product_Variable`: ogni opzione Shopify e' un attributo prodotto
  WooCommerce (locale, non tassonomia globale) e ogni variante Shopify e'
  una `WC_Product_Variation`, con proprio prezzo, SKU, stock e
  eventualmente immagine dedicata.
- Prezzo e disponibilita' mostrati su WordPress (range prezzo incluso) sono
  quelli calcolati nativamente da WooCommerce a partire dalle varianti —
  nessun campo duplicato da mantenere sincronizzato a mano.
- Varianti rimosse da Shopify vengono cancellate dalle relative
  `product_variation` WordPress ad ogni sync, cosi' il catalogo resta
  sempre coerente al 100% con Shopify.

## Categorie (collezioni Shopify)

- Ogni collezione Shopify (manuale o automatica/smart) diventa una
  categoria prodotto WooCommerce (`product_cat`): titolo, descrizione,
  slug e immagine di copertina (usata come thumbnail della categoria).
- L'assegnazione prodotto -> categoria e' calcolata da zero ad ogni
  riconciliazione e sostituisce quella precedente (non si accumula): un
  prodotto tolto da tutte le sue collezioni Shopify perde anche le
  categorie WooCommerce corrispondenti.
- **Categorie piatte, senza sotto-categorie**, per scelta deliberata: Shopify
  ha introdotto una vera gerarchia nativa delle collezioni solo a luglio
  2026 (Collection Sources API), disponibile solo via GraphQL su versione
  API `2026-07` ed ancora in developer preview al momento in cui questo
  plugin e' stato scritto. Implementare la gerarchia contro uno schema non
  verificabile dal vivo avrebbe rischiato di introdurre un mapping padre/
  figlio silenziosamente sbagliato. Il punto di estensione e' gia' pronto
  (`NS_Bridge_Collection_Sync::resolve_parent_term_id()`), da completare una
  volta che quell'API sara' stabile e verificata contro un negozio reale.
- Le categorie si sincronizzano insieme ai prodotti, dentro la
  riconciliazione (giornaliera o manuale) — non via webhook: aggiungere o
  togliere un prodotto da una collezione su Shopify non genera un webhook
  `products/update`, quindi per ora il cambiamento si vede al piu' tardi
  alla riconciliazione successiva, non istantaneamente. Se serve real-time
  anche qui, si possono aggiungere le sottoscrizioni ai webhook
  `collections/create|update|delete` — non ancora implementate.

## Checkout: sempre e solo su Shopify

Il carrello/checkout WooCommerce e' disattivato per ogni prodotto
sincronizzato (`woocommerce_is_purchasable` filtrato a `false`): il tema/
Elementor non deve nascondere nulla a mano. Al posto del bottone "Aggiungi
al carrello", NS Bridge mostra "Acquista su Shopify":

- prodotto semplice: link diretto a `https://{dominio}/cart/{variant_id}:1`
  (variant ID Shopify della sua unica variante);
- prodotto variabile: un selettore per attributo (taglia/colore/...) che,
  via JS senza dipendenze, calcola la variante scelta e aggiorna il link
  verso il suo `variant_id` Shopify prima di abilitare il bottone.

Questo e' lo "shopify cart permalink": Shopify aggiunge la variante al
carrello e porta l'utente dritto al proprio checkout. Se lo storefront
Shopify richiede una password, questo link va adattato di conseguenza.

## Configurazione

### Creare l'app su Shopify (Dev Dashboard)

Dal 1 gennaio 2026 Shopify ha eliminato il vecchio flusso "Sviluppa app"
dentro l'admin del negozio: le app custom si creano ora dal **Dev
Dashboard** (`dev.shopify.com`), e al posto di un token statico si ottiene
una coppia **Client ID / Client secret** — l'accesso vero e proprio si
ottiene con uno scambio OAuth (vedi sotto), gestito automaticamente dal
plugin.

1. Vai su `dev.shopify.com` e accedi con lo stesso account/organizzazione
   del negozio Shopify (**requisito importante**: l'app e il negozio
   devono appartenere alla stessa organizzazione Shopify, altrimenti lo
   scambio del token fallisce).
2. **Apps** &rarr; **Create app** &rarr; scegli un nome (es. "NS Bridge").
3. Nella configurazione dell'app, abilita gli scope Admin API minimi:
   `read_products`, `read_inventory` — nient'altro.
4. Installa l'app sul negozio target.
5. Vai su **Impostazioni** dell'app: li' trovi **Client ID** e **Client
   secret** — sono i due soli valori che servono al plugin.

### Inserirle nel plugin

Due modi, non alternativi tra loro:

**1. Pagina impostazioni** — menu WP Admin "Prodotti" (WooCommerce) &rarr;
"Impostazioni" (`edit.php?post_type=product&page=ns-bridge-settings`). Da li'
si inseriscono dominio negozio, Client ID, Client secret, versione API e
dominio storefront, e si puo' lanciare "Verifica connessione" per
confermare che le credenziali funzionino (il plugin fa lo scambio OAuth e
prova a leggere `/shop.json`) prima di registrare i webhook. E' il modo
piu' comodo per iniziare (es. il test con i 2 prodotti iniziali).

**2. Costanti in `wp-config.php`** — piu' sicuro per produzione, perche'
il valore non finisce nella tabella `wp_options` ne' e' visibile/esportabile
da nessuna schermata admin:

```php
define('NSBRIDGE_SHOPIFY_SHOP_DOMAIN', 'seedtoskin.myshopify.com');
define('NSBRIDGE_SHOPIFY_CLIENT_ID', 'xxxxxxxxxxxxxxxx');        // Dev Dashboard -> app -> Impostazioni
define('NSBRIDGE_SHOPIFY_CLIENT_SECRET', 'xxxxxxxxxxxxxxxx');    // stessa pagina; usato anche per la firma HMAC dei webhook
define('NSBRIDGE_SHOPIFY_API_VERSION', '2024-10');                // opzionale
define('NSBRIDGE_SHOPIFY_STOREFRONT_DOMAIN', 'seedtoskin.com');   // opzionale, per i link "acquista su Shopify"
```

Un campo definito in `wp-config.php` ha sempre la precedenza: nella pagina
impostazioni appare come disabilitato con un'etichetta che lo segnala,
cosi' non si rischia di avere due valori diversi in conflitto.

### Come funziona lo scambio OAuth (per chi vuole capire cosa succede sotto)

Il Client ID/secret da soli non bastano per chiamare l'Admin API: il
plugin li scambia con un vero access token via [client credentials
grant](https://shopify.dev/docs/apps/build/authentication-authorization/client-secrets)
(`POST https://{dominio}/admin/oauth/access_token`), valido 24 ore. Il
token viene messo in cache (23h, per rinnovarlo un po' prima della
scadenza reale) e rigenerato automaticamente in modo trasparente — non
serve nessuna azione manuale di rinnovo.

## Registrazione dei webhook su Shopify

Dopo aver configurato le credenziali (da una delle due vie sopra), registra
i 3 webhook verso `https://tuosito.com/wp-json/ns-bridge/v1/webhook` in
uno di questi modi:

- pulsante "Registra/verifica webhook su Shopify" nella pagina "Stato &
  Statistiche" dell'admin;
- oppure da riga di comando: `wp ns-bridge register-webhooks`.

Entrambi sono idempotenti: rilanciarli non crea doppioni.

## Sicurezza

L'unica superficie pubblica del plugin e' l'endpoint webhook
(`/wp-json/ns-bridge/v1/webhook`); tutto il resto e' dietro
`manage_options` + nonce. Misure gia' implementate nel codice:

- **Autenticazione webhook**: firma HMAC-SHA256 verificata con
  `hash_equals` (a tempo costante, per non essere vulnerabile a timing
  attack), piu' controllo dell'header `X-Shopify-Shop-Domain` contro il
  dominio configurato. Nessuna richiesta senza firma valida arriva mai a
  toccare il database.
- **Rate limiting sui tentativi falliti**: dopo 20 firme non valide da uno
  stesso IP in 5 minuti, l'endpoint risponde `429` senza nemmeno ricalcolare
  l'HMAC. I webhook legittimi (sempre firmati correttamente) non sono mai
  soggetti a questo limite, quindi un import massivo su Shopify non rischia
  di essere bloccato per errore.
- **Protezione SSRF sul download immagini**: il plugin scarica le immagini
  prodotto (`media_sideload_image`) solo da un allowlist di host
  (`cdn.shopify.com`, `*.myshopify.com`, il dominio negozio/storefront
  configurato). Qualsiasi altro host nel payload viene rifiutato e loggato:
  anche in caso di bug futuro o payload malformato, il server WordPress non
  puo' essere usato per raggiungere indirizzi interni (metadata cloud,
  rete privata, ecc.).
- **Sanitizzazione esplicita in scrittura**: titolo, handle, SKU, brand,
  attributi/varianti passano da `sanitize_text_field()`; la descrizione
  prodotto da `wp_kses_post()`. Questo e' aggiuntivo (difesa in profondita')
  rispetto al filtro `kses` che WordPress applica gia' di default ai
  contenuti salvati da un contesto non autenticato come un webhook.
- **Validazione dominio**: i campi dominio negozio/storefront accettano solo
  un hostname (niente schema, path, spazi): alimentano chiamate HTTP
  server-side (Admin API, allowlist immagini), quindi un valore malformato
  viene rifiutato al salvataggio invece che "normalizzato" alla meglio.
- **Nessun crash su payload inatteso**: un webhook con corpo non-JSON o
  JSON valido ma non un oggetto (es. un numero o `null`) viene rifiutato
  con `400` invece di generare un errore fatale PHP.
- **Riconciliazione isolata**: tocca solo i prodotti con il meta
  `_ns_bridge_shopify_id`, mai prodotti WooCommerce creati manualmente.
- **Secret**: mai loggati, mai esposti via REST (`ns_bridge_settings` non e'
  registrato con `register_setting`/`show_in_rest`), mascherati in UI.
  Se salvati da pagina impostazioni invece che da `wp-config.php`, il
  plugin mostra un avviso che consiglia di spostarli.

**Cosa resta fuori dal codice del plugin** (livello infrastruttura/hosting,
particolarmente importante per uno store ad alto fatturato):

- **Token Shopify con scope minimo**: l'app Admin API deve avere solo
  `read_products` (e `read_inventory` se serve lo stock) — mai scope di
  scrittura o accesso a ordini/clienti/pagamenti, che il plugin non usa.
- **HTTPS obbligatorio** su WordPress (l'endpoint webhook non deve mai
  essere raggiungibile in chiaro) e certificato valido.
- **WAF/rate limiting a livello di edge** (Cloudflare o equivalente)
  davanti a tutto `/wp-json/`, come ulteriore livello sopra il rate
  limiting applicativo del plugin.
- **Hardening WordPress core**: aggiornamenti automatici di sicurezza,
  `DISALLOW_FILE_EDIT` in `wp-config.php`, 2FA per gli account
  amministratore, limitazione tentativi di login, backup regolari testati.
- **Accesso al database e agli hosting file** limitato e con credenziali
  ruotate — e' li' che finiscono i secret se si sceglie la via DB invece di
  `wp-config.php`.
- **Rotazione periodica** di Client ID/Client secret (rigenerabili dal Dev
  Dashboard Shopify in qualsiasi momento; basta poi aggiornarli in NS
  Bridge e ri-registrare i webhook).

## Import iniziale senza accesso SSH: sincronizzazione a blocchi

"Esegui riconciliazione ora" gira tutto dentro una singola richiesta del
browser: su un catalogo con molti prodotti/immagini (soprattutto la
prima volta, quando nulla e' ancora in cache) puo' superare il limite di
esecuzione PHP del server e interrompersi a meta' con un "errore critico"
di WordPress. Se non hai accesso SSH/WP-CLI per lanciare
`wp ns-bridge reconcile` (vedi sotto), usa invece la sezione
**"Sincronizzazione iniziale a blocchi"** nella pagina Stato &
Statistiche: elabora un piccolo blocco di prodotti alla volta (20 per
richiesta), riprende da solo da dove si era fermato, e la pagina invia
automaticamente il blocco successivo ogni 2 secondi finche' non finisce —
puoi anche chiudere la scheda in qualsiasi momento e tornare piu' tardi,
riprende esattamente da dove eri arrivato. Pensata per essere usata una
volta sola per il popolamento iniziale: dopo, i prodotti nuovi/modificati
arrivano via webhook in tempo reale.

## Cron di sistema per la riconciliazione giornaliera

Il plugin pianifica anche un fallback via WP-Cron, ma WP-Cron scatta solo
al primo visitatore dopo l'orario previsto: su uno storefront a basso
traffico notturno non e' affidabile per un orario fisso. Meglio un vero
cron di sistema che lancia il comando WP-CLI direttamente:

```
0 3 * * * cd /percorso/del/sito && wp ns-bridge reconcile >> /var/log/ns-bridge-reconcile.log 2>&1
```

Se si usa il cron di sistema, si puo' disattivare lo pseudo-cron di
WordPress aggiungendo in `wp-config.php`:

```php
define('DISABLE_WP_CRON', true);
```

## Pagina "Stato & Statistiche"

Sotto il menu "Prodotti" di WooCommerce: conteggio prodotti sincronizzati
pubblicati/in bozza, esito e timestamp dell'ultima riconciliazione, stato
di registrazione dei 3 webhook (con l'indirizzo endpoint atteso), log degli
ultimi 20 eventi di sincronizzazione (successi ed errori), e due azioni
manuali — "Esegui riconciliazione ora" e "Registra/verifica webhook" —
utili soprattutto durante il test iniziale con i primi prodotti.

## Note SEO

- Ogni prodotto ha una URL reale e stabile (`/products/handle-shopify/`,
  base permalink impostata automaticamente all'attivazione) — nessuna
  pagina va creata a mano.
- Title, meta description e canonical sono generati automaticamente da
  `NS_Bridge_SEO` lato server (nessun rendering JS necessario per Google). Se
  e' attivo Yoast/RankMath/SEOPress, il plugin lascia gestire a loro
  title/description/canonical.
- I dati strutturati `Product`/`Offer` (incluso `AggregateOffer` con range
  di prezzo per i prodotti variabili) sono generati automaticamente da
  WooCommerce stesso a partire da prezzo, stock e SKU sincronizzati — NS Bridge
  non li duplica.
- Un editor puo' sovrascrivere titolo/descrizione a mano tramite i meta
  `_ns_bridge_seo_title` / `_ns_bridge_seo_description`: la sync automatica non li
  tocca mai (scrive solo nei corrispondenti `_auto`, usati come fallback).
- Lo storefront Shopify non deve restare indicizzabile pubblicamente
  (password o dominio non pubblicato), per evitare contenuti duplicati con
  WordPress.

## Cosa manca ancora

- Attributi come tassonomie globali WooCommerce (oggi sono attributi
  locali per prodotto) — utile solo se serve un filtro/faccetta per
  colore/formato condiviso tra prodotti nel catalogo WordPress.
- Sotto-categorie vere (gerarchia collezioni Shopify) — vedi "Categorie
  (collezioni Shopify)" sopra: in attesa che la nuova Collection Sources
  API esca da developer preview e sia verificabile dal vivo.
- Sync categorie in tempo reale via webhook (oggi solo in riconciliazione).
- Generazione automatica della sitemap prodotti (consigliato un plugin SEO
  con supporto sitemap, es. Yoast/RankMath, gia' compatibile nativamente
  con i prodotti WooCommerce).
