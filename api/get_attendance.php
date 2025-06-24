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

// Parse PostgreSQL connection info from Neon URL
$dbUrl = 'postgresql://neondb_owner:npg_0kXx8aimHZfn@ep-super-haze-a92tp83o-pooler.gwc.azure.neon.tech/AttendanceSystem?sslmode=require';
$dbConfig = parse_url($dbUrl);

$dbHost = $dbConfig['host'] ?? '';
$dbPort = $dbConfig['port'] ?? 5432;
$dbUser = $dbConfig['user'] ?? '';
$dbPass = $dbConfig['pass'] ?? '';
$dbName = ltrim($dbConfig['path'] ?? '', '/');

// Build connection string for pg_connect
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
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Could not connect to database"
    ]);
    exit;
}

// Query attendance records sorted by date descending, then time descending
$sql = "SELECT student_name, student_id, date, time FROM attendance ORDER BY date DESC, time DESC";
$result = pg_query($dbconn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Query failed: " . pg_last_error($dbconn)
    ]);
    pg_close($dbconn);
    exit;
}

$attendance_records = [];
while ($row = pg_fetch_assoc($result)) {
    // Convert student_id to int
    $row['student_id'] = (int)$row['student_id'];
    $attendance_records[] = $row;
}

pg_free_result($result);
pg_close($dbconn);

if (empty($attendance_records)) {
    echo json_encode([
        "status" => "success",
        "message" => "No attendance records found",
        "data" => [],
        "count" => 0
    ]);
} else {
    echo json_encode([
        "status" => "success",
        "message" => "Attendance records retrieved successfully",
        "data" => $attendance_records,
        "count" => count($attendance_records)
    ]);
}
exit;
?>
