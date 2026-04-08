<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db.php';

// Get request method and endpoint
$method = $_SERVER['REQUEST_METHOD'];
$request = $_SERVER['REQUEST_URI'];
$path = parse_url($request, PHP_URL_PATH);
$path_parts = explode('/', trim($path, '/'));

// Get the endpoint from URL
$endpoint = end($path_parts);

// Get JSON input for POST requests
$input = json_decode(file_get_contents('php://input'), true);

try {
    switch ($endpoint) {
        case 'save-match':
            if ($method === 'POST') {
                saveMatch($conn, $input);
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;
            
        case 'h2h-check':
            if ($method === 'GET') {
                $team1 = $_GET['team1'] ?? '';
                $team2 = $_GET['team2'] ?? '';
                $limit = $_GET['limit'] ?? 5;
                checkH2H($conn, $team1, $team2, $limit);
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;
            
        case 'team-average':
            if ($method === 'GET') {
                $team = $_GET['team'] ?? '';
                $limit = $_GET['limit'] ?? 5;
                getTeamAverage($conn, $team, $limit);
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;
            
        case 'validate-under15':
            if ($method === 'GET') {
                $team1 = $_GET['team1'] ?? '';
                $team2 = $_GET['team2'] ?? '';
                validateUnder15($conn, $team1, $team2);
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;
            
        case 'create-tables':
            if ($method === 'POST') {
                createTables($conn);
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;
            
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
}

// Function to create database tables
function createTables($conn) {
    try {
        // Create matches table
        $sql = "CREATE TABLE IF NOT EXISTS matches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            team1 VARCHAR(255) NOT NULL,
            team2 VARCHAR(255) NOT NULL,
            team1_score INT NOT NULL,
            team2_score INT NOT NULL,
            total_goals INT NOT NULL,
            league VARCHAR(255),
            match_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            status VARCHAR(50) DEFAULT 'completed',
            INDEX idx_teams (team1, team2),
            INDEX idx_team1 (team1),
            INDEX idx_team2 (team2),
            INDEX idx_date (match_date)
        )";
        
        $conn->exec($sql);
        
        echo json_encode([
            'success' => true,
            'message' => 'Tables created successfully'
        ]);
    } catch (PDOException $e) {
        throw new Exception('Failed to create tables: ' . $e->getMessage());
    }
}

// Function to save match data
function saveMatch($conn, $data) {
    if (!$data || !isset($data['team1']) || !isset($data['team2']) || 
        !isset($data['team1_score']) || !isset($data['team2_score'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields']);
        return;
    }
    
    try {
        $sql = "INSERT INTO matches (team1, team2, team1_score, team2_score, total_goals, league) 
                VALUES (:team1, :team2, :team1_score, :team2_score, :total_goals, :league)";
        
        $stmt = $conn->prepare($sql);
        $total_goals = $data['team1_score'] + $data['team2_score'];
        
        $stmt->execute([
            ':team1' => $data['team1'],
            ':team2' => $data['team2'],
            ':team1_score' => $data['team1_score'],
            ':team2_score' => $data['team2_score'],
            ':total_goals' => $total_goals,
            ':league' => $data['league'] ?? 'Unknown'
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Match saved successfully',
            'match_id' => $conn->lastInsertId()
        ]);
    } catch (PDOException $e) {
        throw new Exception('Failed to save match: ' . $e->getMessage());
    }
}

// Function to check H2H history
function checkH2H($conn, $team1, $team2, $limit = 5) {
    if (empty($team1) || empty($team2)) {
        http_response_code(400);
        echo json_encode(['error' => 'Team names are required']);
        return;
    }
    
    try {
        $sql = "SELECT * FROM matches 
                WHERE (team1 = :team1 AND team2 = :team2) 
                   OR (team1 = :team2 AND team2 = :team1)
                ORDER BY match_date DESC 
                LIMIT :limit";
        
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':team1', $team1);
        $stmt->bindParam(':team2', $team2);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate statistics
        $total_matches = count($matches);
        $under15_count = 0;
        $total_goals_sum = 0;
        
        foreach ($matches as $match) {
            if ($match['total_goals'] <= 1) {
                $under15_count++;
            }
            $total_goals_sum += $match['total_goals'];
        }
        
        $under15_percentage = $total_matches > 0 ? ($under15_count / $total_matches) * 100 : 0;
        $average_goals = $total_matches > 0 ? $total_goals_sum / $total_matches : 0;
        $is_under15_trend = $under15_percentage >= 60; // 60% or more under 1.5
        
        echo json_encode([
            'success' => true,
            'team1' => $team1,
            'team2' => $team2,
            'total_matches' => $total_matches,
            'under15_count' => $under15_count,
            'under15_percentage' => round($under15_percentage, 2),
            'average_goals' => round($average_goals, 2),
            'is_under15_trend' => $is_under15_trend,
            'matches' => $matches
        ]);
    } catch (PDOException $e) {
        throw new Exception('Failed to check H2H: ' . $e->getMessage());
    }
}

// Function to get team average goals
function getTeamAverage($conn, $team, $limit = 5) {
    if (empty($team)) {
        http_response_code(400);
        echo json_encode(['error' => 'Team name is required']);
        return;
    }
    
    try {
        $sql = "SELECT * FROM matches 
                WHERE team1 = :team OR team2 = :team
                ORDER BY match_date DESC 
                LIMIT :limit";
        
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':team', $team);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate statistics
        $total_matches = count($matches);
        $under15_count = 0;
        $total_goals_sum = 0;
        
        foreach ($matches as $match) {
            if ($match['total_goals'] <= 1) {
                $under15_count++;
            }
            $total_goals_sum += $match['total_goals'];
        }
        
        $under15_percentage = $total_matches > 0 ? ($under15_count / $total_matches) * 100 : 0;
        $average_goals = $total_matches > 0 ? $total_goals_sum / $total_matches : 0;
        $is_under15_trend = $under15_percentage >= 60; // 60% or more under 1.5
        
        echo json_encode([
            'success' => true,
            'team' => $team,
            'total_matches' => $total_matches,
            'under15_count' => $under15_count,
            'under15_percentage' => round($under15_percentage, 2),
            'average_goals' => round($average_goals, 2),
            'is_under15_trend' => $is_under15_trend,
            'matches' => $matches
        ]);
    } catch (PDOException $e) {
        throw new Exception('Failed to get team average: ' . $e->getMessage());
    }
}

// Function to validate under 1.5 condition
function validateUnder15($conn, $team1, $team2) {
    if (empty($team1) || empty($team2)) {
        http_response_code(400);
        echo json_encode(['error' => 'Both team names are required']);
        return;
    }
    
    try {
        // Check H2H history
        $h2h_sql = "SELECT * FROM matches 
                    WHERE (team1 = :team1 AND team2 = :team2) 
                       OR (team1 = :team2 AND team2 = :team1)
                    ORDER BY match_date DESC 
                    LIMIT 5";
        
        $h2h_stmt = $conn->prepare($h2h_sql);
        $h2h_stmt->bindParam(':team1', $team1);
        $h2h_stmt->bindParam(':team2', $team2);
        $h2h_stmt->execute();
        $h2h_matches = $h2h_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Check team1 recent matches
        $team1_sql = "SELECT * FROM matches 
                      WHERE team1 = :team OR team2 = :team
                      ORDER BY match_date DESC 
                      LIMIT 5";
        
        $team1_stmt = $conn->prepare($team1_sql);
        $team1_stmt->bindParam(':team', $team1);
        $team1_stmt->execute();
        $team1_matches = $team1_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Check team2 recent matches
        $team2_stmt = $conn->prepare($team1_sql);
        $team2_stmt->bindParam(':team', $team2);
        $team2_stmt->execute();
        $team2_matches = $team2_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate H2H statistics
        $h2h_under15 = 0;
        $h2h_total = count($h2h_matches);
        foreach ($h2h_matches as $match) {
            if ($match['total_goals'] <= 1) $h2h_under15++;
        }
        $h2h_under15_percentage = $h2h_total > 0 ? ($h2h_under15 / $h2h_total) * 100 : 0;
        
        // Calculate team1 statistics
        $team1_under15 = 0;
        $team1_total = count($team1_matches);
        foreach ($team1_matches as $match) {
            if ($match['total_goals'] <= 1) $team1_under15++;
        }
        $team1_under15_percentage = $team1_total > 0 ? ($team1_under15 / $team1_total) * 100 : 0;
        
        // Calculate team2 statistics
        $team2_under15 = 0;
        $team2_total = count($team2_matches);
        foreach ($team2_matches as $match) {
            if ($match['total_goals'] <= 1) $team2_under15++;
        }
        $team2_under15_percentage = $team2_total > 0 ? ($team2_under15 / $team2_total) * 100 : 0;
        
        // Validation logic
        $h2h_valid = $h2h_under15_percentage >= 60; // H2H has 60%+ under 1.5
        $team1_valid = $team1_under15_percentage >= 60; // Team1 has 60%+ under 1.5
        $team2_valid = $team2_under15_percentage >= 60; // Team2 has 60%+ under 1.5
        
        // Overall validation: H2H OR both teams have under 1.5 trend
        $is_valid = $h2h_valid || ($team1_valid && $team2_valid);
        
        echo json_encode([
            'success' => true,
            'team1' => $team1,
            'team2' => $team2,
            'is_valid_for_under15' => $is_valid,
            'validation_details' => [
                'h2h' => [
                    'total_matches' => $h2h_total,
                    'under15_count' => $h2h_under15,
                    'under15_percentage' => round($h2h_under15_percentage, 2),
                    'is_valid' => $h2h_valid
                ],
                'team1' => [
                    'total_matches' => $team1_total,
                    'under15_count' => $team1_under15,
                    'under15_percentage' => round($team1_under15_percentage, 2),
                    'is_valid' => $team1_valid
                ],
                'team2' => [
                    'total_matches' => $team2_total,
                    'under15_count' => $team2_under15,
                    'under15_percentage' => round($team2_under15_percentage, 2),
                    'is_valid' => $team2_valid
                ]
            ],
            'recommendation' => $is_valid ? 'Pertandingan ini cocok untuk bet Under 1.5 Goals' : 'Pertandingan ini tidak direkomendasikan untuk bet Under 1.5 Goals'
        ]);
    } catch (PDOException $e) {
        throw new Exception('Failed to validate under 1.5: ' . $e->getMessage());
    }
}
?>