<?php
/* * *********************************************
 * File      :   tasks.php
 * Project   :   Z-Push
 * Descr     :   This Tasks Backend is for WebTop Groupware.
 *
 * Created   :   29.09.2010 - emerson-faria.nobre@serpro.gov.br
 *
 * ??? Zarafa Deutschland GmbH, www.zarafaserver.de
 * This file is distributed under GPL v2.
 * Consult LICENSE file for details
 * ********************************************** */
require_once("backend/webtop/webtop.php");
require_once("backend/webtoptasks/tasks_config.php");
require_once("backend/webtop/z_RTF.php");
require_once("backend/webtop/dbconnect.php");

class BackendWebTopTasks extends BackendWebtop {

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
            if ($this->device_ios || $this->device_outlook) {
                $cat = "AND category_id IN (SELECT category_id FROM tasks.categories WHERE user_id='".$this->_username."' and domain_id='".$this->_domain."' and sync in ('R','W') and name = '".$folderid."') ";
            } else {
                $cat = "AND category_id IN (SELECT category_id FROM tasks.categories WHERE user_id='".$this->_username."' and domain_id='".$this->_domain."' and sync in ('R','W')) ";
			}
            $result = pg_query($this->db, "select task_id, revision_timestamp from tasks.tasks where revision_status!='D' ".$cat.";");
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

    function GetFolderList() {
        $tasks = array();
        $this->folders_sync = array();

        if ($this->device_ios || $this->device_outlook) {
			$result = pg_query($this->db, "select distinct name from tasks.categories where user_id = '".$this->_username."' and domain_id='".$this->_domain."' and sync in ('R','W');");
            if ($result == FALSE)
                throw new Exception(pg_last_error($this->db));
            while ($row = pg_fetch_row($result)) {
                if (isset($row[0])) {
                    array_push($this->folders_sync, $row[0]);
                    $folder = $this->StatFolder($row[0]);
                    array_push($tasks, $folder);
                }
            }
        } else {
            array_push($this->folders_sync, "WebTop");
            $folder = $this->StatFolder("WebTop");
            array_push($tasks, $folder);
        }
        return $tasks;
    }

    function GetFolder($id) {
        if (in_array($id, $this->folders_sync)) {
            $folder = new SyncFolder();
            $folder->serverid = $id;
            $folder->parentid = "0";
            $folder->displayname = $id;
			if ($this->device_ios || $this->device_outlook) {
                $folder->type = SYNC_FOLDER_TYPE_USER_TASK;
            } else {
				$folder->type = SYNC_FOLDER_TYPE_TASK;
                $folder->displayname = $this->_username;
            }
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
        if (!empty($this->folders_sync) && in_array($folderid, $this->folders_sync) !== TRUE)
            return false;

		try {
            $result = pg_query($this->db, "BEGIN;");
            if ($result == FALSE)
                throw new Exception(pg_last_error($this->db));
			$cat = "";
			if ($this->device_ios || $this->device_outlook) {
                $cat = "AND category_id IN (SELECT category_id FROM tasks.categories WHERE user_id='".$this->_username."' and domain_id='".$this->_domain."' and sync in ('R','W') and name = '".$folderid."') ";
            } else {
                $cat = "AND category_id IN (SELECT category_id FROM tasks.categories WHERE user_id='".$this->_username."' and domain_id='".$this->_domain."' and sync in ('R','W')) ";
			}
            $result_contact = pg_query($this->db, "select revision_timestamp from tasks.tasks where task_id = ".$id." and revision_status!='D' ".$cat." ;");
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
        if (!empty($this->folders_sync) && !in_array($folderid, $this->folders_sync))
            return;
        // Parse the database into object
        $message = new SyncTask();
        try {
            $result = pg_query($this->db, "BEGIN;");
            if ($result == FALSE)
                throw new Exception(pg_last_error($this->db));
            $result_task = pg_query(
				$this->db, 
				"select "
					."task_id, "
					."subject, "
					."description, "
					."importance, "
					."(select name from tasks.categories where categories.category_id = tasks.category_id) as name, "
					."is_private, "
					."completion_percentage,"
					."status,"
					."start_date,"
					."due_date,"
					."revision_timestamp "
				."from tasks.tasks "
				."where task_id = ".$id." "
				."and revision_status!='D'; "
			);
            if ($result_task == FALSE)
                throw new Exception(pg_last_error($this->db));
            $dateTimeZone = new DateTimeZone(date_default_timezone_get());
            $dateTime = new DateTime("now", $dateTimeZone);
            $timeOffset = $dateTimeZone->getOffset($dateTime);
            while ($row_task = pg_fetch_row($result_task)) {
                $message->fileas = $id;
                if (isset($row_task[1])) {
                    $message->subject = $row_task[1];
                }
                if (isset($row_task[2])) {
					$body = str_replace("\n","\r\n", str_replace("\r","",$row_task[2]));
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
                if (isset($row_task[3])) {
					$message->importance = $row_task[3];
                }
                if (isset($row_event[4])) {
                    if (!isset($message->categories))
                        $message->categories = array();
                    array_push($message->categories, $row_event[4]);
                }
                if (isset($row_task[5])) {
                    if ($row_task[5] == TRUE)
                        $message->sensitivity = 2;
                    else
                        $message->sensitivity = 0;
                }
                if (isset($row_task[7])) {
					if ($row_task[7] == "completed")
						$message->complete = 1;
					else
						$message->complete = 0;
                }
                if (isset($row_task[8])) {
                    $message->utcstartdate = strtotime($row_task[8] . " Europe/Rome");
                    $message->startdate = strtotime($row_task[8] . " UTC");
                }
                if (isset($row_task[9])) {
                    $message->utcduedate = strtotime($row_task[9] . " Europe/Rome");
                    $message->duedate = strtotime($row_task[9] . " UTC");
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
            $result = pg_query($this->db, "select task_id from tasks.tasks where task_id = ".$id." and revision_status!='D';");
            if ($result == FALSE)
                throw new Exception(pg_last_error($this->db));
            while ($row_task = pg_fetch_row($result)) {
                if (isset($row_task[0])) {
                    $id_task = $row_task[0];
                }
            }
            if (!isset($id_task)) {
                return true;
            }
            $arrayTask = array();
            $arrayTask["revision_status"] = "D";
            $arrayTask["revision_timestamp"] = "NOW()";
            $result = pg_update($this->db, "tasks.tasks", $arrayTask, array('task_id' => $id_task));
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
		if ($this->IsReadOnly($folderid)) {
			return false;
		}
        try {
            $result = pg_query($this->db, "BEGIN;");
            if ($result == FALSE)
                throw new Exception(pg_last_error($this->db));
            $found_id_task = false;
            if (trim($id) !== "") {     //determina se inserimento o aggiornamento
                $result_task = pg_query($this->db, "select task_id from tasks.tasks where task_id = ".$id." and revision_status!='D';");
                if ($result_task == FALSE)
                    throw new Exception(pg_last_error($this->db));
                while ($row_task = pg_fetch_row($result_task)) {
                    if (isset($row_task[0])) {
                        $id_task = $row_task[0];
                        $found_id_task = true;
                    }
                }
            }
            if (isset($message->subject)) {
                $arrayTask["subject"] = $this->truncateString(($message->subject), 100);
            }
            if (Request::GetProtocolVersion() >= 12.0) {
				$arrayTask["description"] = "";
                if (isset($message->body)) {
                    $arrayTask["description"] = $this->truncateString($message->body, 1024);
                }
				if (isset($message->asbody->data)) {
					$body = stream_get_contents($message->asbody->data);
					fclose($message->asbody->data);
					if ($this->is_html($body)) {
						$content_text = $this->getTextBetweenTags($body);
						$arrayTask["description"] = $this->truncateString($content_text[0], 1024);
					} else {
						$arrayTask["description"] = $this->truncateString($body, 1024);
					}
				}
            } else {
				$arrayTask["description"] = "";
                if (isset($message->body)) {
                    $arrayTask["description"] = $this->truncateString($message->body, 1024);
                }
            }
            if (isset($message->sensitivity)) {
                if ($message->sensitivity == 2)
                    $arrayTask["is_private"] = true;
                else
                    $arrayTask["is_private"] = false;
            }
            if (isset($message->importance)) {
				$arrayTask["importance"] = $message->importance;
            }
            if (isset($message->complete)) {
				if ($message->complete == 1)
                    $arrayTask["status"] = "completed";
				else
                    $arrayTask["status"] = "inprogress";
            }
            if (isset($message->startdate)) {
                $startdate = date("Y-m-d", $message->startdate);
                $arrayTask["start_date"] = $startdate;
            }
            if (isset($message->duedate)) {
                $duedate = date("Y-m-d", $message->duedate);
                $arrayTask["due_date"] = $duedate;
            }
			$arrayTask["revision_timestamp"] = "NOW()";
            if (!$found_id_task) { //inserimento 
				$arrayTask["category_id"] = $this->getCategoryId($folderid);
                $arrayTask["revision_status"] = "N";
                $id = $this->getGlobalKey();
                $arrayTask["task_id"] = $id;
				$arrayTask["public_uid"] = uniqid();
				$arrayTask["completion_percentage"]=0;
                $result = pg_insert($this->db, 'tasks.tasks', $arrayTask);
                if ($result == FALSE)
                    throw new Exception(pg_last_error($this->db));
            } else { //aggiornamento 
                $arrayTask["revision_status"] = "M";
                $id = $id_task;
                $result = pg_update($this->db, 'tasks.tasks', $arrayTask, array('task_id' => $id_task));
                if ($result == FALSE)
                    throw new Exception(pg_last_error($this->db));
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

    // Funzioni specifiche del servizio
    function IsReadOnly($folderid) {
		// Check readonly property
        $result = pg_query($this->db, "SELECT sync FROM tasks.categories WHERE user_id='".$this->_username."' and domain_id='".$this->_domain."' and sync = 'R' and name = '" .$folderid . "';");
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
		if ($this->device_ios || $this->device_outlook) {
			$sql = "SELECT category_id FROM tasks.categories WHERE user_id='".$this->_username."' and domain_id='".$this->_domain."' and name = '" .$folderid . "'";
		} else {
			$sql = "SELECT category_id FROM tasks.categories WHERE user_id='".$this->_username."' and domain_id='".$this->_domain."' and built_in = true";
		}
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
        $result_contact = pg_query($this->db, ("SELECT nextval('tasks.SEQ_TASKS') ;"));
        if ($result_contact == FALSE)
            throw new Exception(pg_last_error($this->db));
        while ($row_contact = pg_fetch_row($result_contact)) {
            if (isset($row_contact[0])) {
                return $row_contact[0];
            }
        }
    }
    // Fine funzioni specifiche del servizio

	
}

;
?>
