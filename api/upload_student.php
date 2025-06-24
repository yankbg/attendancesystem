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

// Load environment variables (you can use vlucas/phpdotenv or set in your environment)
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

// Parse PostgreSQL connection info from DATABASE_URL or use your Neon URL directly
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

    // Prepare unsigned upload preset or use signed upload
    // For simplicity, we'll do a signed upload here
    $timestamp = time();
    $paramsToSign = "timestamp=$timestamp$apiSecret";
    $signature = sha1($paramsToSign);

    $postFields = [
        'file' => 'data:image/jpeg;base64,' . $base64Image,
        'api_key' => $apiKey,
        'timestamp' => $timestamp,
        'signature' => $signature
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    if (isset($result['secure_url'])) {
        return $result['secure_url'];
    }
    return null;
}

$imageUrl = uploadToCloudinary($imageData, $cloudinaryCloudName, $cloudinaryApiKey, $cloudinaryApiSecret);
if (!$imageUrl) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to upload image to Cloudinary']);
    exit;
}

// 5. Connect to PostgreSQL using PDO
try {
    $dsn = "pgsql:host=$dbHost;port=$dbPort;dbname=$dbName;sslmode=$sslmode";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// 6. Create students table if not exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS students (
            id VARCHAR(50) PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            image_path TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to create table: ' . $e->getMessage()]);
    exit;
}

// 7. Check for duplicate student ID only (allow duplicate names)
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $count = $stmt->fetchColumn();
    if ($count > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Student with this ID already registered']);
        exit;
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to check duplicates: ' . $e->getMessage()]);
    exit;
}

// 8. Insert student record
try {
    $stmt = $pdo->prepare("INSERT INTO students (id, name, image_path) VALUES (:id, :name, :image_path)");
    $stmt->execute([
        'id' => $id,
        'name' => $name,
        'image_path' => $imageUrl
    ]);
    echo json_encode([
        'status' => 'success',
        'message' => 'Student registered successfully',
        'image_url' => $imageUrl
    ]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to insert student: ' . $e->getMessage()]);
    exit;
}
