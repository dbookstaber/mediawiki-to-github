# MediaWiki to GitHub Wiki Converter

A Windows-compatible PHP script to convert MediaWiki XML exports to GitHub Flavored Markdown for use in GitHub Wikis.

## Overview

This tool converts MediaWiki XML export files to GitHub Flavored Markdown, making it easy to migrate your content from MediaWiki to GitHub Wiki. It has been specifically designed to:

- Work on Windows systems (unlike some other converters)
- Properly handle media/image references
- Create navigation files for GitHub Wiki
- Maintain the structure of your wiki content

## Requirements

- [PHP](https://windows.php.net/download/) 7.0 or higher
- [Pandoc](https://pandoc.org/installing.html) 2.0 or higher

## Installation

1. Make sure you have PHP installed and included in your PATH
2. Install Pandoc from https://pandoc.org/installing.html
3. Download the `convert_wiki.php` script

## Usage

```
php convert_wiki.php <mediawiki_export.xml> [output_directory]
```

### Parameters

- `<mediawiki_export.xml>`: The path to your MediaWiki XML export file (required)
- `[output_directory]`: The directory where the converted Markdown files will be saved (optional, defaults to 'wiki_output_fixed')

### Example

```
php convert_wiki.php example/sample_export.xml github_wiki_pages
```

## How to Export from MediaWiki

To export your MediaWiki content:

1. Go to Special:Export in your MediaWiki installation
2. Select the pages you want to export (or enter a list of page titles)
3. Make sure "Include only the current revision, not the full history" is checked
4. Click "Export" to download the XML file

## How to Import into GitHub Wiki

After running the converter:

1. Create a GitHub repository if you don't have one
2. Enable the Wiki feature in repository settings
3. Clone your wiki repository:
   ```
   git clone https://github.com/USERNAME/REPOSITORY.wiki.git
   ```
4. Copy the converted files to your cloned wiki repository:
   ```
   cp -r output_directory/* path/to/repository.wiki/
   ```
5. Add, commit, and push the files:
   ```
   cd path/to/repository.wiki
   git add .
   git commit -m "Initial wiki import from MediaWiki"
   git push
   ```

## Features

- Converts MediaWiki syntax to GitHub Flavored Markdown
- Properly fixes media references to include the media/ prefix
- Creates a Home.md file with links to all pages
- Creates a _Sidebar.md file for navigation
- Copies media files from the source to the output directory

## Limitations

- Some complex MediaWiki templates and extensions may not convert properly
- Very complex tables may require manual adjustment
- Some mathematical formulas may need manual fixes after conversion

## Contributing

Contributions are welcome! If you find any issues or have suggestions for improvements, please open an issue or submit a pull request.

## Credits

This tool was inspired by:
- [mediawiki-to-gfm](https://github.com/outofcontrol/mediawiki-to-gfm)
- [Pandoc](https://pandoc.org/)

Created by [David Bookstaber](https://github.com/dbookstaber) in April 2025.