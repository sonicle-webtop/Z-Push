<?php

    //global $db; // global variable that can be used with any script
    function dbconnect()
    {
        // connect to database
	$db = pg_pconnect("host=" . SONICLE_DBHOST . " port=5432 dbname=" . SONICLE_DBNAME . " user=" . SONICLE_DBUSER. " password=" . SONICLE_DBPASS);
        return $db;
    }// end connect()
?>
