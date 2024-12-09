<?php
class AdminInterface {
	private $option_name = 'pmwiki_migrator_settings';
	private $migration_processor;

	public function __construct($migration_processor) {
		$this->migration_processor = $migration_processor;
		add_action('admin_menu', array($this, 'add_admin_menu'));
		add_action('admin_init', array($this, 'register_settings'));
		add_action('admin_post_migrate_wiki_content', array($this, 'handle_migration_request'));
		add_action('admin_post_process_next_batch', array($this, 'handle_batch_request'));
		add_action('admin_post_stop_migration', array($this, 'handle_stop_request'));
		add_action('admin_post_reset_migration', array($this, 'handle_reset_request'));
	}

	public function handle_stop_request() {
		check_admin_referer('stop_migration', 'stop_nonce');
		
		if (!current_user_can('manage_options')) {
			wp_die('Unauthorized access');
		}

		$progress = get_transient('pmwiki_migration_progress');
		if ($progress) {
			$progress['batch_status'] = 'stopped';
			set_transient('pmwiki_migration_progress', $progress, HOUR_IN_SECONDS);
		}

		wp_redirect(add_query_arg(
			'migration_stopped',
			'true',
			admin_url('admin.php?page=pmwiki-migrator')
		));
		exit;
	}

	public function handle_reset_request() {
		check_admin_referer('reset_migration', 'reset_nonce');
		
		if (!current_user_can('manage_options')) {
			wp_die('Unauthorized access');
		}

		delete_transient('pmwiki_migration_progress');

		wp_redirect(add_query_arg(
			'migration_reset',
			'true',
			admin_url('admin.php?page=pmwiki-migrator')
		));
		exit;
	}
	
	public function handle_batch_request() {
		check_admin_referer('process_next_batch', 'batch_nonce');
		
		if (!current_user_can('manage_options')) {
			wp_die('Unauthorized access');
		}

		$settings = get_option($this->option_name);
		$this->migration_processor->process_next_batch($settings);

		wp_redirect(add_query_arg(
			'batch_processed',
			'true',
			admin_url('admin.php?page=pmwiki-migrator')
		));
		exit;
	}

	public function register_settings() {
		register_setting(
			'pmwiki_migrator_options',
			$this->option_name,
			array(
				'type' => 'array',
				'sanitize_callback' => array($this, 'sanitize_settings')
			)
		);
	}
	
	public function sanitize_settings($input) {
		$sanitized = array();
		
		// Source type (http or files)
		if (isset($input['source_type'])) {
			$sanitized['source_type'] = in_array($input['source_type'], ['http', 'files']) ? $input['source_type'] : 'files';
		}
	
		// Wiki source directory
		if (isset($input['wiki_source_dir'])) {
			$sanitized['wiki_source_dir'] = sanitize_text_field($input['wiki_source_dir']);
		}
	
		// Base URL (required for both modes)
		if (isset($input['base_url'])) {
			$sanitized['base_url'] = esc_url_raw($input['base_url']);
		}
	
		// Content div ID (only needed for http mode)
		if (isset($input['content_div_id'])) {
			$sanitized['content_div_id'] = sanitize_text_field($input['content_div_id']);
		}
	
		// Delay between requests
		if (isset($input['delay_between_requests'])) {
			$sanitized['delay_between_requests'] = floatval($input['delay_between_requests']);
			if ($sanitized['delay_between_requests'] < 0.5) {
				$sanitized['delay_between_requests'] = 0.5;
			}
		}
	
		// Batch size
		if (isset($input['batch_size'])) {
			$sanitized['batch_size'] = intval($input['batch_size']);
			if ($sanitized['batch_size'] < 1) {
				$sanitized['batch_size'] = 1;
			} elseif ($sanitized['batch_size'] > 20) {
				$sanitized['batch_size'] = 20;
			}
		}
	
		return $sanitized;
	}
	

	public function add_admin_menu() {
		add_menu_page(
			'PMWiki Migrator',
			'PMWiki Migrator',
			'manage_options',
			'pmwiki-migrator',
			array($this, 'render_admin_page'),
			'dashicons-migrate',
			99
		);
	}

	public function render_admin_page() {
		if (!current_user_can('manage_options')) {
			return;
		}

		$settings = get_option($this->option_name);
		$migration_progress = get_transient('pmwiki_migration_progress');

		// Add status messages
		if (isset($_GET['migration_stopped'])) {
			echo '<div class="notice notice-warning is-dismissible"><p>Migration stopped. You can resume by clicking "Process Next Batch" or reset to start over.</p></div>';
		}
		if (isset($_GET['migration_reset'])) {
			echo '<div class="notice notice-info is-dismissible"><p>Migration reset. You can start a new migration.</p></div>';
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html(get_admin_page_title()); ?></h1>
			
			<?php if ($migration_progress): ?>
			<div class="migration-progress" style="margin: 20px 0; padding: 20px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
				<h2>Migration Progress</h2>
				<?php 
				$percentage = $migration_progress['total'] > 0 ? 
					round(($migration_progress['processed'] / $migration_progress['total']) * 100) : 0;
				?>
				<div class="progress-bar" style="background: #f0f0f0; height: 20px; border-radius: 3px; margin: 10px 0;">
					<div style="background: #2271b1; width: <?php echo esc_attr($percentage); ?>%; height: 100%; border-radius: 3px; transition: width 0.3s ease;">
					</div>
				</div>
				<p>
					<strong>Progress:</strong> <?php echo esc_html($percentage); ?>%
					(<?php echo esc_html($migration_progress['processed']); ?> of <?php echo esc_html($migration_progress['total']); ?> pages)
				</p>
				<p><strong>Current Page:</strong> <?php echo esc_html($migration_progress['current_url']); ?></p>
				<p><strong>Batch Status:</strong> <?php echo esc_html($migration_progress['batch_status'] ?? 'N/A'); ?></p>
				
				<?php if (!empty($migration_progress['errors'])): ?>
					<h3>Errors:</h3>
					<div style="max-height: 200px; overflow-y: auto; background: #fff; padding: 10px; border: 1px solid #ddd;">
						<ul style="margin: 0;">
							<?php foreach ($migration_progress['errors'] as $error): ?>
								<li style="color: #d63638;"><?php echo esc_html($error); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<?php if ($migration_progress['batch_status'] !== 'complete'): ?>
					<div style="margin-top: 15px;">
						<form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="display: inline-block; margin-right: 10px;">
							<input type="hidden" name="action" value="process_next_batch">
							<?php wp_nonce_field('process_next_batch', 'batch_nonce'); ?>
							<?php submit_button('Process Next Batch', 'primary', 'submit', false); ?>
						</form>

						<form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="display: inline-block; margin-right: 10px;">
							<input type="hidden" name="action" value="stop_migration">
							<?php wp_nonce_field('stop_migration', 'stop_nonce'); ?>
							<?php submit_button('Stop Migration', 'secondary', 'submit', false); ?>
						</form>
					</div>
				<?php endif; ?>

				<?php if ($migration_progress['batch_status'] === 'stopped' || $migration_progress['batch_status'] === 'complete'): ?>
					<form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="margin-top: 10px;">
						<input type="hidden" name="action" value="reset_migration">
						<?php wp_nonce_field('reset_migration', 'reset_nonce'); ?>
						<?php submit_button('Reset Migration', 'secondary', 'submit', false); ?>
					</form>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
				<input type="hidden" name="action" value="migrate_wiki_content">
				<?php wp_nonce_field('pmwiki_migrator_action', 'pmwiki_migrator_nonce'); ?>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="source_type">Source Type</label>
						</th>
						<td>
							<select 
								id="source_type" 
								name="<?php echo esc_attr($this->option_name); ?>[source_type]"
							>
								<option value="files" <?php selected($settings['source_type'] ?? 'files', 'files'); ?>>Files (Local wiki.d directory)</option>
								<option value="http" <?php selected($settings['source_type'] ?? 'files', 'http'); ?>>HTTP (Scrape from website)</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wiki_source_dir">Wiki Source Directory</label>
						</th>
						<td>
							<input 
								type="text" 
								id="wiki_source_dir" 
								name="<?php echo esc_attr($this->option_name); ?>[wiki_source_dir]" 
								value="<?php echo esc_attr($settings['wiki_source_dir'] ?? WP_CONTENT_DIR . '/wiki-source'); ?>" 
								class="regular-text"
							>
							<p class="description">Full path to the directory containing wiki.d files (default: <?php echo esc_html(WP_CONTENT_DIR . '/wiki-source'); ?>)</p>
						</td>
					</tr>
					<!-- Existing settings fields continue below -->
					<tr>
						<th scope="row">
							<label for="base_url">Wiki Base URL</label>
						</th>
						<td>
							<input 
								type="url" 
								id="base_url" 
								name="<?php echo esc_attr($this->option_name); ?>[base_url]" 
								value="<?php echo esc_attr($settings['base_url'] ?? 'https://publicbakeovens.ca/wiki/wiki.php'); ?>" 
								class="regular-text"
								required
							>
							<p class="description">Required for page name formatting even in file mode</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="content_div_id">Content Div ID</label>
						</th>
						<td>
							<input 
								type="text" 
								id="content_div_id" 
								name="<?php echo esc_attr($this->option_name); ?>[content_div_id]" 
								value="<?php echo esc_attr($settings['content_div_id'] ?? 'wikitext'); ?>" 
								class="regular-text"
							>
							<p class="description">Only needed for HTTP mode</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="delay_between_requests">Delay Between Requests (seconds)</label>
						</th>
						<td>
							<input 
								type="number" 
								id="delay_between_requests" 
								name="<?php echo esc_attr($this->option_name); ?>[delay_between_requests]" 
								value="<?php echo esc_attr($settings['delay_between_requests'] ?? '1'); ?>" 
								class="regular-text"
								min="0.5"
								step="0.5"
							>
							<p class="description">Only used in HTTP mode. Minimum: 0.5 seconds</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="batch_size">Batch Size</label>
						</th>
						<td>
							<input 
								type="number" 
								id="batch_size" 
								name="<?php echo esc_attr($this->option_name); ?>[batch_size]" 
								value="<?php echo esc_attr($settings['batch_size'] ?? '5'); ?>" 
								class="regular-text"
								min="1"
								max="20"
							>
							<p class="description">Number of pages to process in each batch (1-20)</p>
						</td>
					</tr>
				</table>
				<?php submit_button('Start Migration'); ?>
			</form>
		</div>
		<?php
	}

	public function handle_migration_request() {
		check_admin_referer('pmwiki_migrator_action', 'pmwiki_migrator_nonce');
		
		if (!current_user_can('manage_options')) {
			wp_die('Unauthorized access');
		}
	
		$settings = isset($_POST[$this->option_name]) ? 
			$this->sanitize_settings($_POST[$this->option_name]) : 
			array();
		
		error_log('PMWiki Migration Request Settings: ' . print_r($settings, true));
	
		if (empty($settings['base_url'])) {
			wp_die('Base URL is required');
		}
	
		// Add this line to save the settings
		update_option($this->option_name, $settings);
	
		$this->migration_processor->start_migration($settings);
	
		wp_redirect(add_query_arg(
			'migration_started',
			'true',
			admin_url('admin.php?page=pmwiki-migrator')
		));
		exit;
	}
}