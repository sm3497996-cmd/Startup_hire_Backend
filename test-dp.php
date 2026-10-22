<?php

echo "PHP START\n";

try {
    $pdo = new PDO(
        "mysql:host=127.0.0.1;port=3306;dbname=startup_hire",
        "root",
        ""
    );

    echo "DB CONNECTED\n";
} catch (Throwable $e) {
    echo "DB ERROR: " . $e->getMessage() . "\n";
}