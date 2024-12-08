<?php
class MigrationProcessorFiles extends MigrationProcessor {
	public function start_migration($settings) {
		// Store settings at class level
		$this->settings = $settings;
		
		// Initialize or get existing progress
		$existing_progress = get_transient('pmwiki_migration_progress');
		
		if (!$existing_progress) {
			$this->progress = array(
				'processed' => 0,
				'total' => 0,
				'current_url' => $settings['base_url'],
				'errors' => array(),
				'processed_urls' => array(),
				'page_map' => array(),
				'pending_urls' => array(),
				'batch_status' => 'initializing',
				'settings' => $settings,
				'file_paths' => array() // Store file paths for each virtual URL
			);
		} else {
			$this->progress = $existing_progress;
			if (isset($this->progress['settings'])) {
				$this->settings = $this->progress['settings'];
			}
		}

		// If we're just starting, discover pages from files
		if ($this->progress['batch_status'] === 'initializing') {
			error_log("Starting file-based migration");
			$this->discover_pages_from_files($settings);
			
			$this->progress['batch_status'] = 'processing';
			$this->progress['pending_urls'] = array_values(array_diff(
				$this->progress['pending_urls'],
				$this->progress['processed_urls']
			));
		}

		// Process the next batch
		$this->process_next_batch($settings);
		
		set_transient('pmwiki_migration_progress', $this->progress, HOUR_IN_SECONDS);
	}

	protected function process_page($url, $settings) {
		error_log("Processing page: " . $url);
		
		$this->progress['current_url'] = $url;

		if (isset($this->progress['page_map'][$url])) {
			error_log("Page already exists for URL: " . $url);
			return true;
		}

		if (!isset($this->progress['file_paths'][$url])) {
			error_log("File path not found for URL: " . $url);
			return false;
		}

		$file_path = $this->progress['file_paths'][$url];
		error_log("Reading content from file: " . $file_path);
		
		$content = file_get_contents($file_path);
		if ($content === false) {
			error_log("Failed to read file: " . $file_path);
			return false;
		}

		// Convert PMWiki markup to HTML using PMWikiConverter
		$converter = new PMWikiConverter();
		$content = $converter->convert_to_html($content);
		
		if (!$content) {
			$this->progress['errors'][] = "Failed to convert content for: $url";
			return false;
		}

		$title = $this->extract_title($url);
		error_log("Extracted title: " . $title);

		$page_id = wp_insert_post(array(
			'post_title' => $title,
			'post_content' => $content,
			'post_status' => 'publish',
			'post_type' => 'page'
		));

		if (!is_wp_error($page_id)) {
			$this->progress['page_map'][$url] = $page_id;
			error_log("Created WordPress page ID $page_id for URL: $url");
			return true;
		} else {
			error_log("Failed to create WordPress page for URL: $url");
			error_log("WordPress error: " . $page_id->get_error_message());
			$this->progress['errors'][] = "Failed to create page: $url - " . $page_id->get_error_message();
			return false;
		}
	}

	private function discover_pages_from_files($settings) {
		$wiki_source_dir = isset($settings['wiki_source_dir']) 
			? $settings['wiki_source_dir'] 
			: WP_CONTENT_DIR . '/wiki-source';

		error_log("Looking for wiki files in: " . $wiki_source_dir);

		if (!is_dir($wiki_source_dir)) {
			error_log("Wiki source directory not found: " . $wiki_source_dir);
			$this->progress['errors'][] = "Wiki source directory not found: " . $wiki_source_dir;
			return;
		}

		$files = glob($wiki_source_dir . '/*');
		if ($files === false) {
			error_log("Failed to read wiki source directory");
			$this->progress['errors'][] = "Failed to read wiki source directory";
			return;
		}

		$this->progress['pending_urls'] = array();
		$this->progress['total'] = 0;

		foreach ($files as $file) {
			if (!is_file($file)) {
				continue;
			}

			// PMWiki files typically have the format Group.PageName
			$filename = basename($file);
			$filename_without_extension = pathinfo($filename, PATHINFO_FILENAME);

			// Create a virtual URL that represents this page
			// This maintains compatibility with the parent class's URL-based methods
			$virtual_url = $this->settings['base_url'] . '?n=' . $filename_without_extension;
			
			// Store the actual file path for later use
			$this->progress['file_paths'][$virtual_url] = $file;

			if (!in_array($virtual_url, $this->progress['pending_urls'])) {
				$this->progress['pending_urls'][] = $virtual_url;
				$this->progress['total']++;
				error_log("Added file to pending: " . $filename . " as " . $virtual_url);
			}
		}

		error_log("Total pages to process: " . $this->progress['total']);
		error_log("File paths: " . print_r($this->progress['file_paths'], true));
	}
}