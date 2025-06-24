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

// Build connection string
$connString = sprintf(
    "host=%s port=%d dbname=%s user=%s password=%s sslmode=require",
    $dbHost,
    $dbPort,
    $dbName,
    $dbUser,
    $dbPass
);

// Connect to PostgreSQL
$dbconn = pg_connect($connString);

if (!$dbconn) {
    echo json_encode([
        "status" => "error",
        "message" => "Database connection failed"
    ]);
    exit;
}

// Prepare the query
$query = "SELECT student_id, student_name, time FROM attendance WHERE date = $1";
$result = pg_prepare($dbconn, "get_attendance_by_date", $query);

if (!$result) {
    echo json_encode([
        "status" => "error",
        "message" => "Failed to prepare query"
    ]);
    pg_close($dbconn);
    exit;
}

// Execute the prepared statement with the date parameter
$result = pg_execute($dbconn, "get_attendance_by_date", [$date]);

if (!$result) {
    echo json_encode([
        "status" => "error",
        "message" => "Query failed: " . pg_last_error($dbconn)
    ]);
    pg_close($dbconn);
    exit;
}

$attendanceRecords = [];
while ($row = pg_fetch_assoc($result)) {
    $attendanceRecords[] = $row;
}

pg_free_result($result);
pg_close($dbconn);

echo json_encode([
    "status" => "success",
    "date" => $date,
    "data" => $attendanceRecords
]);
exit;
?>
