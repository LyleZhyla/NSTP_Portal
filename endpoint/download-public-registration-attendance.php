<?php
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');

echo 'Public registration attendance export is disabled. Public registrations are no longer counted as attendance.';
