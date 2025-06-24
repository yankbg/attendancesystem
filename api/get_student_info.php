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

try {
    // Connect to PostgreSQL via PDO
    $dsn = "pgsql:host=$dbHost;port=$dbPort;dbname=$dbName;sslmode=require";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    echo json_encode(["status" => "error", "message" => "DB connection failed: " . $e->getMessage()]);
    exit;
}

// Parse JSON or form-data input
$input = json_decode(file_get_contents('php://input'), true);
$id = $input['student_id'] ?? $_POST['student_id'] ?? '';
$name = $input['student_name'] ?? $_POST['student_name'] ?? '';

// Validate inputs
if (empty($id) || !is_numeric($id) || empty($name)) {
    echo json_encode(["status" => "error", "message" => "Invalid or missing student_id or student_name"]);
    exit;
}

try {
    // Prepare statement with case-insensitive name comparison using ILIKE
    $sql = "SELECT image_path FROM students WHERE id = :id AND name ILIKE :name";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
    $stmt->bindValue(':name', $name, PDO::PARAM_STR);
    $stmt->execute();

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        echo json_encode(["status" => "success", "image_path" => $row['image_path']]);
    } else {
        echo json_encode(["status" => "error", "message" => "Image not found"]);
    }
} catch (PDOException $e) {
    echo json_encode(["status" => "error", "message" => "Query failed: " . $e->getMessage()]);
}

?>
