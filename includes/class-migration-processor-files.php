<?php
class MigrationProcessorFiles extends MigrationProcessor {
	public function start_migration($settings) {
		error_log("MigrationProcessorFiles::start_migration called with settings: " . print_r($settings, true));
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
			
			if (empty($this->progress['errors'])) {
				$this->progress['batch_status'] = 'processing';
				$this->progress['pending_urls'] = array_values(array_diff(
					$this->progress['pending_urls'],
					$this->progress['processed_urls']
				));
			} else {
				$this->progress['batch_status'] = 'error';
				error_log("Errors found during file discovery: " . print_r($this->progress['errors'], true));
			}
		}
		
		set_transient('pmwiki_migration_progress', $this->progress, HOUR_IN_SECONDS);
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
		error_log("Total files found: " . count($files));
	
		// Group files by their PMWiki group
		$grouped_files = array();
		foreach ($files as $file) {
			if (!is_file($file)) {
				continue;
			}
	
			$filename = basename($file);
			
			// Skip deleted files
			if (preg_match('/,del-\d+$/', $filename)) {
				error_log("Skipping deleted file: " . $filename);
				continue;
			}
	
			// Split into group and page components
			$parts = explode('.', $filename);
			if (count($parts) !== 2) {
				error_log("Skipping malformed filename: " . $filename);
				continue;
			}
	
			$group = $parts[0];
			$page = $parts[1];
	
			if (!isset($grouped_files[$group])) {
				$grouped_files[$group] = array();
			}
			$grouped_files[$group][$file] = $page;
		}
	
		$this->progress['pending_urls'] = array();
		$this->progress['total'] = 0;
	
		// Process each group
		foreach ($grouped_files as $group => $group_files) {
			error_log("Processing group: " . $group . " with " . count($group_files) . " files");
			
			// First add the FrontPage if it exists
			foreach ($group_files as $file => $page) {
				if ($page === 'FrontPage') {
					$virtual_url = $this->settings['base_url'] . '?n=' . $group . '.' . $page;
					$this->progress['file_paths'][$virtual_url] = $file;
					$this->progress['pending_urls'][] = $virtual_url;
					$this->progress['total']++;
					error_log("Added FrontPage: " . $virtual_url);
					break;
				}
			}
	
			// Then add all other pages in the group
			foreach ($group_files as $file => $page) {
				if ($page === 'FrontPage') {
					continue; // Already added
				}
				
				$virtual_url = $this->settings['base_url'] . '?n=' . $group . '.' . $page;
				$this->progress['file_paths'][$virtual_url] = $file;
				$this->progress['pending_urls'][] = $virtual_url;
				$this->progress['total']++;
				error_log("Added subpage: " . $virtual_url);
			}
		}
	
		error_log("Total pages to process: " . $this->progress['total']);
	}
}