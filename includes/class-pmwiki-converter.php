<?php
class PMWikiConverter {
	public function convert_to_html($pmwiki_text) {
		error_log("Converting PMWiki markup to HTML");
		
		$html = $pmwiki_text;

		// Basic formatting
		$html = $this->convert_bold($html);
		$html = $this->convert_italic($html);
		$html = $this->convert_headers($html);
		$html = $this->convert_lists($html);
		$html = $this->convert_links($html);

		return $html;
	}

	private function convert_bold($text) {
		// Convert '''bold text''' to <strong>bold text</strong>
		return preg_replace("/'''(.*?)'''/", '<strong>$1</strong>', $text);
	}

	private function convert_italic($text) {
		// Convert ''italic text'' to <em>italic text</em>
		return preg_replace("/''((?!').+?)''/", '<em>$1</em>', $text);
	}

	private function convert_headers($text) {
		// Convert !!! to <h1>, !! to <h2>, ! to <h3>
		$text = preg_replace("/!!!(.*?)$/m", '<h1>$1</h1>', $text);
		$text = preg_replace("/!!(.*?)$/m", '<h2>$1</h2>', $text);
		$text = preg_replace("/!(.*?)$/m", '<h3>$1</h3>', $text);
		return $text;
	}

	private function convert_lists($text) {
		// Convert * to unordered lists
		$lines = explode("\n", $text);
		$in_list = false;
		$result = array();

		foreach ($lines as $line) {
			if (preg_match('/^\*\s*(.*)$/', $line, $matches)) {
				if (!$in_list) {
					$result[] = '<ul>';
					$in_list = true;
				}
				$result[] = '<li>' . $matches[1] . '</li>';
			} else {
				if ($in_list) {
					$result[] = '</ul>';
					$in_list = false;
				}
				$result[] = $line;
			}
		}

		if ($in_list) {
			$result[] = '</ul>';
		}

		return implode("\n", $result);
	}

	private function convert_links($text) {
		// Convert [[link|text]] to <a href="link">text</a>
		return preg_replace_callback(
			'/\[\[(.*?)(?:\|(.*?))?\]\]/',
			function($matches) {
				$url = $matches[1];
				$text = isset($matches[2]) ? $matches[2] : $matches[1];
				
				// If it's an internal link (no http:// or https://)
				if (strpos($url, 'http') !== 0) {
					// Convert to WordPress format
					// For now, just linking to the page name
					return '<a href="' . esc_url(home_url('/' . sanitize_title($url))) . '">' . esc_html($text) . '</a>';
				}
				
				return '<a href="' . esc_url($url) . '">' . esc_html($text) . '</a>';
			},
			$text
		);
	}
}