<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Get raw JSON input
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true); // Convert to associative array

// Retrieve date from JSON or fallback to POST/GET
$date = $input['date'] ?? $_POST['date'] ?? $_GET['date'] ?? '';

if (empty($date)) {
    echo json_encode([
        "status" => "error",
        "message" => "Missing date parameter"
    ]);
    exit;
}

// Validate date format (YYYY-MM-DD)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid date format. Expected YYYY-MM-DD."
    ]);
    exit;
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
    echo json_encode([
        "status" => "error",
        "message" => "Database connection failed: " . $e->getMessage()
    ]);
    exit;
}

try {
    // Prepare and execute the query
    $stmt = $pdo->prepare("SELECT student_id, student_name, time FROM attendance WHERE date = :date");
    $stmt->bindParam(':date', $date);
    $stmt->execute();

    $attendanceRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "success",
        "date" => $date,
        "data" => $attendanceRecords
    ]);
} catch (PDOException $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Query failed: " . $e->getMessage()
    ]);
}
