<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Load environment variables
$cloudinaryUrl = getenv('CLOUDINARY_URL'); // e.g. cloudinary://API_KEY:API_SECRET@CLOUD_NAME
$dbUrl = getenv('DATABASE_URL'); // e.g. postgresql://user:pass@host/dbname?sslmode=require

// Parse Cloudinary credentials from CLOUDINARY_URL
if (!$cloudinaryUrl) {
    echo json_encode(['status' => 'error', 'message' => 'Missing CLOUDINARY_URL environment variable']);
    exit;
}
preg_match('/cloudinary:\/\/([^:]+):([^@]+)@(.+)/', $cloudinaryUrl, $matches);
if (!$matches) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid CLOUDINARY_URL format']);
    exit;
}
list(, $cloudinaryApiKey, $cloudinaryApiSecret, $cloudinaryCloudName) = $matches;

// Parse PostgreSQL connection info from DATABASE_URL
if (!$dbUrl) {
    echo json_encode(['status' => 'error', 'message' => 'Missing DATABASE_URL environment variable']);
    exit;
}
$pgUrl = parse_url($dbUrl);
if (!$pgUrl) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid DATABASE_URL format']);
    exit;
}

$dbHost = $pgUrl['host'] ?? '';
$dbPort = $pgUrl['port'] ?? 5432;
$dbUser = $pgUrl['user'] ?? '';
$dbPass = $pgUrl['pass'] ?? '';
$dbName = ltrim($pgUrl['path'] ?? '', '/');
$query = [];
parse_str($pgUrl['query'] ?? '', $query);
$sslmode = $query['sslmode'] ?? 'require';

// 1. Get raw POST data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input']);
    exit;
}

// 2. Validate required fields
if (empty($data['id']) || empty($data['name']) || empty($data['image'])) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required parameters: id, name, or image']);
    exit;
}

$id = $data['id'];
$name = $data['name'];
$imageData = $data['image'];

// 3. Clean and decode base64 image data
if (strpos($imageData, 'base64,') !== false) {
    $imageData = explode('base64,', $imageData)[1];
}
$imageData = str_replace(' ', '+', $imageData);

$decodedImage = base64_decode($imageData);
if ($decodedImage === false) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to decode Base64 image']);
    exit;
}

// 4. Upload image to Cloudinary
function uploadToCloudinary($base64Image, $cloudName, $apiKey, $apiSecret) {
    $url = "https://api.cloudinary.com/v1_1/$cloudName/image/upload";
    
    $timestamp = time();
    $paramsToSign = "timestamp=$timestamp$apiSecret";
    $signature = sha1($paramsToSign);
    
    $postData = http_build_query([
        'file' => 'data:image/jpeg;base64,' . $base64Image,
        'api_key' => $apiKey,
        'timestamp' => $timestamp,
        'signature' => $signature
    ]);
    
    $opts = [
        "http" => [
            "method" => "POST",
            "header" => "Content-type: application/x-www-form-urlencoded",
            "content" => $postData,
            "timeout" => 30,
            "ignore_errors" => true // Allows reading response on HTTP errors
        ]
    ];
    
    $context = stream_context_create($opts);
    $response = @file_get_contents($url, false, $context); // Suppress warnings
    
    // Check HTTP status code
    if ($response === false || !isset($http_response_header[0]) || strpos($http_response_header[0], '200 OK') === false) {
        error_log("Cloudinary upload failed: " . ($response ?: "No response"));
        return null;
    }
    
    $result = json_decode($response, true);
    return $result['secure_url'] ?? null;
}

$imageUrl = uploadToCloudinary($imageData, $cloudinaryCloudName, $cloudinaryApiKey, $cloudinaryApiSecret);
if (!$imageUrl) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to upload image to Cloudinary']);
    exit;
}

// 5. Connect to PostgreSQL using native pgsql functions
$connString = sprintf(
    "host=%s port=%d dbname=%s user=%s password=%s sslmode=%s",
    $dbHost,
    $dbPort,
    $dbName,
    $dbUser,
    $dbPass,
    $sslmode
);

$dbconn = pg_connect($connString);

if (!$dbconn) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

// 6. Create students table if not exists
$createTableSQL = "
    CREATE TABLE IF NOT EXISTS students (
        id VARCHAR(50) PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        image_path TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
";

$result = pg_query($dbconn, $createTableSQL);
if (!$result) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to create table: ' . pg_last_error($dbconn)]);
    pg_close($dbconn);
    exit;
}

// 7. Check for duplicate student ID only (allow duplicate names)
$checkSQL = "SELECT COUNT(*) FROM students WHERE id = $1";
$result = pg_prepare($dbconn, "check_duplicate", $checkSQL);
if (!$result) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to prepare duplicate check query']);
    pg_close($dbconn);
    exit;
}

$result = pg_execute($dbconn, "check_duplicate", [$id]);
if (!$result) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to execute duplicate check query']);
    pg_close($dbconn);
    exit;
}

$count = pg_fetch_result($result, 0, 0);
pg_free_result($result);

if ($count > 0) {
    echo json_encode(['status' => 'error', 'message' => 'Student with this ID already registered']);
    pg_close($dbconn);
    exit;
}

// 8. Insert student record
$insertSQL = "INSERT INTO students (id, name, image_path) VALUES ($1, $2, $3)";
$result = pg_prepare($dbconn, "insert_student", $insertSQL);
if (!$result) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to prepare insert query']);
    pg_close($dbconn);
    exit;
}

$result = pg_execute($dbconn, "insert_student", [$id, $name, $imageUrl]);
if (!$result) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to insert student: ' . pg_last_error($dbconn)]);
    pg_close($dbconn);
    exit;
}

pg_close($dbconn);

echo json_encode([
    'status' => 'success',
    'message' => 'Student registered successfully',
    'image_url' => $imageUrl
]);
exit;
