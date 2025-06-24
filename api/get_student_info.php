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
    echo json_encode(["status" => "error", "message" => "DB connection failed"]);
    exit;
}

// Parse JSON or form-data input
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);
$id = $input['student_id'] ?? $_POST['student_id'] ?? '';
$name = $input['student_name'] ?? $_POST['student_name'] ?? '';

// Validate inputs
if (empty($id) || !is_numeric($id) || empty($name)) {
    echo json_encode(["status" => "error", "message" => "Invalid or missing student_id or student_name"]);
    pg_close($dbconn);
    exit;
}

// Prepare the query with case-insensitive name comparison using ILIKE
$query = "SELECT image_path FROM students WHERE id = $1 AND name ILIKE $2";
$result = pg_prepare($dbconn, "get_image_path", $query);

if (!$result) {
    echo json_encode(["status" => "error", "message" => "Failed to prepare query"]);
    pg_close($dbconn);
    exit;
}

// Execute the prepared statement
$result = pg_execute($dbconn, "get_image_path", [(int)$id, $name]);

if (!$result) {
    echo json_encode(["status" => "error", "message" => "Query failed: " . pg_last_error($dbconn)]);
    pg_close($dbconn);
    exit;
}

$row = pg_fetch_assoc($result);
pg_free_result($result);
pg_close($dbconn);

if ($row) {
    echo json_encode(["status" => "success", "image_path" => $row['image_path']]);
} else {
    echo json_encode(["status" => "error", "message" => "Image not found"]);
}
?>
