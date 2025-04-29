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
    // Define a function to recursively flatten nested figures
    $flatten_nested_figures = function($content) use (&$flatten_nested_figures) {
        // Pattern to match nested figures (any level of nesting)
        $pattern = '/<figure>\s*(?:<[^>]*>\s*)*<figure>/s';
        
        if (preg_match($pattern, $content)) {
            // Find all nested figure structures
            $nested_pattern = '/<figure>\s*(?:<[^>]*>\s*)*<figure>\s*(?:<[^>]*>\s*)*<img([^>]*)>\s*(?:<[^>]*>\s*)*<figcaption>(.*?)<\/figcaption>\s*(?:<[^>]*>\s*)*<\/figure>\s*(?:<[^>]*>\s*)*(?:<figcaption>.*?<\/figcaption>\s*)?<\/figure>/s';
            
            $content = preg_replace_callback($nested_pattern, function($matches) {
                $img_attrs = $matches[1];
                $inner_caption = trim($matches[2]);
                
                // Return the simplified figure structure with just one level
                return "<figure>\n<img$img_attrs>\n<figcaption>$inner_caption</figcaption>\n</figure>";
            }, $content);
            
            // Continue flattening until no more nested figures are found
            return $flatten_nested_figures($content);
        }
        
        return $content;
    };
    
    // First flatten any nested figures that might exist
    $content = $flatten_nested_figures($content);
    
    // Now process the flattened figures and other image tags
    
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
    
    // Final check for any remaining nested figures
    $content = $flatten_nested_figures($content);
    
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

// Function to extract and preserve math expressions before Pandoc processing
function preserveMathExpressions($wikiText, $debug = false) {
    // Create storage for math expression placeholders
    $placeholders = [];
    
    // Process the text to extract math expressions and replace them with placeholders
    $processedText = processMathExpressions($wikiText, $placeholders, $debug);
    
    // Return the processed text, placeholders array, and any debug info
    $debugInfo = '';
    if ($debug) {
        $debugInfo = "Found " . count($placeholders) . " math expressions to preserve";
    }
    
    return [$processedText, $placeholders, $debugInfo];
}

// Function to process math expressions in wiki text
function processMathExpressions($wikiText, &$placeholders, $debug = false) {
    $mathExpressionCount = 0;
    
    // Match both <math> tags and $...$ LaTeX
    $patterns = [
        '/<math>(.*?)<\/math>/s', // <math> tags
        '/\$(.*?)\$/s'  // $...$ LaTeX - be careful with this pattern as it might match currency symbols
    ];
    
    foreach ($patterns as $index => $pattern) {
        $wikiText = preg_replace_callback($pattern, function($matches) use (&$mathExpressionCount, &$placeholders, $index, $debug) {
            $content = $matches[1];
            
            // Skip empty math blocks
            if (trim($content) === '') {
                return $matches[0];
            }
            
            // Create unique placeholder ID for this math expression
            $placeholderId = "MATH-" . dechex($mathExpressionCount) . "-" . dechex(rand(0, 65535));
            
            // Store the original math expression
            $placeholders[$placeholderId] = $content;
            
            if ($debug && $mathExpressionCount < 5) {
                echo "#$mathExpressionCount Type: " . ($index == 0 ? "math_tag" : "latex") . ", ID: $placeholderId\n";
                echo "Original: " . $matches[0] . "\n";
                echo "Content: $content\n";
            }
            
            $mathExpressionCount++;
            
            // Return a placeholder that's unlikely to be modified by Pandoc
            return "MATH{$placeholderId}ENDMATH";
        }, $wikiText);
    }
    
    if ($debug) {
        echo "Found $mathExpressionCount math expressions.\n";
        if ($mathExpressionCount > 5) {
            echo "First 5 math expressions:\n";
        }
    }
    
    return $wikiText;
}

// Function to restore math expressions after Pandoc processing
function restoreMathExpressions($markdownText, $placeholders, $debug = false) {
    $restoredCount = 0;
    $missedCount = 0;
    $originalCount = count($placeholders);
    
    // First, check how many placeholders we can actually find
    if ($debug) {
        foreach ($placeholders as $id => $mathExp) {
            $pattern = "/MATH" . preg_quote($id, '/') . "ENDMATH/";
            
            if (preg_match($pattern, $markdownText)) {
                $restoredCount++;
            } else {
                $missedCount++;
                echo "Missed placeholder: $id\n";
                // Try to find similar patterns that might be modified
                echo "Searching for similar patterns...\n";
                if (preg_match_all("/MATH.*?ENDMATH/", $markdownText, $matches)) {
                    echo "Found " . count($matches[0]) . " MATH placeholders\n";
                    if (!empty($matches[0])) {
                        echo "Sample: " . implode(", ", array_slice($matches[0], 0, 3)) . "\n";
                    }
                }
            }
        }
        
        echo "Math expression restoration stats:\n";
        echo "Original placeholders: $originalCount\n";
        echo "Found placeholders: $restoredCount\n";
        echo "Missing placeholders: $missedCount\n\n";
    }
    
    // Now do the actual replacement
    foreach ($placeholders as $id => $mathExp) {
        $pattern = "/MATH" . preg_quote($id, '/') . "ENDMATH/";
        
        // For inline math (short expressions), use single $
        if (strpos($mathExp, "\n") === false && strlen($mathExp) < 50) {
            $markdownText = preg_replace($pattern, '$' . $mathExp . '$', $markdownText);
        } 
        // For display math (multiline or complex), use $$
        else {
            $markdownText = preg_replace($pattern, '$$' . $mathExp . '$$', $markdownText);
        }
    }
    
    return $markdownText;
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
    
    // Enable debug mode for Angular Size page - fix the string comparison
    $debug_math = ($title === 'Angular Size');
    
    echo "Converting: $title" . ($debug_math ? " (with math debug)" : "") . "\n";
    
    // Extract and preserve math expressions before Pandoc processing
    list($processed_text, $math_placeholders, $mathDebugInfo) = preserveMathExpressions($text, $debug_math);
    
    // If we found math expressions in debug mode, write pre-processed content to a debug file
    if ($debug_math && !empty($math_placeholders)) {
        $debug_pre_file = $output_dir . '/' . $safe_title . '.pre.txt';
        file_put_contents($debug_pre_file, $processed_text);
        echo "  Saved pre-processed content to $debug_pre_file\n";
    }
    
    // Create a temporary file with the processed MediaWiki content
    $temp_file = tempnam(sys_get_temp_dir(), 'mw2gfm');
    file_put_contents($temp_file, $processed_text);
    
    // Run pandoc to convert from MediaWiki to GitHub Flavored Markdown
    $command = sprintf(
        'pandoc --from=mediawiki --to=gfm "%s" -o "%s"',
        $temp_file,
        $output_file
    );
    
    exec($command, $output, $return_code);
    
    // Clean up the temporary file
    unlink($temp_file);
    
    if ($return_code !== 0) {
        echo "Error converting page: $title\n";
        continue;
    }
    
    // Get content
    $content = file_get_contents($output_file);
    
    // If debugging, save the content immediately after Pandoc conversion
    if ($debug_math) {
        $debug_post_file = $output_dir . '/' . $safe_title . '.post.txt';
        file_put_contents($debug_post_file, $content);
        echo "  Saved post-Pandoc content to $debug_post_file\n";
    }
    
    // Restore math expressions
    $content = restoreMathExpressions($content, $math_placeholders, $debug_math);
    
    // Process image references
    $content = fix_media_references($content);
    $content = convert_image_tags($content);
    
    // Process section headers
    $content = convert_section_headers($content);
    $content = convert_mediawiki_headers($content);
    
    // Process internal links
    $content = convert_internal_links($content);
    
    // Fix existing wiki syntax if any
    $content = fix_existing_wiki_syntax($content);
    
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