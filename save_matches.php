<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

// Buffer output to prevent accidental HTML
ob_start();

function cors_allowed_origins() {
    $raw = getenv('CORS_ALLOWED_ORIGINS') ?: '';
    if ($raw === '') {
        return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', $raw))));
}

function apply_cors_headers() {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowedOrigins = cors_allowed_origins();
    if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
}

function require_api_key() {
    $apiToken = getenv('API_TOKEN') ?: '';
    if ($apiToken === '') {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Server API token is not configured.'
        ]);
        exit;
    }

    $providedToken = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($providedToken === '' || !hash_equals($apiToken, $providedToken)) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized'
        ]);
        exit;
    }
}

apply_cors_headers();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed'
    ]);
    exit;
}

require_api_key();

require_once 'koneksi.php';

try {
    if (!isset($conn) || !$conn || !empty($db_error)) {
        throw new Exception('Database connection failed');
    }

    // Get POST data
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    if (!isset($data['matches']) || !is_array($data['matches'])) {
        throw new Exception('Invalid data format');
    }
    
    $matches = $data['matches'];
    $successCount = 0;
    $errors = [];
    
    // Prepare statement
    $stmt = $conn->prepare("INSERT INTO matches (match_time, home_team, away_team, league, fh_home, fh_away, ft_home, ft_away) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    foreach ($matches as $index => $match) {
        // Validate required fields
        if (!isset($match['match_time']) || !isset($match['home_team']) || !isset($match['away_team'])) {
            $errors[] = "Match " . ($index + 1) . ": Missing required fields";
            continue;
        }
        
        // Convert datetime format if needed
        $datetime = $match['match_time'];
        
        // Parse and reformat datetime to MySQL format
        $dateObj = DateTime::createFromFormat('Y-m-d h:i A', $datetime);
        if ($dateObj) {
            $mysqlDatetime = $dateObj->format('Y-m-d H:i:s');
        } else {
            // Try other formats
            $dateObj = DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
            if ($dateObj) {
                $mysqlDatetime = $datetime;
            } else {
                $errors[] = "Match " . ($index + 1) . ": Invalid datetime format";
                continue;
            }
        }
        
        // Bind parameters
        $stmt->bind_param('ssssiiii', 
            $mysqlDatetime,
            $match['home_team'],
            $match['away_team'],
            $match['league'] ?? 'SABA CLUB FRIENDLY',
            $match['fh_home'] ?? 0,
            $match['fh_away'] ?? 0,
            $match['ft_home'] ?? 0,
            $match['ft_away'] ?? 0
        );
        
        // Execute
        if ($stmt->execute()) {
            $successCount++;
        } else {
            $errors[] = "Match " . ($index + 1) . ": " . $stmt->error;
        }
    }
    
    // All-or-nothing agar data batch konsisten.
    if (count($errors) > 0) {
        $conn->rollback();
    } else {
        $conn->commit();
    }
    
    $stmt->close();
    $conn->close();
    
    // Return response
    ob_clean(); // Clear any buffered output
    echo json_encode([
        'success' => count($errors) === 0,
        'partial' => count($errors) > 0,
        'count' => $successCount,
        'errors' => $errors
    ]);
    
} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
        $conn->close();
    }
    
    ob_clean(); // Clear any buffered output
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
