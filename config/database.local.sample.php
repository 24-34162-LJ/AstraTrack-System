<?php
// Copy to config/database.local.php (git-ignored) and fill in the app user.
// Create the user with the commented block at the end of config/astratrack_mysql.sql.
return [
    'host' => 'localhost',
    'name' => 'astratrack',
    'user' => 'astratrack_app',
    'pass' => 'CHANGE_ME_long_random_password',
];
