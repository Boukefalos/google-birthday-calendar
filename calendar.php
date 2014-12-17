<?php
/**
 * Copyright (C) 2014 Rik Veenboer <rik.veenboer@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
require_once 'vendor/autoload.php';
require_once 'settings.php';

set_time_limit(0);
session_start();
echo '<pre>';

// Initialize google client
$oClient = new Google_Client(array('use_objects' => true));
$oClient->setClientId(CLIENT_ID);
$oClient->setClientSecret(CLIENT_SECRET);
$oClient->setRedirectUri(REDIRECT_URI);
$oClient->setScopes(array(
    'https://www.googleapis.com/auth/calendar',
    'https://www.googleapis.com/auth/contacts.readonly'));

// Logout
if (isset($_GET['logout'])) {
    unset($_SESSION['token']);
}

// Redirected from authentication
if (isset($_GET['code'])) {
    $oClient->authenticate($_GET['code']);
    $_SESSION['token'] = $oClient->getAccessToken();
    header('Location: http://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF']);
}

// Logged-in
// $_SESSION['token'] = base64_decode('');
if (isset($_SESSION['token'])) {
    $oClient->setAccessToken($_SESSION['token']);
    // echo base64_encode($_SESSION['token']);
}

// Ask for permission
if (!($_SESSION['token'] = $oClient->getAccessToken())) {
    $sAuthUrl = $oClient->createAuthUrl();
    printf('<a class="login" href="%s">Connect Me!</a>', $sAuthUrl);
    exit;
}

// Store Google_Auth object
$oRfelection = new ReflectionObject($oClient);
$oProperty = $oRfelection->getProperty('auth');
$oProperty->setAccessible(true);
$oAuth = $oProperty->getValue($oClient);

// Initialize calendar service
$oCalendarService = new Google_Service_Calendar($oClient);

// Search for original and target calendars
$oCalendarsList = $oCalendarService->calendarList->listCalendarList();
$bHasContactsCalendar = $bHasTargetCalendar = false;
foreach ($oCalendarsList->getItems() as $aCalendarMeta) {
    if ($aCalendarMeta->getId() == CONTACTS_CALENDAR_ID) {
        // Original
        $bHasContactsCalendar = true;
    }
    if ($aCalendarMeta->getSummary() == TARGET_CALENDAR_SUMMARY) {
        // Target
        $bHasTargetCalendar = true;
        $sTargetCalendarId = $aCalendarMeta->getId();
    }
}

// Remove old calendar
if ($bHasTargetCalendar) {
    $oCalendarService->calendars->delete($sTargetCalendarId);
}

// Create new calendar
$oCalendar = new Google_Service_Calendar_Calendar($oClient);
$oCalendar->setSummary(TARGET_CALENDAR_SUMMARY);
$oCalendar = $oCalendarService->calendars->insert($oCalendar);
$sTargetCalendarId = $oCalendar->getId();
 
// Keep track of contact events
$aContacts = array();
$aAdded = array();

if ($bHasContactsCalendar) {
    $oTargetEvents = $oCalendarService->events->listEvents(CONTACTS_CALENDAR_ID);
    foreach ($oTargetEvents->getItems() as $oOriginalEvent) {
        // Initialize new event
        $oTargetEvent = new Google_Service_Calendar_Event();

        // Copy relevant parts of original event
        $oTargetEvent->setDescription($oOriginalEvent->getDescription());
        $oTargetEvent->setVisibility($oOriginalEvent->getVisibility());
        $oGadget = $oOriginalEvent->getGadget();
        $oTargetEvent->setGadget($oGadget);

        // Get contact id from event
        $aPreferences = $oGadget->getPreferences();
        if (!isset($aPreferences['goo.contactsContactId']) ) {
            continue;        
        }
        $sContactId = $aPreferences['goo.contactsContactId'];

        // Get event name from summary
        $sSummary = $oOriginalEvent->getSummary();
        preg_match('~[^ ]+$~s', $sSummary, $aMatch);
        $sCurrentEvent = $aMatch[0];

        // Only add first upcoming event
        if (isset($aAdded[$sContactId][$sCurrentEvent])) {
            continue;
        }
        
        if (!isset($aContacts[$sContactId])) {
            // Get contact details from contact id
            $sUrl = sprintf('https://www.google.com/m8/feeds/contacts/%s/full/%s?v=3.0', CONTACTS_USER_EMAIL, $sContactId);
            $oHttpRequest = new Google_Http_Request($sUrl);
            $oHttpRequest = $oAuth->sign($oHttpRequest);
            $aResponse = $oClient->getIo()->executeRequest($oHttpRequest);
            
            // Parse XML to fetch birthday
            $sXml = str_replace(array('gd:', 'gContact:'), null, $aResponse[0]);    
            $oXml = simplexml_load_string($sXml);
            $aBirthday = (array) $oXml->birthday->attributes();
            $sDate = current(current($aBirthday));

            // Save birthday date
            $aContacts[$sContactId][CALENDAR_BIRTHDAY_NAME] = $sDate;

            // Iterate all events of contact
            $aEvents = (array) $oXml->event;
            while (($oEvent = next($aEvents)) !== false) {
                $aEvent = (array) $oEvent;
                $sEvent = current(current($aEvent));
                $sDate = current(current(next($aEvent)));

                // Save other event date
                $aContacts[$sContactId][$sEvent] = $sDate;
            }
        }

        // Get date of current event
        $sDate = $aContacts[$sContactId][$sCurrentEvent];

        // Calculate age
        $oNow = new DateTime();
        $oDate = new DateTime($sDate);
        $iYear = $oDate->format('Y');
        $iYears = $oNow->diff($oDate)->y + 1;

        // Derive new event summary from original one
        $oTargetEvent->setSummary(sprintf('%s (%d / %d)', $sSummary, $iYears, $iYear));
        printf("%s\n", sprintf('%s (%d / %d)', $sSummary, $iYears, $iYear));

        // Correct event date
        $sStart = $oOriginalEvent->getStart()->getDate();
        $oStart = new DateTime($sStart);
        $oStart = new DateTime(sprintf('%s-%s', $oStart->format('d-m'), $oNow->format('Y')));
        if ($oNow->diff($oStart)->invert) {
            $oStart = $oStart->modify('+1 year');
        }

        // Update start and end times
        $oEventDate = new Google_Service_Calendar_EventDateTime();
        $oEventDate->setDate($oStart->format(GOOGLE_DATE_FORMAT));
        $oTargetEvent->setStart($oEventDate);       
        $oEventDate = new Google_Service_Calendar_EventDateTime();
        $oEventDate->setDate($oStart->modify('+1 day')->format(GOOGLE_DATE_FORMAT));
        $oTargetEvent->setEnd($oEventDate);

        // Insert new event to target calendar
        $oCalendarService->events->insert($sTargetCalendarId, $oTargetEvent);

        // Set event as added
        $aAdded[$sContactId][$sCurrentEvent] = true;
    }
}