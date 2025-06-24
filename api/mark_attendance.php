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

    // Database connection using PDO for PostgreSQL
    $dsn = "pgsql:host=$dbHost;port=$dbPort;dbname=$dbName;sslmode=require";
    $conn = new PDO($dsn, $dbUser, $dbPass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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

    $conn->exec($create_table_sql);

    // Check if attendance already marked for student on the date
    $check_sql = "SELECT id FROM attendance WHERE student_id = :student_id AND date = :date";
    $stmt = $conn->prepare($check_sql);
    $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
    $stmt->bindParam(':date', $date);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        echo json_encode([
            "status" => "error",
            "message" => "Attendance already marked for this student on " . $date
        ]);
        exit;
    }

    // Insert attendance record
    $insert_sql = "INSERT INTO attendance (student_id, student_name, date, time) 
                   VALUES (:student_id, :student_name, :date, :time)";
    $stmt = $conn->prepare($insert_sql);
    $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
    $stmt->bindParam(':student_name', $student_name);
    $stmt->bindParam(':date', $date);
    $stmt->bindParam(':time', $time);

    if ($stmt->execute()) {
        // Get last inserted record details
        $last_sql = "SELECT * FROM attendance WHERE id = LASTVAL()";
        $last_stmt = $conn->query($last_sql);
        $record = $last_stmt->fetch(PDO::FETCH_ASSOC);

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
    } else {
        throw new Exception("Failed to mark attendance");
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Database error: " . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
?>
