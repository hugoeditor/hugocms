<?php

define('FILTER_PROPRIETARY_WARNINGS', true); // Schalter für den Filter von proprietären Attributwarnungen

class HTMLValidator {
    private $html;
    private $errors = [];
    private $warnings = [];
    private $config = [
        'clean' => true,
        'output-html' => true,
        'show-warnings' => true,
        'wrap' => 0,
        'doctype' => 'html5',
        'char-encoding' => 'utf8'
    ];

    public function __construct($html) {
        $this->html = $html;
    }

    public function validate() {
        $this->errors = [];
        $this->warnings = [];

        // Check if tidy extension is loaded
        if (!extension_loaded('tidy')) {
            $this->errors[] = "Tidy extension is not installed or enabled.";
            return false;
        }

        // Use tidy to clean and validate HTML
        $tidy = tidy_parse_string($this->html, $this->config, 'utf8');
        $tidy->cleanRepair();

        // Collect errors and warnings
        $this->collectErrorsAndWarnings($tidy);

        return empty($this->errors);
    }

    private function collectErrorsAndWarnings($tidy) {
        $errorBuffer = tidy_get_error_buffer($tidy);

        if ($errorBuffer) {
            $lines = explode("\n", $errorBuffer);
            foreach ($lines as $line) {
                if (stripos($line, 'Warning') !== false) {
                    // Filter out proprietary attribute warnings if the switch is on
                    if (FILTER_PROPRIETARY_WARNINGS && stripos($line, 'proprietary attribute') !== false) {
                        continue;
                    }
                    $this->warnings[] = $line;
                } else {
                    $this->errors[] = $line;
                }
            }
        }
    }

    public function getErrors() {
        return $this->errors;
    }

    public function getWarnings() {
        return $this->warnings;
    }
}

function validateFile($filePath, $relativeDir) {
    $htmlContent = file_get_contents($filePath);
    if ($htmlContent === false) {
        return [
            'status' => 'error',
            'message' => 'Failed to fetch HTML content.',
            'dir' => $relativeDir
        ];
    }

    $validator = new HTMLValidator($htmlContent);
    if ($validator->validate()) {
        return [
            'status' => 'success',
            'message' => 'HTML is valid.',
            'warnings' => $validator->getWarnings(),
            'dir' => $relativeDir
        ];
    } else {
        return [
            'status' => 'error',
            'message' => 'HTML is not valid.',
            'errors' => $validator->getErrors(),
            'warnings' => $validator->getWarnings(),
            'dir' => $relativeDir
        ];
    }
}

function scanDirectory($baseDir) {
    $results = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir));
    foreach ($iterator as $file) {
        if ($file->getFilename() == 'index.html') {
            $relativeDir = str_replace($baseDir . '/', '', $file->getPath());
            $results[] = validateFile($file->getPathname(), $relativeDir);
        }
    }
    return $results;
}

// CLI usage
if (php_sapi_name() === 'cli') {
    if ($argc < 2) {
        echo "Usage: php HTMLValidator.php <base-dir> [sub-dir]\n";
        exit(1);
    }

    $baseDir = $argv[1];
    $results = [];
    $baseDir = $baseDir."public/";

    if (isset($argv[2])) {
        $subDir = $argv[2];
        $dirPath = "$baseDir/$subDir";
        $filePath = "$dirPath/index.html";
        if (file_exists($filePath)) {
            $results[] = validateFile($filePath, $subDir);
        } else {
            echo "File not found: $filePath\n";
            exit(1);
        }
    } else {
        $dirPath = $baseDir;
        if (is_dir($dirPath)) {
            $results = scanDirectory($dirPath);
        } else {
            echo "Directory not found: $dirPath\n";
            exit(1);
        }
    }

    foreach ($results as $result) {
        echo "Verzeichnis: " . $result['dir'] . PHP_EOL;
        if ($result['status'] == 'success') {
            echo "Status: Valid HTML" . PHP_EOL;
            if (!empty($result['warnings'])) {
                echo "Warnings:" . PHP_EOL;
                foreach ($result['warnings'] as $warning) {
                    echo "- $warning" . PHP_EOL;
                }
            }
        } else {
            echo "Status: Invalid HTML" . PHP_EOL;
            echo "Message: " . $result['message'] . PHP_EOL;
            if (!empty($result['errors'])) {
                echo "Errors:" . PHP_EOL;
                foreach ($result['errors'] as $error) {
                    echo "- $error" . PHP_EOL;
                }
            }
            if (!empty($result['warnings'])) {
                echo "Warnings:" . PHP_EOL;
                foreach ($result['warnings'] as $warning) {
                    echo "- $warning" . PHP_EOL;
                }
            }
        }
    }
}
