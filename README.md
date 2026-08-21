# AxRel — Shopify to WordPress Bridge

Plugin WordPress che sincronizza il catalogo Shopify su WordPress in tempo
reale via webhook, con una riconciliazione giornaliera come rete di
sicurezza. WordPress resta lo storefront pubblico e indicizzabile (URL
reali, sitemap, dati strutturati); Shopify resta il commerce engine e
l'unico punto di checkout.

```
SHOPIFY (webhook products/create|update|delete)
   |
   v
WORDPRESS  /wp-json/axrel-shopify/v1/webhook  (HMAC verificato)
   |
   v
axrel_product (CPT)  ->  /products/the-cure/  ->  Elementor + SEO/JSON-LD  ->  Google

Riconciliazione giornaliera (wp axrel reconcile via cron di sistema)
   -> ripulisce eventuali webhook persi, disallineamenti, prodotti rimossi
```

## Perche' webhook + cron giornaliero (non solo polling)

- I webhook Shopify (`products/create`, `products/update`, `products/delete`)
  aggiornano WordPress in tempo quasi reale, senza il ritardo di un polling
  a intervalli fissi.
- Il job giornaliero (`Axrel_Reconciliation::run()`) ripete l'intero pull
  del catalogo e fa da rete di sicurezza: recupera eventuali webhook persi
  (downtime, errori di consegna), disattiva su WP i prodotti non piu'
  presenti su Shopify (soft-delete: stato `draft`, mai cancellazione
  definitiva) e invia un report via email all'amministratore.
- Ogni upsert e' idempotente e protetto contro consegne fuori ordine: un
  payload con `updated_at` piu' vecchio di quello gia' salvato viene
  ignorato.

## Configurazione (wp-config.php)

```php
define('AXREL_SHOPIFY_SHOP_DOMAIN', 'seedtoskin.myshopify.com');
define('AXREL_SHOPIFY_ADMIN_TOKEN', 'shpat_xxxxxxxxxxxxxxxx'); // Admin API access token
define('AXREL_SHOPIFY_WEBHOOK_SECRET', 'xxxxxxxxxxxxxxxx');    // secret del webhook Shopify
define('AXREL_SHOPIFY_API_VERSION', '2024-10');                // opzionale
define('AXREL_SHOPIFY_STOREFRONT_DOMAIN', 'seedtoskin.com');   // opzionale, per i link "acquista su Shopify"
```

Queste sono credenziali sensibili: vanno in `wp-config.php` (fuori dal
repository e fuori dal database), non in un'opzione del pannello admin.

## Registrazione dei webhook su Shopify

Dopo aver attivato il plugin e impostato le costanti sopra:

```
wp axrel register-webhooks
```

Registra (in modo idempotente) i 3 webhook verso
`https://tuosito.com/wp-json/axrel-shopify/v1/webhook`.

## Cron di sistema per la riconciliazione giornaliera

Il plugin pianifica anche un fallback via WP-Cron, ma WP-Cron scatta solo
al primo visitatore dopo l'orario previsto: su uno storefront a basso
traffico notturno non e' affidabile per un orario fisso. Meglio un vero
cron di sistema che lancia il comando WP-CLI direttamente:

```
0 3 * * * cd /percorso/del/sito && wp axrel reconcile >> /var/log/axrel-reconcile.log 2>&1
```

Se si usa il cron di sistema, si puo' disattivare lo pseudo-cron di
WordPress aggiungendo in `wp-config.php`:

```php
define('DISABLE_WP_CRON', true);
```

## Note SEO

- Ogni prodotto ha una URL reale e stabile (`/products/handle-shopify/`),
  generata via CPT `axrel_product` — nessuna pagina va creata a mano.
- Title, meta description, canonical e JSON-LD `Product` sono generati
  automaticamente da `Axrel_SEO` lato server (nessun rendering JS
  necessario per Google). Se e' attivo Yoast/RankMath/SEOPress, il plugin
  lascia a loro title/description/canonical e genera solo il JSON-LD.
- Un editor puo' sovrascrivere titolo/descrizione a mano tramite i meta
  `_axrel_seo_title` / `_axrel_seo_description`: la sync automatica non li
  tocca mai (scrive solo nei corrispondenti `_auto`, usati come fallback).
- Lo storefront Shopify non deve restare indicizzabile pubblicamente
  (password o dominio non pubblicato), per evitare contenuti duplicati con
  WordPress.

## Cosa manca ancora

- Pagina impostazioni in admin (oggi le credenziali sono solo da
  `wp-config.php`).
- Gestione varianti multiple (oggi si usa la prima variante per
  prezzo/SKU/disponibilita').
- Generazione automatica della sitemap prodotti (consigliato un plugin SEO
  con supporto sitemap per CPT, es. Yoast/RankMath abilitato su
  `axrel_product`).
