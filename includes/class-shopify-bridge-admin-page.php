<?php
defined('ABSPATH') || exit;

/**
 * Admin UI for the bridge: API keys + connection test on one page,
 * sync statistics/log/manual actions on another. Both live under their
 * own top-level "Shopify Bridge" sidebar menu.
 */
class Shopify_Bridge_Admin_Page {

	const SETTINGS_SLUG = 'shopify-bridge-settings';
	const STATUS_SLUG   = 'shopify-bridge-status';

	public static function register_menu() {
		add_menu_page(
			'Shopify Bridge',
			'Shopify Bridge',
			'manage_options',
			self::SETTINGS_SLUG,
			[__CLASS__, 'render_settings_page'],
			'dashicons-store',
			56
		);

		add_submenu_page(
			self::SETTINGS_SLUG,
			'Impostazioni Shopify Bridge',
			'Impostazioni',
			'manage_options',
			self::SETTINGS_SLUG,
			[__CLASS__, 'render_settings_page']
		);

		add_submenu_page(
			self::SETTINGS_SLUG,
			'Stato & Statistiche Shopify Bridge',
			'Stato & Statistiche',
			'manage_options',
			self::STATUS_SLUG,
			[__CLASS__, 'render_status_page']
		);
	}

	private static function page_url($slug, $extra = []) {
		return add_query_arg(array_merge(['page' => $slug], $extra), admin_url('admin.php'));
	}

	/* ---------------------------------------------------------------- */
	/* Settings page                                                    */
	/* ---------------------------------------------------------------- */

	private static function render_woocommerce_missing_notice() {
		if (class_exists('WC_Product_Variable')) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>WooCommerce non risulta attivo.</strong> Shopify Bridge usa i tipi di prodotto di WooCommerce (semplice/variabile) per gestire varianti, colori e prezzi: installa e attiva WooCommerce prima di sincronizzare il catalogo.</p></div>';
	}

	private static function render_secrets_in_db_notice() {
		$secret_keys = ['client_secret'];
		$in_db = [];
		foreach ($secret_keys as $key) {
			if (!Shopify_Bridge_Settings::is_locked_by_constant($key) && Shopify_Bridge_Settings::get_stored_value($key) !== '') {
				$in_db[] = Shopify_Bridge_Settings::FIELDS[$key]['label'];
			}
		}
		if (!$in_db) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p><strong>Sicurezza:</strong> %s attualmente salvato/i nel database (tabella wp_options). Per un negozio ad alto traffico/fatturato consigliamo di spostarli come costanti in <code>wp-config.php</code> (fuori dal database, non esportabile da nessuna schermata admin, non raggiungibile da un backup del solo DB) — vedi il README.</p></div>',
			esc_html(implode(' e ', $in_db))
		);
	}

	public static function render_settings_page() {
		$notice = isset($_GET['ns_bridge_notice']) ? sanitize_key($_GET['ns_bridge_notice']) : '';
		?>
		<div class="wrap">
			<h1>Impostazioni Shopify Bridge</h1>
			<?php self::render_woocommerce_missing_notice(); ?>
			<?php self::render_secrets_in_db_notice(); ?>
			<?php self::render_notice($notice); ?>

			<p>Chiavi e parametri per collegare WordPress al negozio Shopify. Un
			campo puo' anche essere definito in <code>wp-config.php</code> (piu'
			sicuro, consigliato per token e secret): in quel caso qui appare
			bloccato e il valore di <code>wp-config.php</code> ha sempre la
			precedenza.</p>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="ns_bridge_save_settings">
				<?php wp_nonce_field('ns_bridge_save_settings'); ?>

				<table class="form-table" role="presentation">
					<?php foreach (Shopify_Bridge_Settings::FIELDS as $key => $field) : ?>
						<tr>
							<th scope="row"><label for="ns_bridge_<?php echo esc_attr($key); ?>"><?php echo esc_html($field['label']); ?></label></th>
							<td>
								<?php self::render_field($key, $field); ?>
								<?php if (!empty($field['help'])) : ?>
									<p class="description"><?php echo esc_html($field['help']); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<?php submit_button('Salva modifiche'); ?>
			</form>

			<hr>

			<h2>Verifica connessione</h2>
			<p>Chiama <code>GET /shop.json</code> su Shopify con le credenziali attuali per confermare che dominio e token siano validi.</p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="ns_bridge_test_connection">
				<?php wp_nonce_field('ns_bridge_test_connection'); ?>
				<?php submit_button('Verifica connessione a Shopify', 'secondary', 'submit', false); ?>
			</form>

			<hr>

			<h2>Diagnostica accesso metaobject</h2>
			<p>Interroga un metaobject specifico usando il token della <strong>nostra app</strong> (non il tuo login
			amministratore) — utile per capire se un "nessun elemento" nel debug box e' davvero un problema di
			permessi dell'app o qualcos'altro, confrontando questa risposta con la stessa query fatta su
			<a href="https://shopify-graphiql-app.shopifycloud.com/" target="_blank" rel="noopener">GraphiQL</a> (che
			usa i tuoi permessi pieni da amministratore).</p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="ns_bridge_test_metaobject">
				<?php wp_nonce_field('ns_bridge_test_metaobject'); ?>
				<p>
					<label for="ns_bridge_test_metaobject_gid">GID del metaobject (es. <code>gid://shopify/Metaobject/123456789</code>)</label><br>
					<input type="text" id="ns_bridge_test_metaobject_gid" name="ns_bridge_test_metaobject_gid" value="" class="regular-text" placeholder="gid://shopify/Metaobject/...">
				</p>
				<?php submit_button('Interroga questo metaobject con la nostra app', 'secondary', 'submit', false); ?>
			</form>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:1em;">
				<input type="hidden" name="action" value="ns_bridge_test_scopes">
				<?php wp_nonce_field('ns_bridge_test_scopes'); ?>
				<?php submit_button('Mostra gli scope effettivi del token attuale', 'secondary', 'submit', false); ?>
			</form>

			<hr>

			<h2>Setup automatico campi prodotto (una tantum)</h2>
			<p>Crea su Shopify, via API, tutte le definizioni di metafield e metaobject della product page dinamica
			(vedi README, sezione "Product page dinamica") con il tipo giusto gia' impostato — evita di doverle
			creare a mano una per una nell'interfaccia Shopify, dove scegliere il tipo sbagliato (es. "Testo
			multiriga" invece di "Rich text") rompe la sync senza dare nessun errore visibile.</p>
			<p class="description" style="color:#d63638;">
				<strong>Attenzione:</strong> questa operazione scrive su Shopify (crea definizioni), quindi l'app
				deve avere temporaneamente gli scope <code>write_products</code> e
				<code>write_metaobject_definitions</code> nel Dev Dashboard Shopify, oltre a quelli in lettura gia'
				configurati. Puoi rimuoverli di nuovo subito dopo: la sync ordinaria del plugin legge soltanto, non
				scrive mai su Shopify. Puoi rilanciare questa operazione piu' volte senza rischi: una definizione
				gia' esistente viene segnalata, non duplicata o sovrascritta.
			</p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="ns_bridge_setup_definitions">
				<?php wp_nonce_field('ns_bridge_setup_definitions'); ?>
				<?php submit_button('Crea definizioni su Shopify', 'secondary', 'submit', false); ?>
			</form>

			<p style="margin-top:2em;">
				<a href="<?php echo esc_url(self::page_url(self::STATUS_SLUG)); ?>">Vai a Stato &amp; Statistiche &rarr;</a>
			</p>
		</div>
		<?php
	}

	private static function render_field($key, array $field) {
		$locked = Shopify_Bridge_Settings::is_locked_by_constant($key);
		$id     = 'ns_bridge_' . $key;

		if ($locked) {
			printf(
				'<input type="text" id="%1$s" class="regular-text" value="%2$s" disabled> <p class="description">Definito in wp-config.php come <code>%3$s</code>.</p>',
				esc_attr($id),
				esc_attr($field['type'] === 'password' ? self::mask(constant($field['const'])) : constant($field['const'])),
				esc_html($field['const'])
			);
			return;
		}

		$stored = Shopify_Bridge_Settings::get_stored_value($key);

		if ($field['type'] === 'password') {
			$placeholder = $stored !== '' ? 'Configurato — lascia vuoto per non modificare (' . self::mask($stored) . ')' : 'Non configurato';
			printf(
				'<input type="password" id="%1$s" name="%2$s" class="regular-text" value="" placeholder="%3$s" autocomplete="new-password">',
				esc_attr($id),
				esc_attr($key),
				esc_attr($placeholder)
			);
			return;
		}

		printf(
			'<input type="text" id="%1$s" name="%2$s" class="regular-text" value="%3$s" placeholder="%4$s">',
			esc_attr($id),
			esc_attr($key),
			esc_attr($stored),
			esc_attr($field['default'])
		);
	}

	private static function mask($value) {
		$value = (string) $value;
		return strlen($value) <= 4 ? '****' : str_repeat('*', strlen($value) - 4) . substr($value, -4);
	}

	public static function handle_save_settings() {
		if (!current_user_can('manage_options')) {
			wp_die('Non autorizzato');
		}
		check_admin_referer('ns_bridge_save_settings');

		$rejected = Shopify_Bridge_Settings::update($_POST);
		Shopify_Bridge_Shopify_Client::clear_cached_token();

		if ($rejected) {
			set_transient('ns_bridge_settings_rejected_fields', $rejected, 60);
			wp_safe_redirect(self::page_url(self::SETTINGS_SLUG, ['ns_bridge_notice' => 'saved_with_errors']));
			exit;
		}

		wp_safe_redirect(self::page_url(self::SETTINGS_SLUG, ['ns_bridge_notice' => 'saved']));
		exit;
	}

	public static function handle_setup_definitions() {
		if (!current_user_can('manage_options')) {
			wp_die('Non autorizzato');
		}
		check_admin_referer('ns_bridge_setup_definitions');

		$client = new Shopify_Bridge_Shopify_Client();
		if (!$client->is_configured()) {
			wp_safe_redirect(self::page_url(self::SETTINGS_SLUG, ['ns_bridge_notice' => 'definitions_setup_fail']));
			exit;
		}

		$log = Shopify_Bridge_Setup_Definitions::run($client);
		set_transient('ns_bridge_definitions_setup_result', $log, 300);

		wp_safe_redirect(self::page_url(self::SETTINGS_SLUG, ['ns_bridge_notice' => 'definitions_setup']));
		exit;
	}

	public static function handle_test_connection() {
		if (!current_user_can('manage_options')) {
			wp_die('Non autorizzato');
		}
		check_admin_referer('ns_bridge_test_connection');

		$client = new Shopify_Bridge_Shopify_Client();
		if (!$client->is_configured()) {
			set_transient('ns_bridge_test_connection_result', 'Dominio negozio o token mancanti.', 60);
			wp_safe_redirect(self::page_url(self::SETTINGS_SLUG, ['ns_bridge_notice' => 'test_fail']));
			exit;
		}

		$shop = $client->get_shop();
		if (is_wp_error($shop)) {
			set_transient('ns_bridge_test_connection_result', $shop->get_error_message(), 60);
			wp_safe_redirect(self::page_url(self::SETTINGS_SLUG, ['ns_bridge_notice' => 'test_fail']));
			exit;
		}

		set_transient('ns_bridge_test_connection_result', $shop['name'] ?? 'connesso', 60);
		wp_safe_redirect(self::page_url(self::SETTINGS_SLUG, ['ns_bridge_notice' => 'test_ok']));
		exit;
	}

	/**
	 * Runs the exact same single-metaobject query a merchant would test in
	 * Shopify's GraphiQL app, but through OUR app's own Admin API token
	 * instead of the merchant's own admin session — isolates whether a
	 * field resolving empty is an app-permission problem (this returns
	 * null/error while GraphiQL succeeds) or something else (both agree).
	 */
	public static function handle_test_metaobject() {
		if (!current_user_can('manage_options')) {
			wp_die('Non autorizzato');
		}
		check_admin_referer('ns_bridge_test_metaobject');

		$gid = sanitize_text_field(wp_unslash($_POST['ns_bridge_test_metaobject_gid'] ?? ''));
		if ($gid === '') {
			set_transient('ns_bridge_test_metaobject_result', 'Nessun GID inserito.', 60);
			wp_safe_redirect(self::page_url(self::SETTINGS_SLUG, ['ns_bridge_notice' => 'test_metaobject_fail']));
			exit;
		}

		$client = new Shopify_Bridge_Shopify_Client();
		if (!$client->is_configured()) {
			set_transient('ns_bridge_test_metaobject_result', 'Dominio negozio o token mancanti.', 60);
			wp_safe_redirect(self::page_url(self::SETTINGS_SLUG, ['ns_bridge_notice' => 'test_metaobject_fail']));
			exit;
		}

		$query = 'query TestMetaobjectAccess($id: ID!) { metaobject(id: $id) { id type handle fields { key value } } }';
		$data  = $client->graphql($query, ['id' => $gid]);

		if (is_wp_error($data)) {
			set_transient('ns_bridge_test_metaobject_result', $data->get_error_message(), 60);
			wp_safe_redirect(self::page_url(self::SETTINGS_SLUG, ['ns_bridge_notice' => 'test_metaobject_fail']));
			exit;
		}

		set_transient('ns_bridge_test_metaobject_result', wp_json_encode($data), 60);
		wp_safe_redirect(self::page_url(self::SETTINGS_SLUG, ['ns_bridge_notice' => 'test_metaobject_ok']));
		exit;
	}

	/**
	 * Lists the Admin API scopes the CURRENT token actually carries (via
	 * currentAppInstallation), as opposed to what the Dev Dashboard's scope
	 * selector shows as checked. A scope checked in the dashboard config but
	 * missing here means that configuration never propagated to the live
	 * installation on this store — the one thing the metaobject-access test
	 * alone can't distinguish from "scope not actually granted at all".
	 */
	public static function handle_test_scopes() {
		if (!current_user_can('manage_options')) {
			wp_die('Non autorizzato');
		}
		check_admin_referer('ns_bridge_test_scopes');

		$client = new Shopify_Bridge_Shopify_Client();
		if (!$client->is_configured()) {
			set_transient('ns_bridge_test_scopes_result', 'Dominio negozio o token mancanti.', 60);
			wp_safe_redirect(self::page_url(self::SETTINGS_SLUG, ['ns_bridge_notice' => 'test_scopes_fail']));
			exit;
		}

		$data = $client->graphql('{ currentAppInstallation { accessScopes { handle } } }');

		if (is_wp_error($data)) {
			set_transient('ns_bridge_test_scopes_result', $data->get_error_message(), 60);
			wp_safe_redirect(self::page_url(self::SETTINGS_SLUG, ['ns_bridge_notice' => 'test_scopes_fail']));
			exit;
		}

		$scopes = wp_list_pluck($data['currentAppInstallation']['accessScopes'] ?? [], 'handle');
		sort($scopes);
		set_transient('ns_bridge_test_scopes_result', $scopes, 60);
		wp_safe_redirect(self::page_url(self::SETTINGS_SLUG, ['ns_bridge_notice' => 'test_scopes_ok']));
		exit;
	}

	private static function render_notice($notice) {
		switch ($notice) {
			case 'saved':
				echo '<div class="notice notice-success is-dismissible"><p>Impostazioni salvate.</p></div>';
				break;
			case 'saved_with_errors':
				$rejected = get_transient('ns_bridge_settings_rejected_fields');
				delete_transient('ns_bridge_settings_rejected_fields');
				$labels = array_map(function ($key) {
					return Shopify_Bridge_Settings::FIELDS[$key]['label'] ?? $key;
				}, (array) $rejected);
				printf(
					'<div class="notice notice-warning is-dismissible"><p>Impostazioni salvate, ma questi campi avevano un formato non valido (dominio atteso, senza <code>https://</code> o percorsi) e NON sono stati modificati: %s.</p></div>',
					esc_html(implode(', ', $labels))
				);
				break;
			case 'test_ok':
				$name = get_transient('ns_bridge_test_connection_result');
				delete_transient('ns_bridge_test_connection_result');
				printf('<div class="notice notice-success is-dismissible"><p>Connessione riuscita: %s</p></div>', esc_html($name));
				break;
			case 'test_fail':
				$error = get_transient('ns_bridge_test_connection_result');
				delete_transient('ns_bridge_test_connection_result');
				printf('<div class="notice notice-error is-dismissible"><p>Connessione fallita: %s</p></div>', esc_html($error));
				break;
			case 'webhooks_registered':
				$result = get_transient('ns_bridge_webhook_registration_result');
				delete_transient('ns_bridge_webhook_registration_result');
				echo '<div class="notice notice-success is-dismissible"><p>Registrazione webhook completata:</p><ul style="margin-left:1.5em;list-style:disc;">';
				foreach ((array) $result as $topic => $status) {
					printf('<li><code>%s</code>: %s</li>', esc_html($topic), esc_html($status));
				}
				echo '</ul></div>';
				break;
			case 'definitions_setup':
				self::render_definitions_setup_result();
				break;
			case 'definitions_setup_fail':
				echo '<div class="notice notice-error is-dismissible"><p>Setup non eseguito: dominio negozio o token Admin API mancanti.</p></div>';
				break;
			case 'test_metaobject_ok':
				$raw = get_transient('ns_bridge_test_metaobject_result');
				delete_transient('ns_bridge_test_metaobject_result');
				printf(
					'<div class="notice notice-success is-dismissible"><p>Risposta GraphQL con il token della nostra app:</p><pre style="white-space:pre-wrap;word-break:break-all;background:#fff;padding:1em;border:1px solid #ccd0d4;">%s</pre></div>',
					esc_html($raw)
				);
				break;
			case 'test_metaobject_fail':
				$error = get_transient('ns_bridge_test_metaobject_result');
				delete_transient('ns_bridge_test_metaobject_result');
				printf('<div class="notice notice-error is-dismissible"><p>Richiesta fallita: %s</p></div>', esc_html($error));
				break;
			case 'test_scopes_ok':
				$scopes = get_transient('ns_bridge_test_scopes_result');
				delete_transient('ns_bridge_test_scopes_result');
				$has_metaobjects = in_array('read_metaobjects', (array) $scopes, true);
				printf(
					'<div class="notice %s is-dismissible"><p>Scope effettivamente attivi sul token attuale (%s <code>read_metaobjects</code>):</p><pre style="white-space:pre-wrap;background:#fff;padding:1em;border:1px solid #ccd0d4;">%s</pre></div>',
					$has_metaobjects ? 'notice-success' : 'notice-error',
					$has_metaobjects ? 'presente' : 'ASSENTE',
					esc_html(implode("\n", (array) $scopes))
				);
				break;
			case 'test_scopes_fail':
				$error = get_transient('ns_bridge_test_scopes_result');
				delete_transient('ns_bridge_test_scopes_result');
				printf('<div class="notice notice-error is-dismissible"><p>Richiesta fallita: %s</p></div>', esc_html($error));
				break;
		}
	}

	private static function render_definitions_setup_result() {
		$log = get_transient('ns_bridge_definitions_setup_result');
		delete_transient('ns_bridge_definitions_setup_result');
		if (!is_array($log) || !$log) {
			echo '<div class="notice notice-error is-dismissible"><p>Setup non riuscito: nessuna risposta da Shopify.</p></div>';
			return;
		}
		$has_error = false;
		echo '<div class="notice notice-info is-dismissible"><p><strong>Setup campi Shopify — risultato:</strong></p><ul style="margin-left:1.5em;list-style:disc;">';
		foreach ($log as $line) {
			$is_error  = (stripos($line, 'errore') !== false || stripos($line, 'saltato') !== false);
			$has_error = $has_error || $is_error;
			printf('<li style="%s">%s</li>', $is_error ? 'color:#d63638;' : '', esc_html($line));
		}
		echo '</ul>';
		if ($has_error) {
			echo "<p>Alcune definizioni non sono state create &mdash; controlla lo scope <code>write_products</code> / <code>write_metaobject_definitions</code> sull'app Shopify e riprova (l'operazione &egrave; sicura da ripetere).</p>";
		}
		echo '</div>';
	}

	/* ---------------------------------------------------------------- */
	/* Status / statistics page                                        */
	/* ---------------------------------------------------------------- */

	public static function render_status_page() {
		$notice = isset($_GET['ns_bridge_notice']) ? sanitize_key($_GET['ns_bridge_notice']) : '';
		?>
		<div class="wrap">
			<h1>Stato &amp; Statistiche Shopify Bridge</h1>
			<?php self::render_woocommerce_missing_notice(); ?>
			<?php self::render_status_notice($notice); ?>

			<?php if (!Shopify_Bridge_Settings::is_configured()) : ?>
				<div class="notice notice-warning"><p>
					Dominio negozio o token Admin API non configurati.
					<a href="<?php echo esc_url(self::page_url(self::SETTINGS_SLUG)); ?>">Vai alle impostazioni</a>.
				</p></div>
			<?php endif; ?>

			<h2 class="title">Catalogo su WordPress</h2>
			<?php self::render_product_counts(); ?>

			<h2 class="title">Ultima riconciliazione giornaliera</h2>
			<?php self::render_last_reconciliation(); ?>

			<h2 class="title">Webhook Shopify</h2>
			<?php self::render_webhook_status(); ?>

			<h2 class="title">Sincronizzazione iniziale a blocchi</h2>
			<?php self::render_batch_sync_section($notice); ?>

			<h2 class="title">Azioni manuali</h2>
			<p>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:1em;">
					<input type="hidden" name="action" value="ns_bridge_run_reconciliation">
					<?php wp_nonce_field('ns_bridge_run_reconciliation'); ?>
					<?php submit_button('Esegui riconciliazione ora', 'primary', 'submit', false); ?>
				</form>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:1em;">
					<input type="hidden" name="action" value="ns_bridge_register_webhooks">
					<?php wp_nonce_field('ns_bridge_register_webhooks'); ?>
					<?php submit_button('Registra/verifica webhook su Shopify', 'secondary', 'submit', false); ?>
				</form>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
					<input type="hidden" name="action" value="ns_bridge_merge_duplicate_categories">
					<?php wp_nonce_field('ns_bridge_merge_duplicate_categories'); ?>
					<?php submit_button('Unisci categorie duplicate', 'secondary', 'submit', false); ?>
				</form>
				<p class="description">Pulizia una tantum per le categorie duplicate create da una versione precedente del plugin (stesso nome, slug con un numero aggiunto in fondo) — sposta i prodotti sulla categoria originale ed elimina il duplicato. Puoi rilanciarla quante volte vuoi: se non trova duplicati non cambia nulla.</p>
			</p>
			<p class="description" style="color:#d63638;">
				<strong>Attenzione:</strong> questo bottone gira tutto dentro questa singola richiesta del
				browser — se il catalogo (o le immagini da scaricare) richiedono piu' tempo del limite di
				esecuzione PHP del server, la richiesta puo' interrompersi con un "errore critico" a meta'
				strada. Per il primo import usa invece la <strong>sincronizzazione a blocchi</strong> qui
				sopra, oppure <code>wp shopify-bridge reconcile</code> via SSH se hai accesso alla riga di
				comando (vedi README).
			</p>

			<h2 class="title">Log recenti</h2>
			<?php self::render_log_table(); ?>
		</div>
		<?php
	}

	private static function render_status_notice($notice) {
		if ($notice === 'reconciled') {
			$stats = get_transient('ns_bridge_manual_reconciliation_result');
			delete_transient('ns_bridge_manual_reconciliation_result');
			if (is_array($stats) && !isset($stats['error'])) {
				printf(
					'<div class="notice notice-success is-dismissible"><p>Riconciliazione completata: %d creati/aggiornati, %d rimossi da Shopify, %d categorie sincronizzate, %d errori.</p></div>',
					(int) ($stats['created_or_updated'] ?? 0),
					(int) ($stats['unpublished'] ?? 0),
					(int) ($stats['categories'] ?? 0),
					(int) ($stats['errors'] ?? 0)
				);
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>Riconciliazione non eseguita: credenziali Shopify mancanti.</p></div>';
			}
		}

		if ($notice === 'webhooks_registered') {
			$result = get_transient('ns_bridge_webhook_registration_result');
			delete_transient('ns_bridge_webhook_registration_result');
			echo '<div class="notice notice-success is-dismissible"><p>Registrazione webhook completata:</p><ul style="margin-left:1.5em;list-style:disc;">';
			foreach ((array) $result as $topic => $status) {
				printf('<li><code>%s</code>: %s</li>', esc_html($topic), esc_html($status));
			}
			echo '</ul></div>';
		}

		if ($notice === 'duplicates_merged') {
			$result = get_transient('ns_bridge_merge_duplicates_result');
			delete_transient('ns_bridge_merge_duplicates_result');
			$merged = (int) ($result['merged'] ?? 0);
			if ($merged === 0) {
				echo '<div class="notice notice-success is-dismissible"><p>Nessuna categoria duplicata trovata.</p></div>';
			} else {
				printf('<div class="notice notice-success is-dismissible"><p>%d categorie duplicate unite:</p><ul style="margin-left:1.5em;list-style:disc;">', $merged);
				foreach ((array) ($result['report'] ?? []) as $line) {
					printf('<li>%s</li>', esc_html($line));
				}
				echo '</ul></div>';
			}
		}

		if ($notice === 'batch_reset') {
			echo '<div class="notice notice-success is-dismissible"><p>Sincronizzazione a blocchi azzerata: il prossimo blocco ripartira\' da zero.</p></div>';
		}

		if ($notice === 'batch_error') {
			$error = get_transient('ns_bridge_batch_error');
			delete_transient('ns_bridge_batch_error');
			$message = $error === 'not_configured'
				? "Credenziali Shopify mancanti: configurale prima nella pagina Impostazioni."
				: "WooCommerce non e' attivo.";
			printf('<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html($message));
		}
	}

	/** Counts only products carrying our Shopify id meta, not every WooCommerce product. */
	private static function count_synced_products($status) {
		$ids = get_posts([
			'post_type'      => Shopify_Bridge_Product_Sync::POST_TYPE,
			'post_status'    => $status,
			'meta_key'       => Shopify_Bridge_Product_Sync::META_SHOPIFY_ID,
			'posts_per_page' => -1,
			'fields'         => 'ids',
		]);
		return count($ids);
	}

	private static function render_product_counts() {
		?>
		<table class="widefat striped" style="max-width:500px;">
			<tbody>
				<tr><td>Pubblicati (indicizzabili)</td><td><strong><?php echo esc_html(self::count_synced_products('publish')); ?></strong></td></tr>
				<tr><td>Bozza (rimossi/non attivi su Shopify)</td><td><strong><?php echo esc_html(self::count_synced_products('draft')); ?></strong></td></tr>
			</tbody>
		</table>
		<?php
	}

	private static function render_last_reconciliation() {
		$stats = get_option('ns_bridge_last_reconciliation');

		if (!$stats) {
			echo '<p>Nessuna riconciliazione eseguita finora.</p>';
			return;
		}
		?>
		<table class="widefat striped" style="max-width:500px;">
			<tbody>
				<tr><td>Eseguita il</td><td><strong><?php echo esc_html($stats['ran_at'] ?? '-'); ?></strong></td></tr>
				<tr><td>Creati/aggiornati</td><td><strong><?php echo esc_html($stats['created_or_updated'] ?? 0); ?></strong></td></tr>
				<tr><td>Rimossi da Shopify (impostati a bozza)</td><td><strong><?php echo esc_html($stats['unpublished'] ?? 0); ?></strong></td></tr>
				<tr><td>Categorie (collezioni Shopify) sincronizzate</td><td><strong><?php echo esc_html($stats['categories'] ?? 0); ?></strong></td></tr>
				<tr><td>Errori</td><td><strong style="<?php echo (($stats['errors'] ?? 0) > 0) ? 'color:#d63638;' : ''; ?>"><?php echo esc_html($stats['errors'] ?? 0); ?></strong></td></tr>
			</tbody>
		</table>
		<?php
	}

	private static function render_webhook_status() {
		if (!Shopify_Bridge_Settings::is_configured()) {
			echo '<p>Configura prima dominio e token per vedere lo stato dei webhook.</p>';
			return;
		}

		$client   = new Shopify_Bridge_Shopify_Client();
		$address  = Shopify_Bridge_Webhook_Registrar::webhook_address();
		$existing = $client->list_webhooks();

		if (is_wp_error($existing)) {
			printf('<p style="color:#d63638;">Impossibile leggere i webhook da Shopify: %s</p>', esc_html($existing->get_error_message()));
			return;
		}

		echo '<table class="widefat striped" style="max-width:700px;"><thead><tr><th>Topic</th><th>Stato</th></tr></thead><tbody>';
		foreach (Shopify_Bridge_Webhook_Registrar::TOPICS as $topic) {
			$registered = array_filter($existing, function ($w) use ($topic, $address) {
				return $w['topic'] === $topic && $w['address'] === $address;
			});
			$ok = (bool) $registered;
			printf(
				'<tr><td><code>%s</code></td><td style="color:%s;">%s</td></tr>',
				esc_html($topic),
				$ok ? '#008a20' : '#d63638',
				$ok ? 'Registrato' : 'Non registrato'
			);
		}
		echo '</tbody></table>';
		printf('<p class="description">Endpoint atteso: <code>%s</code></p>', esc_html(self::mask_url_credentials($address)));
	}

	/** Never echo a Basic Auth password to the screen, even masked-but-present in the URL itself. */
	private static function mask_url_credentials($url) {
		return preg_replace('#://([^:/@]+):[^@/]+@#', '://$1:****@', $url);
	}

	private static function render_batch_sync_section($notice) {
		$state       = Shopify_Bridge_Batch_Sync::get_state();
		// Don't auto-continue right after a failed step (e.g. credentials
		// broke mid-run) — that would just resubmit the same failing
		// request every 2 seconds forever instead of waiting for the user.
		$in_progress = Shopify_Bridge_Batch_Sync::is_in_progress() && $notice !== 'batch_error';

		echo '<p class="description">Alternativa a "Esegui riconciliazione ora" per il primo import: '
			. 'elabora un piccolo blocco di prodotti alla volta (una richiesta breve, mai a rischio di '
			. 'timeout) e riprende da solo da dove si era fermato. Pensata per essere usata una volta '
			. 'sola — dopo il primo import, i prodotti nuovi/modificati arrivano via webhook in tempo '
			. 'reale, con la riconciliazione giornaliera come rete di sicurezza.</p>';

		if ($state['phase'] !== 'idle') {
			?>
			<table class="widefat striped" style="max-width:500px;">
				<tbody>
					<tr><td>Fase</td><td><strong><?php echo esc_html(self::batch_phase_label($state['phase'])); ?></strong></td></tr>
					<tr><td>Prodotti creati/aggiornati finora</td><td><strong><?php echo esc_html($state['created_or_updated']); ?></strong></td></tr>
					<tr><td>Categorie sincronizzate</td><td><strong><?php echo esc_html($state['categories']); ?></strong></td></tr>
					<tr><td>Errori</td><td><strong style="<?php echo $state['errors'] > 0 ? 'color:#d63638;' : ''; ?>"><?php echo esc_html($state['errors']); ?></strong></td></tr>
				</tbody>
			</table>
			<?php
		}
		?>
		<p style="margin-top:1em;">
			<form id="ns-bridge-batch-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:1em;">
				<input type="hidden" name="action" value="ns_bridge_batch_step">
				<?php wp_nonce_field('ns_bridge_batch_step'); ?>
				<?php submit_button($state['phase'] === 'idle' ? 'Avvia sincronizzazione a blocchi' : 'Elabora prossimo blocco ora', 'primary', 'submit', false); ?>
			</form>
			<?php if ($state['phase'] !== 'idle') : ?>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
					<input type="hidden" name="action" value="ns_bridge_batch_reset">
					<?php wp_nonce_field('ns_bridge_batch_reset'); ?>
					<?php submit_button('Interrompi e riavvia da capo', 'secondary', 'submit', false); ?>
				</form>
			<?php endif; ?>
		</p>
		<?php if ($in_progress) : ?>
			<p class="description">Questa pagina invia da sola il prossimo blocco tra 2 secondi — puoi
			lasciarla aperta e seguire il progresso, oppure chiudere la scheda in qualsiasi momento:
			tornando qui e cliccando di nuovo riprendi esattamente da dove eri arrivato.</p>
			<script>
			setTimeout(function () {
				var form = document.getElementById('ns-bridge-batch-form');
				if (form) { form.submit(); }
			}, 2000);
			</script>
		<?php endif; ?>
		<?php
	}

	private static function batch_phase_label($phase) {
		switch ($phase) {
			case 'products':   return 'Prodotti in corso...';
			case 'categories': return 'Categorie in corso...';
			case 'done':       return 'Completata';
			default:           return $phase;
		}
	}

	private static function render_log_table() {
		$entries = Shopify_Bridge_Logger::recent(20);

		if (!$entries) {
			echo '<p>Nessun evento registrato finora.</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th style="width:160px;">Data</th><th style="width:220px;">Evento</th><th>Messaggio</th></tr></thead><tbody>';
		foreach ($entries as $entry) {
			printf(
				'<tr><td>%s</td><td><code>%s</code></td><td>%s</td></tr>',
				esc_html($entry['time']),
				esc_html($entry['event']),
				esc_html($entry['message'])
			);
		}
		echo '</tbody></table>';
	}

	public static function handle_run_reconciliation() {
		if (!current_user_can('manage_options')) {
			wp_die('Non autorizzato');
		}
		check_admin_referer('ns_bridge_run_reconciliation');

		$stats = Shopify_Bridge_Reconciliation::run();
		set_transient('ns_bridge_manual_reconciliation_result', $stats, 60);

		wp_safe_redirect(self::page_url(self::STATUS_SLUG, ['ns_bridge_notice' => 'reconciled']));
		exit;
	}

	public static function handle_register_webhooks() {
		if (!current_user_can('manage_options')) {
			wp_die('Non autorizzato');
		}
		check_admin_referer('ns_bridge_register_webhooks');

		$result = Shopify_Bridge_Webhook_Registrar::ensure_registered();
		set_transient('ns_bridge_webhook_registration_result', $result, 60);

		wp_safe_redirect(self::page_url(self::STATUS_SLUG, ['ns_bridge_notice' => 'webhooks_registered']));
		exit;
	}

	public static function handle_merge_duplicate_categories() {
		if (!current_user_can('manage_options')) {
			wp_die('Non autorizzato');
		}
		check_admin_referer('ns_bridge_merge_duplicate_categories');

		$result = Shopify_Bridge_Collection_Sync::merge_duplicate_terms();
		set_transient('ns_bridge_merge_duplicates_result', $result, 60);

		wp_safe_redirect(self::page_url(self::STATUS_SLUG, ['ns_bridge_notice' => 'duplicates_merged']));
		exit;
	}

	public static function handle_batch_step() {
		if (!current_user_can('manage_options')) {
			wp_die('Non autorizzato');
		}
		check_admin_referer('ns_bridge_batch_step');

		$result = Shopify_Bridge_Batch_Sync::run_next_step();
		if (is_array($result) && isset($result['error'])) {
			set_transient('ns_bridge_batch_error', $result['error'], 60);
			wp_safe_redirect(self::page_url(self::STATUS_SLUG, ['ns_bridge_notice' => 'batch_error']));
			exit;
		}

		wp_safe_redirect(self::page_url(self::STATUS_SLUG, ['ns_bridge_notice' => 'batch_step']));
		exit;
	}

	public static function handle_batch_reset() {
		if (!current_user_can('manage_options')) {
			wp_die('Non autorizzato');
		}
		check_admin_referer('ns_bridge_batch_reset');

		Shopify_Bridge_Batch_Sync::reset();

		wp_safe_redirect(self::page_url(self::STATUS_SLUG, ['ns_bridge_notice' => 'batch_reset']));
		exit;
	}
}
