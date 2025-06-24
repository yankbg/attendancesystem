<?php
$url = "https://attendancesystem-007-git-main-yankbgs-projects.vercel.app/api/check_student.php";

$options = [
    "http" => [
        "method"  => "POST",
        "header"  => "Content-Type: application/x-www-form-urlencoded\r\n",
        "content" => http_build_query([
            "studentId" => "123",
            "fullname" => "john bob"
        ]),
        "timeout" => 10,
        "ignore_errors" => true // <-- important to get response even on HTTP error
    ]
];

$context = stream_context_create($options);
$response = file_get_contents($url, false, $context);

if ($response === false) {
    echo "Request failed\n";
} else {
    // Output response and HTTP status code
    echo "HTTP Response:\n";
    echo $response . "\n";

    if (isset($http_response_header)) {
        foreach ($http_response_header as $header) {
            echo $header . "\n";
        }
    }
}
