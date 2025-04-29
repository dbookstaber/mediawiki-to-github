# Example Usage

This directory contains a small sample MediaWiki XML export file (`sample_export.xml`) that you can use to test the converter.

## How to Run the Example

1. From the parent directory, run:
   ```
   php media_fixed_converter.php example/sample_export.xml example/output
   ```

2. The converted Markdown files will be created in the `example/output` directory.

3. Examine the output to see how the MediaWiki content was converted to GitHub Flavored Markdown.

## What to Look For

- Notice how images are properly referenced with the `media/` prefix
- See how the Home.md file provides navigation to all pages
- Check the _Sidebar.md file that GitHub Wiki will use for navigation
- Observe how MediaWiki syntax has been converted to GitHub Flavored Markdown