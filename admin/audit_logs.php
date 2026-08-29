<?php
// Audit history is intentionally stored in the database and is not exposed as an application page.
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not Found';
exit();
