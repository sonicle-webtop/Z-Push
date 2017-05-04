<?php

/* * *********************************************
 * File      :   calendar.php
 * Project   :   Z-Push
 * Descr     :   This Calendar Backend is for WebTop Groupware.
 *
 * Created   :   29.09.2010 - emerson-faria.nobre@serpro.gov.br
 *
 * ? Zarafa Deutschland GmbH, www.zarafaserver.de
 * This file is distributed under GPL v2.
 * Consult LICENSE file for details
 * ********************************************** */
require_once("backend/webtop/webtop.php");
require_once("backend/webtopcalendar/calendar_config.php");
require_once("backend/webtop/z_RTF.php");
require_once("backend/webtop/dbconnect.php");

class BackendWebTopCalendar extends BackendWebtop {

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
            $todate = "";
            $untildate = "";
            if (isset($cutoffdate)) {
                $todate = date("Y-m-d H:i:s", $cutoffdate);
                $untildate = date("Y-m-d H:i:s", $cutoffdate);
                $untildate = " AND until_date >= '" . $untildate . "' ";
                $todate = " AND end_date >= '" . $todate . "' ";
            }
			$cat = "";
            if ($this->device_ios || $this->device_outlook) {
                $cat = "AND calendar_id IN (SELECT calendar_id FROM calendar.calendars WHERE user_id='".$this->_username."' and domain_id='".$this->_domain."' and sync in ('R','W') and name = '" .$folderid . "') ";
            } else {
                $cat = "AND calendar_id IN (SELECT calendar_id FROM calendar.calendars WHERE user_id='".$this->_username."' and domain_id='".$this->_domain."' and sync in ('R','W')) ";
			}
            $result = pg_query($this->db, 
					" SELECT EVENTS.event_id,revision_timestamp "
                    . "FROM calendar.EVENTS "
                    . "WHERE revision_status != 'D' "
                    . "AND read_only is false "
                    . "AND recurrence_id IS NULL "
                    . $todate
                    . $cat
                    . "UNION "
                    ."SELECT EVENTS.event_id,revision_timestamp "
                    . "FROM calendar.RECURRENCES,calendar.EVENTS "
                    . "WHERE revision_status != 'D' "
                    . "AND read_only is false "
                    . "AND EVENTS.recurrence_id = RECURRENCES.recurrence_id "
                    . $untildate
                    . $cat . ";");
            if ($result == FALSE)
                throw new Exception(pg_last_error($this->db));
            while ($row = pg_fetch_row($result)) {
                $message = array();
                $message["id"] = $row[0];
                $broken_event = $this->isBrokenEvent($row[0]);
                if ($broken_event == TRUE) {
                    continue;
                }
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
        $calendar = array();
        $this->folders_sync = array();
		
        if ($this->device_ios || $this->device_outlook) {
            $result = pg_query($this->db, "select distinct name from calendar.calendars where user_id = '" . $this->_username . "' and domain_id='" . $this->_domain . "' and sync in ('R','W');");
            if ($result == FALSE)
                throw new Exception(pg_last_error($this->db));
            while ($row = pg_fetch_row($result)) {
                if (isset($row[0])) {
                    array_push($this->folders_sync, $row[0]);
                    $folder = $this->StatFolder($row[0]);
                    array_push($calendar, $folder);
                }
            }
        } else {
            array_push($this->folders_sync, "WebTop");
            $folder = $this->StatFolder("WebTop");
            array_push($calendar, $folder);
        }
        return $calendar;
    }

    function GetFolder($id) {
        if (in_array($id, $this->folders_sync)) {
            $folder = new SyncFolder();
            $folder->serverid = $id;
			$folder->parentid = "0";
            $folder->displayname = $id;
			if ($this->device_ios || $this->device_outlook) {
                $folder->type = SYNC_FOLDER_TYPE_USER_APPOINTMENT;
            } else {
                $folder->type = SYNC_FOLDER_TYPE_APPOINTMENT;
                $folder->displayname = $this->_username;
            }
            return $folder;
        } else 
            return null;
    }

    function StatFolder($id) {  
        $folder = $this->GetFolder($id);
        $stat = array();
        if ($folder != null) {
			$stat["id"] = $id;
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
			$folder = $folderid;
			$cat = "";
            if ($this->device_ios || $this->device_outlook) {
                $cat = "AND calendar_id IN (SELECT calendar_id FROM calendar.calendars WHERE user_id='".$this->_username."' and domain_id='".$this->_domain."' and sync in ('R','W') and name = '".$folderid."') ";
            } else {
                $cat = "AND calendar_id IN (SELECT calendar_id FROM calendar.calendars WHERE user_id='".$this->_username."' and domain_id='".$this->_domain."' and sync in ('R','W')) ";
			}
            $result_events = pg_query($this->db, 
				"select revision_timestamp "
				."from calendar.events "
				."where event_id = " . $id . " "
				.$cat
				."and 0=("
					."select count(*) "
					."from calendar.recurrences_broken "
					."where new_event_id= ".$id.""
				. ") ;"
				);
            if ($result_events == FALSE)
                throw new Exception(pg_last_error($this->db));
            while ($row_events = pg_fetch_row($result_events)) {
                if (isset($row_events[0])) {
                    $message = array();
                    $message["mod"] = substr($row_events[0], 0, strlen($row_events[0]));
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
        $message = new SyncAppointment();
        try {
            $result = pg_query($this->db, "BEGIN;");
            if ($result == FALSE)
                throw new Exception(pg_last_error($this->db));
            $result = pg_query($this->db, 
				" select event_id,"
				. "title,"
				. "revision_timestamp,"
				. "start_date,"
				. "end_date,"
				. "description,"
				. "location,"
				. "is_private,"
				. "reminder,"
				. "all_day,"
				. "busy,"
				. "timezone,"
				. "recurrence_id,"
				. "exists(select event_id from calendar.recurrences_broken where event_id = " . $id . ") as broken "
				. "from calendar.events "
				. "where event_id = " . $id . " "
				. "and revision_status!='D';"
			);
            if ($result == FALSE)
                throw new Exception(pg_last_error($this->db));
            $dateTimeZone = new DateTimeZone(date_default_timezone_get());
            $dateTime = new DateTime("now", $dateTimeZone);
            $timeOffset = $dateTimeZone->getOffset($dateTime);
            if ($row_event = pg_fetch_row($result)) {
                $message->fileas = $id;
                $message->organizeremail = $this->getEmail();
                $message->meetingstatus = 0;
                if (isset($row_event[1])) {
                    $message->subject = $row_event[1];
                }
                if (isset($row_event[2])) {
                    $dtstamp = strtotime($row_event[2]);
                    $message->dtstamp = $dtstamp;
                }
                if (isset($row_event[3])) {
                    $fromtime = strtotime($row_event[3]);
                    $message->starttime = $fromtime;
                }
                if (isset($row_event[4])) {
                    $totime = strtotime($row_event[4]);
                    $message->endtime = $totime;
                }
                if (isset($row_event[5])) {
					$body = str_replace("\n","\r\n", str_replace("\r","",$row_event[5]));
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
                if (isset($row_event[6])) {
                    $message->location = $row_event[6];
                }
                if (isset($row_event[7])) {
                    if ($row_event[7] == "t")
                        $message->sensitivity = 2;    //privato = 2
                    else
                        $message->sensitivity = 0;    //normale = 0
                }
                if (isset($row_event[8])) {
                    $message->reminder = $row_event[8];
                }
                if (isset($row_event[9])) {
                    if ($row_event[9] == "t") {
                        $message->alldayevent = 1;
                        $message->starttime = $this->convertTimeAllDay($row_event[3], true, "00", "00", "");
                        $message->endtime = $this->convertTimeAllDay($row_event[4], false, "00", "00", "+");
                    } else
                        $message->alldayevent = 0;

                }
                if (isset($row_event[10])) {
                    if ($row_event[10] == "t")
                        $message->busystatus = 2;      //occupato=2
                    else
                        $message->busystatus = 0;      //libero=0
                }
                if (isset($row_event[11])) {
                    $tzObject = TimezoneUtil::GetFullTZ($row_event[11]);
                } else {
                    $tzObject = $this->getDefaultTimeZone();
                }
                $message->timezone = base64_encode($this->GetTzSyncBlob($tzObject));
				// Ricorrenza
                if (isset($row_event[12]) && $row_event[12] != "") {   //ricorrenza
                    $result = pg_query($this->db, 
						"select "
							."type,"
							."daily_freq,"
							."weekly_freq," 
							."monthly_day,"
							."monthly_freq,"
							."yearly_day,"
							."yearly_freq,"
							."until_date,"
							."weekly_day_1,"
							."weekly_day_2,"
							."weekly_day_3,"
							."weekly_day_4,"
							."weekly_day_5,"
							."weekly_day_6,"
							."weekly_day_7,"
							."permanent,"
							."repeat,"
							."start_date "
                        ."from calendar.recurrences "
						."where recurrence_id='".$row_event[12]."' ;");
                    if ($result == FALSE)
                        throw new Exception(pg_last_error($this->db));
                    if ($row = pg_fetch_row($result)) {
                        $recur = new SyncRecurrence();
                        $rec_type = $row[0];
                        switch ($rec_type) {
                            case "D":
                                $recur->type = 0;
                                break;
                            case "W":
                                $recur->type = 1;
                                break;
                            case "M":
                                $recur->type = 2;
                                break;
                            case "Y":
                                $recur->type = 5;
                                break;
                        }
                        if ($rec_type == "W") {
                            $dayofweek = 0;
                            if (isset($row[8]) && $row[8] == "true")
                                $dayofweek+=2;
                            if (isset($row[9]) && $row[9] == "true")
                                $dayofweek+=4;
                            if (isset($row[10]) && $row[10] == "true")
                                $dayofweek+=8;
                            if (isset($row[11]) && $row[11] == "true")
                                $dayofweek+=16;
                            if (isset($row[12]) && $row[12] == "true")
                                $dayofweek+=32;
                            if (isset($row[13]) && $row[13] == "true")
                                $dayofweek+=64;
                            if (isset($row[14]) && $row[14] == "true")
                                $dayofweek+=1;
                            $recur->dayofweek = $dayofweek;
                        }
                        if ($rec_type == "M") {
                            if (isset($row[3]))
                                $recur->dayofmonth = $row[3];
                        }
                        if ($rec_type == "Y") {
                            if (isset($row[5])) {
                                $recur->dayofmonth = $row[5];
                            }
                            if (isset($row[6])) {
                                $recur->monthofyear = $row[6];
                            }
                        }
                        if (isset($row[7]) && (isset($row[15]) && $row[15] == "false")) {
                            $until = strtotime($row[7] . " +1 days");
                            $recur->until = $until;
                            //$recur->until = $until + $timeOffset;
                        }
                        if (isset($row[16]) && $row[16] != "0") {
                            $recur->occurrences = $row[16];
                        }
                        $message->recurrence = $recur;
                    }
                }
                if (isset($row_event[13]) && $row_event[13] == "t" && $row_event[12] != "") {
                    $result_rec_broken = pg_query($this->db, "select new_event_id,event_date from calendar.recurrences_broken where recurrence_id=".$row_event[12]);
                    while ($row_events = pg_fetch_row($result_rec_broken)) {
                        if (isset($row_events[0])) {        //ricorrenza spezzata da evento
                            $events_exc = $this->getEvent($row_events[0], $folderid);
                            if (!isset($message->exceptions))
                                $message->exceptions = array();
                            array_push($message->exceptions, $events_exc);
                        }else {
                            if (isset($row_events[1])) {        //ricorrenza spezzata da evento cancellato
                                $recur_deleted = new SyncAppointmentException();
                                $recur_deleted->deleted = 1;
                                $recur_deleted->exceptionstarttime = strtotime($row_events[1]);
                                $hour = date('H', $message->starttime);
                                $min = date('i', $message->starttime);
                                $recur_deleted->exceptionstarttime = strtotime("+$hour hours", $recur_deleted->exceptionstarttime);
                                $recur_deleted->exceptionstarttime = strtotime("+$min minutes", $recur_deleted->exceptionstarttime);
                                if (!isset($message->exceptions))
                                    $message->exceptions = array();
                                array_push($message->exceptions, $recur_deleted);
                            }
                        }
                    }
                }
                $result_planning = pg_query($this->db, "select recipient,response_status from calendar.events_attendees where recipient is not null and event_id=".$id);
                while ($row_planning = pg_fetch_row($result_planning)) {
					if (!isset($message->attendees))
						$message->attendees = array();
                    $attendee = new SyncAttendee();
					$message->meetingstatus = 1;
                    if (isset($row_planning[0])) {
                        $attendee->name = $row_planning[0];
                        $attendee->email = $row_planning[0];
                    }
					$message->attendeestatus = 0;
                    if (isset($row_planning[1])) {
						if ($row_planning[1] == "NA") {
							$message->attendeestatus = 5;
						} else if ($row_planning[1] == "AC") {
							$message->attendeestatus = 3;
						} else if ($row_planning[1] == "TE") {
							$message->attendeestatus = 2;
						} else if ($row_planning[1] == "DE") {
							$message->attendeestatus = 4;
						}
					}
					array_push($message->attendees, $attendee);
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
            $result_events = pg_query($this->db, "select event_id from calendar.events where event_id = " . $id . " and revision_status!='D';");
            if ($result_events == FALSE)
                throw new Exception(pg_last_error($this->db));
            while ($row_event = pg_fetch_row($result_events)) {
                if (isset($row_event[0])) {
                    $event_id = $row_event[0];
                }
            }
            //elimino eventuali eventi associati a ricorrenze spezzate
            $result = pg_query($this->db, "select new_event_id from calendar.recurrences_broken where event_id = " . $id);
            if ($result == FALSE)
                throw new Exception(pg_last_error($this->db));
            while ($row_event_broken = pg_fetch_row($result)) {
                if (isset($row_event_broken[0])) {
                    $new_event_id = $row_event_broken[0];
                    $arrayEventsBroken = array();
                    $arrayEventsBroken["revision_status"] = "D";
                    $arrayEventsBroken["revision_timestamp"] = "NOW()";
                    $result = pg_update($this->db, "calendar.events", $arrayEventsBroken, array('event_id' => $new_event_id));
                }
            }
            if (!isset($event_id)) {
                return true;
            }
            $arrayEvents = array();
            $arrayEvents["revision_status"] = "D";
            $arrayEvents["revision_timestamp"] = "NOW()";
            $result = pg_update($this->db, "calendar.events", $arrayEvents, array('event_id' => $event_id));
            $result = pg_query($this->db, "COMMIT;");
            if ($result == FALSE)
                throw new Exception(pg_last_error($this->db));
            $result_events = pg_query($this->db, "update calendar.events set revision_timestamp=now() where event_id=(select event_id from calendar.recurrences_broken where new_event_id=" . $id . ")");
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
            $found_id_event = false;
            $found_id_recurrence = false;
            if (trim($id) !== "") {     //determina se inserimento o aggiornamento
                $result_events = pg_query($this->db, "select event_id,recurrence_id from calendar.events where event_id = ".$id." and revision_status!='D';");
                if ($result_events == FALSE)
                    throw new Exception(pg_last_error($this->db));
                while ($row_event = pg_fetch_row($result_events)) {
                    if (isset($row_event[0])) {
                        $event_id = $row_event[0];
                        $found_id_event = true;
                    }
                    if (isset($row_event[1])) {  //ricorrenza
                        $recurr_id = $row_event[1];
                        $found_id_recurrence = true;
                    }
                }
            }
            $dateTimeZone = new DateTimeZone(date_default_timezone_get());
            $dateTime = new DateTime("now", $dateTimeZone);
            $timeOffset = $dateTimeZone->getOffset($dateTime);
            if (isset($message->subject)) {
                $arrayEvent["title"] = $this->truncateString(($message->subject), 100);
            }
            if (isset($message->dtstamp)) {
                $dtstamp = $message->dtstamp;
                $dtstamp = gmdate("Y-m-d 00:00:00", $dtstamp);
            }
            if (isset($message->starttime)) {
				$starttime = $message->starttime + $timeOffset;
                $fromtime = gmdate("Y-m-d H:i:s", $starttime);
                $arrayEvent["start_date"] = $fromtime;
            }
            if (isset($message->endtime)) {
                $totime = $message->endtime + $timeOffset;
                $totime = gmdate("Y-m-d H:i:s", $totime);
                $arrayEvent["end_date"] = $totime;
            }
            if (isset($message->alldayevent)) {
                $allday = "false";
                if ($message->alldayevent == 1) {
                    $allday = "true";
                    $startt = date('Y-m-d H:i:s', $message->starttime);
                    $endt = date('Y-m-d H:i:s', $message->endtime);
                    $arrayEvent["start_date"] = date('Y-m-d H:i:s', $this->convertTimeAllDay($startt, true, "08", "00", ""));
                    $arrayEvent["end_date"] = date('Y-m-d H:i:s', $this->convertTimeAllDay($endt, false, "18", "00", "-"));
                }
                $arrayEvent["all_day"] = $allday;
			}
			$busy = false;
            if (isset($message->busystatus)) {
                $busy = false;
                if ($message->busystatus == 2)
                    $busy = true;
            }
			$arrayEvent["busy"] = $busy;
			
            if (Request::GetProtocolVersion() >= 12.0) {
				$arrayEvent["description"] = "";
                if (isset($message->body)) {
                    $arrayEvent["description"] = $this->truncateString($message->body, 1024);
                }
				if (isset($message->asbody->data)) {
					$body = stream_get_contents($message->asbody->data);
					fclose($message->asbody->data);
					if ($this->is_html($body)) {
						$content_text = $this->getTextBetweenTags($body);
						$arrayEvent["description"] = $this->truncateString($content_text[0], 1024);
					} else {
						$arrayEvent["description"] = $this->truncateString($body, 1024);
					}
				}
            } else {
				$arrayEvent["description"] = "";
                if (isset($message->body)) {
                    $arrayEvent["description"] = $this->truncateString($message->body, 1024);
                }
            }
            if (isset($message->timezone)) {
                $tzn = $this->getTimezone($message->timezone);
                $arrayEvent["timezone"] = $tzn;
            }
			$arrayEvent["location"] = "";
            if (isset($message->location) || is_null($message->location)) {
                $arrayEvent["location"] = $this->truncateString(($message->location), 100);
            }            
			$arrayEvent["reminder"] = "";
            if (isset($message->reminder)) {
                $arrayEvent["reminder"] = $message->reminder;
			}
			$arrayEvent["is_private"] = false;
            if (isset($message->sensitivity)) {
                if ($message->sensitivity == 2)
                    $arrayEvent["is_private"] = true;
				else
                    $arrayEvent["is_private"] = false;
            }
            if (!$found_id_event) {
                $arrayEvent["calendar_id"] = $this->getCategoryId($folderid,$this->_username,$this->_domain);
            }
            $arrayEvent["revision_timestamp"] = "NOW()";
            if (!$found_id_event) {
                $arrayEvent["revision_status"] = "N";
                $id = $this->getGlobalKey();
                $arrayEvent["event_id"] = $id;
				$arrayEvent["public_uid"] = uniqid();
				$arrayEvent["organizer"] = $this->_emaillogin;
				$arrayEvent["read_only"] = false;
                $result = pg_insert($this->db, 'calendar.events', $arrayEvent);
                if ($result == FALSE)
                    throw new Exception(pg_last_error($this->db));
            } else {                        //aggiornamento
                $arrayEvent["revision_status"] = "M";
				$result = pg_update($this->db, 'calendar.events', $arrayEvent, array('event_id' => $event_id));
                if ($result == FALSE)
                    throw new Exception(pg_last_error($this->db));
            }
            //planning events
            if (isset($message->attendees)) {
                $e_id = $id;
                if ($found_id_event)
                    $e_id = $event_id;
                $this->deleteAttendees($e_id);
                foreach ($message->attendees as $attendee) {
                    $arrayAttendees["attendee_id"] = $this->getAttendeeId();
                    $arrayAttendees["event_id"] = $e_id;
                    $arrayAttendees["notify"] = false;
                    $arrayAttendees["recipient_type"] = "IND";
                    if (isset($attendee->email))
                        $arrayAttendees["recipient"] = $attendee->email;
                    if (isset($attendee->type) && $attendee->type == 2)
                        $arrayAttendees["recipient_role"] = "OPT";
                    else
                        $arrayAttendees["recipient_role"] = "REQ";
					$arrayAttendees["response_status"] = "NA";
                    if (isset($attendee->status)) {
						if ($attendee->status==5) {
							$arrayAttendees["response_status"] = "NA";
						} else if ($attendee->status==3) {
							$arrayAttendees["response_status"] = "AC";
						} else if ($attendee->status==2) {
							$arrayAttendees["response_status"] = "TE";
						} else if ($attendee->status==4) {
							$arrayAttendees["response_status"] = "DE";
						}
					}
                    $result = pg_insert($this->db, 'calendar.events_attendees', $arrayAttendees);
                    if ($result == FALSE)
                        throw new Exception(pg_last_error($this->db));
                }
            }
			//ricorrenza
            $recurrence = false;
            $recur_broken = false;
            if (isset($message->recurrence)) {
				$recurrence = false;
                switch ($message->recurrence->type) {
                    case 0: //giornaliera
                        $arrayRecur["recurr_type"] = "D";
                        $recurr_type = "D";
                        $recurrence = true;
                        break;
                    case 1: //settimanale
                        $arrayRecur["recurr_type"] = "W";
                        $recurr_type = "W";
                        $recurrence = true;
                        break;
                    case 2: //mensile ogni xx del mese
                        $arrayRecur["recurr_type"] = "M";
                        $recurr_type = "M";
                        $recurrence = true;
                        break;
                    case 3: //mensile ogni x della settimana
                        // RICORRENZA NON GESTITA IN WEBTOP --- viene inserito evento normale
                        $recurrence = false;
                        break;
                    case 5: //annuale
                        $arrayRecur["recurr_type"] = "Y";
                        $recurr_type = "Y";
                        $recurrence = true;
                        break;
                }
                if ($recurr_type == "D") { //giornaliera
                    $arrayRecur["daily_freq"] = "1";
                    //$arrayRecur["start_date"] = $start_date;
                    $starttime = $message->starttime - $timeOffset;
                    $start_date = gmdate("Y-m-d 00:00:00", $starttime);
                    $arrayRecur["start_date"] = $start_date;
                }                      //fine giornaliera

                if ($recurr_type == "W") { //settimanale
                    $arrayRecur["weekly_day_1"] = "false";
                    $arrayRecur["weekly_day_2"] = "false";
                    $arrayRecur["weekly_day_3"] = "false";
                    $arrayRecur["weekly_day_4"] = "false";
                    $arrayRecur["weekly_day_5"] = "false";
                    $arrayRecur["weekly_day_6"] = "false";
                    $arrayRecur["weekly_day_7"] = "false";
                    if (($message->recurrence->dayofweek & 1) == 1)
                        $arrayRecur["weekly_day_7"] = "true";
                    if (($message->recurrence->dayofweek & 2) == 2)
                        $arrayRecur["weekly_day_1"] = "true";
                    if (($message->recurrence->dayofweek & 4) == 4)
                        $arrayRecur["weekly_day_2"] = "true";
                    if (($message->recurrence->dayofweek & 8) == 8)
                        $arrayRecur["weekly_day_3"] = "true";
                    if (($message->recurrence->dayofweek & 16) == 16)
                        $arrayRecur["weekly_day_4"] = "true";
                    if (($message->recurrence->dayofweek & 32) == 32)
                        $arrayRecur["weekly_day_5"] = "true";
                    if (($message->recurrence->dayofweek & 64) == 64)
                        $arrayRecur["weekly_day_6"] = "true";
                    $arrayRecur["weekly_freq"] = "1";
                    $starttime = $message->starttime - $timeOffset;
                    //$starttime = $message->starttime;
                    $start_date = gmdate("Y-m-d 00:00:00", $starttime);
                    $arrayRecur["start_date"] = $start_date;
                }                       //fine settimanale
                if ($recurr_type == "M") { //mensile
                    if (isset($message->recurrence->dayofmonth)) {
                        $arrayRecur["monthly_day"] = $message->recurrence->dayofmonth;
                        $arrayRecur["monthly_freq"] = "1";
                    }
                    //$starttime = $message->starttime;
                    $starttime = $message->starttime - $timeOffset;
                    $start_date = gmdate("Y-m-d 00:00:00", $starttime);
                    $arrayRecur["start_date"] = $start_date;
                }                       //fine mensile
                if ($recurr_type == "Y") { //annuale
                    if (isset($message->recurrence->dayofmonth)) {
                        $arrayRecur["yearly_day"] = $message->recurrence->dayofmonth;
                    }
                    if (isset($message->recurrence->monthofyear)) {
                        $arrayRecur["yearly_freq"] = $message->recurrence->monthofyear;
                    }
                    //$starttime = $message->starttime;
                    $starttime = $message->starttime - $timeOffset;
                    $start_date = gmdate("Y-m-d 00:00:00", $starttime);
                    $arrayRecur["start_date"] = $start_date;
                }                       //fine annuale
                if (isset($message->recurrence->occurences)) {
                    $arrayRecur["repeat"] = $repeat;
                }
                if (isset($message->recurrence->until)) {
                    $until_date = $message->recurrence->until;
                    if ($this->device_android)
                        $until_date = strtotime("-1 days", $until_date);
                    $until_date = gmdate("Y-m-d 00:00:00", $until_date);
                    $arrayRecur["until_date"] = $until_date;
                    $arrayRecur["permanent"] = false;
                }else {
                    $arrayRecur["until_date"] = "2100-12-31 00:00:00";
                    $arrayRecur["permanent"] = true;
                }
                $recur_broken_event = false;
                if (isset($message->exceptions) && $recurrence == true) {
                    foreach ($message->exceptions as $recur_exception) {
                        if ($recur_exception->deleted == 0) { //ricorrenza spezzata con evento
                            $recur_broken = true;
                            $recur_broken_event = true;
                            $result = pg_query($this->db, "select new_event_id from calendar.recurrences_broken where event_id = ".$id." and recurrence_id=".$recurr_id."  and event_date = '" . gmdate("Y-m-d 00:00:00", $recur_exception->exceptionstarttime) . "';");
                            if ($result == FALSE)
                                throw new Exception(pg_last_error($this->db));
                            $found_event_broken = false;
                            while ($row = pg_fetch_row($result)) {
                                if (isset($row[0])) {
                                    $found_event_broken = true;
                                    $new_event_id = $row[0];
                                }
                            }
                            if (isset($recur_exception->subject)) {
                                $arrayException["title"] = $this->truncateString(($recur_exception->subject), 100);
                            } elseif ($message->subject) {
                                $arrayException["title"] = $this->truncateString(($message->subject), 100);
                            }
                            if (isset($recur_exception->starttime)) {
                                $starttime = $recur_exception->starttime;
                                $fromtime = gmdate("Y-m-d H:i:s", $starttime);
                                $arrayException["start_date"] = $fromtime;
                            } elseif (isset($message->starttime)) {
                                $starttime = $message->starttime;
                                $fromtime = gmdate("Y-m-d H:i:s", $starttime);
                                $arrayException["start_date"] = $fromtime;
                            }
                            if (isset($recur_exception->endtime)) {
                                $totime = $recur_exception->endtime;
                                $totime = gmdate("Y-m-d H:i:s", $totime);
                                $arrayException["end_date"] = $totime;
                            } elseif (isset($message->endtime)) {
                                $totime = $message->endtime;
                                $totime = gmdate("Y-m-d H:i:s", $totime);
                                $arrayException["end_date"] = $totime;
                            }
                            if (isset($recur_exception->alldayevent)) {
                                $allday = false;
                                if ($recur_exception->alldayevent == 1)
                                    $allday = true;
                                $arrayException["allday"] = $allday;
                            }elseif (isset($message->alldayevent)) {
                                $allday = false;
                                if ($message->alldayevent == 1)
                                    $allday = true;
                                $arrayException["allday"] = $allday;
                            }
                            if (isset($recur_exception->busystatus)) {
                                $busy = false;
                                if ($recur_exception->alldayevent == 2)
                                    $busy = true;
                                $arrayException["busy"] = $busy;
                            }elseif (isset($message->busystatus)) {
                                $busy = false;
                                if ($message->alldayevent == 2)
                                    $busy = true;
                                $arrayException["busy"] = $busy;
                            }
                            if (isset($recur_exception->body)) {
                                $arrayException["description"] = $this->truncateString(($recur_exception->body), 1024);
                            } elseif (isset($message->body)) {
                                $arrayException["description"] = $this->truncateString(($message->body), 1024);
                            }
                            if (isset($recur_exception->timezone)) {
                                $tzn = $this->getTimezone($recur_exception->timezone);
                                $arrayException["timezone"] = $tzn;
                            } elseif (isset($message->timezone)) {
                                $tzn = $this->getTimezone($message->timezone);
                                $arrayException["timezone"] = $tzn;
                            }
                            if (isset($recur_exception->location)) {
                                $arrayException["location"] = $this->truncateString(($recur_exception->location), 100);
                            } elseif (isset($message->location)) {
                                $arrayException["location"] = $this->truncateString(($message->location), 100);
                            }
                            if (isset($recur_exception->reminder)) {
                                $arrayException["reminder"] = $recur_exception->reminder;
                            } elseif (isset($message->reminder)) {
                                $arrayException["reminder"] = $message->reminder;
                            }
                            if (isset($recur_exception->sensitivity)) {
                                if ($recur_exception->sensitivity == 2)
                                    $arrayException["is_private"] = true;
								else
                                    $arrayException["is_private"] = false;
                            }elseif (isset($message->sensitivity)) {
                                if ($message->sensitivity == 2)
                                    $arrayException["is_private"] = true;
								else
                                    $arrayException["is_private"] = false;
                            }
                            $arrayException["calendar_id"] = $this->getCategoryId($folderid,$this->_username,$this->_domain);
                            $broken_delete = false;
                            $broken_update = false;
                            if (!$found_event_broken) {
                                $arrayException["revision_status"] = "N";
								$arrayException["revision_timestamp"] = "NOW()";
                                $new_event_id = $this->getGlobalKey();
                                $arrayException["event_id"] = $new_event_id;
                                $arrayRecBroken["new_event_id"] = $new_event_id;
                                $arrayRecBroken["event_id"] = $id;
								$arrayRecBroken["organizer"] = $message->organizeremail;
								$result = pg_insert($this->db, 'calendar.events', $arrayException);
                                if ($result == FALSE)
                                    throw new Exception(pg_last_error($this->db));
                                if (isset($recur_exception->exceptionstarttime)) {
                                    $starttime = $recur_exception->exceptionstarttime;
                                    $start_date = gmdate("Y-m-d 00:00:00", $starttime);
                                    $arrayRecBroken["event_date"] = $start_date;
                                }
                                $event_m["revision_status"] = "M";
                                $event_m["revision_timestamp"] = "NOW()";
                                $result = pg_update($this->db, 'calendar.events', $event_m, array('event_id' => $id));
                                if ($result == FALSE)
                                    throw new Exception(pg_last_error($this->db));
                                $result = pg_query($this->db, "COMMIT;");
                            }else {
                                $arrayException["revision_status"] = "M";
                                $arrayException["revision_timestamp"] = "NOW()";
                                $arrayRecBroken["new_event_id"] = $new_event_id;
                                $arrayRecBroken["event_id"] = $id;
                                $result = pg_update($this->db, 'calendar.events', $arrayException, array('event_id' => $new_event_id));
                                if ($result == FALSE)
                                    throw new Exception(pg_last_error($this->db));
                                if (isset($recur_exception->exceptionstarttime)) {
                                    $starttime = $recur_exception->exceptionstarttime;
                                    $start_date = gmdate("Y-m-d 00:00:00", $starttime);
                                    $arrayRecBroken["event_date"] = $start_date;
                                }
                                $broken_update = true;
                            }
                        } else {      //cancellazione evento in una ricorrenza
                            $recur_broken = true;
                            $broken_delete = true;
                            if (isset($recur_exception->exceptionstarttime)) {
                                $starttime = $recur_exception->exceptionstarttime;
                                $start_date = gmdate("Y-m-d 00:00:00", $starttime);
                                $arrayRecBrokenDelete["event_date"] = $start_date;
                            }
                            $arrayException["revision_status"] = "M";
                            $arrayException["revision_timestamp"] = "NOW()";
                            $arrayRecBrokenDelete["event_id"] = $id;
                            $arrayRecBrokenDelete["recurrence_id"] = $recurr_id;
                        }
                        if ($recurrence == true) {
                            $arrayR["revision_timestamp"] = "NOW()";
                            $arrayR["revision_status"] = "M";
                            $result = pg_update($this->db, 'calendar.recurrences', $arrayRecur, array('recurrence_id' => $recurr_id));
                            if ($result == FALSE)
                                throw new Exception(pg_last_error($this->db));
                            $result = pg_update($this->db, 'calendar.events', $arrayR, array('event_id' => $id));
                            if ($result == FALSE)
                                throw new Exception(pg_last_error($this->db));
                            $result = pg_query($this->db, "COMMIT;");
                        }
                        if ($recur_broken == true) {
                            $arrayRecBroken["recurrence_id"] = $recurr_id;
                            if ($broken_delete) {
                                $existRecurrBroken = $this->existRecurrBroken($arrayRecBrokenDelete["event_id"], $arrayRecBrokenDelete["recurrence_id"], $arrayRecBrokenDelete["event_date"], null);
                                if (!$existRecurrBroken)
                                    $resultRecBroken = pg_insert($this->db, 'calendar.recurrences_broken', $arrayRecBrokenDelete);
                                if ($resultRecBroken == FALSE)
                                    throw new Exception(pg_last_error($this->db));
                            }else {
                                $existRecurrBroken = $this->existRecurrBroken($arrayRecBroken["event_id"], $arrayRecBroken["recurrence_id"], $arrayRecBroken["event_date"], $arrayRecBroken["new_event_id"]);
                                if (!$existRecurrBroken)
                                    $resultRecBroken = pg_insert($this->db, 'calendar.recurrences_broken', $arrayRecBroken);
                                if ($resultRecBroken == FALSE)
                                    throw new Exception(pg_last_error($this->db));
                            }
                        }
                    }
                }
            }else {
                $arrayEvent["recurrence_id"] = null;
            }//fine ricorrenza

            if ($recurrence == true) {
				$arrayR["rule"] = $this->_GenerateRecurrence($message->recurrence);
                if ($found_id_recurrence == false) {
                    $recurr_id = $this->getRecurrenceId();
                    $arrayRecur["recurrence_id"] = $recurr_id;
                    $result = pg_insert($this->db, 'calendar.recurrences', $arrayRecur);
                    if ($result == FALSE)
                        throw new Exception(pg_last_error($this->db));
                    $arrayR["recurrence_id"] = $recurr_id;
                    $e_id = $id;
                    if ($found_id_event)
                        $e_id = $event_id;
                    $arrayR["revision_timestamp"] = "NOW()";
                    $result = pg_update($this->db, 'calendar.events', $arrayR, array('event_id' => $id));
                    if ($result == FALSE)
                        throw new Exception(pg_last_error($this->db));
                }else {
                    $arrayR["revision_timestamp"] = "NOW()";
                    $arrayR["revision_status"] = "M";
                    $result = pg_update($this->db, 'calendar.recurrences', $arrayRecur, array('recurrence_id' => $recurr_id));
                    if ($result == FALSE)
                        throw new Exception(pg_last_error($this->db));
                    $result = pg_update($this->db, 'calendar.events', $arrayR, array('event_id' => $id));
                    if ($result == FALSE)
                        throw new Exception(pg_last_error($this->db));
                }
            }
            $result = pg_query($this->db, "COMMIT;");
            if ($result == FALSE)
                throw new Exception(pg_last_error($this->db));
            if ($recurrence == false && $found_id_recurrence == true) {
                $arrayFuture["recurrence_id"] = null;
                $arrayFuture["revision_timestamp"] = "now()";
                $arrayFuture["revision_status"] = "M";
                $result = pg_update($this->db, 'calendar.events', $arrayFuture, array('event_id' => $id));
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
        $result = pg_query($this->db, "SELECT sync FROM calendar.calendars WHERE user_id='".$this->_username."' and domain_id='".$this->_domain."' and sync = 'R' and name = '" .$folderid . "';");
        if ($result == FALSE) {
            return false;
		}
        if ($row_calendar = pg_fetch_row($result)) {
			return true;
        }
		return false;
    }
	
    function getParentEventId($id) {
        $event_id = $id;
        $result = pg_query($this->db, "select event_id from calendar.recurrences_broken where new_event_id = " . $id);
        if ($result == FALSE)
            throw new Exception(pg_last_error($this->db));
        if ($row_event = pg_fetch_row($result)) {
            if (isset($row_event[0])) {
                $event_id = $row_event[0];
            }
        }
        return $event_id;
    }

    function getEvent($id, $folderid) {
        $result = pg_query($this->db, 
			"select "
				. "event_id, "
				. "title, "
				. "revision_timestamp, "
				. "start_date, "
				. "end_date, "
				. "description, "
				. "location, "
				. "is_private, "
				. "reminder, "
				. "all_day, "
				. "busy, "
				. "timezone, "
				. "recurrence_id "
				. "from calendar.events "
				. "where event_id = " . $id . " "
				. "and revision_status!='D';"
		);
        if ($result == FALSE)
            throw new Exception(pg_last_error($this->db));
        $dateTimeZone = new DateTimeZone(date_default_timezone_get());
        $dateTime = new DateTime("now", $dateTimeZone);
        $timeOffset = $dateTimeZone->getOffset($dateTime);
        if ($row_event = pg_fetch_row($result)) {
            $event_rec = new SyncAppointmentException();
            $event_rec->fileas = $id;
            $event_rec->organizeremail = $this->getEmail();
            if (isset($row_event[1])) {
                $event_rec->subject = $row_event[1];
            }
            if (isset($row_event[2])) {
                $dtstamp = strtotime($row_event[2]);
                $d = new DateTime($row_event[2]);
                $timeOffset = $d->getOffset();
                //$message->dtstamp = $dtstamp + $timeOffset;
                $message->dtstamp = $dtstamp;
            }
            if (isset($row_event[3])) {
                $fromtime = strtotime($row_event[3]);
                $d = new DateTime($row_event[3]);
                $timeOffset = $d->getOffset();
                //$event_rec->starttime = $fromtime + $timeOffset;
                $event_rec->starttime = $fromtime;
                $event_rec->exceptionstarttime = $event_rec->starttime;
            }
            if (isset($row_event[4])) {
                $totime = strtotime($row_event[4]);
                $d = new DateTime($row_event[4]);
                $timeOffset = $d->getOffset();
                //$event_rec->endtime = $totime + $timeOffset;
                $event_rec->endtime = $totime;
            }
            if (isset($row_event[5])) {
                $event_rec->body = $row_event[5];
            }
            if (isset($row_event[6])) {
                $event_rec->location = $row_event[6];
            }
            if (isset($row_event[9])) {
                if ($row_event[9] == true) {
                    $event_rec->alldayevent = 1;
                    $event_rec->starttime = $this->convertTimeAllDay($row_event[3], true, "00", "00", "");
                    $event_rec->endtime = $this->convertTimeAllDay($row_event[4], false, "00", "00", "+");
                } else
                    $event_rec->alldayevent = 0;
            }
            if (isset($row_event[11])) {
                $tzObject = TimezoneUtil::GetFullTZ($row_event[11]);
            } else {
                $tzObject = $this->getDefaultTimeZone();
            }
            $event_rec->timezone = base64_encode($this->GetTzSyncBlob($tzObject));
            $event_rec->deleted = 0;
        }
        return $event_rec;
    }

    function deleteAttendees($id) {
        $result_at = pg_query($this->db, "select event_id, recipient from calendar.events_attendees where event_id = " . $id . ";");
        if ($result_at == FALSE)
            throw new Exception(pg_last_error($this->db));
        while ($row_at = pg_fetch_row($result_at)) {
            if (isset($row_at[0])) {
                $res = pg_query($this->db, "delete from calendar.events_attendees where event_id = " . $row_at[0] . ";");
                if ($res == FALSE)
                    throw new Exception(pg_last_error($this->db));
            }
        }
    }

    function getGlobalKey() {
        $result_contact = pg_query($this->db, ("SELECT nextval('calendar.SEQ_EVENTS') ;"));
        if ($result_contact == FALSE)
            throw new Exception(pg_last_error($this->db));
        while ($row_contact = pg_fetch_row($result_contact)) {
            if (isset($row_contact[0])) {
                return $row_contact[0];
            }
        }
    }

    function getAttendeeId() {
		return uniqid();
    }

    function getRecurrenceId() {
        $result_recurr = pg_query($this->db, ("SELECT nextval('calendar.seq_recurrences') ;"));
        if ($result_recurr == FALSE)
            throw new Exception(pg_last_error($this->db));
        while ($row_recurr = pg_fetch_row($result_recurr)) {
            if (isset($row_recurr[0])) {
                return $row_recurr[0];
            }
        }
    }

    function getCalendarsId() {
        $result_recurr = pg_query($this->db, ("SELECT nextval('calendar.SEQ_CALENDARS') ;"));
        if ($result_recurr == FALSE)
            throw new Exception(pg_last_error($this->db));
        while ($row_recurr = pg_fetch_row($result_recurr)) {
            if (isset($row_recurr[0])) {
                return $row_recurr[0];
            }
        }
    }

    function getCategoryId($folderid,$username,$domain) {
		$sql = "";
		if ($this->device_ios || $this->device_outlook) {
			$sql = "SELECT calendar_id FROM calendar.calendars WHERE user_id='".$username."' and domain_id='".$domain."' and name = '" .$folderid . "'";
		} else {
			$sql = "SELECT calendar_id FROM calendar.calendars WHERE user_id='".$username."' and domain_id='".$domain."' and built_in = true";
		}
        $result = pg_query($this->db, $sql);
        if ($result == FALSE)
            throw new Exception(pg_last_error($this->db));
        while ($rows = pg_fetch_row($result)) {
            if (isset($rows[0])) {
                return $rows[0];
            }
        }
    }

    function isBrokenEvent($event_id) {
        if ($event_id != null && $event_id != "") {
            $result_dom = pg_query($this->db, ("select event_id from calendar.recurrences_broken where new_event_id=" . $event_id));
            if ($result_dom == FALSE)
                throw new Exception(pg_last_error($this->db));
            if ($row_dom = pg_fetch_row($result_dom)) {
                if (isset($row_dom[0])) {
                    return true;
                }
            }
        }
        return false;
    }

    function existRecurrBroken($id, $recurr_id, $exceptionstarttime, $new_event_id) {
        $query = "";
        if ($new_event_id != null)
            $query = " and new_event_id=" . $new_event_id;
        $result = pg_query($this->db, "select new_event_id from calendar.recurrences_broken where event_id = ".$id." and recurrence_id=".$recurr_id.$query."  and event_date = '".$exceptionstarttime."';");
        if ($result == FALSE)
            throw new Exception(pg_last_error($this->db));
        while ($row = pg_fetch_row($result)) {
            return true;
        }
        return false;
    }
	
    function getDefaultTimeZone() {
        $tz = $this->GetTzGmt();
        $result_tz = pg_query($this->db, 
			"select value "
			."from core.user_settings "
			."where domain_id = '".$this->_domain."' "
			."and user_id = '".$this->_username."' "
			."and service_id = 'com.sonicle.webtop.core' "
			."and key = 'i18n.timezone' "
			."union "
			."select value "
			."from core.domain_settings "
			."where domain_id = '".$this->_domain."' "
			."and service_id = 'com.sonicle.webtop.core' "
			."and key = 'default.i18n.timezone' "
			."union "
			."select value "
			."from core.settings "
			."where service_id = 'com.sonicle.webtop.core' "
			."and key = 'default.i18n.timezone' ;"
		);
        if ($result_tz == FALSE)
            return $tz;
        while ($row_tz = pg_fetch_row($result_tz)) {
            if (isset($row_tz[0]))
                $tz = $row_tz[0];
        }
        return $tz;
    }

    function getEmail() {
        $email = "";
        $result_email = pg_query($this->db, ("select email from core.users_info where user_id='".$this->_username."' and domain_id='".$this->_domain."';"));
        if ($result_email == FALSE)
            return $email;
        while ($row_email = pg_fetch_row($result_email)) {
            if (isset($row_email[0]))
                $email = $row_email[0];
        }
        return $email;
    }

    function GetTzSyncBlob($timezone, $with_names = true) {
        // UTC needs special handling
        if ($this->device_android) {
            if ($timezone == "UTC")
                return base64_encode(pack('la64vvvvvvvvla64vvvvvvvvl', 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0));
            try {
                //Generate a timezone string (PHP 5.3 needed for this)
                $timezone = new DateTimeZone($timezone);
                $trans = $timezone->getTransitions(time());
                $stdTime = null;
                $dstTime = null;
                if (count($trans) < 3) {
                    throw new Exception();
                }
                if ($trans[1]['isdst'] == 1) {
                    $dstTime = $trans[1];
                    $stdTime = $trans[2];
                } else {
                    $dstTime = $trans[2];
                    $stdTime = $trans[1];
                }
                $stdTimeO = new DateTime($stdTime['time']);
                $stdFirst = new DateTime(sprintf("first sun of %s %s", $stdTimeO->format('F'), $stdTimeO->format('Y')), timezone_open("UTC"));
                $stdBias = $stdTime['offset'] - 60;
                $stdName = $stdTime['abbr'];
                $stdYear = 0;
                $stdMonth = $stdTimeO->format('n');
                $stdWeek = floor(($stdTimeO->format("j") - $stdFirst->format("j")) / 7) + 1;
                $stdDay = $stdTimeO->format('w');
                $stdHour = $stdTimeO->format('H');
                $stdMinute = $stdTimeO->format('i');
                $stdTimeO->add(new DateInterval('P7D'));
                if ($stdTimeO->format('n') != $stdMonth) {
                    $stdWeek = 5;
                }
                $dstTimeO = new DateTime($dstTime['time']);
                $dstFirst = new DateTime(sprintf("first sun of %s %s", $dstTimeO->format('F'), $dstTimeO->format('Y')), timezone_open("UTC"));
                $dstName = $dstTime['abbr'];
                $dstYear = 0;
                $dstMonth = $dstTimeO->format('n');
                $dstWeek = floor(($dstTimeO->format("j") - $dstFirst->format("j")) / 7) + 1;
                $dstDay = $dstTimeO->format('w');
                $dstHour = $dstTimeO->format('H');
                $dstMinute = $dstTimeO->format('i');
                $dstTimeO->add(new DateInterval('P7D'));
                if ($dstTimeO->format('n') != $dstMonth) {
                    $dstWeek = 5;
                }
                $dstBias = ($dstTime['offset'] - $stdTime['offset']) / -60;
                if ($with_names) {
                    return base64_encode(pack('la64vvvvvvvvla64vvvvvvvvl', $stdBias, $stdName, 0, $stdMonth, $stdDay, $stdWeek, $stdHour, $stdMinute, 0, 0, 0, $dstName, 0, $dstMonth, $dstDay, $dstWeek, $dstHour, $dstMinute, 0, 0, $dstBias));
                } else {
                    return base64_encode(pack('la64vvvvvvvvla64vvvvvvvvl', $stdBias, '', 0, $stdMonth, $stdDay, $stdWeek, $stdHour, $stdMinute, 0, 0, 0, '', 0, $dstMonth, $dstDay, $dstWeek, $dstHour, $dstMinute, 0, 0, $dstBias));
                }
            } catch (Exception $e) {
                // If invalid timezone is given, we return UTC
                return base64_encode(pack('la64vvvvvvvvla64vvvvvvvvl', 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0));
            }
            return base64_encode(pack('la64vvvvvvvvla64vvvvvvvvl', 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0));
        } else {
            $tzObject = $timezone;
            $packed = pack(
                    "la64vvvvvvvv" . "la64vvvvvvvv" . "l", 
                    $tzObject["bias"], 
                    $tzObject["tzname"], 
                    $tzObject["dstendyear"], 
                    $tzObject["dstendmonth"], 
                    $tzObject["dstendday"], 
                    $tzObject["dstendweek"], 
                    $tzObject["dstendhour"], 
                    $tzObject["dstendminute"], 
                    $tzObject["dstendsecond"], 
                    $tzObject["dstendmillis"], 
                    $tzObject["stdbias"], 
                    $tzObject["tznamedst"], 
                    $tzObject["dststartyear"], 
                    $tzObject["dststartmonth"], 
                    $tzObject["dststartday"], 
                    $tzObject["dststartweek"], 
                    $tzObject["dststarthour"], 
                    $tzObject["dststartminute"], 
                    $tzObject["dststartsecond"], 
                    $tzObject["dststartmillis"], 
                    $tzObject["dstbias"]
            );
            return $packed;
        }
    }

    function getTimezone($timezone) {
        $tzObject = $this->_getTZFromSyncBlob(base64_decode($timezone));
        $dstoffset = -60 * (intval($tzObject['bias']) + intval($tzObject['dstbias']));
        if (function_exists("timezone_name_from_abbr")) {
            $tzName = timezone_name_from_abbr("", $dstoffset, 1); // DST - Most accurate
        } else
            $tzName = false;

        if ($tzName === false) {
            $stdoffset = -60 * (intval($tzObject['bias']) + intval($tzObject['stdbias']));
            if (function_exists("timezone_name_from_abbr")) {
                $tzName = timezone_name_from_abbr("", $stdoffset, 0); // STD - Next best
            } else
                $tzName = false;
            if ($tzName === false) {
                if (function_exists("timezone_abbreviations_list")) {
                    $timezone_abbreviations = timezone_abbreviations_list();
                    while ($region = each($timezone_abbreviations)) {
                        $count = sizeof($region['value']);
                        for ($i = 0; $i < $count; $i++) {
                            $tzListItem = $region['value'][$i];
                            if (($tzListItem['dst'] === true) && ( $tzListItem['offset'] == $dstoffset )) {
                                $tzName = $tzListItem['timezone_id'];
                                break;
                            }
                        }
                        if ($tzName != false)
                            break;
                        for ($i = 0; $i < $count; $i++) {
                            $tzListItem = $region['value'][$i];
                            if (($tzListItem['dst'] === false) && ( $tzListItem['offset'] == $stdoffset )) {
                                $tzName = $tzListItem['timezone_id'];
                                break;
                            }
                        }
                        if ($tzName != false)
                            break;
                    }
                }
            }
        } 
        return $tzName;
    }

    function diff_date_day($data1, $data2) {
        //$data1 e $data2 in formato Y-m-d (1979-12-16), se vuote '' prende valore di oggi
        if (empty($data1))
            $data1 = date('Y-m-d', $data1);
        if (empty($data2))
            $data2 = date('Y-m-d', $data2);
        $a_1 = explode('-', $data1);
        $a_2 = explode('-', $data2);
        $mktime1 = mktime(0, 0, 0, $a_1[1], $a_1[2], $a_1[0]);
        $mktime2 = mktime(0, 0, 0, $a_2[1], $a_2[2], $a_2[0]);
        $secondi = $mktime1 - $mktime2;
        $giorni = intval($secondi / 86400); /* ovvero (24ore*60minuti*60seconi) */
        return ($giorni);
    }

    function _getTZFromSyncBlob($data) {
        $tz = unpack("lbias/a64name/vdstendyear/vdstendmonth/vdstendday/vdstendweek/vdstendhour/vdstendminute/vdstendsecond/vdstendmillis/" .
                "lstdbias/a64name/vdststartyear/vdststartmonth/vdststartday/vdststartweek/vdststarthour/vdststartminute/vdststartsecond/vdststartmillis/" .
                "ldstbias", $data);
        // Make the structure compatible with class.recurrence.php
        $tz["timezone"] = $tz["bias"];
        $tz["timezonedst"] = $tz["dstbias"];
        return $tz;
    }

    function GetTzGMT() {
        $tzObject = array("name" => "UTC", "bias" => 0, "stdname" => "UTC", "stdbias" => 0, "dstbias" => 0, "dstendyear" => 0, "dstendmonth" => 0, "dstendday" => 0, "dstendweek" => 0, "dstendhour" => 0, "dstendminute" => 0, "dstendsecond" => 0, "dstendmillis" => 0,
            "dstname" => "UTC", "dststartyear" => 0, "dststartmonth" => 0, "dststartday" => 0, "dststartweek" => 0, "dststarthour" => 0, "dststartminute" => 0, "dststartsecond" => 0, "dststartmillis" => 0);
        return $tzObject;
    }

    function convertTimeAllDay($time, $isstart, $hour, $min, $op) {
        $day = substr($time, 0, 10);
        $day = $day . " " . $hour . ":" . $min . ":00";
        $returnday = strtotime($day);
        if ($isstart == false) {
            $diff = " " . $op . "1 days";
            $returnday = strtotime($day . $diff);
        }
        return $returnday;
    }

    private function _GenerateRecurrence($rec) {
        $rrule = array();
        if (isset($rec->type)) {
            $freq = "";
            switch ($rec->type) {
                case "0":
                    $freq = "DAILY";
                    break;
                case "1":
                    $freq = "WEEKLY";
                    break;
                case "2":
                case "3":
                    $freq = "MONTHLY";
                    break;
                case "5":
                    $freq = "YEARLY";
                    break;
            }
            $rrule[] = "FREQ=" . $freq;
        }
        if (isset($rec->until)) {
            $rrule[] = "UNTIL=" . gmdate("Ymd\THis\Z", $rec->until);
        }
        if (isset($rec->occurrences)) {
            $rrule[] = "COUNT=" . $rec->occurrences;
        }
        if (isset($rec->interval)) {
            $rrule[] = "INTERVAL=" . $rec->interval;
        }
        if (isset($rec->dayofweek)) {
            $week = '';
            if (isset($rec->weekofmonth)) {
                $week = $rec->weekofmonth;
            }
            $days = array();
            if (($rec->dayofweek & 1) == 1) {
                if (empty($week)) {
                    $days[] = "SU";
                }
                else {
                    $days[] = $week . "SU";
                }
            }
            if (($rec->dayofweek & 2) == 2) {
                if (empty($week)) {
                    $days[] = "MO";
                }
                else {
                    $days[] = $week . "MO";
                }
            }
            if (($rec->dayofweek & 4) == 4) {
                if (empty($week)) {
                    $days[] = "TU";
                }
                else {
                    $days[] = $week . "TU";
                }
            }
            if (($rec->dayofweek & 8) == 8) {
                if (empty($week)) {
                    $days[] = "WE";
                }
                else {
                    $days[] = $week . "WE";
                }
            }
            if (($rec->dayofweek & 16) == 16) {
                if (empty($week)) {
                    $days[] = "TH";
                }
                else {
                    $days[] = $week . "TH";
                }
            }
            if (($rec->dayofweek & 32) == 32) {
                if (empty($week)) {
                    $days[] = "FR";
                }
                else {
                    $days[] = $week . "FR";
                }
            }
            if (($rec->dayofweek & 64) == 64) {
                if (empty($week)) {
                    $days[] = "SA";
                }
                else {
                    $days[] = $week . "SA";
                }
            }
            $rrule[] = "BYDAY=" . implode(",", $days);
        }
        if (isset($rec->dayofmonth)) {
            $rrule[] = "BYMONTHDAY=" . $rec->dayofmonth;
        }
        if (isset($rec->monthofyear)) {
            $rrule[] = "BYMONTH=" . $rec->monthofyear;
        }
        return implode(";", $rrule);
    }
	// Fine funzioni specifiche del servizio

	
}


?>
