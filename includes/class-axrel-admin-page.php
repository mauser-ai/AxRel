<?php
defined('ABSPATH') || exit;

/**
 * Admin UI for the bridge: API keys + connection test on one page,
 * sync statistics/log/manual actions on another. Both live under
 * WooCommerce's own "Prodotti" menu (post_type=product).
 */
class Axrel_Admin_Page {

	const SETTINGS_SLUG = 'axrel-settings';
	const STATUS_SLUG   = 'axrel-status';

	public static function register_menu() {
		add_submenu_page(
			'edit.php?post_type=' . Axrel_Product_Sync::POST_TYPE,
			'Impostazioni AxRel',
			'Impostazioni',
			'manage_options',
			self::SETTINGS_SLUG,
			[__CLASS__, 'render_settings_page']
		);

		add_submenu_page(
			'edit.php?post_type=' . Axrel_Product_Sync::POST_TYPE,
			'Stato & Statistiche AxRel',
			'Stato & Statistiche',
			'manage_options',
			self::STATUS_SLUG,
			[__CLASS__, 'render_status_page']
		);
	}

	private static function page_url($slug, $extra = []) {
		return add_query_arg(array_merge([
			'post_type' => Axrel_Product_Sync::POST_TYPE,
			'page'      => $slug,
		], $extra), admin_url('edit.php'));
	}

	/* ---------------------------------------------------------------- */
	/* Settings page                                                    */
	/* ---------------------------------------------------------------- */

	private static function render_woocommerce_missing_notice() {
		if (class_exists('WC_Product_Variable')) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>WooCommerce non risulta attivo.</strong> AxRel usa i tipi di prodotto di WooCommerce (semplice/variabile) per gestire varianti, colori e prezzi: installa e attiva WooCommerce prima di sincronizzare il catalogo.</p></div>';
	}

	private static function render_secrets_in_db_notice() {
		$secret_keys = ['admin_token', 'webhook_secret'];
		$in_db = [];
		foreach ($secret_keys as $key) {
			if (!Axrel_Settings::is_locked_by_constant($key) && Axrel_Settings::get_stored_value($key) !== '') {
				$in_db[] = Axrel_Settings::FIELDS[$key]['label'];
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
		$notice = isset($_GET['axrel_notice']) ? sanitize_key($_GET['axrel_notice']) : '';
		?>
		<div class="wrap">
			<h1>Impostazioni AxRel</h1>
			<?php self::render_woocommerce_missing_notice(); ?>
			<?php self::render_secrets_in_db_notice(); ?>
			<?php self::render_notice($notice); ?>

			<p>Chiavi e parametri per collegare WordPress al negozio Shopify. Un
			campo puo' anche essere definito in <code>wp-config.php</code> (piu'
			sicuro, consigliato per token e secret): in quel caso qui appare
			bloccato e il valore di <code>wp-config.php</code> ha sempre la
			precedenza.</p>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="axrel_save_settings">
				<?php wp_nonce_field('axrel_save_settings'); ?>

				<table class="form-table" role="presentation">
					<?php foreach (Axrel_Settings::FIELDS as $key => $field) : ?>
						<tr>
							<th scope="row"><label for="axrel_<?php echo esc_attr($key); ?>"><?php echo esc_html($field['label']); ?></label></th>
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
				<input type="hidden" name="action" value="axrel_test_connection">
				<?php wp_nonce_field('axrel_test_connection'); ?>
				<?php submit_button('Verifica connessione a Shopify', 'secondary', 'submit', false); ?>
			</form>

			<p style="margin-top:2em;">
				<a href="<?php echo esc_url(self::page_url(self::STATUS_SLUG)); ?>">Vai a Stato &amp; Statistiche &rarr;</a>
			</p>
		</div>
		<?php
	}

	private static function render_field($key, array $field) {
		$locked = Axrel_Settings::is_locked_by_constant($key);
		$id     = 'axrel_' . $key;

		if ($locked) {
			printf(
				'<input type="text" id="%1$s" class="regular-text" value="%2$s" disabled> <p class="description">Definito in wp-config.php come <code>%3$s</code>.</p>',
				esc_attr($id),
				esc_attr($field['type'] === 'password' ? self::mask(constant($field['const'])) : constant($field['const'])),
				esc_html($field['const'])
			);
			return;
		}

		$stored = Axrel_Settings::get_stored_value($key);

		if ($field['type'] === 'password') {
			$placeholder = $stored !== '' ? 'Configurato — lascia vuoto per non modificare (' . self::mask($stored) . ')' : 'Non configurato';
			printf(
				'<input type="password" id="%1$s" name="%1$s" class="regular-text" value="" placeholder="%2$s" autocomplete="new-password">',
				esc_attr($id),
				esc_attr($placeholder)
			);
			return;
		}

		printf(
			'<input type="text" id="%1$s" name="%1$s" class="regular-text" value="%2$s" placeholder="%3$s">',
			esc_attr($id),
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
		check_admin_referer('axrel_save_settings');

		$rejected = Axrel_Settings::update($_POST);

		if ($rejected) {
			set_transient('axrel_settings_rejected_fields', $rejected, 60);
			wp_safe_redirect(self::page_url(self::SETTINGS_SLUG, ['axrel_notice' => 'saved_with_errors']));
			exit;
		}

		wp_safe_redirect(self::page_url(self::SETTINGS_SLUG, ['axrel_notice' => 'saved']));
		exit;
	}

	public static function handle_test_connection() {
		if (!current_user_can('manage_options')) {
			wp_die('Non autorizzato');
		}
		check_admin_referer('axrel_test_connection');

		$client = new Axrel_Shopify_Client();
		if (!$client->is_configured()) {
			set_transient('axrel_test_connection_result', 'Dominio negozio o token mancanti.', 60);
			wp_safe_redirect(self::page_url(self::SETTINGS_SLUG, ['axrel_notice' => 'test_fail']));
			exit;
		}

		$shop = $client->get_shop();
		if (is_wp_error($shop)) {
			set_transient('axrel_test_connection_result', $shop->get_error_message(), 60);
			wp_safe_redirect(self::page_url(self::SETTINGS_SLUG, ['axrel_notice' => 'test_fail']));
			exit;
		}

		set_transient('axrel_test_connection_result', $shop['name'] ?? 'connesso', 60);
		wp_safe_redirect(self::page_url(self::SETTINGS_SLUG, ['axrel_notice' => 'test_ok']));
		exit;
	}

	private static function render_notice($notice) {
		switch ($notice) {
			case 'saved':
				echo '<div class="notice notice-success is-dismissible"><p>Impostazioni salvate.</p></div>';
				break;
			case 'saved_with_errors':
				$rejected = get_transient('axrel_settings_rejected_fields');
				delete_transient('axrel_settings_rejected_fields');
				$labels = array_map(function ($key) {
					return Axrel_Settings::FIELDS[$key]['label'] ?? $key;
				}, (array) $rejected);
				printf(
					'<div class="notice notice-warning is-dismissible"><p>Impostazioni salvate, ma questi campi avevano un formato non valido (dominio atteso, senza <code>https://</code> o percorsi) e NON sono stati modificati: %s.</p></div>',
					esc_html(implode(', ', $labels))
				);
				break;
			case 'test_ok':
				$name = get_transient('axrel_test_connection_result');
				delete_transient('axrel_test_connection_result');
				printf('<div class="notice notice-success is-dismissible"><p>Connessione riuscita: %s</p></div>', esc_html($name));
				break;
			case 'test_fail':
				$error = get_transient('axrel_test_connection_result');
				delete_transient('axrel_test_connection_result');
				printf('<div class="notice notice-error is-dismissible"><p>Connessione fallita: %s</p></div>', esc_html($error));
				break;
			case 'webhooks_registered':
				$result = get_transient('axrel_webhook_registration_result');
				delete_transient('axrel_webhook_registration_result');
				echo '<div class="notice notice-success is-dismissible"><p>Registrazione webhook completata:</p><ul style="margin-left:1.5em;list-style:disc;">';
				foreach ((array) $result as $topic => $status) {
					printf('<li><code>%s</code>: %s</li>', esc_html($topic), esc_html($status));
				}
				echo '</ul></div>';
				break;
		}
	}

	/* ---------------------------------------------------------------- */
	/* Status / statistics page                                        */
	/* ---------------------------------------------------------------- */

	public static function render_status_page() {
		$notice = isset($_GET['axrel_notice']) ? sanitize_key($_GET['axrel_notice']) : '';
		?>
		<div class="wrap">
			<h1>Stato &amp; Statistiche AxRel</h1>
			<?php self::render_woocommerce_missing_notice(); ?>
			<?php self::render_status_notice($notice); ?>

			<?php if (!Axrel_Settings::is_configured()) : ?>
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

			<h2 class="title">Azioni manuali</h2>
			<p>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:1em;">
					<input type="hidden" name="action" value="axrel_run_reconciliation">
					<?php wp_nonce_field('axrel_run_reconciliation'); ?>
					<?php submit_button('Esegui riconciliazione ora', 'primary', 'submit', false); ?>
				</form>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
					<input type="hidden" name="action" value="axrel_register_webhooks">
					<?php wp_nonce_field('axrel_register_webhooks'); ?>
					<?php submit_button('Registra/verifica webhook su Shopify', 'secondary', 'submit', false); ?>
				</form>
			</p>
			<p class="description">
				La riconciliazione manuale esegue subito un pull completo del catalogo: su cataloghi grandi
				puo' richiedere tempo o andare in timeout dal browser. Per cataloghi estesi preferisci
				<code>wp axrel reconcile</code> via SSH/cron (vedi README).
			</p>

			<h2 class="title">Log recenti</h2>
			<?php self::render_log_table(); ?>
		</div>
		<?php
	}

	private static function render_status_notice($notice) {
		if ($notice === 'reconciled') {
			$stats = get_transient('axrel_manual_reconciliation_result');
			delete_transient('axrel_manual_reconciliation_result');
			if (is_array($stats) && !isset($stats['error'])) {
				printf(
					'<div class="notice notice-success is-dismissible"><p>Riconciliazione completata: %d creati/aggiornati, %d rimossi da Shopify, %d errori.</p></div>',
					(int) ($stats['created_or_updated'] ?? 0),
					(int) ($stats['unpublished'] ?? 0),
					(int) ($stats['errors'] ?? 0)
				);
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>Riconciliazione non eseguita: credenziali Shopify mancanti.</p></div>';
			}
		}

		if ($notice === 'webhooks_registered') {
			$result = get_transient('axrel_webhook_registration_result');
			delete_transient('axrel_webhook_registration_result');
			echo '<div class="notice notice-success is-dismissible"><p>Registrazione webhook completata:</p><ul style="margin-left:1.5em;list-style:disc;">';
			foreach ((array) $result as $topic => $status) {
				printf('<li><code>%s</code>: %s</li>', esc_html($topic), esc_html($status));
			}
			echo '</ul></div>';
		}
	}

	/** Counts only products carrying our Shopify id meta, not every WooCommerce product. */
	private static function count_synced_products($status) {
		$ids = get_posts([
			'post_type'      => Axrel_Product_Sync::POST_TYPE,
			'post_status'    => $status,
			'meta_key'       => Axrel_Product_Sync::META_SHOPIFY_ID,
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
		$stats = get_option('axrel_last_reconciliation');

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
				<tr><td>Errori</td><td><strong style="<?php echo (($stats['errors'] ?? 0) > 0) ? 'color:#d63638;' : ''; ?>"><?php echo esc_html($stats['errors'] ?? 0); ?></strong></td></tr>
			</tbody>
		</table>
		<?php
	}

	private static function render_webhook_status() {
		if (!Axrel_Settings::is_configured()) {
			echo '<p>Configura prima dominio e token per vedere lo stato dei webhook.</p>';
			return;
		}

		$client   = new Axrel_Shopify_Client();
		$address  = rest_url('axrel-shopify/v1/webhook');
		$existing = $client->list_webhooks();

		if (is_wp_error($existing)) {
			printf('<p style="color:#d63638;">Impossibile leggere i webhook da Shopify: %s</p>', esc_html($existing->get_error_message()));
			return;
		}

		echo '<table class="widefat striped" style="max-width:700px;"><thead><tr><th>Topic</th><th>Stato</th></tr></thead><tbody>';
		foreach (Axrel_Webhook_Registrar::TOPICS as $topic) {
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
		printf('<p class="description">Endpoint atteso: <code>%s</code></p>', esc_html($address));
	}

	private static function render_log_table() {
		$entries = Axrel_Logger::recent(20);

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
		check_admin_referer('axrel_run_reconciliation');

		$stats = Axrel_Reconciliation::run();
		set_transient('axrel_manual_reconciliation_result', $stats, 60);

		wp_safe_redirect(self::page_url(self::STATUS_SLUG, ['axrel_notice' => 'reconciled']));
		exit;
	}

	public static function handle_register_webhooks() {
		if (!current_user_can('manage_options')) {
			wp_die('Non autorizzato');
		}
		check_admin_referer('axrel_register_webhooks');

		$result = Axrel_Webhook_Registrar::ensure_registered();
		set_transient('axrel_webhook_registration_result', $result, 60);

		wp_safe_redirect(self::page_url(self::STATUS_SLUG, ['axrel_notice' => 'webhooks_registered']));
		exit;
	}
}
