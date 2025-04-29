<?php
/**
 * Windows-compatible MediaWiki to GitHub Flavored Markdown converter
 * With improved media reference handling
 */

// Import the MediaWiki XML export file path from command line arguments
if ($argc < 2) {
    echo "Usage: php media_fixed_converter.php <mediawiki_export.xml> [output_directory]\n";
    exit(1);
}

$input_file = $argv[1];
$output_dir = isset($argv[2]) ? $argv[2] : 'wiki_output_fixed';

// Check if the input file exists
if (!file_exists($input_file)) {
    echo "Error: Input file '{$input_file}' does not exist.\n";
    exit(1);
}

// Create output directory if it doesn't exist
if (!file_exists($output_dir)) {
    mkdir($output_dir, 0777, true);
}

// Check if pandoc is available
exec('pandoc --version', $version_output, $return_code);
if ($return_code !== 0) {
    echo "Error: Pandoc not found. Make sure it's installed and in your PATH.\n";
    exit(1);
}

echo "Found pandoc: " . $version_output[0] . "\n";

// Load the MediaWiki XML export file
$xml_content = file_get_contents($input_file);

// Replace namespace to make parsing easier
$xml_content = str_replace('xmlns=', 'ns=', $xml_content);

// Parse the XML content
try {
    $xml = new SimpleXMLElement($xml_content, LIBXML_PARSEHUGE);
} catch (Exception $e) {
    echo "Error parsing XML file: " . $e->getMessage() . "\n";
    exit(1);
}

// Find all pages
$pages = $xml->xpath('page');
if (empty($pages)) {
    echo "No pages found in the MediaWiki export file.\n";
    exit(1);
}

echo "Found " . count($pages) . " pages to convert.\n";

// Track if we've seen a Home page
$has_home_page = false;

// Create a Home.md file that will serve as the wiki index if no Home page exists
$home_content = "# Wiki Home\n\n";
$home_content .= "Welcome to the wiki. Click on the links below to navigate the content:\n\n";

// Create a _Sidebar.md file for GitHub Wiki navigation
$sidebar_content = "# Navigation\n\n";
$sidebar_content .= "* [Home](Home)\n";
$sidebar_content .= "## Pages\n";

// Track successfully converted pages
$converted_pages = 0;

// Function to fix media references in markdown content
function fix_media_references($content) {
    // Fix image references that don't have media/ prefix
    // This matches standard markdown image syntax: ![alt text](file.jpg)
    $content = preg_replace('/!\[(.*?)\]\((?!media\/|http)(.*?)\)/', '![$1](media/$2)', $content);
    
    // Fix MediaWiki-style image syntax that might have been preserved
    $content = preg_replace('/\[\[File:(.*?)\]\]/', '![media/$1](media/$1)', $content);
    $content = preg_replace('/\[\[Image:(.*?)\]\]/', '![media/$1](media/$1)', $content);
    
    return $content;
}

// Function to convert HTML image tags to GitHub-compatible HTML
function convert_image_tags($content) {
    // Pattern to match figure with image and figcaption
    $pattern1 = '/<figure>\s*<img src="([^"]*)"([^>]*)\/>\s*<figcaption>(.*?)<\/figcaption>\s*<\/figure>/s';
    $content = preg_replace_callback($pattern1, function($matches) {
        $img_src = $matches[1];
        $img_attrs = $matches[2];
        $caption = trim($matches[3]);
        
        // Add media/ prefix if not already present
        if (strpos($img_src, 'media/') !== 0 && strpos($img_src, 'http') !== 0) {
            $img_src = 'media/' . $img_src;
        }
        
        // Extract width if present
        $width = "";
        if (preg_match('/width="([^"]*)"/', $img_attrs, $width_match)) {
            $width = ' width="' . $width_match[1] . '"';
        }
        
        // Clean up caption (remove any pipe-delimited parts)
        if (strpos($caption, '|') !== false) {
            $caption_parts = explode('|', $caption);
            $caption = end($caption_parts); // Take the last part after pipes
        }
        
        // Create clean alt text from caption
        $alt_text = strip_tags($caption);
        $alt = ' alt="' . htmlspecialchars($alt_text) . '"';
        
        return "<figure>\n<img src=\"$img_src\"$width$alt />\n<figcaption>$caption</figcaption>\n</figure>";
    }, $content);
    
    // Pattern to match standalone img tags
    $pattern2 = '/<img src="([^"]*)"([^>]*)>/';
    $content = preg_replace_callback($pattern2, function($matches) {
        $img_src = $matches[1];
        $img_attrs = $matches[2];
        
        // Add media/ prefix if not already present
        if (strpos($img_src, 'media/') !== 0 && strpos($img_src, 'http') !== 0) {
            $img_src = 'media/' . $img_src;
        }
        
        // Extract width if present
        $width = "";
        if (preg_match('/width="([^"]*)"/', $img_attrs, $width_match)) {
            $width = ' width="' . $width_match[1] . '"';
        }
        
        // Extract alt text or title if present
        $alt = "";
        $caption = "";
        if (preg_match('/alt="([^"]*)"/', $img_attrs, $alt_match)) {
            $alt_text = trim($alt_match[1]);
            
            // Clean up alt text (remove any pipe-delimited parts)
            if (strpos($alt_text, '|') !== false) {
                $alt_parts = explode('|', $alt_text);
                $alt_text = end($alt_parts); // Take the last part after pipes
            }
            
            $alt = ' alt="' . htmlspecialchars($alt_text) . '"';
            $caption = $alt_text;
        }
        
        // If we have a caption, use figure/figcaption
        if (!empty($caption)) {
            return "<figure>\n<img src=\"$img_src\"$width$alt />\n<figcaption>$caption</figcaption>\n</figure>";
        } else {
            // Otherwise use standard markdown
            return "![]($img_src)";
        }
    }, $content);
    
    // Convert wiki-style image syntax with pipe-delimited parts
    $pattern3 = '/\[\[(media\/[^|\]]+)(\|[^\]]*)\]\]/';
    $content = preg_replace_callback($pattern3, function($matches) {
        $img_src = $matches[1];
        $attrs = $matches[2];
        
        // Extract width if present
        $width = "";
        if (preg_match('/width=([0-9]+)px/', $attrs, $width_match)) {
            $width = " width=\"" . $width_match[1] . "\"";
        }
        
        // Extract alt text and caption
        $alt = "";
        $caption = "";
        if (preg_match('/alt=([^|]+)/', $attrs, $alt_match)) {
            $alt_text = trim($alt_match[1]);
            $alt = " alt=\"" . htmlspecialchars($alt_text) . "\"";
            $caption = $alt_text;
        }
        
        // If we have a caption, use figure/figcaption
        if (!empty($caption)) {
            return "<figure>\n<img src=\"$img_src\"$width$alt />\n<figcaption>$caption</figcaption>\n</figure>";
        } else {
            // Otherwise use standard markdown
            return "![]($img_src)";
        }
    }, $content);
    
    // Convert remaining bare references
    $pattern4 = '/\[\[(media\/[^\]]+)\]\]/';
    $content = preg_replace($pattern4, '![]($1)', $content);
    
    // Handle standard markdown images to ensure they have media prefix
    $pattern5 = '/!\[(.*?)\]\((?!media\/|http)([^)]+)\)/';
    $content = preg_replace($pattern5, '![$1](media/$2)', $content);
    
    return $content;
}

// Function to convert internal wiki links to GitHub wiki syntax
function convert_internal_links($content) {
    // Pattern to match internal wiki links: <a href="PageName" class="wikilink" title="PageName">link text</a>
    $pattern = '/<a href="([^"]+)" class="wikilink"[^>]*>([^<]+)<\/a>/';

    return preg_replace_callback($pattern, function($matches) {
        $pageName = $matches[1];
        $linkText = $matches[2];
        
        // Replace underscores with dashes for GitHub wiki compatibility
        $pageName = str_replace('_', '-', $pageName);
        
        // Convert to GitHub wiki link format: [[PageName|link text]]
        return "[[$pageName|$linkText]]";
    }, $content);
}

// Function to convert MediaWiki section headers to Markdown headers
function convert_section_headers($content) {
    // Match MediaWiki section headers: == Header == or === Header ===
    // We need to be more specific with our pattern and handle whitespace properly
    $content = preg_replace_callback('/^[ \t]*(=+)[ \t]*(.*?)[ \t]*\1[ \t]*$/m', function($matches) {
        $level = strlen($matches[1]); // Number of = characters
        $header_text = trim($matches[2]);
        
        // Convert to Markdown header (# to ###### for h1 to h6)
        $markdown_prefix = str_repeat('#', $level);
        return "$markdown_prefix $header_text";
    }, $content);
    
    return $content;
}

// Direct string replacement for MediaWiki headers
function convert_mediawiki_headers($content) {
    // Directly target the format we're seeing in the files
    $patterns = [
        '/== (.*?) ==/' => '## $1',
        '/=== (.*?) ===/' => '### $1',
        '/==== (.*?) ====/' => '#### $1',
        '/===== (.*?) =====/' => '##### $1',
        '/====== (.*?) ======/' => '###### $1'
    ];
    
    foreach ($patterns as $pattern => $replacement) {
        $content = preg_replace($pattern, $replacement, $content);
    }
    
    return $content;
}

// Function to fix existing GitHub wiki syntax
function fix_existing_wiki_syntax($content) {
    // Fix existing GitHub wiki image syntax to use media/ prefix
    $pattern1 = '/\[\[([^|\]]+)(\|[^\]]*)\]\]/';
    $content = preg_replace_callback($pattern1, function($matches) {
        $img_src = $matches[1];
        $rest = isset($matches[2]) ? $matches[2] : '';
        
        // Don't modify if it's already a wiki page link with no file extension
        if (preg_match('/\.(jpg|jpeg|png|gif|svg|webp)$/i', $img_src) && 
            strpos($img_src, 'media/') !== 0 && 
            strpos($img_src, 'http') !== 0) {
            $img_src = 'media/' . $img_src;
        }
        
        return "[[$img_src$rest]]";
    }, $content);
    
    // Fix existing internal links with underscores to use dashes
    $pattern2 = '/\[\[([^|\]]+)_([^|\]]+)(\|[^\]]*)\]\]/';
    while (preg_match($pattern2, $content)) {
        $content = preg_replace_callback($pattern2, function($matches) {
            $prefix = $matches[1];
            $suffix = $matches[2];
            $rest = isset($matches[3]) ? $matches[3] : '';
            
            return "[[$prefix-$suffix$rest]]";
        }, $content);
    }
    
    return $content;
}

// Function to directly process all wiki image syntax in one step
function process_wiki_images($content) {
    // First ensure all image paths have media/ prefix
    $content = preg_replace('/<img src="(?!media\/|http)([^"]+)/', '<img src="media/$1', $content);
    
    // Handle title attributes that contain pipe-delimited content (alt|caption)
    $content = preg_replace_callback('/<img ([^>]*) title="([^|]+)\|([^"]+)"([^>]*)>/', function($matches) {
        $start = $matches[1];
        $alt = trim($matches[2]);
        $caption = trim($matches[3]);
        $end = $matches[4];
        
        // Extract width if present
        $width = "";
        if (preg_match('/width="([^"]*)"/', $start . $end, $width_match)) {
            $width = ' width="' . $width_match[1] . '"';
        }
        
        // Get the src attribute
        $src = "";
        if (preg_match('/src="([^"]*)"/', $start . $end, $src_match)) {
            $src = $src_match[1];
        }
        
        // Create a figure with figcaption
        return "<figure>\n<img src=\"$src\"$width alt=\"$alt\" />\n<figcaption>$caption</figcaption>\n</figure>";
    }, $content);
    
    // Now replace all wiki image syntax with clean HTML or markdown
    $pattern = '/\[\[(media\/[^|\]]+)(?:\|alt=(.*?))?(?:\|width=(\d+)px)?\]\]/';
    $content = preg_replace_callback($pattern, function($matches) {
        $img_src = $matches[1];
        $alt = isset($matches[2]) ? trim($matches[2]) : "";
        $width = isset($matches[3]) ? $matches[3] : "";
        
        // If we have alt text, use it as a caption with figure/figcaption
        if (!empty($alt)) {
            $width_attr = !empty($width) ? " width=\"$width\"" : "";
            return "<figure>\n<img src=\"$img_src\"$width_attr alt=\"$alt\" />\n<figcaption>$alt</figcaption>\n</figure>";
        } else {
            // Otherwise use standard markdown
            return "![](media/$img_src)";
        }
    }, $content);
    
    // Clean up standalone images with alt attributes
    $content = preg_replace_callback('/<img ([^>]*)alt="([^|"]+)\|([^"]+)"([^>]*)>/', function($matches) {
        $start = $matches[1];
        $alt = trim($matches[2]);
        $caption = trim($matches[3]);
        $end = $matches[4];
        
        // Extract width if present
        $width = "";
        if (preg_match('/width="([^"]*)"/', $start . $end, $width_match)) {
            $width = ' width="' . $width_match[1] . '"';
        }
        
        // Get the src attribute
        $src = "";
        if (preg_match('/src="([^"]*)"/', $start . $end, $src_match)) {
            $src = $src_match[1];
        }
        
        // Create a figure with figcaption
        return "<figure>\n<img src=\"$src\"$width alt=\"$alt\" />\n<figcaption>$caption</figcaption>\n</figure>";
    }, $content);
    
    // Remove any nested figures that might remain
    $content = preg_replace('/<figure>\s*<figure>(.*?)<\/figure>\s*<\/figure>/s', '<figure>$1</figure>', $content);
    
    // Remove duplicate figcaptions
    $content = preg_replace('/<figcaption>(.*?)<\/figcaption>\s*<figcaption>(.*?)<\/figcaption>/s', '<figcaption>$1</figcaption>', $content);
    
    return $content;
}

foreach ($pages as $page) {
    $title = (string)$page->title;
    
    // Skip non-content namespaces
    $ns = (int)$page->ns;
    if ($ns !== 0) {
        echo "Skipping non-content namespace: $title\n";
        continue;
    }
    
    // Get the latest revision text
    $text = (string)$page->revision->text;
    if (empty($text)) {
        echo "Skipping empty page: $title\n";
        continue;
    }

    // Check if this is the Home page
    if ($title === 'Home') {
        $has_home_page = true;
    }
    
    // Clean the title for use as a filename
    $safe_title = preg_replace('/[\\\\\/\:\*\?\"\<\>\|]/', '', str_replace(' ', '-', $title));
    $output_file = $output_dir . '/' . $safe_title . '.md';
    
    // Create a temporary file with the MediaWiki content
    $temp_file = tempnam(sys_get_temp_dir(), 'mw2gfm');
    file_put_contents($temp_file, $text);
    
    // Run pandoc to convert from MediaWiki to GitHub Flavored Markdown
    $command = sprintf(
        'pandoc --from=mediawiki --to=gfm "%s" -o "%s"',
        $temp_file,
        $output_file
    );
    
    echo "Converting: $title\n";
    exec($command, $output, $return_code);
    
    // Clean up the temporary file
    unlink($temp_file);
    
    if ($return_code !== 0) {
        echo "Error converting page: $title\n";
        continue;
    }
    
    // Get content and apply conversions
    $content = file_get_contents($output_file);
    
    // No longer adding the title at the top
    // $content = "# $title\n\n" . $content;
    
    // Process wiki image syntax first
    $content = process_wiki_images($content);
    
    // Process section headers
    $content = convert_section_headers($content);
    $content = convert_mediawiki_headers($content);
    
    // Process internal links
    $content = convert_internal_links($content);
    
    file_put_contents($output_file, $content);
    
    // Add page to navigation pages, but don't add Home to itself
    if ($title !== 'Home') {
        $home_content .= "* [$title]($safe_title)\n";
    }
    $sidebar_content .= "* [$title]($safe_title)\n";
    
    $converted_pages++;
}

// Only create the auto-generated Home.md if no actual Home page was found
if (!$has_home_page) {
    file_put_contents($output_dir . '/Home.md', $home_content);
}

// Write _Sidebar.md
file_put_contents($output_dir . '/_Sidebar.md', $sidebar_content);

// Create media directory
if (!file_exists($output_dir . '/media')) {
    mkdir($output_dir . '/media', 0777, true);
}

// Copy media files if available
$source_media_dir = 'markdown_pages/media';
if (file_exists($source_media_dir)) {
    echo "Copying media files to $output_dir/media/...\n";
    
    $media_count = 0;
    $files = scandir($source_media_dir);
    foreach ($files as $file) {
        if ($file == '.' || $file == '..') {
            continue;
        }
        
        $source = $source_media_dir . '/' . $file;
        $dest = $output_dir . '/media/' . $file;
        
        if (is_file($source)) {
            copy($source, $dest);
            $media_count++;
        }
    }
    
    echo "Copied $media_count media files.\n";
}

echo "\nConversion complete! Successfully converted $converted_pages pages.\n";
echo "Output files are in the '$output_dir' directory.\n";