<?php
class MigrationProcessor {
	protected $content_processor;
	protected $progress;
	protected $settings;

	public function __construct($content_processor) {
		$this->content_processor = $content_processor;
	}

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
				'settings' => $settings
			);
		} else {
			$this->progress = $existing_progress;
			if (isset($this->progress['settings'])) {
				$this->settings = $this->progress['settings'];
			}
		}

		// If we're just starting, get the sitemap
		if ($this->progress['batch_status'] === 'initializing') {
			$sitemap_url = $settings['base_url'] . '?n=Site.SiteMap';
			error_log("Starting migration from sitemap: " . $sitemap_url);
			
			$this->discover_urls_from_sitemap($sitemap_url, $settings);
			
			$this->progress['batch_status'] = 'processing';
			$this->progress['pending_urls'] = array_values(array_diff(
				$this->progress['pending_urls'],
				$this->progress['processed_urls']
			));

			// Save initial progress
			set_transient('pmwiki_migration_progress', $this->progress, HOUR_IN_SECONDS);
		}
	}

	public function process_next_batch($settings = null) {
		// Get current progress
		$this->progress = get_transient('pmwiki_migration_progress');
		
		if (!$this->progress) {
			error_log("No progress data found, cannot process batch");
			return; // Don't start a new migration
		}

		// Load settings from progress data if not provided
		if (!$settings && isset($this->progress['settings'])) {
			$settings = $this->progress['settings'];
		}

		// Store settings at class level
		$this->settings = $settings;

		// Get batch size from settings or use default
		$batch_size = isset($settings['batch_size']) ? intval($settings['batch_size']) : 5;
		$batch_size = max(1, min($batch_size, 20));

		// Get delay between requests from settings or use default
		$delay = isset($settings['delay_between_requests']) ? floatval($settings['delay_between_requests']) : 1;
		$delay = max(0.5, $delay);

		error_log("Processing batch with size: $batch_size and delay: $delay seconds");

		if (empty($this->progress['pending_urls'])) {
			error_log("No pending URLs found");
			$this->progress['batch_status'] = 'complete';
			set_transient('pmwiki_migration_progress', $this->progress, HOUR_IN_SECONDS);
			return;
		}

		// Take the next batch of URLs
		$batch_urls = array_splice($this->progress['pending_urls'], 0, $batch_size);
		error_log("Processing batch of " . count($batch_urls) . " URLs");
		
		foreach ($batch_urls as $url) {
			if (in_array($url, $this->progress['processed_urls'])) {
				error_log("Skipping already processed URL: " . $url);
				continue;
			}

			error_log("Processing URL: " . $url);
			
			if ($delay > 0) {
				usleep(intval($delay * 1000000));
			}
			
			$retry_count = 0;
			$max_retries = 3;
			$success = false;
			
			while ($retry_count < $max_retries && !$success) {
				if ($retry_count > 0) {
					error_log("Retry attempt " . ($retry_count + 1) . " for URL: " . $url);
					sleep(2);
				}
				
				$success = $this->process_page($url, $settings);
				$retry_count++;
			}

			if ($success) {
				$this->progress['processed_urls'][] = $url;
				$this->progress['processed'] = count($this->progress['processed_urls']);
				error_log("Successfully processed URL: " . $url . ". Total processed: " . $this->progress['processed']);
			} else {
				array_unshift($this->progress['pending_urls'], $url);
				error_log("Failed to process URL after " . $max_retries . " attempts: " . $url);
				$this->progress['errors'][] = "Failed to process after " . $max_retries . " attempts: " . $url;
			}

			set_transient('pmwiki_migration_progress', $this->progress, HOUR_IN_SECONDS);
		}

		if (empty($this->progress['pending_urls'])) {
			$this->progress['batch_status'] = 'complete';
		} else {
			$this->progress['batch_status'] = 'processing';
		}

		error_log("Batch complete. Status: " . $this->progress['batch_status']);
		error_log("Remaining URLs: " . count($this->progress['pending_urls']));
		set_transient('pmwiki_migration_progress', $this->progress, HOUR_IN_SECONDS);
	}

	protected function process_page($url, $settings) {
		error_log("Processing page: " . $url);
		
		$this->progress['current_url'] = $url;

		if (isset($this->progress['page_map'][$url])) {
			error_log("Page already exists for URL: " . $url);
			return true;
		}

		error_log("About to scrape content from URL: " . $url . " with div_id: " . $settings['content_div_id']);
		$content = $this->content_processor->scrape_content($url, $settings['content_div_id']);
		
		if ($content === false) {
			error_log("Content scraping failed");
			$this->progress['errors'][] = "Failed to scrape: $url";
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

	private function discover_urls_from_sitemap($sitemap_url, $settings) {
		error_log("Fetching sitemap from: " . $sitemap_url);
		
		$response = wp_remote_get($sitemap_url);
		if (is_wp_error($response)) {
			error_log('Failed to fetch sitemap: ' . $response->get_error_message());
			return;
		}

		$body = wp_remote_retrieve_body($response);
		$dom = new DOMDocument();
		@$dom->loadHTML(mb_convert_encoding($body, 'HTML-ENTITIES', 'UTF-8'));

		$xpath = new DOMXPath($dom);
		$content_div = $xpath->query("//*[@id='" . $settings['content_div_id'] . "']")->item(0);

		if (!$content_div) {
			error_log('Content div not found in sitemap');
			return;
		}

		$links = $xpath->query(".//a", $content_div);
		error_log("Found " . $links->length . " links in sitemap");
		
		$this->progress['pending_urls'] = array();
		$this->progress['total'] = 0;
		
		foreach ($links as $link) {
			$href = $link->getAttribute('href');
			if (empty($href) || strpos($href, '#') === 0) {
				continue;
			}

			$page_url = $this->build_page_url($settings['base_url'], $href);
			if (!in_array($page_url, $this->progress['pending_urls'])) {
				$this->progress['pending_urls'][] = $page_url;
				$this->progress['total']++;
				error_log("Added URL to pending: " . $page_url);
			}
		}

		error_log("Total pages to process: " . $this->progress['total']);
	}

	protected function build_page_url($base_url, $href) {
		if (strpos($href, '?n=') !== false) {
			if (strpos($href, 'wiki.php') !== false) {
				$parts = parse_url($href);
				return $base_url . '?' . $parts['query'];
			}
			return $base_url . '?' . $href;
		}
		return $base_url . '?' . $href;
	}

	protected function extract_title($url) {
		error_log("Extracting title from URL: " . $url);
		
		$parsed_url = parse_url($url);
		if ($parsed_url === false || !isset($parsed_url['query'])) {
			error_log("Failed to parse URL or no query string found");
			return "Untitled Page";
		}
		
		parse_str($parsed_url['query'], $query);
		if (!isset($query['n'])) {
			error_log("No 'n' parameter found in query string");
			return "Untitled Page";
		}

		$title_parts = explode('.', $query['n']);
		
		if (count($title_parts) >= 2) {
			$group = $title_parts[0];
			$page = $title_parts[1];
			
			$group = str_replace(['_', '-'], ' ', $group);
			$page = str_replace(['_', '-'], ' ', $page);
			
			if ($page === 'FrontPage') {
				error_log("FrontPage detected, using group name only: " . $group);
				return ucwords($group);
			}
			
			$title = ucwords($group) . ': ' . ucwords($page);
			error_log("Generated title: " . $title);
			return $title;
		}
		
		$title = ucwords(str_replace(['_', '-'], ' ', $query['n']));
		error_log("Generated single-part title: " . $title);
		return $title;
	}
}