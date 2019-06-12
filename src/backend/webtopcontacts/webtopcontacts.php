<?php

/* * *********************************************
 * File      :   vcarddir.php
 * Project   :   Z-Push
 * Descr     :   This Contact Backend is for WebTop Groupware.
 *
 * Created   :   29.09.2010 - emerson-faria.nobre@serpro.gov.br
 *
 * ??? Zarafa Deutschland GmbH, www.zarafaserver.de
 * This file is distributed under GPL v2.
 * Consult LICENSE file for details
 * ********************************************** */
require_once("backend/webtop/webtop.php");
require_once("backend/webtopcontacts/contacts_config.php");
require_once("backend/webtop/dbconnect.php");
require_once("backend/webtop/z_RTF.php");

class BackendWebTopContacts extends BackendWebtop implements ISearchProvider {

    function Logon($username, $domain, $password) {
        $deviceType = strtolower(Request::GetDeviceType());
		$this->checkDeviceType($deviceType);
		$this->checkAutheticationType($username, $domain, $password);
		return $this->isLogonEnabled($username, $domain, $password);
    }

    public function Logoff() {
        return true;
    }

    function SendMail($rfc822, $forward = false, $reply = false, $parent = false) {
        return false;
    }

    function GetWasteBasket() {
        return false;
    }

    function GetMessageList($folderid, $cutoffdate) {
        $messages = array();
        try {
            $result = pg_query($this->db, "BEGIN;");
            if ($result == FALSE)
                throw new Exception(pg_last_error($this->db));
            $cat = "";
//ANDROID            if ($this->device_ios) {
				if ($this->isShared($folderid)) {
					$sharedid=$this->getSharedId($folderid);
					$cat = "AND category_id = " . $sharedid;
				} else {
					$cat = "AND category_id IN (SELECT category_id FROM contacts.categories WHERE user_id='".$this->_username."' and domain_id='".$this->_domain."' and sync in ('R','W') and name = '" .$folderid . "') ";
				}
//ANDROID            } else {
//ANDROID                $cat = "AND category_id IN (SELECT category_id FROM contacts.categories WHERE (user_id='".$this->_username."' and domain_id='".$this->_domain."' and sync in ('R','W')) ";
//ANDROID				//shared folders
//ANDROID				$json_shared=$this->getJSONShared();
//ANDROID				if (sizeof($json_shared)>0) {
//ANDROID					$cat = $cat . " or category_id in (";
//ANDROID					for($i = 0; $i < sizeof($json_shared); ++$i){
//ANDROID						if ($i>0) $cat = $cat . ",";
//ANDROID						$cat = $cat . $json_shared[$i]->categoryId;
//ANDROID					}
//ANDROID					$cat = $cat . ")";
//ANDROID				}
//ANDROID				//
//ANDROID				$cat = $cat . " )";
//ANDROID			}
            $result = pg_query($this->db, "select contact_id,revision_timestamp from contacts.contacts where revision_status!='D' ".$cat." and is_list is false;");
            if ($result == FALSE)
                throw new Exception(pg_last_error($this->db));
            while ($row = pg_fetch_row($result)) {
                $message = array();
                $message["id"] = $row[0];
                $message["mod"] = substr($row[1], 0, strlen($row[1]));
                $message["flags"] = 1; // always 'read'
                $messages[] = $message;
            }
            $result = pg_query($this->db, "COMMIT;");
            if ($result == FALSE)
                throw new Exception(pg_last_error($this->db));
        } catch (Exception $e) {
            pg_query($this->db, "ROLLBACK;");
        }
        return $messages;
    }

	function getJSONShared() {
		$resp=$this->doHTTPRequest(SONICLE_REST_BASE_URL . "/api/com.sonicle.webtop.contacts/categories/incoming");
		return json_decode($resp);
	}

    function GetFolderList() {
        $contacts = array();
        $this->folders_sync = array();

//ANDROID		if ($this->device_ios) {
            $result = pg_query($this->db, "select distinct name from contacts.categories where user_id = '".$this->_username."' and domain_id='".$this->_domain."' and sync in ('R','W');");
            if ($result == FALSE)
                throw new Exception(pg_last_error($this->db));
            while ($row = pg_fetch_row($result)) {
                if (isset($row[0])) {
                    array_push($this->folders_sync, $row[0]);
                    $folder = $this->StatFolder($row[0]);
                    array_push($contacts, $folder);
                }
            }
			
			//shared folders
			$json_shared=$this->getJSONShared();
			//ZLog::Write(LOGLEVEL_INFO, sprintf("WebTop: GetFolderList: json_shared (%s)", print_r($json_shared, true)));
			foreach($json_shared as $share) {
				$share_name=$share->ownerDisplayName . " / " . $share->categoryName . " [" . $share->categoryId . "]";
				array_push($this->folders_sync, $share_name);
				$folder = $this->StatFolder($share_name);
				array_push($contacts, $folder);
				//ZLog::Write(LOGLEVEL_INFO, sprintf("WebTop: GetFolderList: added folder (%s)", print_r($folder, true)));
			}
//ANDROID        } else {
//ANDROID            array_push($this->folders_sync, "WebTop");
//ANDROID            $folder = $this->StatFolder("WebTop");
//ANDROID            array_push($contacts, $folder);
//ANDROID        }
        return $contacts;
    }

    function GetFolder($id) {
        if (in_array($id, $this->folders_sync)) {
            $folder = new SyncFolder();
            $folder->serverid = $id;
            $folder->parentid = "0";
            $folder->displayname = $id;
//ANDROID			if ($this->device_ios) {
                $folder->type = SYNC_FOLDER_TYPE_USER_CONTACT;
//ANDROID            } else {
//ANDROID				$folder->type = SYNC_FOLDER_TYPE_CONTACT;
//ANDROID                $folder->displayname = $this->_username;
//ANDROID            }
            return $folder;
        } else
            return null;
    }

    function StatFolder($id) {
        $folder = $this->GetFolder($id);
        $stat = array();
        $stat["id"] = $id;
        if ($folder != null) {
            $stat["mod"] = $folder->displayname;
            $stat["parent"] = $folder->parentid;
        } else {
            $stat["mod"] = $id;
            $stat["parent"] = "0";
        }
        return $stat;
    }

    function GetAttachmentData($attname) {
        return false;
    }

    function StatMessage($folderid, $id) {
        if (!empty($this->folders_sync) && in_array($folderid, $this->folders_sync) != TRUE)
            return false;

        try {
            $result = pg_query($this->db, "BEGIN;");
            if ($result == FALSE)
                throw new Exception(pg_last_error($this->db));
			$cat = "";
//ANDROID            if ($this->device_ios) {
				//if ($this->isShared($folderid)) {
				//	$sharedid=$this->getSharedId($folderid);
				//	$cat = "AND category_id = " . $sharedid;
				//} else {
				//	$cat = "AND category_id IN (SELECT category_id FROM contacts.categories WHERE user_id='".$this->_username."' and domain_id='".$this->_domain."' and sync in ('R','W') and name = '".$folderid."') ";
				//}
				
//ANDROID            } else {
//ANDROID                $cat = "AND category_id IN (SELECT category_id FROM contacts.categories WHERE (user_id='".$this->_username."' and domain_id='".$this->_domain."' and sync in ('R','W')) ";
//ANDROID				//shared folders
//ANDROID				$json_shared=$this->getJSONShared();
//ANDROID				if (sizeof($json_shared)>0) {
//ANDROID					$cat = $cat . " or category_id in (";
//ANDROID					for($i = 0; $i < sizeof($json_shared); ++$i){
//ANDROID						if ($i>0) $cat = $cat . ",";
//ANDROID						$cat = $cat . $json_shared[$i]->categoryId;
//ANDROID					}
//ANDROID					$cat = $cat . ")";
//ANDROID				}
//ANDROID				//
//ANDROID				$cat = $cat . " )";
//ANDROID			}
            $sql = "select revision_timestamp from contacts.contacts where contact_id = ".$id." and revision_status!='D' ".$cat." ;";
			$result_contact = pg_query($this->db, $sql);
            if ($result_contact == FALSE)
                throw new Exception(pg_last_error($this->db));
            while ($row_contact = pg_fetch_row($result_contact)) {
                if (isset($row_contact[0])) {
                    $message = array();
                    $message["mod"] = substr($row_contact[0], 0, strlen($row_contact[0]));
                    $message["id"] = $id;
                    $message["flags"] = 1;
                    return $message;
                }
            }
            $result = pg_query($this->db, "COMMIT;");
            if ($result == FALSE)
                throw new Exception(pg_last_error($this->db));
        } catch (Exception $e) {
            pg_query($this->db, "ROLLBACK;");
        }
        return false;
    }

    function GetMessage($folderid, $id, $truncsize, $mimesupport = 0) {
        if (!empty($this->folders_sync) && in_array($folderid, $this->folders_sync) != TRUE)
            return false;
        // Parse the database into object
        $message = new SyncContact();
        try {
            $result = pg_query($this->db, "BEGIN;");
            if ($result == FALSE)
                throw new Exception(pg_last_error($this->db));
            $result_contact = pg_query($this->db, 
				"select contact_id,"
				. "firstname,"
				. "lastname,"
				. "title,"
				. "company,"
				. "work_address,"
				. "work_city,"
				. "work_state,"
				. "work_postalcode,"
				. "work_country,"
				. "work_telephone,"
				. "work_fax,"
				. "work_telephone2,"
				. "work_pager,"
				. "work_email,"
				. "assistant,"
				. "home_address,"
				. "home_city,"
				. "home_state,"
				. "home_postalcode,"
				. "home_country,"
				. "home_telephone,"
				. "home_fax,"
				. "home_telephone2,"
				. "home_mobile,"
				. "home_email,"
				. "revision_timestamp,"
				. "work_mobile,"
				. "assistant_telephone,"
				. "partner,"
				. "anniversary,"
				. "birthday,"
				. "department,"
				. "url,"
				. "manager,"
				. "work_im,"
				. "home_im,"
				. "other_im,"
				. "other_address,"
				. "other_email,"
				."(select name from contacts.categories where categories.category_id = contacts.category_id) as name, "
				. "other_city,"
				. "other_state,"
				. "other_postalcode,"
				. "other_country,"
				. "function,"
				. "notes "
				. "from contacts.contacts "
				. "where contact_id = " . $id . ";");
            if ($result_contact == FALSE) {
                throw new Exception(pg_last_error($this->db));
            }
            while ($row_contact = pg_fetch_row($result_contact)) {

                if (isset($row_contact[1])) { //firstname
                    $message->firstname = $row_contact[1];
                }
                if (isset($row_contact[2])) { //lastname
                    $message->lastname = $row_contact[2];
                }
                if (isset($row_contact[3])) { //title
                    $message->suffix = $row_contact[3];
                }
                if (isset($row_contact[4])) { //company
                    $message->companyname = $this->getCompanyName($row_contact[4]);
                }
                if (isset($row_contact[5])) { //caddress
                    $message->businessstreet = $row_contact[5];
                }
                if (isset($row_contact[6])) { //ccity
                    $message->businesscity = $row_contact[6];
                }
                if (isset($row_contact[7])) { //cstate
                    $message->businessstate = $row_contact[7];
                }
                if (isset($row_contact[8])) { //cpostalcode
                    $message->businesspostalcode = $row_contact[8];
                }
                if (isset($row_contact[9])) { //ccountry
                    $message->businesscountry = $row_contact[9];
                }
                if (isset($row_contact[10])) { //ctelephone
                    $message->businessphonenumber = $row_contact[10];
                }
                if (isset($row_contact[11])) { //cfax
                    $message->businessfaxnumber = $row_contact[11];
                }
                if (isset($row_contact[12])) { //ctelephone2
                    $message->business2phonenumber = $row_contact[12];
                }
                if (isset($row_contact[13])) { //cpager
                    $message->pagernumber = $row_contact[13];
                }
                if (isset($row_contact[14])) { //cemail
                    $message->email2address = $row_contact[14];
                }
                if (isset($row_contact[15])) { //cassistant
                    $message->assistantname = $row_contact[15];
                }
                if (isset($row_contact[16])) { //haddress
                    $message->homestreet = $row_contact[16];
                }
                if (isset($row_contact[17])) { //hcity
                    $message->homecity = $row_contact[17];
                }
                if (isset($row_contact[18])) { //hstate
                    $message->homestate = $row_contact[18];
                }
                if (isset($row_contact[19])) { //hpostalcode
                    $message->homepostalcode = $row_contact[19];
                }
                if (isset($row_contact[20])) { //hcountry
                    $message->homecountry = $row_contact[20];
                }
                if (isset($row_contact[21])) { //htelephone
                    $message->homephonenumber = $row_contact[21];
                }
                if (isset($row_contact[22])) { //hfax
                    $message->homefaxnumber = $row_contact[22];
                }
                if (isset($row_contact[23])) { //htelephone2
                    $message->home2phonenumber = $row_contact[23];
                }
                if (isset($row_contact[24])) { //hmobile
                    $message->mobilephonenumber = $row_contact[24];
                }
                if (isset($row_contact[25])) { //hemail
                    $message->email1address = $row_contact[25];
                }
                if (isset($row_contact[26])) { //revision
                }
                if (isset($row_contact[27])) { //cmobile
                    $message->mobilephonenumber = $row_contact[27];
                }
                if (isset($row_contact[28])) { //ctelephoneassistant
                    $message->assistnamephonenumber = $row_contact[28];
                }
                if (isset($row_contact[29])) { //hpartner
                    $message->spouse = $row_contact[29];
                }
                if (isset($row_contact[30])) { //hanniversary
                    if ($row_contact[30] != "" && $row_contact[30] != null) {
                        $anniversary = strtotime($row_contact[30]);
                        $message->anniversary = $anniversary;
                    }
                }
                if (isset($row_contact[31])) { //hbirthday
                    if ($row_contact[31] != "" && $row_contact[31] != null) {
                        $hbirthday = strtotime($row_contact[31]);
                        $message->birthday = $hbirthday;
                    }
                }
                if (isset($row_contact[32])) { //cdepartment
                    $message->department = $row_contact[32];
                }
                if (isset($row_contact[33])) { //url
                    $message->webpage = $row_contact[33];
                }
                if (isset($row_contact[34])) { //cmanager
                    $message->managername = $row_contact[34];
                }
                if (isset($row_contact[35])) { //cinstant_msg
                    $message->imaddress = $row_contact[35];
                }
                if (isset($row_contact[36])) { //hinstant_msg
                    $message->imaddress2 = $row_contact[36];
                }
                if (isset($row_contact[37])) { //oinstant_msg
                    $message->imaddress3 = $row_contact[37];
                }
                if (isset($row_contact[38])) { //oaddress
                    $message->otherstreet = $row_contact[38];
                }
                if (isset($row_contact[39])) { //oemail
                    $message->email3address = $row_contact[39];
                }
                if (isset($row_contact[40])) { //category
                    $message->categories = $row_contact[40];
                }
                if (isset($row_contact[41])) { //ocity
                    $message->othercity = $row_contact[41];
                }
                if (isset($row_contact[42])) { //ostate
                    $message->otherstate = $row_contact[42];
                }
                if (isset($row_contact[43])) { //opostalcode
                    $message->otherpostalcode = $row_contact[43];
                }
                if (isset($row_contact[44])) { //ocountry
                    $message->othercountry = $row_contact[44];
                }
                if (isset($row_contact[45])) { //function
                    $message->jobtitle = $row_contact[45];
                }
                if (isset($row_contact[46])) { //function
					$body = str_replace("\n","\r\n", str_replace("\r","",$row_contact[46]));
                    if (Request::GetProtocolVersion() >= 12.0) {
                        $message->asbody = new SyncBaseBody();
                        // truncate body, if requested
                        if (strlen($body) > 1024) {
                            $body = Utils::Utf8_truncate($body, 1024);
                            $message->asbody->truncated = 1;
                        } else {
                            $message->asbody->truncated = 0;
                        }
                        $message->asbody->type = 1;
                        $message->asbody->data = StringStreamWrapper::Open($body);
						$message->asbody->estimatedDataSize = strlen($body);
                    } else {
                        // truncate body, if requested
                        if(strlen($body) > 1024) {
                            $message->bodytruncated = 1;
                            $body = Utils::Utf8_truncate($body, 1024);
                        } else {
                            $message->bodytruncated = 0;
                        }
                        $message->body = $body;
					}
                }
            }
            $result = pg_query($this->db, "COMMIT;");
            if ($result == FALSE)
                throw new Exception(pg_last_error($this->db));
        } catch (Exception $e) {
            pg_query($this->db, "ROLLBACK;");
            return;
        }
        return $message;
    }

    function DeleteMessage($folderid, $id, $contentParameters) {
		if ($this->IsReadOnly($folderid)) {
			return false;
		}
        $result = pg_query($this->db, "BEGIN;");
        try {
            $result_contact = pg_query($this->db, "select contact_id from contacts.contacts where contact_id = ".$id." and revision_status!='D';");
            if ($result_contact == FALSE)
                throw new Exception(pg_last_error($this->db));
            while ($row_contact = pg_fetch_row($result_contact)) {
                if (isset($row_contact[0])) {
                    $id_contact = $row_contact[0];
                }
            }
            if (!isset($id_contact)) {
                return true;
            }
            $arrayContact = array();
            $arrayContact["revision_status"] = "D";
            $arrayContact["revision_timestamp"] = "NOW()";
            $result = pg_update($this->db, "contacts.contacts", $arrayContact, array('contact_id' => $id_contact));
            $result = pg_query($this->db, "COMMIT;");
            if ($result == FALSE)
                throw new Exception(pg_last_error($this->db));
        } catch (Exception $e) {
            pg_query($this->db, "ROLLBACK;");
            return false;
        }
        return true;
    }

    public function DeleteFolder($id, $parentid) {
        return false;
    }

    function SetReadFlag($folderid, $id, $flags, $contentParameters) {
        return false;
    }

    function ChangeMessage($folderid, $id, $message, $contentParameters) {
		//ZLog::Write(LOGLEVEL_INFO, sprintf("WebTop: ChangeMessage: message id=%s (%s)",$id,print_r($message, true)));
		if ($this->IsReadOnly($folderid)) {
			//ZLog::Write(LOGLEVEL_INFO, sprintf("WebTop: ChangeMessage: contact is read only"));
			return false;
		}
        try {
            $result = pg_query($this->db, "BEGIN;");
            if ($result == FALSE)
                throw new Exception(pg_last_error($this->db));
            $found_id_contact = false;
            if (trim($id) !== "") {     //determina se inserimento o aggiornamento
                $result_contact = pg_query($this->db, "select contact_id from contacts.contacts where contact_id = ".$id." and revision_status!='D';");
                if ($result_contact == FALSE)
                    throw new Exception(pg_last_error($this->db));
                while ($row_contact = pg_fetch_row($result_contact)) {
                    if (isset($row_contact[0])) {
                        $id_contact = $row_contact[0];
                        $found_id_contact = true;
                    }
                }
            }        	
            $arrayContact["searchfield"] = "";
            $message->fileas = $id;
			$dn = "";
            if (isset($message->firstname) || !is_null($message->firstname)) {
                $arrayContact["firstname"] = $this->truncateString(($message->firstname), 60);
                $arrayContact["searchfield"] = $arrayContact["searchfield"] . $arrayContact["firstname"];
				if ($arrayContact["firstname"] !== '') {
					$dn = $arrayContact["firstname"];
				}
            }
            if (isset($message->lastname) || !is_null($message->lastname)) {
                $arrayContact["lastname"] = $this->truncateString(($message->lastname), 60);
                $arrayContact["searchfield"] = $arrayContact["searchfield"] . $arrayContact["lastname"];
				if ($arrayContact["lastname"] !== '') {
					$dn = $dn . " " . $arrayContact["lastname"];
				}
            }
			if ($dn !== '') {
				$arrayContact["display_name"] = $this->truncateString(trim($dn), 255);
			}
            if (isset($message->title) || is_null($message->title)) {
                $arrayContact["title"] = $this->truncateString(($message->title), 30);
            }
            if (isset($message->jobtitle) || is_null($message->jobtitle)) {
                $arrayContact["title"] = $this->truncateString(($message->jobtitle), 30);
            }
            if (isset($message->companyname) || is_null($message->companyname)) {
                $arrayContact["company"] = $this->truncateString(($message->companyname), 60);
            }
            if (isset($message->companyname)) {
                $company = $this->getCompanyId($message->companyname);
                $arrayContact["company"] = $this->truncateString(($company), 60);
            }
            if (isset($message->businessstreet) || is_null($message->businessstreet)) {
                $arrayContact["work_address"] = $this->truncateString(($message->businessstreet), 100);
            }
            if (isset($message->businesscity) || is_null($message->businesscity)) {
                $arrayContact["work_city"] = $this->truncateString(($message->businesscity), 30);
            }
            if (isset($message->businessstate) || is_null($message->businessstate)) {
                //$arrayContact["work_state"] = $this->truncateString(($message->businessstate),30);
                $arrayContact["work_country"] = $this->truncateString(($message->businessstate), 30);
            }
            if (isset($message->businesspostalcode) || is_null($message->businesspostalcode)) {
                $arrayContact["work_postalcode"] = $this->truncateString(($message->businesspostalcode), 20);
            }
            if (isset($message->businesscountry) || is_null($message->businesscountry)) {
                //$arrayContact["work_country"] = $this->truncateString(($message->businesscountry),30);
                $arrayContact["work_state"] = $this->truncateString(($message->businessstate), 30);
            }
            if (isset($message->businessphonenumber) || is_null($message->businessphonenumber)) {
                $arrayContact["work_telephone"] = $this->truncateString(($message->businessphonenumber), 50);
            }
            if (isset($message->businessfaxnumber) || is_null($message->businessfaxnumber)) {
                $arrayContact["work_fax"] = $this->truncateString(($message->businessfaxnumber), 50);
            }
            if (isset($message->business2phonenumber) || is_null($message->business2phonenumber)) {
                $arrayContact["work_telephone2"] = $this->truncateString(($message->business2phonenumber), 50);
            }
            if (isset($message->pagernumber) || is_null($message->pagernumber)) {
                $arrayContact["work_pager"] = $this->truncateString(($message->pagernumber), 50);
            }
            if (isset($message->email2address) || is_null($message->email2address)) {
                $lastmin = $this->lastIndexOf($message->email2address, "<");
                if ($lastmin !== -1) {
                    $lastmax = $this->lastIndexOf($message->email2address, ">");
                    $message->email2address = substr($message->email2address, $lastmin + 1, $lastmax);
                }
                $arrayContact["work_email"] = $this->truncateString(($message->email2address), 80);
            }
            if (isset($message->assistantname) || is_null($message->assistantname)) {
                $arrayContact["assistant"] = $this->truncateString(($message->assistantname), 30);
            }
            if (isset($message->homestreet) || is_null($message->homestreet)) {
                $arrayContact["home_address"] = $this->truncateString(($message->homestreet), 100);
            }
            if (isset($message->homecity) || is_null($message->homecity)) {
                $arrayContact["home_city"] = $this->truncateString(($message->homecity), 30);
            }
            if (isset($message->homestate) || is_null($message->homestate)) {
                //$arrayContact["home_state"] = $this->truncateString(($message->homestate),30);
                $arrayContact["home_country"] = $this->truncateString(($message->homestate), 30);
            }
            if (isset($message->homepostalcode) || is_null($message->homepostalcode)) {
                $arrayContact["home_postalcode"] = $this->truncateString(($message->homepostalcode), 20);
            }
            if (isset($message->homecountry) || is_null($message->homecountry)) {
                //$arrayContact["home_country"] = $this->truncateString(($message->homecountry),30);
                $arrayContact["home_state"] = $this->truncateString(($message->homecountry), 30);
            }
            if (isset($message->homephonenumber) || is_null($message->homephonenumber)) {
                $arrayContact["home_telephone"] = $this->truncateString(($message->homephonenumber), 50);
            }
            if (isset($message->homefaxnumber) || is_null($message->homefaxnumber)) {
                $arrayContact["home_fax"] = $this->truncateString(($message->homefaxnumber), 50);
            }
            if (isset($message->home2phonenumber) || is_null($message->home2phonenumber)) {
                $arrayContact["home_telephone2"] = $this->truncateString(($message->home2phonenumber), 50);
            }
            if (isset($message->mobilephonenumber) || is_null($message->mobilephonenumber)) {
                $arrayContact["work_mobile"] = $this->truncateString(($message->mobilephonenumber), 50);
                $arrayContact["home_mobile"] = $this->truncateString(($message->mobilephonenumber), 50);
            }
            if (isset($message->email1address) || is_null($message->email1address)) {
                $lastmin = $this->lastIndexOf($message->email1address, "<");
                if ($lastmin !== -1) {
                    $lastmax = $this->lastIndexOf($message->email1address, ">");
                    $message->email1address = substr($message->email1address, $lastmin + 1, $lastmax);
                }
                $arrayContact["home_email"] = $this->truncateString(($message->email1address), 80);
            }
            if (isset($message->assistnamephonenumber) || is_null($message->assistnamephonenumber)) {
                $arrayContact["assistant_telephone"] = $this->truncateString(($message->assistnamephonenumber), 50);
            }
            if (isset($message->spouse) || is_null($message->spouse)) {
                $arrayContact["partner"] = $this->truncateString(($message->spouse), 200);
            }
            /*$TODOarrayContact["anniversary"] = "";
            if (isset($message->anniversary)) {
                $anniversary = date('Y-m-d 00:00:00', $message->anniversary);
                $arrayContact["anniversary"] = $anniversary;
            }
            $arrayContact["birthday"] = "";
            if (isset($message->birthday)) {
                $birthday = date('Y-m-d 00:00:00', $message->birthday);
                $arrayContact["birthday"] = $birthday;
            }*/
            if (isset($message->department) || is_null($message->department)) {
                $arrayContact["department"] = $this->truncateString(($message->department), 200);
            }
            if (isset($message->webpage) || is_null($message->webpage)) {
                $arrayContact["url"] = $this->truncateString(($message->webpage), 200);
            }
            if (isset($message->managername) || is_null($message->managername)) {
                $arrayContact["manager"] = $this->truncateString(($message->managername), 200);
            }
            if (isset($message->imaddress) || is_null($message->imaddress)) {
                $arrayContact["work_im"] = $this->truncateString(($message->imaddress), 200);
            }
            if (isset($message->imaddress2) || is_null($message->imaddress2)) {
                $arrayContact["home_im"] = $this->truncateString(($message->imaddress2), 200);
            }
            if (isset($message->imaddress3) || is_null($message->imaddress3)) {
                $arrayContact["other_im"] = $this->truncateString(($message->imaddress3), 200);
            }
            if (isset($message->otherstreet) || is_null($message->otherstreet)) {
                $arrayContact["other_address"] = $this->truncateString(($message->otherstreet), 100);
            }
            if (isset($message->email3address) || is_null($message->email3address)) {
                $lastmin = $this->lastIndexOf($message->email3address, "<");
                if ($lastmin !== -1) {
                    $lastmax = $this->lastIndexOf($message->email3address, ">");
                    $message->email3address = substr($message->email3address, $lastmin + 1, $lastmax);
                }
                $arrayContact["other_email"] = $this->truncateString(($message->email3address), 80);
            }
            if (isset($message->othercity) || is_null($message->othercity)) {
                $arrayContact["other_city"] = $this->truncateString(($message->othercity), 30);
            }
            if (isset($message->otherstate) || is_null($message->otherstate)) {
                //$arrayContact["other_state"] = $this->truncateString(($message->otherstate),30);
                $arrayContact["other_country"] = $this->truncateString(($message->otherstate), 30);
            }
            if (isset($message->otherpostalcode) || is_null($message->otherpostalcode)) {
                $arrayContact["other_postalcode"] = $this->truncateString(($message->otherpostalcode), 20);
            }
            if (isset($message->othercountry) || is_null($message->othercountry)) {
                //$arrayContact["other_country"] = $this->truncateString(($message->othercountry),30);
                $arrayContact["other_state"] = $this->truncateString(($message->othercountry), 30);
            }
            if (isset($message->jobtitle) || is_null($message->jobtitle)) {
                $arrayContact["function"] = $this->truncateString(($message->jobtitle), 50);
            }
            if (Request::GetProtocolVersion() >= 12.0) {
				$arrayContact["notes"] = "";
                if (isset($message->body)) {
                    $arrayContact["notes"] = $this->truncateString($message->body, 1024);
                }
				if (isset($message->asbody->data)) {
					$body = stream_get_contents($message->asbody->data);
					fclose($message->asbody->data);
					if ($this->is_html($body)) {
						$content_text = $this->getTextBetweenTags($body);
						$arrayContact["notes"] = $this->truncateString($content_text[0], 1024);
					} else {
						$arrayContact["notes"] = $this->truncateString($body, 1024);
					}
				}
            } else {
				$arrayContact["notes"] = "";
                if (isset($message->body)) {
                    $arrayContact["notes"] = $this->truncateString($message->body, 1024);
                }
            }
			$result = pg_query($this->db, "BEGIN;");
            if ($result == FALSE)
                throw new Exception(pg_last_error($this->db));
            $arrayContact["revision_timestamp"] = "NOW()";
            if (!$found_id_contact) {        //inserimento nuovo contatto
                $arrayContact["revision_status"] = "N";
                $id = $this->getGlobalKey();
                $arrayContact["contact_id"] = $id;
				$arrayContact["public_uid"] = $this->buildContactUid($id, $this->getDomainInternetName($this->_domain));
				$arrayContact["href"] = $this->buildHref($arrayContact["public_uid"]);
				$arrayContact["category_id"] = $this->getCategoryId($folderid);
				$arrayContact["is_list"] = false;
				//ZLog::Write(LOGLEVEL_INFO, sprintf("WebTop: ChangeMessage: inserting contact (%s)", print_r($arrayContact, true)));
                $result = pg_insert($this->db, 'contacts.contacts', $arrayContact);
                if ($result == FALSE)
                    throw new Exception(pg_last_error($this->db));
            } else {                        //aggiornamento contatto
				//ZLog::Write(LOGLEVEL_INFO, sprintf("WebTop: ChangeMessage: updating contact (%s)", print_r($arrayContact, true)));
                $arrayContact["revision_status"] = "M";
                $result = pg_update($this->db, 'contacts.contacts', $arrayContact, array('contact_id' => $id_contact));
                if ($result == FALSE){
                    throw new Exception(pg_last_error($this->db));
                }
                $id = $id_contact;
            }
            $result = pg_query($this->db, "COMMIT;");
            if ($result == FALSE)
                throw new Exception(pg_last_error($this->db));
        } catch (Exception $e) {
            pg_query($this->db, "ROLLBACK;");
            return false;
        }
        return $this->StatMessage($folderid, $id);
    }

    function MoveMessage($folderid, $id, $newfolderid, $contentParameters) {
        return false;
    }

    public function ChangeFolder($folderid, $oldid, $displayname, $type) {
        return false;
    }

	function isShareReadOnly($sharedid) {
		$json_shared=$this->getJSONShared();
		foreach($json_shared as $share) {
			if ($share->categoryId==$sharedid && $share->readOnly) return true;
		}
		return false;
	}
	
    // Funzioni specifiche del servizio
    function IsReadOnly($folderid) {
		$result=FALSE;
		if ($this->isShared($folderid)) {
			$sharedid=$this->getSharedId($folderid);
			if ($sharedid!=null) {
				if ($this->isShareReadOnly($sharedid)) {
					//ZLog::Write(LOGLEVEL_INFO, sprintf("WebTop: isReadOnly: shared %s is read only", $folderid));
					return true;
				}
				$result = pg_query($this->db, "SELECT sync FROM contacts.categories WHERE sync = 'R' and category_id = " . $sharedid . ";");
			}
		} else {
			$result = pg_query($this->db, "SELECT sync FROM contacts.categories WHERE user_id='".$this->_username."' and domain_id='".$this->_domain."' and sync = 'R' and name = '" .$folderid . "';");
		}
		// Check readonly property
        if ($result == FALSE) {
            return false;
		}
        if ($row_calendar = pg_fetch_row($result)) {
			return true;
        }
		return false;
    }
		
	function getCategoryId($folderid) {
		$sql = "";
//ANDROID		if ($this->device_ios || $this->device_outlook) {
			if ($this->isShared($folderid)) {
				$sharedid=$this->getSharedId($folderid);
				$sql = "SELECT category_id FROM contacts.categories WHERE category_id = " . $sharedid;
			} else {
				$sql = "SELECT category_id FROM contacts.categories WHERE user_id='".$this->_username."' and domain_id='".$this->_domain."' and name = '" .$folderid . "'";
			}
//ANDROID		} else {
//ANDROID			$sql = "SELECT category_id FROM contacts.categories WHERE user_id='".$this->_username."' and domain_id='".$this->_domain."' and built_in = true";
//ANDROID		}
		$result_cid = pg_query($this->db, $sql);
        if ($result_cid == FALSE)
            throw new Exception(pg_last_error($this->db));
        while ($row_cid = pg_fetch_row($result_cid)) {
            if (isset($row_cid[0])) {
                return $row_cid[0];
            }
        }
		return null;
    }

    function getGlobalKey() {
        $result_contact = pg_query($this->db, ("SELECT nextval('contacts.SEQ_CONTACTS') ;"));
        if ($result_contact == FALSE)
            throw new Exception(pg_last_error($this->db));
        while ($row_contact = pg_fetch_row($result_contact)) {
            if (isset($row_contact[0])) {
                return $row_contact[0];
            }
        }
    }
	
    function getCompanyName($company_id) {
        $company = $company_id;
        try{
	    	$c_array = array();
	        array_push($c_array,$company);
        	$result_user = pg_query_params($this->db, "select description from core.master_data where master_data_id=$1", $c_array);
        	if ($result_user == FALSE)
            	new Exception(pg_last_error($this->db));
        	while ($row_dom = pg_fetch_row($result_user)) {
            	if (isset($row_dom[0])) {
               		 $company = $row_dom[0];
            	}
        	}
        } catch (Exception $e) { 
        }
        return $company;
    }

    function getCompanyId($company_name) {
        $company = $company_name;
        try{
	        $c_array = array();
	        array_push($c_array,$company);
 		  	$result_user = pg_query_params($this->db, "select master_data_id from core.master_data where description=$1", $c_array);
        	if ($result_user == FALSE)
            	new Exception(pg_last_error($this->db));
        	while ($row_dom = pg_fetch_row($result_user)) {
            	if (isset($row_dom[0])) {
                	$company = $row_dom[0];
            	}
        	}
        } catch (Exception $e) {
        }
        return $company;
    }

    function lastIndexOf($string, $item) {
        $index = strpos(strrev($string), strrev($item));
        if ($index) {
            $index = strlen($string) - strlen($item) - $index;
            return $index;
        } else
            return -1;
    }

    // Fine funzioni specifiche del servizio

    /*     * ************************GAL************************** */

    /**
     * Indicates if a search type is supported by this SearchProvider
     * Currently only the type ISearchProvider::SEARCH_GAL (Global Address List) is implemented
     *
     * @param string        $searchtype
     *
     * @access public
     * @return boolean
     */
    public function SupportsType($searchtype) {
        return ($searchtype == ISearchProvider::SEARCH_GAL);
    }

    /*
     * Queries the LDAP backend
     *
     * @param string        $searchquery        string to be searched for
     * @param string        $searchrange        specified searchrange
     *
     * @access public
     * @return array        search results
     */

    public function GetGALSearchResults($searchquery, $searchrange) {
        global $gal_field_map;
        $username = Request::GetAuthUser();
        $domain = Request::GetAuthDomain();
        $this->_username = $username;
        if ($domain != '') {
            $this->_domain = $domain;
        } else {
            $et = strrpos($username, "@");
            if ($et != false) {
                $this->_domain = $this->getDomain($username);
                //$this->_auth_uri = $this->getAuth_Uri($this->_domain);
                $this->_auth_type = $this->getAutheticationType($this->_auth_uri);
                $this->_username=$this->getLogin($username,$this->_domain);
            } else {
                $this->_domain = $this->getDefaultDomain();
            }
        }
        $items = array();
        $like_search = "";
        $groupname = $this->getWorkgroups();
        if (is_array($groupname)) {
            $first = true;
            foreach ($groupname as $value) {
                if (!$first)
                    $like_search.=",";
                $like_search.="'" . $value . "'";
                $first = false;
            }
        }
        if ($like_search != "") {
            $result_user = pg_query($this->db,
				"select contact_id,"
					. "firstname,"
					. "lastname,"
					. "title,"
					. "COALESCE((select description from core.master_data where master_data_id=company),company) as company,"
					. "home_mobile,"
					. "work_mobile,"
					. "work_email "
				. "from contacts.contacts "
				. "where revision_status!='D' "
                . "and category_id IN (SELECT category_id FROM contacts.categories WHERE user_id='".$this->_username."' and domain_id='".$this->_domain."' and sync in ('R','W') "
				. "and (firstname ilike '%".$searchquery."%' or lastname ilike '%".$searchquery."%' or company ilike '%".$searchquery."%');"
			);
            if ($result_user == FALSE)
                return false;
            $rc = 0;
            while ($row_dom = pg_fetch_assoc($result_user)) {
                foreach ($gal_field_map as $key => $value) {
                    if (isset($row_dom[$value])) {
						$items[$rc][$key] = $row_dom[$value];
                    }
                }
                $rc++;
            }
            return $items;
        }
        return false;
    }

    /**
     * Terminates a search for a given PID
     *
     * @param int $pid
     *
     * @return boolean
     */
    public function TerminateSearch($pid) {
        return true;
    }

    /**
     * Searches for the emails on the server
     *
     * @param ContentParameter $cpo
     *
     * @return array
     */
    public function GetMailboxSearchResults($cpo) {
        return array();
    }

    /**
     * Disconnects from LDAP
     *
     * @access public
     * @return boolean
     */
    public function Disconnect() {
        return true;
    }

    function getWorkgroups() {
        $groupname = array();
        /*$result_user = pg_query($this->db, ("select groupname from workgroups where contacts!='F' and login='" . $this->_username . "' and iddomain='" . $this->_domain . "';"));
        if ($result_user == FALSE)
            return false;
        while ($row_dom = pg_fetch_row($result_user)) {
            if (isset($row_dom[0])) {
                array_push($groupname, $row_dom[0]);
            }
        }*/
        return $groupname;
    }

	private function buildContactUid($contactId, $internetName) {
		$s = uniqid() . "." . strval($contactId);
		return md5($s) . "@" . $internetName;
	}
	
	private function buildHref($publicUid) {
		return $publicUid . ".vcf";
	}
}

;
?>
