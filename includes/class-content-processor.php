<?php
class ContentProcessor {
	public function scrape_content($url, $div_id) {
		$response = wp_remote_get($url);
		if (is_wp_error($response)) {
			error_log('Failed to fetch content: ' . $response->get_error_message());
			return false;
		}

		$body = wp_remote_retrieve_body($response);
		$dom = new DOMDocument();
		@$dom->loadHTML(mb_convert_encoding($body, 'HTML-ENTITIES', 'UTF-8'));

		$xpath = new DOMXPath($dom);
		$content_div = $xpath->query("//*[@id='$div_id']")->item(0);

		if (!$content_div) {
			error_log('Content div not found: ' . $div_id);
			return false;
		}

		// Process tables
		$tables = $content_div->getElementsByTagName('table');
		foreach ($tables as $table) {
			$table->setAttribute('class', 'wiki-imported-table');
			$table->setAttribute('style', 'border-collapse: collapse; width: 100%; margin-bottom: 1em;');

			$cells = $table->getElementsByTagName('td');
			foreach ($cells as $cell) {
				$cell->setAttribute('style', 'border: 1px solid #ddd; padding: 8px;');
			}

			$headers = $table->getElementsByTagName('th');
			foreach ($headers as $header) {
				$header->setAttribute('style', 'border: 1px solid #ddd; padding: 8px; background-color: #f2f2f2;');
			}
		}

		// Process images
		$images = $content_div->getElementsByTagName('img');
		foreach ($images as $img) {
			$src = $img->getAttribute('src');
			if (empty($src)) continue;

			if (strpos($src, 'http') !== 0) {
				$src = $this->build_absolute_url($url, $src);
				$img->setAttribute('src', $src);
			}

			$upload_data = $this->upload_image($src);
			if ($upload_data) {
				$img->setAttribute('src', $upload_data['url']);
			}
		}

		return $dom->saveHTML($content_div);
	}

	private function upload_image($url) {
		error_log("Checking image: " . $url);

		// First try to find by URL as a custom field
		$existing_attachment = get_posts(array(
			'post_type' => 'attachment',
			'meta_key' => '_source_url',
			'meta_value' => $url,
			'posts_per_page' => 1
		));

		if (!empty($existing_attachment)) {
			error_log("Found existing image by source URL");
			return array(
				'id' => $existing_attachment[0]->ID,
				'url' => wp_get_attachment_url($existing_attachment[0]->ID)
			);
		}

		// Then try to find by filename
		$filename = basename($url);
		$existing_attachment = get_page_by_title($filename, OBJECT, 'attachment');
		
		if ($existing_attachment) {
			error_log("Found existing image by filename: " . $filename);
			return array(
				'id' => $existing_attachment->ID,
				'url' => wp_get_attachment_url($existing_attachment->ID)
			);
		}

		error_log("No existing image found, downloading: " . $url);

		// If not found, proceed with download and upload
		require_once(ABSPATH . 'wp-admin/includes/media.php');
		require_once(ABSPATH . 'wp-admin/includes/file.php');
		require_once(ABSPATH . 'wp-admin/includes/image.php');

		$tmp = download_url($url);
		if (is_wp_error($tmp)) {
			error_log('Failed to download image: ' . $tmp->get_error_message());
			return false;
		}

		$file_array = array(
			'name' => $filename,
			'tmp_name' => $tmp
		);

		$id = media_handle_sideload($file_array, 0);
		@unlink($tmp);

		if (is_wp_error($id)) {
			error_log('Failed to upload image: ' . $id->get_error_message());
			return false;
		}

		// Store the source URL as meta data
		update_post_meta($id, '_source_url', $url);

		error_log("Successfully uploaded new image with ID: " . $id);
		
		return array(
			'id' => $id,
			'url' => wp_get_attachment_url($id)
		);
	}

	private function build_absolute_url($base_url, $relative_path) {
		$base_parts = parse_url($base_url);
		$base = $base_parts['scheme'] . '://' . $base_parts['host'];
		
		if (isset($base_parts['path'])) {
			$base .= dirname($base_parts['path']);
		}

		// Handle root-relative URLs
		if (strpos($relative_path, '/') === 0) {
			return $base . $relative_path;
		}

		// Handle relative URLs
		return rtrim($base, '/') . '/' . ltrim($relative_path, '/');
	}
}