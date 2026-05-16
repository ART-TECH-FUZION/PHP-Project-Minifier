<?php
/**
 * PHP Project Compressor - Core Library
 * 
 * A Vite-style minifier for PHP projects.
 * Handles PHP, HTML, CSS, JS, JSON, JSX, XML files.
 * 
 * @version 1.0.0
 */

class Compressor {
    
    private $stats = [
        'files' => 0,
        'compressed' => 0,
        'skipped' => 0,
        'ignored' => 0,
        'originalSize' => 0,
        'compressedSize' => 0,
        'errors' => []
    ];
    
    private $config = [
        'extensions' => ['php', 'html', 'htm', 'css', 'js', 'json', 'jsx', 'xml'],
        'exclude' => ['.git'],
        'createIndex' => true,
        'indexContent' => "<?php\n// Silence is golden.\n"
    ];
    
    private $gitignorePatterns = [];
    private $sourceBaseDir = '';
    private $outputDir = '';
    private $isFirstLevel = true;
    
    /**
     * Constructor
     */
    public function __construct($config = []) {
        $this->config = array_merge($this->config, $config);
    }
    
    /**
     * Get compression stats
     */
    public function getStats() {
        return $this->stats;
    }
    
    /**
     * Main compression method
     */
    public function compress($sourceDir, $outputDir, $dryRun = false) {
        if (!is_dir($sourceDir)) {
            throw new Exception("Source directory does not exist: $sourceDir");
        }
        
        $this->resetStats();
        $this->sourceBaseDir = realpath($sourceDir);
        $this->outputDir = realpath($outputDir) ?: $outputDir;
        $this->isFirstLevel = true;
        $this->loadGitignore($this->sourceBaseDir);
        
        if (!$dryRun) {
            if (!is_dir($outputDir)) {
                if (!@mkdir($outputDir, 0755, true)) {
                    throw new Exception("Cannot create output directory: $outputDir");
                }
            }
            
            if ($this->config['createIndex']) {
                $this->createIndexFile($outputDir);
            }
        }
        
        $this->recurseCopy($this->sourceBaseDir, $outputDir, $dryRun);
        
        return $this->stats;
    }
    
    /**
     * Reset stats
     */
    private function resetStats() {
        $this->stats = [
            'files' => 0,
            'compressed' => 0,
            'skipped' => 0,
            'ignored' => 0,
            'originalSize' => 0,
            'compressedSize' => 0,
            'errors' => []
        ];
    }
    
    /**
     * Recursively copy and process files
     */
    private function recurseCopy($src, $dst, $dryRun) {
        $dir = opendir($src);
        
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') continue;
            
            $srcPath = $src . DIRECTORY_SEPARATOR . $file;
            $dstPath = $dst . DIRECTORY_SEPARATOR . $file;
            
            // Skip if this is the output 'compress' directory in the source
            // (when output dir is named 'compress' and exists in source)
            if ($this->isFirstLevel && $file === 'compress' && is_dir($srcPath)) {
                $outputBasename = basename($this->outputDir);
                if ($file === $outputBasename) {
                    $this->stats['skipped']++;
                    continue;
                }
            }
            
            // Check exclude patterns
            $relativePath = $this->getRelativePath($srcPath);

            if ($this->isExcluded($relativePath, is_dir($srcPath))) {
                $this->stats['ignored']++;
                continue;
            }
            
            if (is_dir($srcPath)) {
                // Mark that we're past first level
                $wasFirst = $this->isFirstLevel;
                $this->isFirstLevel = false;
                
                if (!$dryRun) {
                    if (!is_dir($dstPath)) {
                        @mkdir($dstPath, 0755, true);
                    }
                    
                    if ($this->config['createIndex']) {
                        $this->createIndexFile($dstPath);
                    }
                }
                
                $this->recurseCopy($srcPath, $dstPath, $dryRun);
                
                // Restore first level state
                $this->isFirstLevel = $wasFirst;
            } else {
                $this->processFile($srcPath, $dstPath, $dryRun);
            }
        }
        
        closedir($dir);
    }
    
    /**
     * Process single file
     */
    private function processFile($srcPath, $dstPath, $dryRun) {
        $this->stats['files']++;
        
        $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
        
        // Skip unsupported files
        if (!in_array($ext, $this->config['extensions'])) {
            if (!$dryRun) {
                copy($srcPath, $dstPath);
            }
            $this->stats['skipped']++;
            return;
        }
        
        $content = file_get_contents($srcPath);
        $originalSize = strlen($content);
        $this->stats['originalSize'] += $originalSize;
        
        try {
            $compressed = $this->minifyContent($content, $ext);
            $compressedSize = strlen($compressed);
            
            if (!$dryRun) {
                file_put_contents($dstPath, $compressed);
            }
            
            $this->stats['compressed']++;
            $this->stats['compressedSize'] += $compressedSize;
            
        } catch (Exception $e) {
            if (!$dryRun) {
                copy($srcPath, $dstPath);
            }
            $this->stats['errors'][] = [
                'file' => $srcPath,
                'error' => $e->getMessage()
            ];
            $this->stats['skipped']++;
        }
    }
    
    /**
     * Minify content based on file type
     */
    private function minifyContent($content, $ext) {
        switch ($ext) {
            case 'php':
                return $this->minifyMixedCode($content);
            case 'html':
            case 'htm':
                return $this->minifyHTML($content);
            case 'css':
                return $this->minifyCSS($content);
            case 'js':
                return $this->minifyJS($content);
            case 'json':
                return $this->minifyJSON($content);
            case 'jsx':
                return $this->minifyJSX($content);
            case 'xml':
                return $this->minifyXML($content);
            default:
                return $content;
        }
    }
    
    // ==========================================
    // CSS MINIFIER
    // ==========================================
    
    private function minifyCSS($css) {
        // Protect strings
        $strings = [];
        $css = preg_replace_callback('/([\'"])(.*?)(?<!\\\\)\1/s', function($m) use (&$strings) {
            $strings[] = $m[0];
            return '___CSS_STR_' . (count($strings) - 1) . '___';
        }, $css);
        
        // Remove comments
        $css = preg_replace('!/\*.*?\*/!s', '', $css);
        
        // Collapse whitespace
        $css = preg_replace('/\s+/', ' ', $css);
        
        // Remove unnecessary spaces
        $css = str_replace([' {', '{ ', ' }', '}', ': ', ';}', ', '], ['{', '{', '}', '}', ':', '}', ','], $css);
        
        // Restore strings
        foreach ($strings as $i => $str) {
            $css = str_replace('___CSS_STR_' . $i . '___', $str, $css);
        }
        
        return trim($css);
    }
    
    // ==========================================
    // JAVASCRIPT MINIFIER
    // ==========================================
    
    private function minifyJS($js) {
        $result = '';
        $len = strlen($js);
        $i = 0;
        $inString = false;
        $stringChar = '';
        $lastChar = '';
        $inLineComment = false;
        $inBlockComment = false;
        $inRegex = false;
        $regexFlags = 'gimsuy';
        
        while ($i < $len) {
            $char = $js[$i];
            $nextChar = ($i + 1 < $len) ? $js[$i + 1] : '';
            
            // NOT IN COMMENT OR REGEX
            if (!$inLineComment && !$inBlockComment && !$inRegex) {
                
                // Template literals
                if (!$inString && $char === '`') {
                    [$templateLiteral, $nextIndex] = $this->consumeTemplateLiteral($js, $i);
                    $result .= $templateLiteral;
                    $lastChar = substr($templateLiteral, -1);
                    $i = $nextIndex;
                    continue;
                }
                
                // Regular strings
                if (!$inString && ($char === '"' || $char === "'")) {
                    $inString = true;
                    $stringChar = $char;
                    $result .= $char;
                    $lastChar = $char;
                    $i++;
                    continue;
                }
                
                if ($inString && $stringChar !== '`') {
                    $result .= $char;
                    if ($char === '\\' && $i + 1 < $len) {
                        $i++;
                        $result .= $js[$i];
                        $lastChar = $js[$i];
                    } elseif ($char === $stringChar) {
                        $inString = false;
                        $lastChar = $char;
                    } else {
                        $lastChar = $char;
                    }
                    $i++;
                    continue;
                }
                
                // Comments
                if ($char === '/' && $nextChar === '/') {
                    $inLineComment = true;
                    $i += 2;
                    continue;
                }
                
                if ($char === '/' && $nextChar === '*') {
                    $inBlockComment = true;
                    $i += 2;
                    continue;
                }
                
                // Regex detection
                if ($char === '/' && !$inString) {
                    if ($this->canStartJsRegex($result)) {
                        $inRegex = true;
                        $result .= $char;
                        $lastChar = $char;
                        $i++;
                        continue;
                    }
                }
            }
            
            // IN LINE COMMENT
            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                    $nextSignificant = $this->getNextNonWhitespaceChar($js, $i + 1);
                    if ($this->needsTokenSeparator($lastChar, $nextSignificant, false)) {
                        $result .= ' ';
                        $lastChar = ' ';
                    }
                }
                $i++;
                continue;
            }
            
            // IN BLOCK COMMENT
            if ($inBlockComment) {
                if ($char === '*' && $nextChar === '/') {
                    $inBlockComment = false;
                    $nextSignificant = $this->getNextNonWhitespaceChar($js, $i + 2);
                    if ($this->needsTokenSeparator($lastChar, $nextSignificant, false)) {
                        $result .= ' ';
                        $lastChar = ' ';
                    }
                    $i += 2;
                    continue;
                }
                $i++;
                continue;
            }
            
            // IN REGEX
            if ($inRegex) {
                $result .= $char;
                if ($char === '\\' && $i + 1 < $len) {
                    $i++;
                    $result .= $js[$i];
                    $lastChar = $js[$i];
                } elseif ($char === '/') {
                    $inRegex = false;
                    while ($i + 1 < $len && strpos($regexFlags, $js[$i + 1]) !== false) {
                        $i++;
                        $result .= $js[$i];
                    }
                    $lastChar = $result[strlen($result) - 1];
                } else {
                    $lastChar = $char;
                }
                $i++;
                continue;
            }
            
            // WHITESPACE
            if (preg_match('/\s/', $char)) {
                if (preg_match('/\s/', $lastChar)) {
                    $i++;
                    continue;
                }
                
                if (preg_match('/[a-zA-Z0-9_$]/', $lastChar) && preg_match('/[a-zA-Z0-9_$]/', $nextChar)) {
                    $result .= ' ';
                    $lastChar = ' ';
                } else {
                    $lastChar = ' ';
                }
                $i++;
                continue;
            }
            
            $result .= $char;
            $lastChar = $char;
            $i++;
        }
        
        return trim($result);
    }
    
    // ==========================================
    // JSON MINIFIER (Pure String Processing)
    // ==========================================
    
    private function minifyJSON($json) {
        $result = '';
        $len = strlen($json);
        $i = 0;
        $inString = false;
        
        while ($i < $len) {
            $char = $json[$i];
            
            if ($inString) {
                $result .= $char;
                if ($char === '\\' && $i + 1 < $len) {
                    $i++;
                    $result .= $json[$i];
                } elseif ($char === '"') {
                    $inString = false;
                }
                $i++;
                continue;
            }
            
            if ($char === '"') {
                $inString = true;
                $result .= $char;
            } elseif (!preg_match('/\s/', $char)) {
                $result .= $char;
            }
            
            $i++;
        }
        
        return trim($result);
    }
    
    // ==========================================
    // JSX MINIFIER
    // ==========================================
    
    private function minifyJSX($code) {
        return $this->minifyJS($code);
    }
    
    // ==========================================
    // XML MINIFIER
    // ==========================================
    
    private function minifyXML($xml) {
        $placeholders = [];
        
        // Protect CDATA
        $xml = preg_replace_callback('/<!\[CDATA\[.*?\]\]>/is', function($m) use (&$placeholders) {
            $key = '___XML_CDATA_' . count($placeholders) . '___';
            $placeholders[$key] = $m[0];
            return $key;
        }, $xml);
        
        // Protect processing instructions
        $xml = preg_replace_callback('/<\?.*?\?>/s', function($m) use (&$placeholders) {
            $key = '___XML_PI_' . count($placeholders) . '___';
            $placeholders[$key] = $m[0];
            return $key;
        }, $xml);
        
        // Remove comments
        $xml = preg_replace('/<!--.*?-->/s', '', $xml);
        
        // Collapse whitespace
        $xml = preg_replace('/\s+/', ' ', $xml);
        $xml = preg_replace('/>\s+</', '><', $xml);
        $xml = preg_replace('/\s*=\s*/', '=', $xml);
        
        // Restore placeholders
        foreach ($placeholders as $key => $val) {
            $xml = str_replace($key, $val, $xml);
        }
        
        return trim($xml);
    }
    
    // ==========================================
    // HTML MINIFIER
    // ==========================================
    
    private function minifyHTML($html) {
        $result = '';
        $len = strlen($html);
        $i = 0;
        $buffer = '';
        $lowerHtml = strtolower($html);
        
        while ($i < $len) {
            // Protect <pre> and <textarea>
            if (substr($lowerHtml, $i, 4) === '<pre' || substr($lowerHtml, $i, 9) === '<textarea') {
                if ($buffer !== '') {
                    $result .= $this->minifyHTMLContent($buffer);
                    $buffer = '';
                }
                
                $tagName = substr($lowerHtml, $i, 4) === '<pre' ? 'pre' : 'textarea';
                $closeTag = stripos($html, '</' . $tagName . '>', $i);
                
                if ($closeTag === false) {
                    $buffer .= substr($html, $i);
                    break;
                }
                
                $closeTagEnd = $closeTag + strlen('</' . $tagName . '>');
                $result .= substr($html, $i, $closeTagEnd - $i);
                $i = $closeTagEnd;
                continue;
            }
            
            // Minify <style>
            if (substr($lowerHtml, $i, 6) === '<style') {
                if ($buffer !== '') {
                    $result .= $this->minifyHTMLContent($buffer);
                    $buffer = '';
                }
                
                $tagEnd = strpos($html, '>', $i);
                if ($tagEnd === false) {
                    $buffer .= $html[$i];
                    $i++;
                    continue;
                }
                
                $tagEnd++;
                $closeTag = stripos($html, '</style>', $tagEnd);
                
                if ($closeTag === false) {
                    $result .= substr($html, $i, $tagEnd - $i) . $this->minifyCSS(substr($html, $tagEnd));
                    break;
                }
                
                $result .= substr($html, $i, $tagEnd - $i) . $this->minifyCSS(substr($html, $tagEnd, $closeTag - $tagEnd)) . '</style>';
                $i = $closeTag + 8;
                continue;
            }
            
            // Minify <script>
            if (substr($lowerHtml, $i, 7) === '<script') {
                if ($buffer !== '') {
                    $result .= $this->minifyHTMLContent($buffer);
                    $buffer = '';
                }
                
                $tagEnd = strpos($html, '>', $i);
                if ($tagEnd === false) {
                    $buffer .= $html[$i];
                    $i++;
                    continue;
                }
                
                $tagEnd++;
                $closeTag = stripos($html, '</script>', $tagEnd);
                
                if ($closeTag === false) {
                    $result .= substr($html, $i, $tagEnd - $i) . $this->minifyJS(substr($html, $tagEnd));
                    break;
                }
                
                $result .= substr($html, $i, $tagEnd - $i) . $this->minifyJS(substr($html, $tagEnd, $closeTag - $tagEnd)) . '</script>';
                $i = $closeTag + 9;
                continue;
            }
            
            $buffer .= $html[$i];
            $i++;
        }
        
        if ($buffer !== '') {
            $result .= $this->minifyHTMLContent($buffer);
        }
        
        return trim($result);
    }
    
    private function minifyHTMLContent($html) {
        $html = preg_replace('/<!--[\s\S]*?-->/s', '', $html);
        $html = preg_replace('/\s+/', ' ', $html);
        $html = preg_replace('/>\s+</', '><', $html);
        $html = preg_replace('/\s+\/>/', '/>', $html);
        return trim($html);
    }
    
    // ==========================================
    // MIXED PHP/HTML/CSS/JS MINIFIER
    // ==========================================
    
    private function minifyMixedCode($code) {
        $segments = $this->parseMixedCode($code);
        $result = '';
        
        foreach ($segments as $segment) {
            $originalContent = $segment['content'];
            $minified = '';
            
            if ($segment['type'] === 'php') {
                $minified = $this->minifyPHPBlock($originalContent);
            } else {
                $minified = $this->minifyNonPHP($originalContent);
            }
            
            // Smart spacing to prevent class merging bugs
            if ($segment['type'] === 'html' && preg_match('/\s$/', $originalContent) && !preg_match('/\s$/', $minified)) {
                $minified .= ' ';
            }
            if ($segment['type'] === 'php' && preg_match('/^\s/', $originalContent) && !preg_match('/^\s/', $minified)) {
                $minified = ' ' . $minified;
            }
            
            $result .= $minified;
        }
        
        return $result;
    }
    
    private function parseMixedCode($code) {
        $segments = [];
        $len = strlen($code);
        $i = 0;
        $buffer = '';
        $inPhp = false;
        
        while ($i < $len) {
            if (!$inPhp) {
                // <?php tag
                if (substr($code, $i, 5) === '<?php') {
                    $nextIdx = $i + 5;
                    if ($nextIdx >= $len || !preg_match('/[a-zA-Z0-9_\x7f-\xff]/', $code[$nextIdx])) {
                        if ($buffer !== '') {
                            $segments[] = ['type' => 'html', 'content' => $buffer];
                            $buffer = '';
                        }
                        $inPhp = true;
                        $buffer = '<?php';
                        $i += 5;
                        continue;
                    }
                }
                
                // <?= short echo tag
                if (substr($code, $i, 3) === '<?=') {
                    if ($buffer !== '') {
                        $segments[] = ['type' => 'html', 'content' => $buffer];
                        $buffer = '';
                    }
                    $inPhp = true;
                    $buffer = '<?=';
                    $i += 3;
                    continue;
                }
                
                // <? short tag
                if (substr($code, $i, 2) === '<?') {
                    $after = substr($code, $i + 2, 3);
                    if (!preg_match('/^[a-zA-Z]/', $after)) {
                        if ($buffer !== '') {
                            $segments[] = ['type' => 'html', 'content' => $buffer];
                            $buffer = '';
                        }
                        $inPhp = true;
                        $buffer = '<?';
                        $i += 2;
                        continue;
                    }
                }
                
                $buffer .= $code[$i];
                $i++;
            } else {
                // Close PHP tag
                if (substr($code, $i, 2) === '?>') {
                    $buffer .= '?>';
                    $segments[] = ['type' => 'php', 'content' => $buffer];
                    $buffer = '';
                    $inPhp = false;
                    $i += 2;
                    continue;
                }
                $buffer .= $code[$i];
                $i++;
            }
        }
        
        if ($buffer !== '') {
            $segments[] = ['type' => $inPhp ? 'php' : 'html', 'content' => $buffer];
        }
        
        return $segments;
    }
    
    private function minifyPHPBlock($block) {
        if (preg_match('/^(<\?(?:php|=)?)\s*/i', $block, $matches)) {
            $openTag = $matches[1];
            $content = substr($block, strlen($matches[0]));
        } else {
            return $block;
        }
        
        $hasCloseTag = false;
        if (substr($content, -2) === '?>') {
            $content = substr($content, 0, -2);
            $hasCloseTag = true;
        }
        
        $minified = $this->minifyPHPContent($content);
        
        // Remove empty PHP blocks
        if (empty($minified) && $openTag === '<?php') {
            return '';
        }
        
        return $openTag . ' ' . $minified . ($hasCloseTag ? ' ?>' : '');
    }
    
    private function minifyPHPContent($code) {
        $result = '';
        $len = strlen($code);
        $i = 0;
        $lastChar = '';
        $inLineComment = false;
        $inBlockComment = false;
        
        while ($i < $len) {
            $char = $code[$i];
            $nextChar = ($i + 1 < $len) ? $code[$i + 1] : '';
            
            if (!$inLineComment && !$inBlockComment) {
                if ($char === '"' || $char === "'") {
                    [$literal, $nextIndex] = $this->consumePhpStringLiteral($code, $i, $char);
                    $result .= $this->minifyStructuredPhpStringLiteral($literal, $char);
                    $lastChar = substr($literal, -1);
                    $i = $nextIndex;
                    continue;
                }
                
                // Comments
                if ($char === '/' && $nextChar === '/') {
                    $inLineComment = true;
                    $i += 2;
                    continue;
                }
                
                if ($char === '#') {
                    $inLineComment = true;
                    $i++;
                    continue;
                }
                
                if ($char === '/' && $nextChar === '*') {
                    $inBlockComment = true;
                    $i += 2;
                    continue;
                }
            }
            
            // Line comment
            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                    $nextSignificant = $this->getNextNonWhitespaceChar($code, $i + 1);
                    if ($this->needsTokenSeparator($lastChar, $nextSignificant, true)) {
                        $result .= ' ';
                        $lastChar = ' ';
                    }
                }
                $i++;
                continue;
            }
            
            // Block comment
            if ($inBlockComment) {
                if ($char === '*' && $nextChar === '/') {
                    $inBlockComment = false;
                    $nextSignificant = $this->getNextNonWhitespaceChar($code, $i + 2);
                    if ($this->needsTokenSeparator($lastChar, $nextSignificant, true)) {
                        $result .= ' ';
                        $lastChar = ' ';
                    }
                    $i += 2;
                    continue;
                }
                $i++;
                continue;
            }
            
            // Whitespace
            if (preg_match('/\s/', $char)) {
                if (preg_match('/\s/', $lastChar)) {
                    $i++;
                    continue;
                }
                
                if ($this->needsTokenSeparator($lastChar, $nextChar, true)) {
                    $result .= ' ';
                    $lastChar = ' ';
                } else {
                    $lastChar = ' ';
                }
                $i++;
                continue;
            }
            
            $result .= $char;
            $lastChar = $char;
            $i++;
        }
        
        return trim($result);
    }
    
    private function minifyNonPHP($content) {
        $result = '';
        $len = strlen($content);
        $i = 0;
        $buffer = '';
        $lowerContent = strtolower($content);
        
        while ($i < $len) {
            // Handle <style> tags
            if (substr($lowerContent, $i, 6) === '<style') {
                if ($buffer !== '') {
                    $result .= $this->minifyHTMLContent($buffer);
                    $buffer = '';
                }
                
                $tagEnd = strpos($content, '>', $i);
                if ($tagEnd === false) {
                    $buffer .= $content[$i];
                    $i++;
                    continue;
                }
                
                $tagEnd++;
                $styleOpenTag = substr($content, $i, $tagEnd - $i);
                $closeTag = stripos($content, '</style>', $tagEnd);
                
                if ($closeTag === false) {
                    $result .= $styleOpenTag . $this->minifyCSS(substr($content, $tagEnd));
                    break;
                }
                
                $result .= $styleOpenTag . $this->minifyCSS(substr($content, $tagEnd, $closeTag - $tagEnd)) . '</style>';
                $i = $closeTag + 8;
                continue;
            }
            
            // Handle <script> tags
            if (substr($lowerContent, $i, 7) === '<script') {
                if ($buffer !== '') {
                    $result .= $this->minifyHTMLContent($buffer);
                    $buffer = '';
                }
                
                $tagEnd = strpos($content, '>', $i);
                if ($tagEnd === false) {
                    $buffer .= $content[$i];
                    $i++;
                    continue;
                }
                
                $tagEnd++;
                $scriptOpenTag = substr($content, $i, $tagEnd - $i);
                $closeTag = stripos($content, '</script>', $tagEnd);
                
                if ($closeTag === false) {
                    $result .= $scriptOpenTag . $this->minifyJS(substr($content, $tagEnd));
                    break;
                }
                
                $result .= $scriptOpenTag . $this->minifyJS(substr($content, $tagEnd, $closeTag - $tagEnd)) . '</script>';
                $i = $closeTag + 9;
                continue;
            }
            
            $buffer .= $content[$i];
            $i++;
        }
        
        if ($buffer !== '') {
            $result .= $this->minifyHTMLContent($buffer);
        }
        
        return $result;
    }
    
    // ==========================================
    // EXCLUDE PATTERNS
    // ==========================================
    
    private function loadGitignore($sourceDir) {
        $gitignorePath = $sourceDir . '/.gitignore';
        $this->gitignorePatterns = [];
        
        if (!file_exists($gitignorePath)) {
            return;
        }
        
        $lines = file($gitignorePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || $line[0] === '#') {
                continue;
            }
            $negated = $line[0] === '!';
            if ($negated) {
                $line = substr($line, 1);
            }
            if ($line === '') {
                continue;
            }
            $this->gitignorePatterns[] = [
                'pattern' => $line,
                'negated' => $negated
            ];
        }
    }
    
    private function isExcluded($relativePath, $isDirectory) {
        $relativePath = str_replace('\\', '/', ltrim($relativePath, '/'));

        // Check config exclude
        foreach ($this->config['exclude'] as $pattern) {
            if ($this->matchesPathPattern($pattern, $relativePath, $isDirectory)) {
                return true;
            }
        }
        
        // Check gitignore patterns
        $ignored = false;
        foreach ($this->gitignorePatterns as $rule) {
            if ($this->matchesPathPattern($rule['pattern'], $relativePath, $isDirectory)) {
                $ignored = !$rule['negated'];
            }
        }
        
        return $ignored;
    }
    
    private function matchesPathPattern($pattern, $relativePath, $isDirectory) {
        $pattern = trim(str_replace('\\', '/', $pattern));

        if ($pattern === '' || $pattern === '/') {
            return false;
        }

        $directoryOnly = substr($pattern, -1) === '/';
        if ($directoryOnly) {
            $pattern = rtrim($pattern, '/');
            if (!$isDirectory) {
                return false;
            }
        }

        $rootAnchored = substr($pattern, 0, 1) === '/';
        if ($rootAnchored) {
            $pattern = ltrim($pattern, '/');
        }

        if ($pattern === '') {
            return false;
        }

        $hasPathSeparator = strpos($pattern, '/') !== false;
        $regex = $this->globPatternToRegex($pattern);

        if ($rootAnchored || $hasPathSeparator) {
            return (bool) preg_match($regex, $relativePath);
        }

        foreach (explode('/', $relativePath) as $segment) {
            if ((bool) preg_match($regex, $segment)) {
                return true;
            }
        }

        return false;
    }

    private function globPatternToRegex($pattern) {
        $quoted = preg_quote($pattern, '~');
        $quoted = str_replace('\*\*', '___DOUBLE_WILDCARD___', $quoted);
        $quoted = str_replace('\*', '[^/]*', $quoted);
        $quoted = str_replace('\?', '[^/]', $quoted);
        $quoted = str_replace('___DOUBLE_WILDCARD___', '.*', $quoted);

        return '~^' . $quoted . '$~';
    }

    private function getRelativePath($path) {
        $normalizedBase = rtrim(str_replace('\\', '/', $this->sourceBaseDir), '/');
        $normalizedPath = str_replace('\\', '/', $path);

        if (strpos($normalizedPath, $normalizedBase) === 0) {
            return ltrim(substr($normalizedPath, strlen($normalizedBase)), '/');
        }

        return ltrim($normalizedPath, '/');
    }

    private function getNextNonWhitespaceChar($code, $offset) {
        $len = strlen($code);
        for ($i = $offset; $i < $len; $i++) {
            if (!preg_match('/\s/', $code[$i])) {
                return $code[$i];
            }
        }

        return '';
    }

    private function needsTokenSeparator($leftChar, $rightChar, $allowHighAscii) {
        if ($leftChar === '' || $rightChar === '') {
            return false;
        }

        $identifierPattern = $allowHighAscii
            ? '/[a-zA-Z0-9_$\\\\\x7f-\xff]/'
            : '/[a-zA-Z0-9_$]/';

        if (preg_match($identifierPattern, $leftChar) && preg_match($identifierPattern, $rightChar)) {
            return true;
        }

        return (bool) (
            preg_match('/[+\-*\/%&|<>=!?:.]/', $leftChar) &&
            preg_match('/[+\-*\/%&|<>=!?:.]/', $rightChar)
        );
    }

    private function canStartJsRegex($result) {
        if ($result === '') {
            return true;
        }

        if (preg_match('/[=(:,;\[!&|?{}~^%+*\/-]$/', $result)) {
            return true;
        }

        $trimmed = strtolower(trim($result));
        if (preg_match('/(return|case|typeof|instanceof|in|delete|void|throw|new|yield|await)\s*$/', $trimmed)) {
            return true;
        }

        return false;
    }

    private function consumeTemplateLiteral($js, $startIndex) {
        $len = strlen($js);
        $i = $startIndex + 1;
        $content = '';
        $placeholders = [];

        while ($i < $len) {
            $char = $js[$i];
            $nextChar = ($i + 1 < $len) ? $js[$i + 1] : '';

            if ($char === '\\') {
                $content .= $char;
                if ($i + 1 < $len) {
                    $i++;
                    $content .= $js[$i];
                }
                $i++;
                continue;
            }

            if ($char === '$' && $nextChar === '{') {
                [$expression, $endIndex] = $this->extractTemplateExpression($js, $i + 2);
                $placeholder = '___TPL_EXPR_' . count($placeholders) . '___';
                $placeholders[$placeholder] = '${' . trim($this->minifyJS($expression)) . '}';
                $content .= $placeholder;
                $i = $endIndex + 1;
                continue;
            }

            if ($char === '`') {
                $content = $this->minifyTemplateLiteralContent($content, $placeholders);
                return ['`' . $content . '`', $i + 1];
            }

            $content .= $char;
            $i++;
        }

        return ['`' . strtr($content, $placeholders), $i];
    }

    private function extractTemplateExpression($source, $startIndex) {
        $len = strlen($source);
        $i = $startIndex;
        $depth = 1;
        $result = '';

        while ($i < $len) {
            $char = $source[$i];
            $nextChar = ($i + 1 < $len) ? $source[$i + 1] : '';

            if ($char === '"' || $char === "'") {
                [$stringLiteral, $nextIndex] = $this->consumeQuotedString($source, $i, $char);
                $result .= $stringLiteral;
                $i = $nextIndex;
                continue;
            }

            if ($char === '`') {
                [$templateLiteral, $nextIndex] = $this->consumeRawTemplateLiteral($source, $i);
                $result .= $templateLiteral;
                $i = $nextIndex;
                continue;
            }

            if ($char === '/' && $nextChar === '/') {
                while ($i < $len && $source[$i] !== "\n") {
                    $result .= $source[$i];
                    $i++;
                }
                continue;
            }

            if ($char === '/' && $nextChar === '*') {
                $result .= '/*';
                $i += 2;
                while ($i < $len) {
                    $result .= $source[$i];
                    if ($source[$i] === '*' && ($i + 1 < $len) && $source[$i + 1] === '/') {
                        $i += 2;
                        $result .= '/';
                        break;
                    }
                    $i++;
                }
                continue;
            }

            if ($char === '/' && $this->canStartJsRegex($result)) {
                [$regexLiteral, $nextIndex] = $this->consumeRegexLiteral($source, $i);
                $result .= $regexLiteral;
                $i = $nextIndex;
                continue;
            }

            if ($char === '{') {
                $depth++;
                $result .= $char;
                $i++;
                continue;
            }

            if ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return [$result, $i];
                }
                $result .= $char;
                $i++;
                continue;
            }

            $result .= $char;
            $i++;
        }

        return [$result, $i];
    }

    private function consumeQuotedString($source, $startIndex, $quote) {
        $len = strlen($source);
        $i = $startIndex + 1;
        $result = $quote;

        while ($i < $len) {
            $char = $source[$i];
            $result .= $char;

            if ($char === '\\' && $i + 1 < $len) {
                $i++;
                $result .= $source[$i];
                $i++;
                continue;
            }

            $i++;
            if ($char === $quote) {
                break;
            }
        }

        return [$result, $i];
    }

    private function consumeRawTemplateLiteral($source, $startIndex) {
        $len = strlen($source);
        $i = $startIndex + 1;
        $result = '`';

        while ($i < $len) {
            $char = $source[$i];
            $nextChar = ($i + 1 < $len) ? $source[$i + 1] : '';

            if ($char === '\\') {
                $result .= $char;
                if ($i + 1 < $len) {
                    $i++;
                    $result .= $source[$i];
                }
                $i++;
                continue;
            }

            if ($char === '$' && $nextChar === '{') {
                [$expression, $endIndex] = $this->extractTemplateExpression($source, $i + 2);
                $result .= '${' . $expression . '}';
                $i = $endIndex + 1;
                continue;
            }

            $result .= $char;
            $i++;

            if ($char === '`') {
                break;
            }
        }

        return [$result, $i];
    }

    private function consumeRegexLiteral($source, $startIndex) {
        $len = strlen($source);
        $i = $startIndex;
        $result = '/';
        $i++;
        $inCharClass = false;

        while ($i < $len) {
            $char = $source[$i];
            $result .= $char;

            if ($char === '\\' && $i + 1 < $len) {
                $i++;
                $result .= $source[$i];
                $i++;
                continue;
            }

            if ($char === '[') {
                $inCharClass = true;
            } elseif ($char === ']' && $inCharClass) {
                $inCharClass = false;
            } elseif ($char === '/' && !$inCharClass) {
                $i++;
                while ($i < $len && preg_match('/[a-z]/i', $source[$i])) {
                    $result .= $source[$i];
                    $i++;
                }
                return [$result, $i];
            }

            $i++;
        }

        return [$result, $i];
    }

    private function minifyTemplateLiteralContent($content, $placeholders) {
        $probe = strtr($content, array_fill_keys(array_keys($placeholders), ''));

        if (!preg_match('/<\s*\/?[a-z!][^>]*>/i', $probe)) {
            return strtr($content, $placeholders);
        }

        return strtr($this->minifyHTML($content), $placeholders);
    }

    private function consumePhpStringLiteral($source, $startIndex, $quote) {
        $len = strlen($source);
        $i = $startIndex + 1;
        $literal = $quote;

        while ($i < $len) {
            $char = $source[$i];
            $literal .= $char;

            if ($char === '\\' && $i + 1 < $len) {
                $i++;
                $literal .= $source[$i];
                $i++;
                continue;
            }

            $i++;
            if ($char === $quote) {
                break;
            }
        }

        return [$literal, $i];
    }

    private function minifyStructuredPhpStringLiteral($literal, $quote) {
        if (strlen($literal) < 2) {
            return $literal;
        }

        $content = substr($literal, 1, -1);
        if (!preg_match('/[\r\n]/', $content)) {
            return $literal;
        }

        if ($this->looksLikeSqlString($content)) {
            $content = $this->minifySqlStringContent($content);
        } elseif ($this->looksLikeHtmlString($content)) {
            $content = $this->minifyHTML($content);
        } else {
            return $literal;
        }

        return $quote . $content . $quote;
    }

    private function looksLikeSqlString($content) {
        $trimmed = trim($content);

        if ($trimmed === '') {
            return false;
        }

        return (bool) preg_match(
            '/\b(select|insert|update|delete|replace|create|alter|drop|truncate|where|from|join|order\s+by|group\s+by|limit|values|primary\s+key|foreign\s+key|engine=|default\s+charset)\b/i',
            $trimmed
        );
    }

    private function minifySqlStringContent($content) {
        $content = preg_replace('/\s+/', ' ', trim($content));
        $content = preg_replace('/\s*,\s*/', ',', $content);
        $content = preg_replace('/\(\s+/', '(', $content);
        $content = preg_replace('/\s+\)/', ')', $content);

        return $content;
    }

    private function looksLikeHtmlString($content) {
        return (bool) preg_match('/<\s*\/?[a-z!][^>]*>/i', $content);
    }
    
    // ==========================================
    // INDEX FILE CREATION
    // ==========================================
    
    private function createIndexFile($dirPath) {
        $indexFile = $dirPath . DIRECTORY_SEPARATOR . 'index.php';
        $content = $this->config['indexContent'];
        
        $result = @file_put_contents($indexFile, $content);
        if ($result === false) {
            error_log("Failed to create index.php in: " . $dirPath);
        }
        
        return $result;
    }
}
