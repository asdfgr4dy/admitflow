<?php
declare(strict_types=1);

return [
    'directory' => dirname(__DIR__) . '/storage/backups',
    'tar_binary' => 'tar',
    'mysqldump_binary' => 'mysqldump',
    'mysql_binary' => 'mysql',
    'retention_count' => 8,
];