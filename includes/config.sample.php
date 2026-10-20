<?php
/**
 * Database login for a web host (e.g. InfinityFree).
 *
 * Copy this file to includes/config.php ON THE HOST and fill in the details from the
 * host's control panel (MySQL Databases). config.php is kept out of Git, so the
 * password never ends up on GitHub. On your own PC (XAMPP) you don't need config.php.
 */
define('DB_HOST', 'sqlXXX.infinityfree.com');    // "MySQL Hostname"
define('DB_USER', 'if0_00000000');               // "MySQL Username"
define('DB_PASS', 'your-account-password');      // "MySQL Password"
define('DB_NAME', 'if0_00000000_station_finds'); // the database name you created
