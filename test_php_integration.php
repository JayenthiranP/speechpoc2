<?php
/**
 * PHP Integration Example for Speech Assessment
 * 
 * This script demonstrates how to call the Python speech assessment
 * from PHP and parse the JSON results.
 */

// ============================================================================
// CONFIGURATION
// ============================================================================

// Path to Python executable
$pythonPath = "python";  // or full path like "C:\\Python39\\python.exe"

// Path to the speech assessment script
$scriptPath = __DIR__ . "\\speech_assessment.py";

// Parameters for speech assessment
$referenceDir = __DIR__ . "\\reference";
$testAudioPath = __DIR__ . "\\test\\input1.wav";
$testWord = "shockingly";
$outputDir = __DIR__ . "\\output";


// ============================================================================
// EXECUTE PYTHON SCRIPT
// ============================================================================

echo "=======================================================================\n";
echo "Speech Pronunciation Assessment - PHP Integration\n";
echo "=======================================================================\n\n";

// Build the command
$command = sprintf(
    '%s "%s" "%s" "%s" "%s" "%s" 2>&1',
    $pythonPath,
    $scriptPath,
    $referenceDir,
    $testAudioPath,
    $testWord,
    $outputDir
);

echo "Executing command:\n";
echo "$command\n\n";
echo "-----------------------------------------------------------------------\n";

// Execute and capture output
$output = shell_exec($command);

if ($output === null) {
    die("ERROR: Failed to execute Python script!\n");
}

// Display full output
echo $output;
echo "\n-----------------------------------------------------------------------\n\n";


// ============================================================================
// PARSE JSON RESULT
// ============================================================================

// Extract JSON result between markers
if (preg_match('/JSON_RESULT_START\s*(.*?)\s*JSON_RESULT_END/s', $output, $matches)) {
    $jsonString = $matches[1];
    $result = json_decode($jsonString, true);
    
    if ($result === null) {
        echo "ERROR: Failed to parse JSON result!\n";
        echo "JSON String: $jsonString\n";
        exit(1);
    }
    
    // ========================================================================
    // PROCESS RESULTS
    // ========================================================================
    
    if ($result['status'] === 'success') {
        echo "=======================================================================\n";
        echo "ANALYSIS SUCCESSFUL!\n";
        echo "=======================================================================\n\n";
        
        echo "Word: " . $result['word'] . "\n";
        echo "Analysis Time: " . $result['timestamp'] . "\n\n";
        
        // Summary statistics
        echo "-----------------------------------------------------------------------\n";
        echo "SUMMARY:\n";
        echo "-----------------------------------------------------------------------\n";
        echo "Total Phonemes: " . $result['summary']['total_phonemes'] . "\n";
        echo "OK: " . $result['summary']['ok_count'] . " (" . round($result['summary']['ok_count'] / $result['summary']['total_phonemes'] * 100, 1) . "%)\n";
        echo "Weak: " . $result['summary']['weak_count'] . " (" . round($result['summary']['weak_count'] / $result['summary']['total_phonemes'] * 100, 1) . "%)\n";
        echo "Errors: " . $result['summary']['error_count'] . " (" . round($result['summary']['error_count'] / $result['summary']['total_phonemes'] * 100, 1) . "%)\n";
        echo "Overall Score: " . $result['summary']['overall_score'] . "%\n\n";
        
        // Phoneme details
        echo "-----------------------------------------------------------------------\n";
        echo "PHONEME DETAILS:\n";
        echo "-----------------------------------------------------------------------\n";
        printf("%-15s %-15s %-20s\n", "Phoneme", "Score", "Status");
        echo "-----------------------------------------------------------------------\n";
        
        foreach ($result['phoneme_scores'] as $phoneme => $score) {
            $status = $result['phoneme_errors'][$phoneme];
            $emoji = $status === 'OK' ? '✓' : ($status === 'Weak' ? '⚠' : '✗');
            printf("%-15s %-15.1f%% %s %-20s\n", 
                   $phoneme, 
                   $score * 100, 
                   $emoji, 
                   $status);
        }
        
        // Output files
        echo "\n-----------------------------------------------------------------------\n";
        echo "OUTPUT FILES:\n";
        echo "-----------------------------------------------------------------------\n";
        echo "Heatmap: " . $result['files']['heatmap'] . "\n";
        echo "Report: " . $result['files']['report'] . "\n";
        echo "JSON: " . $result['files']['json'] . "\n\n";
        
        // ====================================================================
        // EXAMPLE: Save to database or use in web application
        // ====================================================================
        
        /*
        // Example database insertion (uncomment and modify as needed)
        $pdo = new PDO('mysql:host=localhost;dbname=speech_assessment', 'username', 'password');
        
        $stmt = $pdo->prepare("
            INSERT INTO assessments (word, overall_score, phoneme_data, heatmap_path, created_at) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $result['word'],
            $result['summary']['overall_score'],
            json_encode($result['phoneme_scores']),
            $result['files']['heatmap'],
            $result['timestamp']
        ]);
        
        echo "Result saved to database!\n";
        */
        
        // Example: Return JSON for AJAX request
        /*
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'data' => $result,
            'heatmap_url' => '/output/' . basename($result['files']['heatmap'])
        ]);
        */
        
    } else {
        // Error occurred
        echo "=======================================================================\n";
        echo "ANALYSIS FAILED!\n";
        echo "=======================================================================\n\n";
        echo "Error: " . $result['message'] . "\n";
        echo "Time: " . $result['timestamp'] . "\n\n";
        exit(1);
    }
    
} else {
    echo "ERROR: Could not find JSON result in output!\n";
    echo "This might indicate a Python error occurred.\n";
    exit(1);
}

echo "=======================================================================\n";
echo "PHP Integration Test Complete!\n";
echo "=======================================================================\n";

?>
