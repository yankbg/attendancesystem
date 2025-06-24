
<?php
$url = "https://attendancesytem-007.vercel.app/api/check_student.php";

$options = [
    "http" => [
        "method"  => "POST",
        "header"  => "Content-Type: application/x-www-form-urlencoded\r\n",
        "content" => http_build_query([
            "studentId" => "123",
            "fullname" => "john bob"
        ]),
        "timeout" => 10
    ]
];

$context = stream_context_create($options);
$response = file_get_contents($url, false, $context);

if ($response === false) {
    // Handle error
    echo "Request failed";
} else {
    echo $response;
}
