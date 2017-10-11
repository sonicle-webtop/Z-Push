<?php
	/*
	 * {orig-zpush-version}.{sonicle-revision}
	 * eg. 2.3.4.0
	 *    - 2.3.4 z-push version
	 *    - 0 sonicle backend revision
	 */
	error_reporting(E_ALL & ~E_NOTICE);
	define('ZPUSH_VERSION','2.3.4.3');
	// Webtop main config
    define('LOGBASE_DIR','/sonicle/var/log/z-push-sync/');
    define('STATEBASE_DIR', LOGBASE_DIR );
    define('SONICLE_DBHOST','webtop.sonicle.com');
    define('SONICLE_DBUSER','sonicle');
    define('SONICLE_DBPASS','sonicle');
    define('SONICLE_DBNAME','webtop5');
    define('TIMEZONE', 'Europe/Rome');
    /* Now set backend/imap/config.php */
	
    define('STATE_DIR', STATEBASE_DIR . 'state/');
    define('LOGFILEDIR', LOGBASE_DIR );
    define('LOGFILE', LOGFILEDIR . 'z-push.log');
    define('LOGERRORFILE', LOGFILEDIR . 'z-push-error.log');

