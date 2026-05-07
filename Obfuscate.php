#!/usr/bin/env php
<?php

/**
 * EmpMonitor Code Obfuscator / Encryptor
 *
 * CLI tool to obfuscate/encrypt PHP, Blade, JS, and CSS files
 * for on-premise distribution.
 *
 * Usage:
 *   php obfuscate.php --input <file|folder> --output <file|folder> [options]
 *
 * Options:
 *   --input, -i       Input file or folder path (required)
 *   --output, -o      Output file or folder path (required)
 *   --key, -k         Encryption key for blade files (auto-generated if not provided)
 *   --skip             Comma-separated folder names to skip (default: vendor,node_modules,storage,.git)
 *   --blade-mode       Blade handling: "encrypt" or "minify" (default: encrypt)
 *   --php-mode         PHP handling: "encode" or "strip" (default: encode)
 *   --preserve         Comma-separated file extensions to copy as-is (e.g., .json,.xml,.env)
 *   --verbose, -v      Show detailed processing info
 *   --help, -h         Show this help message
 */

// ─── CLI Parsing ────────────────────────────────────────────────────────────────

$options = getopt('i:o:k:vh', [
    'input:', 'output:', 'key:', 'skip:', 'blade-mode:', 'php-mode:',
    'preserve:', 'verbose', 'help'
]);

if (isset($options['h']) || isset($options['help'])) {
    echo file_get_contents(__FILE__);
    // Print just the doc block
    printUsage();
    exit(0);
}

$input  = $options['i'] ?? $options['input'] ?? null;
$output = $options['o'] ?? $options['output'] ?? null;
$encryptionKey = $options['k'] ?? $options['key'] ?? null;
$skipDirs = explode(',', $options['skip'] ?? 'vendor,node_modules,storage,.git,.github,tools');
$bladeMode = $options['blade-mode'] ?? 'encrypt';
$phpMode = $options['php-mode'] ?? 'encode';
$preserveExts = array_filter(explode(',', $options['preserve'] ?? '.json,.xml,.env,.yml,.yaml,.lock,.md,.txt,.png,.jpg,.jpeg,.gif,.svg,.ico,.woff,.woff2,.ttf,.eot,.map'));
$verbose = isset($options['v']) || isset($options['verbose']);

if (!$input || !$output) {
    printUsage();
    exit(1);
}

$input  = realpath($input);
if (!$input) {
    error("Input path does not exist.");
    exit(1);
}

// Generate encryption key if not provided
if (!$encryptionKey) {
    $encryptionKey = bin2hex(random_bytes(16));
}

// ─── Stats ──────────────────────────────────────────────────────────────────────

$stats = [
    'php'   => 0,
    'blade' => 0,
    'js'    => 0,
    'css'   => 0,
    'copied' => 0,
    'skipped' => 0,
    'errors' => 0,
];

// ─── Main ───────────────────────────────────────────────────────────────────────

echo "\n";
echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║          EmpMonitor Code Obfuscator v1.0                ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

echo "  Input:      $input\n";
echo "  Output:     $output\n";
echo "  PHP Mode:   $phpMode\n";
echo "  Blade Mode: $bladeMode\n";
if ($bladeMode === 'encrypt') {
    echo "  Enc. Key:   $encryptionKey\n";
}
echo "\n";

if (is_file($input)) {
    // Single file mode
    $outputDir = dirname($output);
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }
    processFile($input, $output);
} elseif (is_dir($input)) {
    // Folder mode
    processDirectory($input, $output, $skipDirs);

    // Generate ServiceProvider if blade encryption is used
    if ($bladeMode === 'encrypt' && $stats['blade'] > 0) {
        generateServiceProvider($output, $encryptionKey);
    }
} else {
    error("Input is neither a file nor a directory.");
    exit(1);
}

// Print summary
echo "\n";
echo "┌──────────────────────────────────────────────────────────┐\n";
echo "│  Summary                                                 │\n";
echo "├──────────────────────────────────────────────────────────┤\n";
printf("│  PHP files obfuscated:    %-30d│\n", $stats['php']);
printf("│  Blade files processed:   %-30d│\n", $stats['blade']);
printf("│  JS files minified:       %-30d│\n", $stats['js']);
printf("│  CSS files minified:      %-30d│\n", $stats['css']);
printf("│  Files copied as-is:      %-30d│\n", $stats['copied']);
printf("│  Files skipped:           %-30d│\n", $stats['skipped']);
printf("│  Errors:                  %-30d│\n", $stats['errors']);
echo "└──────────────────────────────────────────────────────────┘\n\n";

if ($bladeMode === 'encrypt' && $stats['blade'] > 0) {
    echo "IMPORTANT: A BladeDecryptServiceProvider has been generated.\n";
    echo "Register it in config/app.php 'providers' array:\n";
    echo "  App\\Providers\\BladeDecryptServiceProvider::class\n\n";
    echo "Set this in your .env file:\n";
    echo "  BLADE_ENCRYPTION_KEY=$encryptionKey\n\n";
}

echo "Done!\n\n";
exit($stats['errors'] > 0 ? 1 : 0);

// ─── Directory Processing ───────────────────────────────────────────────────────

function processDirectory(string $inputDir, string $outputDir, array $skipDirs): void
{
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }

    $iterator = new DirectoryIterator($inputDir);

    foreach ($iterator as $item) {
        if ($item->isDot()) continue;

        $name = $item->getFilename();
        $inputPath  = $item->getPathname();
        $outputPath = $outputDir . DIRECTORY_SEPARATOR . $name;

        // Normalize path separators
        $inputPath  = str_replace('\\', '/', $inputPath);
        $outputPath = str_replace('\\', '/', $outputPath);

        if ($item->isDir()) {
            if (in_array($name, $skipDirs)) {
                logVerbose("SKIP DIR: $name");
                continue;
            }
            processDirectory($inputPath, $outputPath, $skipDirs);
        } else {
            processFile($inputPath, $outputPath);
        }
    }
}

// ─── File Processing (Router) ───────────────────────────────────────────────────

function processFile(string $inputPath, string $outputPath): void
{
    global $stats, $preserveExts, $verbose;

    $ext = getFullExtension($inputPath);

    // Check if this extension should be preserved (copied as-is)
    foreach ($preserveExts as $pExt) {
        $pExt = ltrim(trim($pExt), '.');
        if (pathinfo($inputPath, PATHINFO_EXTENSION) === $pExt || str_ends_with($inputPath, '.' . $pExt)) {
            copyFile($inputPath, $outputPath);
            $stats['copied']++;
            logVerbose("COPY: $inputPath");
            return;
        }
    }

    try {
        if ($ext === 'blade.php') {
            processBladeFile($inputPath, $outputPath);
            $stats['blade']++;
        } elseif ($ext === 'php') {
            processPhpFile($inputPath, $outputPath);
            $stats['php']++;
        } elseif ($ext === 'js') {
            processJsFile($inputPath, $outputPath);
            $stats['js']++;
        } elseif ($ext === 'css') {
            processCssFile($inputPath, $outputPath);
            $stats['css']++;
        } elseif ($ext === 'scss' || $ext === 'sass' || $ext === 'less') {
            processCssFile($inputPath, $outputPath);
            $stats['css']++;
        } else {
            copyFile($inputPath, $outputPath);
            $stats['copied']++;
            logVerbose("COPY: $inputPath");
        }
    } catch (Exception $e) {
        $stats['errors']++;
        error("Failed to process $inputPath: " . $e->getMessage());
    }
}

// ─── PHP Obfuscation ───────────────────────────────────────────────────────────

function processPhpFile(string $inputPath, string $outputPath): void
{
    global $phpMode;

    $code = file_get_contents($inputPath);
    if ($code === false) throw new Exception("Cannot read file");

    $outputDir = dirname($outputPath);
    if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);

    if ($phpMode === 'strip') {
        // Just strip whitespace and comments
        $stripped = php_strip_whitespace($inputPath);
        file_put_contents($outputPath, $stripped);
        logVerbose("STRIP PHP: $inputPath");
        return;
    }

    // Full encode mode: strip → compress → base64 → eval wrapper
    $stripped = php_strip_whitespace($inputPath);

    // Extract the PHP code without the opening tag
    $phpCode = $stripped;
    if (str_starts_with($phpCode, '<?php')) {
        $phpCode = substr($phpCode, 5);
    }
    $phpCode = trim($phpCode);

    if (empty($phpCode)) {
        // Empty PHP file, just copy
        file_put_contents($outputPath, $stripped);
        logVerbose("COPY (empty PHP): $inputPath");
        return;
    }

    // Multi-layer encoding: code → gzdeflate → base64 → variable obfuscation
    $compressed = gzdeflate($phpCode, 9);
    $encoded = base64_encode($compressed);

    // Split the encoded string into chunks for harder pattern matching
    $chunks = str_split($encoded, 64);
    $varName = generateVarName();
    $decoderVar = generateVarName();

    $obfuscated = "<?php\n";
    $obfuscated .= "/* Protected by EmpMonitor Obfuscator | " . date('Y-m-d') . " */\n";
    $obfuscated .= "\${$varName}='';\n";

    foreach ($chunks as $chunk) {
        $obfuscated .= "\${$varName}.='{$chunk}';\n";
    }

    $obfuscated .= "\${$decoderVar}=base64_decode(\${$varName});\n";
    $obfuscated .= "eval(gzinflate(\${$decoderVar}));\n";

    file_put_contents($outputPath, $obfuscated);
    logVerbose("ENCODE PHP: $inputPath");
}

// ─── Blade File Processing ──────────────────────────────────────────────────────

function processBladeFile(string $inputPath, string $outputPath): void
{
    global $bladeMode, $encryptionKey;

    $code = file_get_contents($inputPath);
    if ($code === false) throw new Exception("Cannot read file");

    $outputDir = dirname($outputPath);
    if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);

    if ($bladeMode === 'minify') {
        $minified = minifyBlade($code);
        file_put_contents($outputPath, $minified);
        logVerbose("MINIFY BLADE: $inputPath");
        return;
    }

    // Encrypt mode: encrypt content with AES-256-CBC
    $iv = random_bytes(16);
    $encrypted = openssl_encrypt($code, 'aes-256-cbc', $encryptionKey, 0, $iv);
    $payload = base64_encode($iv) . '|' . $encrypted;

    // Store as a special marker that the ServiceProvider will decode
    $wrapped = '{{-- ENCRYPTED:BLADE --}}' . "\n" . $payload;

    file_put_contents($outputPath, $wrapped);
    logVerbose("ENCRYPT BLADE: $inputPath");
}

function minifyBlade(string $code): string
{
    // Remove Blade comments {{-- ... --}}
    $code = preg_replace('/\{\{--.*?--\}\}/s', '', $code);

    // Remove HTML comments (but keep IE conditionals)
    $code = preg_replace('/<!--(?!\[if).*?-->/s', '', $code);

    // Collapse multiple whitespace/newlines into single space (outside of <pre>, <script>, <style>)
    // Simple approach: collapse whitespace between tags
    $code = preg_replace('/>\s+</', '> <', $code);

    // Collapse multiple blank lines into one
    $code = preg_replace("/\n{3,}/", "\n\n", $code);

    // Trim lines
    $lines = explode("\n", $code);
    $lines = array_map('rtrim', $lines);
    $code = implode("\n", $lines);

    return trim($code) . "\n";
}

// ─── JS Obfuscation ────────────────────────────────────────────────────────────

function processJsFile(string $inputPath, string $outputPath): void
{
    $code = file_get_contents($inputPath);
    if ($code === false) throw new Exception("Cannot read file");

    $outputDir = dirname($outputPath);
    if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);

    $obfuscated = obfuscateJs($code);
    file_put_contents($outputPath, $obfuscated);
    logVerbose("OBFUSCATE JS: $inputPath");
}

function obfuscateJs(string $code): string
{
    // Step 1: Remove single-line comments (but not URLs like http://)
    $code = preg_replace('#(?<!:)//[^\n]*#', '', $code);

    // Step 2: Remove multi-line comments
    $code = preg_replace('#/\*.*?\*/#s', '', $code);

    // Step 3: Collapse whitespace
    $code = preg_replace('/[ \t]+/', ' ', $code);

    // Step 4: Remove empty lines
    $code = preg_replace("/\n\s*\n/", "\n", $code);

    // Step 5: Trim lines
    $lines = explode("\n", $code);
    $lines = array_map('trim', $lines);
    $lines = array_filter($lines, fn($line) => $line !== '');

    // Step 6: Encode the minified JS using base64 + eval
    $minified = implode("\n", $lines);
    $encoded = base64_encode($minified);

    $wrapper = "/* Protected by EmpMonitor Obfuscator | " . date('Y-m-d') . " */\n";
    $wrapper .= "(function(){var _0x=atob('{$encoded}');eval(_0x);})();\n";

    return $wrapper;
}

// ─── CSS Minification ───────────────────────────────────────────────────────────

function processCssFile(string $inputPath, string $outputPath): void
{
    $code = file_get_contents($inputPath);
    if ($code === false) throw new Exception("Cannot read file");

    $outputDir = dirname($outputPath);
    if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);

    $minified = minifyCss($code);
    file_put_contents($outputPath, $minified);
    logVerbose("MINIFY CSS: $inputPath");
}

function minifyCss(string $code): string
{
    // Remove comments
    $code = preg_replace('#/\*.*?\*/#s', '', $code);

    // Remove whitespace around selectors and properties
    $code = preg_replace('/\s*([{}:;,>~+])\s*/', '$1', $code);

    // Remove remaining multiple spaces
    $code = preg_replace('/\s+/', ' ', $code);

    // Remove trailing semicolons before closing braces
    $code = str_replace(';}', '}', $code);

    // Remove leading/trailing whitespace
    $code = trim($code);

    return "/* Protected by EmpMonitor Obfuscator */\n" . $code . "\n";
}

// ─── Service Provider Generator ─────────────────────────────────────────────────

function generateServiceProvider(string $outputDir, string $key): void
{
    $providerPath = $outputDir . '/app/Providers/BladeDecryptServiceProvider.php';
    $providerDir = dirname($providerPath);

    if (!is_dir($providerDir)) {
        mkdir($providerDir, 0755, true);
    }

    $providerCode = <<<'PROVIDER'
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\View\FileViewFinder;
use Illuminate\View\Engines\CompilerEngine;

class BladeDecryptServiceProvider extends ServiceProvider
{
    const ENCRYPTED_MARKER = '{{-- ENCRYPTED:BLADE --}}';

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $key = env('BLADE_ENCRYPTION_KEY');

        if (!$key) {
            throw new \RuntimeException(
                'BLADE_ENCRYPTION_KEY is not set in .env. This is required for encrypted blade templates.'
            );
        }

        // Override the blade compiler to decrypt files before compiling
        $this->app->extend('blade.compiler', function ($compiler, $app) use ($key) {
            $customCompiler = new \App\Providers\DecryptingBladeCompiler(
                $app['files'],
                $app['config']['view.compiled'],
                $key
            );

            // Copy custom directives and components from the original compiler
            return $customCompiler;
        });
    }
}

class DecryptingBladeCompiler extends \Illuminate\View\Compilers\BladeCompiler
{
    protected string $encryptionKey;

    public function __construct($files, $cachePath, string $encryptionKey)
    {
        parent::__construct($files, $cachePath);
        $this->encryptionKey = $encryptionKey;
    }

    /**
     * Compile the view at the given path — decrypt first if encrypted.
     */
    public function compile($path = null): void
    {
        if ($path) {
            $this->setPath($path);
        }

        $contents = $this->files->get($this->getPath());

        // Check if the file is encrypted
        if (str_starts_with(trim($contents), '{{-- ENCRYPTED:BLADE --}}')) {
            $contents = $this->decryptBlade($contents);
        }

        // Compile the decrypted blade content
        $compiled = $this->compileString($contents);

        $this->ensureCompiledDirectoryExists(
            $compiledPath = $this->getCompiledPath($this->getPath())
        );

        $this->files->put($compiledPath, $compiled);
    }

    protected function decryptBlade(string $contents): string
    {
        // Remove the marker line
        $payload = trim(str_replace('{{-- ENCRYPTED:BLADE --}}', '', $contents));

        // Split IV and encrypted data
        $parts = explode('|', $payload, 2);
        if (count($parts) !== 2) {
            throw new \RuntimeException('Invalid encrypted blade file format at: ' . $this->getPath());
        }

        [$ivBase64, $encrypted] = $parts;
        $iv = base64_decode($ivBase64);

        $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $this->encryptionKey, 0, $iv);

        if ($decrypted === false) {
            throw new \RuntimeException('Failed to decrypt blade file: ' . $this->getPath() . '. Check your BLADE_ENCRYPTION_KEY.');
        }

        return $decrypted;
    }
}
PROVIDER;

    file_put_contents($providerPath, $providerCode);
    info("Generated: BladeDecryptServiceProvider.php");
}

// ─── Helpers ────────────────────────────────────────────────────────────────────

function getFullExtension(string $path): string
{
    $basename = basename($path);

    if (str_ends_with($basename, '.blade.php')) {
        return 'blade.php';
    }

    return strtolower(pathinfo($basename, PATHINFO_EXTENSION));
}

function generateVarName(): string
{
    $chars = 'abcdefghijklmnopqrstuvwxyz';
    $name = '_';
    for ($i = 0; $i < 8; $i++) {
        $name .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $name;
}

function copyFile(string $from, string $to): void
{
    $dir = dirname($to);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    copy($from, $to);
}

function logVerbose(string $message): void
{
    global $verbose;
    if ($verbose) {
        echo "  [*] $message\n";
    }
}

function info(string $message): void
{
    echo "  [+] $message\n";
}

function error(string $message): void
{
    echo "  [!] ERROR: $message\n";
}

function printUsage(): void
{
    echo <<<USAGE

Usage:
  php obfuscate.php --input <file|folder> --output <file|folder> [options]

Options:
  --input, -i       Input file or folder path (required)
  --output, -o      Output file or folder path (required)
  --key, -k         Encryption key for blade files (auto-generated if not set)
  --skip            Comma-separated folder names to skip
                    (default: vendor,node_modules,storage,.git)
  --blade-mode      "encrypt" or "minify" (default: encrypt)
  --php-mode        "encode" or "strip" (default: encode)
  --preserve        Comma-separated extensions to copy as-is
  --verbose, -v     Show detailed processing info
  --help, -h        Show this help

Examples:
  # Obfuscate entire project
  php obfuscate.php -i ./app -o ./dist/app -v

  # Obfuscate with custom key
  php obfuscate.php -i ./app -o ./dist/app -k my_secret_key_here

  # Single file
  php obfuscate.php -i ./app/Http/Controllers/UserController.php -o ./dist/UserController.php

  # Minify blade only (no encryption, no ServiceProvider needed)
  php obfuscate.php -i ./resources/views -o ./dist/views --blade-mode minify

  # Strip PHP only (no base64 encoding)
  php obfuscate.php -i ./app -o ./dist/app --php-mode strip

  # Full project build for on-premise shipping
  php obfuscate.php -i . -o ../release-build -v


USAGE;
}
