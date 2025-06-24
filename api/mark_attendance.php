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

// Parse Neon PostgreSQL connection from environment variable
$dbUrl = 'postgresql://neondb_owner:npg_0kXx8aimHZfn@ep-super-haze-a92tp83o-pooler.gwc.azure.neon.tech/AttendanceSystem?sslmode=require';
$dbConfig = parse_url($dbUrl);

// Extract database credentials
$dbHost = $dbConfig['host'] ?? '';
$dbPort = $dbConfig['port'] ?? 5432;
$dbUser = $dbConfig['user'] ?? '';
$dbPass = $dbConfig['pass'] ?? '';
$dbName = ltrim($dbConfig['path'] ?? '', '/');

// Connect to PostgreSQL
$connString = sprintf(
    "host=%s port=%d dbname=%s user=%s password=%s sslmode=require",
    $dbHost,
    $dbPort,
    $dbName,
    $dbUser,
    $dbPass
);

$dbconn = pg_connect($connString);

if (!$dbconn) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Could not connect to database"
    ]);
    exit;
}

try {
    // Get POST data
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data || !isset($data['qr_data'])) {
        throw new Exception("Missing 'qr_data' in request");
    }

    // qr_data is a JSON string, decode it
    $qr_info = json_decode($data['qr_data'], true);

    if (!$qr_info) {
        throw new Exception("Invalid JSON format in 'qr_data'");
    }

    // Validate required fields in QR data
    $required_fields = ['studentId', 'fullname', 'Date', 'time'];
    foreach ($required_fields as $field) {
        if (empty($qr_info[$field])) {
            throw new Exception("Missing required field: " . $field);
        }
    }

    $student_id = intval($qr_info['studentId']);
    $student_name = $qr_info['fullname'];
    $date = $qr_info['Date']; // Expected format: YYYY-MM-DD
    $time = $qr_info['time']; // Expected format: HH:MM:SS or HH:MM

    // Create attendance table if not exists
    $create_table_sql = "CREATE TABLE IF NOT EXISTS attendance (
        id SERIAL PRIMARY KEY,
        student_id INT NOT NULL,
        student_name VARCHAR(255) NOT NULL,
        date DATE NOT NULL,
        time TIME NOT NULL,
        marked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (student_id, date)
    )";

    $result = pg_query($dbconn, $create_table_sql);
    if (!$result) {
        throw new Exception("Failed to create table: " . pg_last_error($dbconn));
    }

    // Check if attendance already marked for student on the date
    $check_sql = "SELECT id FROM attendance WHERE student_id = $1 AND date = $2";
    $result = pg_prepare($dbconn, "check_attendance", $check_sql);
    if (!$result) {
        throw new Exception("Failed to prepare check query: " . pg_last_error($dbconn));
    }

    $result = pg_execute($dbconn, "check_attendance", [$student_id, $date]);
    if (!$result) {
        throw new Exception("Failed to execute check query: " . pg_last_error($dbconn));
    }

    if (pg_num_rows($result) > 0) {
        echo json_encode([
            "status" => "error",
            "message" => "Attendance already marked for this student on " . $date
        ]);
        pg_free_result($result);
        pg_close($dbconn);
        exit;
    }
    pg_free_result($result);

    // Insert attendance record
    $insert_sql = "INSERT INTO attendance (student_id, student_name, date, time) VALUES ($1, $2, $3, $4) RETURNING *";
    $result = pg_prepare($dbconn, "insert_attendance", $insert_sql);
    if (!$result) {
        throw new Exception("Failed to prepare insert query: " . pg_last_error($dbconn));
    }

    $result = pg_execute($dbconn, "insert_attendance", [$student_id, $student_name, $date, $time]);
    if (!$result) {
        throw new Exception("Failed to execute insert query: " . pg_last_error($dbconn));
    }

    $record = pg_fetch_assoc($result);
    pg_free_result($result);
    pg_close($dbconn);

    echo json_encode([
        "status" => "success",
        "message" => "Attendance marked successfully for student " . $student_name,
        "data" => [
            "studentId" => $student_id,
            "fullname" => $student_name,
            "Date" => $date,
            "time" => $time,
            "marked_at" => $record['marked_at']
        ]
    ]);

} catch (Exception $e) {
    if ($dbconn) {
        pg_close($dbconn);
    }
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
?>
