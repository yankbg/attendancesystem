<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Handle preflight OPTIONS request for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        "status" => "error",
        "message" => "Only POST requests are allowed"
    ]);
    exit;
}

// Parse input data depending on Content-Type
$contentType = $_SERVER["CONTENT_TYPE"] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    $inputJSON = file_get_contents('php://input');
    $input = json_decode($inputJSON, true);
    $studentId = $input['studentId'] ?? '';
    $fullname = $input['fullname'] ?? '';
} else {
    // Fallback to form-urlencoded data
    $studentId = $_POST['studentId'] ?? '';
    $fullname = $_POST['fullname'] ?? '';
}

// Validate inputs
if (empty($studentId) || empty($fullname)) {
    echo json_encode([
        "status" => "error",
        "message" => "Missing studentId or fullname"
    ]);
    exit;
}

if (!is_numeric($studentId)) {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid student Id"
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
    // Prepare and execute query with case-insensitive name comparison using ILIKE
    $sql = "SELECT id, image_path FROM students WHERE id = :id AND name ILIKE :name";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', (int)$studentId, PDO::PARAM_INT);
    $stmt->bindValue(':name', $fullname, PDO::PARAM_STR);
    $stmt->execute();

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        echo json_encode([
            "status" => "success",
            "exists" => true,
            "image_path" => $row['image_path'] // Provide relative or absolute URL to student image
        ]);
    } else {
        echo json_encode([
            "status" => "error",
            "exists" => false,
            "message" => "Student not found"
        ]);
    }
} catch (PDOException $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Query failed: " . $e->getMessage()
    ]);
}
?>
