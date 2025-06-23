<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Parse PostgreSQL connection info from Neon URL
$dbUrl = 'postgresql://neondb_owner:npg_0kXx8aimHZfn@ep-super-haze-a92tp83o-pooler.gwc.azure.neon.tech/AttendanceSystem?sslmode=require';
$dbConfig = parse_url($dbUrl);

$dbHost = $dbConfig['host'] ?? '';
$dbPort = $dbConfig['port'] ?? 5432;
$dbUser = $dbConfig['user'] ?? '';
$dbPass = $dbConfig['pass'] ?? '';
$dbName = ltrim($dbConfig['path'] ?? '', '/');

try {
    // Connect to PostgreSQL via PDO
    $dsn = "pgsql:host=$dbHost;port=$dbPort;dbname=$dbName;sslmode=require";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Database connection failed: " . $e->getMessage()
    ]);
    exit;
}

try {
    // Query attendance records sorted by date descending, then time descending
    $sql = "SELECT student_name, student_id, date, time FROM attendance ORDER BY date DESC, time DESC";
    $stmt = $pdo->query($sql);

    $attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($attendance_records)) {
        echo json_encode([
            "status" => "success",
            "message" => "No attendance records found",
            "data" => [],
            "count" => 0
        ]);
        exit;
    } else {
        // Convert student_id to int for consistency
        foreach ($attendance_records as &$record) {
            $record['student_id'] = (int)$record['student_id'];
        }
        echo json_encode([
            "status" => "success",
            "message" => "Attendance records retrieved successfully",
            "data" => $attendance_records,
            "count" => count($attendance_records)
        ]);
        exit;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Query failed: " . $e->getMessage()
    ]);
    exit;
}
