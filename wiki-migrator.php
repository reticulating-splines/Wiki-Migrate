<?php
/*
Plugin Name: PMWiki to WordPress Migrator
Description: Migrate entire PMWiki sites to WordPress pages using either HTTP or local files. Working with claude on this.
Version: 2.1
*/

if (!defined('ABSPATH')) {
	exit;
}

// Load required files
require_once plugin_dir_path(__FILE__) . 'includes/class-admin-interface.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-migration-processor.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-migration-processor-files.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-content-processor.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pmwiki-converter.php';

class PMWikiMigrator {
	private $admin_interface;
	private $migration_processor;
	private $content_processor;

	public function __construct() {
		$this->content_processor = new ContentProcessor();
		$this->migration_processor = $this->create_migration_processor();
		$this->admin_interface = new AdminInterface($this->migration_processor);
	}

	private function create_migration_processor() {
		// Get settings to determine which processor to use
		$settings = get_option('pmwiki_migrator_settings', array());
		$source_type = isset($settings['source_type']) ? $settings['source_type'] : 'http';

		if ($source_type === 'files') {
			return new MigrationProcessorFiles($this->content_processor);
		} else {
			return new MigrationProcessor($this->content_processor);
		}
	}
}

// Initialize the plugin
function pmwiki_migrator_init() {
	new PMWikiMigrator();
}
add_action('plugins_loaded', 'pmwiki_migrator_init');