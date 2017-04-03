<?php

/* * *********************************************
 * File      :   webtop.php
 * Project   :   Z-Push
 * Descr     :   This Backend is abstract for WebTop Groupware.
 *
 * Created   :   29.09.2010 - emerson-faria.nobre@serpro.gov.br
 *
 * ? Zarafa Deutschland GmbH, www.zarafaserver.de
 * This file is distributed under GPL v2.
 * Consult LICENSE file for details
 * ********************************************** */

require_once("backend/webtop/z_RTF.php");
require_once("backend/webtop/dbconnect.php");

abstract class BackendWebtop extends BackendDiff {

    var $db;
    var $_emaillogin;
    var $_username;
    var $_domain;
    var $device_ios;
    var $device_android;
    var $device_outlook;
	var $_auth_uri;
	var $_auth_admin;
	var $_auth_password;
	var $_auth_security;
	var $_auth_params;
    var $_auth_type;
	var $AUTH_WEBTOP = "webtop";
    var $AUTH_IMAP = "imap";
    var $AUTH_WEBTOPLDAP = "ldapwebtop";
    var $AUTH_LDAPNETH = "ldapneth";
    var $AUTH_LDAPAD = "ad";
    
    var $folders_sync = array();

    public function HasChangesSink() {
        return true;
    }

	public function ChangesSinkInitialize($folderid) {
        return true;
    }
	
    public function ChangesSink($timeout = 5) {
        $stopat = time() + $timeout - 1;
        // Wait to timeout
		while ($stopat > time()) {
			sleep(1);
		}		
        return $this->folders_sync;
    }

    public function GetSupportedASVersion() {
        return ZPush::ASV_14;
    }
	
	function __construct() {
        $this->db = dbconnect();
        if (!$this->db) {
            echo "Errore di connesione.\n";
            exit;
        }
    }

	function checkAutheticationType($username, $domain, $password) {
        $this->_username = $username;
        $this->_emaillogin = $username;
        if ($domain != '') {
            $this->_domain = $domain;
        } else {
            $et = strrpos($username, "@");
            if ($et != false) {
                $this->_domain = $this->getDomain($username);
				//$this->_auth_uri = $this->getAuth_Uri($this->_domain);
				$params = $this->getDomainParameters($this->_domain);
				ZLog::Write(LOGLEVEL_DEBUG, sprintf("WebTop: checkAutheticationType: domain id (%s) params (%s)", $this->_domain, print_r($params, true)));
				$this->_auth_uri = $params['dir_uri'];
				$this->_auth_admin = $params['dir_admin'];
				$this->_auth_password = $params['dir_password'];
				$this->_auth_security = $params['dir_connection_security'];
				$this->_auth_parameters = json_decode($params['dir_parameters'], true);
				ZLog::Write(LOGLEVEL_DEBUG, sprintf("WebTop: checkAutheticationType: ldap params (%s)", print_r($this->_auth_parameters, true)));
                $this->_auth_type = $this->getAutheticationType($this->_auth_uri);
                $this->_username = $this->getLogin($username, $this->_domain);
            } else {
                $this->_domain = $this->getDefaultDomain();
            }
        }
	}

	function isLogonEnabled($username, $domain, $password) {
        $syncenabled = $this->isSyncEnabled();
        if ($syncenabled) {
            if ($this->_auth_type == $this->AUTH_WEBTOPLDAP) {
                $success = $this->ldap_authenticate($username, $password, $this->_auth_uri);
            } elseif ($this->_auth_type == $this->AUTH_LDAPNETH) {
                $success = $this->ldap_ns7_authenticate($username, $password, $this->_auth_uri, $this->_auth_admin, $this->_auth_password, $this->_auth_security, $this->_auth_parameters);
	    } elseif ($this->_auth_type == $this->AUTH_LDAPAD) {
                $success = $this->ldapad_authenticate($username, $password, $this->_auth_uri);
            } else {
                $success = $this->checkUserLogin($password);
            }
        } else {
            $success = false;
        }
		return $success;
	}
	
	function checkDeviceType($deviceType) {
        $this->device_android = strpos($deviceType, "android");
        if ($this->device_android !== false)
            $this->device_android = true;
        else
            $this->device_android = false;
        $this->device_outlook = strpos($deviceType, "outlook");
        if ($this->device_outlook !== false)
            $this->device_outlook = true;
        else
            $this->device_outlook = false;
        $this->device_ios = strpos($deviceType, "iphone");
        if ($this->device_ios !== false)
            $this->device_ios = true;
        else {
            $this->device_ios = strpos($deviceType, "ipad");
            if ($this->device_ios !== false)
                $this->device_ios = true;
            else
                $this->device_ios = false;
        }
	}
	
    function is_html($string) {
        return preg_match("/<[^<]+>/", $string, $m) != 0;
    }

    function getTextBetweenTags($html) {
        $matches = array();
        preg_match_all('/<font.*?>(.*?)<\/font>/is', $html, $matches);
        return $matches[1];
    }

    function truncateString($string, $size) {
        if (strlen($string) <= $size)
            return $string;
        else
            return substr($string, 0, $size - 1);
    }

    function getDefaultDomain() {
        $result_domain = pg_query($this->db, ("select domain_id from core.domains where 1=0;"));
        if ($result_domain == FALSE)
            throw new Exception(pg_last_error($this->db));
        while ($row_domain = pg_fetch_row($result_domain)) {
            if (isset($row_domain[0])) {
                return $row_domain[0];
            }
        }
    }

    function getLogin($username, $domain) {
        $result_user = pg_query($this->db, ("select user_id from core.users where (user_id='".$username."' or user_id = substr('".$username."',0,strpos('".$username."', '@')) ) and domain_id='".$domain."';"));
        if ($result_user == FALSE)
            return false;
        while ($row_dom = pg_fetch_row($result_user)) {
            if (isset($row_dom[0])) {
                $username = $row_dom[0];
            }
        }
        return $username;
    }

    function checkUserLogin($password) {
        $encpsw = $this->getEncryptPassword($password);
        $result_user = pg_query($this->db, ("select * from core.local_vault where user_id='" . $this->_username . "' and password='" . $encpsw . "' and domain_id='" . $this->_domain . "';"));
        if ($result_user == FALSE)
            return false;
        while ($row_domain = pg_fetch_row($result_user)) {
            return true;
        }
        return false;
    }

    function getEncryptPassword($password) {
        return base64_encode(sha1($password, true));
    }

    function getAutheticationType($uri) {
		$parts = parse_url($uri);
		return $parts['scheme'];
    }

	function getDomainParameters($iddomain) {
		$result = pg_query($this->db, "select * from core.domains where domain_id='".$iddomain."' and enabled is true;");
		if ($result == FALSE)
            throw new Exception(pg_last_error($this->db));
		return pg_fetch_assoc($result);
    }

    function getDomain($username) {
        $et = strrpos($username, "@");
        if ($et != false) {
            $et_domain = substr($username, $et + 1, strlen($username));
            $result_dom = pg_query($this->db, "select domain_id from core.domains where internet_name='".$et_domain."' and enabled is true;");
            if ($result_dom == FALSE)
                throw new Exception(pg_last_error($this->db));
            while ($row_dom = pg_fetch_row($result_dom)) {
                if (isset($row_dom[0])) {
                    return $row_dom[0];
                }
            }
        }
    }
	
	function ldap_ns7_authenticate($username, $password, $dir_uri, $dir_admin, $dir_password, $dir_conn_security, $dir_parameters) {
		// https://github.com/zakkak/qa-ldap-login/blob/master/ActiveDirectoryLDAPServer.php
		// https://www.exchangecore.com/blog/how-use-ldap-active-directory-authentication-php/
		if (strpos($username, '@') !== false) {
			$tmp = explode('@', $username);
			$short_username = $tmp[0];
			$domain = $tmp[1];
		}
		
		$uri_parts = parse_url($dir_uri);
		$scheme = "ldap";
		$port = "389";
		if ($dir_conn_security == 'SSL') {
			$scheme = "ldaps";
			$port = "636";
		}
		
		$uri = $scheme . "://" . $uri_parts['host'] . ":" . $port;
		$conn = ldap_connect($uri);
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("WebTop::ldap_ns7_authenticate: ldap_connect(%s)", $uri));
		if (!$conn) {
			ZLog::Write(LOGLEVEL_DEBUG, sprintf("WebTop::ldap_ns7_authenticate: LDAP connection failed : ldap_error (%s)", ldap_error($conn)));
			return false;
		}
		ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
		ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
		if ($dir_conn_security == 'STARTTLS') {
			if (!ldap_start_tls($conn)) {
				ZLog::Write(LOGLEVEL_DEBUG, sprintf("WebTop::ldap_ns7_authenticate: LDAP starttls failed"));
				return false;
			}
		}
		
		$dir_password2 = $this->decrypt_dir_password($dir_password);
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("WebTop::ldap_ns7_authenticate: ldap_bind(%s, ...)", $dir_admin));
		$bind = ldap_bind($conn, $dir_admin, $dir_password2);
		if (!$bind) {
			ZLog::Write(LOGLEVEL_DEBUG, sprintf("WebTop::ldap_ns7_authenticate: LDAP bind failed"));
			return false;
		}
		
		$attributes = array('dn');
		$base_dn = $dir_parameters['loginDn'];
		$filter = $this->join_ldap_filters($dir_parameters['userIdField'] . "=" . $short_username, $dir_parameters['loginFilter']);
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("WebTop::ldap_ns7_authenticate: ldap_search(%s, %s)", $base_dn, $filter));
		$search = ldap_search($conn, $base_dn, $filter, $attributes);
		$data = ldap_get_entries($conn, $search);
		
		if (!isset($data[0])) {
			ZLog::Write(LOGLEVEL_DEBUG, sprintf("WebTop::ldap_ns7_authenticate: LDAP search failed : base_dn (%s) filter: (%s)", $base_dn, $filter));
			return false;	
		}
		
		$login_dn = $data[0]['dn'];
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("WebTop::ldap_ns7_authenticate: ldap_bind(%s, ...)", $login_dn));
		$login_bind = ldap_bind($conn, $login_dn, $password);
		if ($login_bind) {
			return true;
		} else {
			ZLog::Write(LOGLEVEL_DEBUG, sprintf("WebTop::ldap_ns7_authenticate: LDAP invalid credentials - login_dn (%s)", $login_dn));
			return false;
		}
	}
	
	function decrypt_dir_password($password) {
		$dec_b64 = base64_decode($password);
		$key = 'p' . 'a' . 's' . 's' . 'w' . 'o' . 'r' . 'd';
		$dec = mcrypt_decrypt(MCRYPT_DES, $key, $dec_b64, MCRYPT_MODE_ECB);
		$pad = ord($dec[strlen($dec)-1]);
		return substr($dec, 0, -$pad);
	}
	
	function join_ldap_filters($filter1, $filter2) {
		if (!isset($filter1) || trim($filter1)==='') {
			return $filter2;
		}
		if (!isset($filter2) || trim($filter2)==='') {
			return $filter1;
		}
		return "(&(" . $filter1 . ")(" . $filter2 . "))";
	}

    function ldap_custom_authenticate($username, $password, $auth_uri, $auth_security, $auth_params) {
		if (strpos($username, '@') !== false) {
			$tmp = explode('@', $username);
			$short_username = $tmp[0];
			$domain = $tmp[1];
		}
		$uri_parts = parse_url($auth_uri);
		$scheme = "ldap";
		$uri_port = "389";
		if ($auth_security == 'SSL') {
			$scheme = "ldaps";
			$uri_port = "636";
		}
		if (isset($uri_parts['port'])) {
			$uri_port = $uri_parts['port'];
		}
		$ldap_uri = $schema . "://".$uri_parts['host'].$uri_port;
		$ldapconn = ldap_connect($ldap_uri);
		if ($ldapconn) {
			if ($auth_security == 'STARTTLS') {
				if (!ldap_start_tls($ldapconn)) {
					ZLog::Write(LOGLEVEL_DEBUG, sprintf("WebTop: ldap_custom_authenticate: LDAP starttls failed : ldap_uri (%s)", $auth_uri));
					return false;
				}
			}

			# Assumption: AD LDAP uses cn=<user>, unix LDAP servers uses uid=<user>
			if ($auth_params['userIdField'] == 'sAMAccountName') {
				$user_cn = 'cn';
			} else {
				$user_cn = 'uid';
			}
			$binddn = $user_cn . '=' . $short_username . ',' . $auth_params['loginDn'];
			$ldapbind = ldap_bind($ldapconn, $binddn, $password);
			if ($ldapbind) {
				return true;
			}

			# Retry with full username form
			$binddn = $user_cn . '=' . $username . ',' . $auth_params['loginDn'];
			$ldapbind = ldap_bind($ldapconn, $binddn, $password);
			if ($ldapbind) {
				return true;
			}

			ZLog::Write(LOGLEVEL_DEBUG, sprintf("WebTop: ldap_custom_authenticate: LDAP bind failed : binddn (%s) bindpass (%s)", $binddn, $bindpass));
			return false;
		}
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("WebTop: ldap_custom_authenticate: LDAP connection failed : ldap_uri (%s) ldap_error (%s)", $auth_uri, ldap_error($ldapconn)));
		return false;
	}

	function ldap_authenticate($username, $password, $auth_uri) {
        /* BEGIN Greg's contribution */
        $et = strrpos($username, "@");
        $et_dom = substr($username, $et + 1);
        $et_dom = str_replace(".",",dc=",$et_dom);
        $username = substr($username, 0, $et);
        $ldaprdn = "uid=$username,ou=people,dc=" . $et_dom;
        /* END Greg's contribution */
        $ldappass = $password; // Password
        if (!$username or ! $password) {
            return false;
        }
        // Connessione al server ldap
        $pos_s = strrpos($auth_uri, "//");
        $auth_uri_ldap = substr($auth_uri, $pos_s + 2, strlen($auth_uri));
        $auth_uri_ldap = str_replace("/ou=people","/",$auth_uri_ldap);
        $auth_uri_ldap = "ldap://" . $auth_uri_ldap;
        $ldapconn = ldap_connect($auth_uri_ldap);
        if ($ldapconn) {

            ldap_set_option($ldapconn, LDAP_OPT_PROTOCOL_VERSION, 3);
            // binding
            $ldapbind = @ldap_bind($ldapconn, $ldaprdn, $ldappass);
            // verifica bind
            if ($ldapbind) {
                return true;
            } else {
                return false;
            }
        }
    }

    function ldapad_authenticate($username, $password, $auth_uri) {
		$ldaprdn = $username;
        $ldappass = $password; // Password
        if (!$username or ! $password) {
            return false;
        }
        // Connessione al server ldap
        $pos_s = strrpos($auth_uri, "//");
        $auth_uri_ldap = substr($auth_uri, $pos_s + 2, strlen($auth_uri));
        $auth_uri_ldap = "ldap://" . $auth_uri_ldap;
        $ldapconn = ldap_connect($auth_uri_ldap);
        if ($ldapconn) {
            ldap_set_option($ldapconn, LDAP_OPT_PROTOCOL_VERSION, 3);
            // binding
            $ldapbind = @ldap_bind($ldapconn, $ldaprdn, $ldappass);
            // verifica bind
            if ($ldapbind) {
                return true;
            } else {
                return false;
            }
        }
    }
	
    function isSyncEnabled() {
        $result_user = pg_query($this->db, 
			" select distinct 1 "
			." from core.roles_permissions "
			." WHERE roles_permissions.role_uid in ( "
			." select user_uid from core.users "
			."  where users.domain_id='" . $this->_domain . "' and users.user_id='" . $this->_username . "' "
			." UNION "
			." select distinct users_associations.group_uid from core.users_associations inner join core.users on users_associations.user_uid=users.user_uid "
			."  where users.domain_id='" . $this->_domain . "' and users.user_id='" . $this->_username . "' "
			." UNION "
			." select distinct roles_associations.role_uid from core.roles_associations inner join core.users on roles_associations.user_uid=users.user_uid "
			."  where users.domain_id='" . $this->_domain . "' and users.user_id='" . $this->_username . "' "
			." UNION "
			." select distinct roles_associations.role_uid  "
			."  from core.roles_associations inner join core.users_associations on roles_associations.user_uid=users_associations.group_uid "
			."  inner join core.users on users_associations.user_uid=users.user_uid "
			."  where users.domain_id='" . $this->_domain . "' and users.user_id='" . $this->_username . "' "
			." ) "
			." AND roles_permissions.service_id = 'com.sonicle.webtop.core' "
			." AND roles_permissions.key = 'DEVICES_SYNC' "
			." AND roles_permissions.action = 'ACCESS' "
			." AND roles_permissions.instance = '*' "
		);
        if ($result_user == FALSE)
            return false;
        while ($row_dom = pg_fetch_row($result_user)) {
            return true;
        }
        return false;
    }
	
}

;
?>
