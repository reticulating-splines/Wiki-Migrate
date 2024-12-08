# PMWiki to WordPress Migrator

A WordPress plugin that migrates content from PMWiki to WordPress pages. Supports both direct web scraping and local file migration.

## Features

- Two migration modes:
  - HTTP mode: Scrapes content directly from a live PMWiki site
  - File mode: Reads from local PMWiki files (from wiki.d directory)
- Batch processing with configurable batch sizes
- Rate limiting with configurable delays between requests
- Progress tracking and error reporting
- Automatic image importing
- Table formatting preservation
- Configurable content scraping

## Installation

1. Upload the plugin files to the `/wp-content/plugins/pmwiki-migrator` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to 'PMWiki Migrator' in the WordPress admin menu

## Configuration

### HTTP Mode (Web Scraping)
- **Base URL**: The URL to your PMWiki installation (e.g., `https://yourwiki.com/wiki/wiki.php`)
- **Content Div ID**: The ID of the div containing wiki content (default: 'wikitext')
- **Delay Between Requests**: Time to wait between page scrapes (minimum: 0.5 seconds)
- **Batch Size**: Number of pages to process in each batch (1-20)

### File Mode (Local Files)
- **Wiki Source Directory**: Path to the wiki.d directory files (default: wp-content/wiki-source)
- **Base URL**: Still required for formatting page names
- **Batch Size**: Number of pages to process in each batch (1-20)

## Usage

1. Choose your migration mode (HTTP or Files)
2. Configure the required settings
3. Click "Start Migration"
4. Monitor progress and use "Process Next Batch" to continue
5. Use "Stop Migration" to pause or "Reset Migration" to start over

## File Mode Setup

To use file mode:
1. Get access to your PMWiki's wiki.d directory
2. Create a directory at `wp-content/wiki-source` in your WordPress installation
3. Copy all files from wiki.d into wp-content/wiki-source
4. Select "Files" as the source type in the plugin settings

## Known Issues

- Content scraping might fail if the PMWiki uses non-standard HTML structure
- Image imports might fail for restricted or very large files
- Some complex PMWiki markup might not convert perfectly

## To Do

1. [ ] Link updating (convert internal wiki links to WordPress permalinks)
2. [ ] Parent/child page relationships
3. [ ] Full PMWiki markup parsing
4. [ ] Better error recovery
5. [ ] Support for PMWiki page groups and categories

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

This project is licensed under the GPL v2 or later.

## Troubleshooting

### Common Issues

1. **Migration seems stuck**: Try increasing the delay between requests
2. **Images not importing**: Check file permissions and PHP memory limits
3. **Content not found**: Verify the content div ID setting

### Debug Logging

The plugin logs detailed information using WordPress's debug log. To enable debugging:

1. Add to wp-config.php:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

2. Check the debug.log file in wp-content for detailed logs

## Support

For support questions or feature requests, please open an issue on GitHub.

## Credits

Developed by [Your Name/Organization]