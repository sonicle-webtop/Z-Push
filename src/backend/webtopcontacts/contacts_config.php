<?php
/***********************************************
* File      :   config.php
* Project   :   Z-Push
* Descr     :   VCardDir backend configuration file
*
* Created   :   27.11.2012
*
* Copyright 2007 - 2013 Zarafa Deutschland GmbH
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation with the following additional
* term according to sec. 7:
*
* According to sec. 7 of the GNU Affero General Public License, version 3,
* the terms of the AGPL are supplemented with the following terms:
*
* "Zarafa" is a registered trademark of Zarafa B.V.
* "Z-Push" is a registered trademark of Zarafa Deutschland GmbH
* The licensing of the Program under the AGPL does not imply a trademark license.
* Therefore any rights, title and interest in our trademarks remain entirely with us.
*
* However, if you propagate an unmodified version of the Program you are
* allowed to use the term "Z-Push" to indicate that you distribute the Program.
* Furthermore you may use our trademarks where it is necessary to indicate
* the intended purpose of a product or service provided you use it in accordance
* with honest practices in industrial or commercial matters.
* If you want to propagate modified versions of the Program under the name "Z-Push",
* you may only do so if you have a written permission by Zarafa Deutschland GmbH
* (to acquire a permission please contact Zarafa at trademark@zarafa.com).
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
* Consult LICENSE file for details
************************************************/

// **********************
//  BackendVCardDir settings
// **********************

//define('VCARDDIR_DIR', SONICLE_TEMP_PATH);

global $gal_field_map;
$gal_field_map = array(
					
					SYNC_GAL_DISPLAYNAME    => 'cn',
                    SYNC_GAL_PHONE          => 'ctelephone',
                    SYNC_GAL_OFFICE         => '',
                    SYNC_GAL_TITLE          => 'title',
                    SYNC_GAL_COMPANY        => 'company',
                    SYNC_GAL_ALIAS          => 'contact_id',
                    SYNC_GAL_FIRSTNAME      => 'firstname',
                    SYNC_GAL_LASTNAME       => 'lastname',
                    SYNC_GAL_HOMEPHONE      => 'home_mobile',
                    SYNC_GAL_MOBILEPHONE    => 'work_mobile',
                    SYNC_GAL_EMAILADDRESS   => 'work_email'
                );


?>
