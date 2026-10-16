<?php

/**
 * Routes for conferences
 * 
 * This file is part of the OSIRIS package.
 * Copyright (c) 2026 Julia Koblitz, OSIRIS Solutions GmbH
 * 
 * @package     OSIRIS
 * @since       1.3.5
 * 
 * @copyright	Copyright (c) 2026 Julia Koblitz, OSIRIS Solutions GmbH
 * @author		Julia Koblitz <julia.koblitz@osiris-solutions.de>
 * @license     MIT
 */

// This endpoint uses the same session-only access as the event CRUD routes.
Route::get('/ajax/conferences/suggest', function () {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    if (($_SESSION['loggedin'] ?? false) !== true || empty($_SESSION['username'])) {
        http_response_code(401);
        echo json_encode(['results' => [], 'error' => 'Authentication required.']);
        return;
    }

    $query = $_GET['q'] ?? '';
    $exclude = $_GET['exclude'] ?? null;
    $limit = $_GET['limit'] ?? '10';
    $start = $_GET['start'] ?? '';
    $location = $_GET['location'] ?? '';
    if (!is_string($query) || strlen($query) > 800 || preg_match('//u', $query) !== 1
        || preg_match_all('/./us', $query) > 200
        || ($exclude !== null && (!is_string($exclude) || !preg_match('/^[a-f0-9]{24}$/iD', $exclude)))
        || !is_scalar($limit) || !preg_match('/^(?:[1-9]|10)$/D', (string)$limit)
        || !is_string($start) || ($start !== '' && !conferenceDateIsValid($start))
        || !is_string($location) || strlen($location) > 2000 || preg_match('//u', $location) !== 1
        || preg_match_all('/./us', $location) > 500) {
        http_response_code(400);
        echo json_encode(['results' => [], 'error' => 'Invalid search parameters.']);
        return;
    }

    include_once BASEPATH . '/php/init.php';
    require_once BASEPATH . '/php/EventSearch.php';
    // Do not serialize successive autocomplete requests behind the PHP session lock.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    try {
        $results = (new EventSearch($osiris->conferences))->search(
            $query, (int)$limit, $exclude, ['start' => $start, 'location' => $location]
        );
        echo json_encode(['results' => $results], JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $error) {
        // A timed-out search must not look like an authoritative empty result.
        http_response_code(503);
        echo json_encode(['results' => [], 'error' => 'Event search is temporarily unavailable.']);
    }
}, 'login');

function conferenceDateIsValid($value): bool
{
    if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts)) return false;
    return checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]);
}

/** Validate strings before they can become MongoDB query operands. */
function conferenceValuesAreValid($values): bool
{
    if (!is_array($values)) return false;
    foreach (['title', 'start', 'location'] as $field) {
        if (!isset($values[$field]) || !is_string($values[$field]) || trim($values[$field]) === '') return false;
    }
    foreach (['title' => 2000, 'title_full' => 8000, 'location' => 2000, 'start' => 10, 'end' => 10,
        'country' => 10, 'type' => 100, 'url' => 4000, 'participants' => 10000, 'interests' => 10000] as $field => $maxBytes) {
        if (isset($values[$field]) && (!is_string($values[$field]) || strlen($values[$field]) > $maxBytes
            || preg_match('//u', $values[$field]) !== 1)) return false;
    }
    foreach (['title' => 500, 'title_full' => 2000, 'location' => 500] as $field => $maxCharacters) {
        if (isset($values[$field]) && preg_match_all('/./us', $values[$field]) > $maxCharacters) return false;
    }
    return conferenceDateIsValid($values['start'])
        && (empty($values['end']) || (conferenceDateIsValid($values['end']) && $values['end'] >= $values['start']));
}

Route::get('/(conferences|deadlines)', function ($page) {
    include_once BASEPATH . "/php/init.php";

    if ($page == 'deadlines') {
        $name = 'Deadlines';

        if (!$Settings->featureEnabled('deadlines', false)) {
            abortwith(500, lang('Deadlines are not enabled.', "Deadlines sind nicht aktiviert."));
        }
    } else {
        $name = 'Events';
    }
    $breadcrumb = [
        ['name' => $name]
    ];

    include BASEPATH . "/header.php";
    if ($page == 'deadlines') {
        include BASEPATH . "/pages/deadlines/list.php";
    } else {
        include BASEPATH . "/pages/events/list.php";
    }
    include BASEPATH . "/footer.php";
});


Route::get('/(conferences|deadlines)/new', function ($page) {
    include_once BASEPATH . "/php/init.php";

    if ($page == 'deadlines') {
        if (!$Settings->featureEnabled('deadlines', false)) {
            abortwith(500, lang('Deadlines are not enabled.', "Deadlines sind nicht aktiviert."));
        }
        $breadcrumb = [
            ['name' => lang('Deadlines'), 'path' => '/deadlines'],
            ['name' => lang('New deadline', 'Neue Deadline')]
        ];
    } else {
        $breadcrumb = [
            ['name' => lang('Events'), 'path' => '/conferences'],
            ['name' => lang('New event', 'Neues Event')]
        ];
    }

    $form = [];
    $new = true;

    include BASEPATH . "/header.php";
    if ($page == 'deadlines') {
        include BASEPATH . "/pages/deadlines/edit.php";
    } else {
        include BASEPATH . "/pages/events/edit.php";
    }
    include BASEPATH . "/footer.php";
});


Route::get('/conferences/view/(.*)', function ($id) {
    include_once BASEPATH . "/php/init.php";

    $conf_id = DB::to_ObjectID($id);
    // get conference
    $conference = $osiris->conferences->findOne(['_id' => $conf_id]);
    if (!$conference) {
        abortwith(404, lang('Event', "Veranstaltung"), '/conferences');
    }

    $breadcrumb = [
        ['name' => lang('Events'), 'path' => '/conferences'],
        ['name' => $conference['title']]
    ];

    $activities = $osiris->activities->find(['conference_id' => $id])->toArray();

    include BASEPATH . "/header.php";
    include BASEPATH . "/pages/events/view.php";
    include BASEPATH . "/footer.php";
});


Route::get('/conferences/edit/(.*)', function ($id) {
    include_once BASEPATH . "/php/init.php";

    $conf_id = DB::to_ObjectID($id);
    // get conference
    $new = false;
    $form = $osiris->conferences->findOne(['_id' => $conf_id]);
    if (!$form) {
        abortwith(404, lang('Event', "Veranstaltung"), '/conferences');
    }

    $breadcrumb = [
        ['name' => lang('Events'), 'path' => '/conferences'],
        ['name' => $form['title']]
    ];

    $activities = $osiris->activities->find(['conference_id' => $id])->toArray();

    include BASEPATH . "/header.php";
    include BASEPATH . "/pages/events/edit.php";
    include BASEPATH . "/footer.php";
});


// download conference as ics
Route::get('/conference/ics/(.*)', function ($id) {
    include BASEPATH . '/php/ICS.php';
    include_once BASEPATH . "/php/init.php";

    $conf_id = DB::to_ObjectID($id);
    // get conference
    $conf = $osiris->conferences->findOne(['_id' => $conf_id]);

    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename=invite.ics');

    $ics = new ICS(array(
        'location' => $conf['location'],
        'summary' => $conf['title'],
        'dtstart' => $conf['start'],
        'dtend' => $conf['end'],
        'description' => $conf['title_full'] ?? '',
        'url' => $conf['url'] ?? ''
    ));

    echo $ics->to_string();
});



Route::get('/deadlines/view/(.*)', function ($id) {
    include_once BASEPATH . "/php/init.php";

    if (!$Settings->featureEnabled('deadlines', false)) {
        abortwith(500, lang('Deadlines are not enabled.', "Deadlines sind nicht aktiviert."));
    }

    $mongo_id = DB::to_ObjectID($id);
    // get deadline
    $deadline = $osiris->deadlines->findOne(['_id' => $mongo_id]);
    if (!$deadline) {
        abortwith(404, lang('Deadline', "Deadline"), '/deadlines');
    }
    $breadcrumb = [
        ['name' => lang('Deadlines'), 'path' => '/deadlines'],
        ['name' => $deadline['title']]
    ];

    include BASEPATH . "/header.php";
    include BASEPATH . "/pages/deadlines/view.php";
    include BASEPATH . "/footer.php";
});

Route::get('/deadlines/edit/(.*)', function ($id) {
    include_once BASEPATH . "/php/init.php";

    if (!$Settings->featureEnabled('deadlines', false)) {
        abortwith(500, lang('Deadlines are not enabled.', "Deadlines sind nicht aktiviert."));
    }
    $conf_id = DB::to_ObjectID($id);
    // get deadline
    $new = false;
    $form = $osiris->deadlines->findOne(['_id' => $conf_id]);
    if (!$form) {
        abortwith(404, lang('Deadline', "Deadline"), '/deadlines');
    }

    $breadcrumb = [
        ['name' => lang('Deadlines'), 'path' => '/deadlines'],
        ['name' => $form['title']]
    ];

    include BASEPATH . "/header.php";
    include BASEPATH . "/pages/deadlines/edit.php";
    include BASEPATH . "/footer.php";
});



// crud/conferences/add

Route::post('/crud/conferences/add', function () {
    include_once BASEPATH . "/php/init.php";
    require_once BASEPATH . '/php/EventSearch.php';
    // check if json is requested from ajax
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $accept_json = strpos($accept, 'application/json') !== false;

    if (!isset($_POST['values']) || !is_array($_POST['values'])) {
        if ($accept_json) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'msg' => lang('No values provided.', 'Keine Werte angegeben.')]);
            exit;
        }
        abortwith(500, lang('No values provided.', 'Keine Werte angegeben.'));
    }
    $values = $_POST['values'];
    unset($values['_id'], $values['conference_id']);

    // required fields:
    if (!conferenceValuesAreValid($values)) {
        http_response_code(422);
        if ($accept_json) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'msg' => lang('Provide a title, location and valid dates.', 'Bitte Titel, Ort und gültige Datumsangaben eingeben.')]);
            exit;
        }
        $new = true;
        $form = array_filter($values, 'is_string');
        include BASEPATH . "/header.php";
        printMsg(lang('Provide a title, location and valid dates.', 'Bitte Titel, Ort und gültige Datumsangaben eingeben.'), 'error', lang('Invalid fields', 'Ungültige Daten'));
        include BASEPATH . "/pages/events/edit.php";
        include BASEPATH . "/footer.php";
        exit;
    }
    // Preserve the existing exact title + start identity check.
    $existing = $osiris->conferences->findOne([
        'title' => $values['title'],
        'start' => $values['start']
    ]);
    if ($existing) {
        if ($accept_json) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'warning', 'msg' => lang('An event with the same title and date already exists.', 'Ein Event mit dem gleichen Titel und Datum existiert bereits.'), 'id' => (string)$existing['_id'], 'event' => EventSearch::eventData($existing)], JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $_SESSION['msg'] = lang('An event with the same title and date already exists.', 'Ein Event mit dem gleichen Titel und Datum existiert bereits.');
        header("Location: " . ROOTPATH . "/conferences/view/" . $existing['_id']);
        exit;
    }

    try {
        $duplicateMatches = (new EventSearch($osiris->conferences))->likelyDuplicates($values);
    } catch (Throwable $error) {
        http_response_code(503);
        $message = lang('The duplicate check is temporarily unavailable. Your event has not been saved. Please try again.', 'Die Duplikatprüfung ist vorübergehend nicht verfügbar. Die Veranstaltung wurde nicht gespeichert. Bitte erneut versuchen.');
        if ($accept_json) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'msg' => $message]);
            exit;
        }
        $new = true;
        $form = $values;
        include BASEPATH . '/header.php';
        printMsg($message, 'error');
        include BASEPATH . '/pages/events/edit.php';
        include BASEPATH . '/footer.php';
        exit;
    }
    if ($duplicateMatches && ($_POST['confirm_duplicate'] ?? '') !== '1') {
        $duplicateMessage = lang('Similar events already exist. Reuse an existing event or confirm that this is a distinct event.', 'Ähnliche Veranstaltungen sind bereits vorhanden. Verwende eine bestehende Veranstaltung oder bestätige, dass dies eine eigenständige Veranstaltung ist.');
        if ($accept_json) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'duplicate', 'msg' => $duplicateMessage, 'matches' => $duplicateMatches], JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $new = true;
        $form = $values;
        $exactDuplicate = false;
        include BASEPATH . '/header.php';
        include BASEPATH . '/pages/events/edit.php';
        include BASEPATH . '/footer.php';
        exit;
    }

    if (isset($values['public'])) $values['public'] = boolval($values['public'] === 'true');

    $values['created'] = date('Y-m-d');
    $values['created_by'] = $_SESSION['username'];

    $start = strtotime($values['start']);
    $values['year'] = intval(date('Y', $start));
    $values['month'] = intval(date('n', $start));
    $values['quarter'] = ceil($values['month'] / 3);
    $values['day'] = intval(date('j', $start));

    if (isset($values['participants'])) {
        $values['participants'] = explode(',', $values['participants']);
    } else {
        $values['participants'] = [];
    }
    if (isset($values['interests'])) {
        $values['interests'] = explode(',', $values['interests']);
    } else {
        $values['interests'] = [];
    }
    $values['activities'] = [];

    $added = $osiris->conferences->insertOne($values);

    $id = $added->getInsertedId();

    if (strpos($accept, 'application/json') !== false) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'id' => (string)$id, 'event' => EventSearch::eventData(['_id' => $id] + $values)], JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    $_SESSION['msg'] = lang('Event added successfully.', 'Veranstaltung erfolgreich hinzugefügt.');
    $_SESSION['msg_type'] = 'success';
    header("Location: " . ROOTPATH . "/conferences/view/$id");
}, 'login');


Route::post('/crud/conferences/update/(.*)', function ($id) {
    include_once BASEPATH . "/php/init.php";
    require_once BASEPATH . '/php/EventSearch.php';

    if (!preg_match('/^[a-f0-9]{24}$/iD', $id)) {
        abortwith(404, lang('Event', 'Veranstaltung'), '/conferences');
    }
    $record = $osiris->conferences->findOne(['_id' => DB::to_ObjectID($id)]);
    if (!$record) abortwith(404, lang('Event', 'Veranstaltung'), '/conferences');
    $values = $_POST['values'] ?? [];
    $accept_json = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
    if (!conferenceValuesAreValid($values)) {
        http_response_code(422);
        $message = lang('Provide a title, location and valid dates.', 'Bitte Titel, Ort und gültige Datumsangaben eingeben.');
        if ($accept_json) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'msg' => $message]);
            exit;
        }
        $new = false;
        $form = array_replace((array)$record, is_array($values) ? array_filter($values, 'is_string') : []);
        $form['_id'] = $record['_id'];
        include BASEPATH . '/header.php';
        printMsg($message, 'error', lang('Invalid fields', 'Ungültige Daten'));
        include BASEPATH . '/pages/events/edit.php';
        include BASEPATH . '/footer.php';
        exit;
    }

    $existing = $osiris->conferences->findOne([
        '_id' => ['$ne' => $record['_id']], 'title' => $values['title'], 'start' => $values['start']
    ]);
    $exactDuplicate = (bool)$existing;
    try {
        $duplicateMatches = $existing ? [EventSearch::eventData($existing)]
            : (new EventSearch($osiris->conferences))->likelyDuplicates($values, $id);
    } catch (Throwable $error) {
        http_response_code(503);
        $message = lang('The duplicate check is temporarily unavailable. Your changes have not been saved. Please try again.', 'Die Duplikatprüfung ist vorübergehend nicht verfügbar. Die Änderungen wurden nicht gespeichert. Bitte erneut versuchen.');
        if ($accept_json) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'msg' => $message]);
            exit;
        }
        $new = false;
        $form = array_replace((array)$record, $values);
        $form['_id'] = $record['_id'];
        include BASEPATH . '/header.php';
        printMsg($message, 'error');
        include BASEPATH . '/pages/events/edit.php';
        include BASEPATH . '/footer.php';
        exit;
    }
    if ($duplicateMatches && ($exactDuplicate || ($_POST['confirm_duplicate'] ?? '') !== '1')) {
        $duplicateMessage = $exactDuplicate
            ? lang('Another event has the same title and date. Change the title or date, or use the existing record.', 'Eine andere Veranstaltung hat denselben Titel und dasselbe Datum. Ändere Titel oder Datum oder verwende den vorhandenen Eintrag.')
            : lang('Similar events already exist. Check the existing records or confirm that this is a distinct event.', 'Ähnliche Veranstaltungen sind bereits vorhanden. Prüfe die vorhandenen Einträge oder bestätige, dass dies eine eigenständige Veranstaltung ist.');
        if ($accept_json) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'duplicate', 'exact' => $exactDuplicate, 'msg' => $duplicateMessage, 'matches' => $duplicateMatches], JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $new = false;
        $form = array_replace((array)$record, $values);
        $form['_id'] = $record['_id'];
        include BASEPATH . '/header.php';
        include BASEPATH . '/pages/events/edit.php';
        include BASEPATH . '/footer.php';
        exit;
    }
    // Updating an event must never replace its ID or existing activity links.
    unset($values['_id'], $values['activities'], $values['conference_id'], $values['created'], $values['created_by']);
    $redirect = false;
    if (isset($values['redirect'])) {
        $redirect = $values['redirect'];
        unset($values['redirect']);
    }
    $values['updated'] = date('Y-m-d');
    $values['updated_by'] = $_SESSION['username'];

    $start = strtotime($values['start']);
    $values['year'] = intval(date('Y', $start));
    $values['month'] = intval(date('n', $start));
    $values['quarter'] = ceil($values['month'] / 3);
    $values['day'] = intval(date('j', $start));
    if (isset($values['public'])) $values['public'] = boolval($values['public'] === 'true');

    $updated = $osiris->conferences->updateOne(
        ['_id' => $DB::to_ObjectID($id)],
        ['$set' => $values]
    );

    if ($accept_json) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'id' => $id, 'event' => EventSearch::eventData(array_replace((array)$record, $values))], JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    $_SESSION['msg'] = lang('Event updated successfully.', 'Veranstaltung erfolgreich aktualisiert.');
    $_SESSION['msg_type'] = 'success';
    header("Location: " . ROOTPATH . "/conferences/view/$id");
}, 'login');


Route::post('/crud/conferences/delete/(.*)', function ($id) {
    include_once BASEPATH . "/php/init.php";
    if (!$Settings->featureEnabled('conferences', true)) {
        abortwith(500, lang('Events are not enabled.', "Veranstaltungen sind nicht aktiviert."));
    }
    $data = $osiris->conferences->findOne(['_id' => DB::to_ObjectID($id)]);
    if (!$data) {
        abortwith(404, lang('Event', "Veranstaltung"), '/conferences');
    }
    if ($data['created_by'] != $_SESSION['username'] && !$Settings->hasPermission('conferences.delete')) {
        abortwith(403, lang('You do not have permission to delete this event.', 'Sie haben keine Berechtigung, diese Veranstaltung zu löschen.'));
    }
    $osiris->conferences->deleteOne(['_id' => DB::to_ObjectID($id)]);
    $_SESSION['msg'] = lang('Event deleted successfully.', 'Veranstaltung erfolgreich gelöscht.');
    $_SESSION['msg_type'] = 'success';
    header("Location: " . ROOTPATH . '/conferences');
}, 'login');


Route::post('/ajax/conferences/toggle-interest', function () {
    include_once BASEPATH . "/php/init.php";
    // only ajax requests
    if ($_SERVER['HTTP_X_REQUESTED_WITH'] != 'XMLHttpRequest') {
        die('No direct access allowed');
    }
    // required data: conference_id
    if (!isset($_POST['conference'])) {
        die('No conference given');
    }
    $conf_id = DB::to_ObjectID($_POST['conference']);
    // get conference
    $conference = $osiris->conferences->findOne(['_id' => $conf_id]);
    $key = $_POST['type'] ?? 'interests';
    // check if user is already interested
    if (in_array($_SESSION['username'], DB::doc2Arr($conference[$key]))) {
        // remove user from interests
        $osiris->conferences->updateOne(['_id' => $conf_id], ['$pull' => [$key => $_SESSION['username']]]);
    } else {
        // add user to interests
        $osiris->conferences->updateOne(['_id' => $conf_id], ['$push' => [$key => $_SESSION['username']]]);
    }
    // return new interest count
    $conference = $osiris->conferences->findOne(['_id' => $conf_id]);
    echo count($conference[$key]);
}, 'login');




Route::post('/crud/deadlines/add', function () {
    include_once BASEPATH . "/php/init.php";
    // check if json is requested from ajax
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $accept_json = strpos($accept, 'application/json') !== false;

    if (!isset($_POST['values'])) {
        if ($accept_json) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'msg' => lang('No values provided.', 'Keine Werte angegeben.')]);
            exit;
        }
        abortwith(500, lang('No values provided.', 'Keine Werte angegeben.'));
    }
    $values = $_POST['values'];

    // required fields:
    if (!isset($values['title']) || !isset($values['date'])) {
        if ($accept_json) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'msg' => lang('Title and Date are needed.', 'Titel und Datum sind erforderliche Felder.')]);
            exit;
        }
        $new = true;
        $form = $values;
        include BASEPATH . "/header.php";
        printMsg(lang('Title and Date are needed.', 'Titel und Datum sind erforderliche Felder.'), 'error', lang('Missing fields', 'Fehlende Daten'));
        include BASEPATH . "/pages/events/edit.php";
        include BASEPATH . "/footer.php";
        exit;
    }
    // check if title, start, location are already in the database
    $existing = $osiris->deadlines->findOne([
        'title' => $values['title'],
        'start' => $values['start']
    ]);
    if ($existing) {
        if ($accept_json) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'warning', 'msg' => lang('An event with the same title and date already exists.', 'Ein Event mit dem gleichen Titel und Datum existiert bereits.'), 'id' => (string)$existing['_id']]);
            exit;
        }
        $_SESSION['msg'] = lang('An event with the same title and date already exists.', 'Ein Event mit dem gleichen Titel und Datum existiert bereits.');
        header("Location: " . ROOTPATH . "/deadlines/view/" . $existing['_id']);
        exit;
    }

    $values['created'] = date('Y-m-d');
    $values['created_by'] = $_SESSION['username'];

    $start = strtotime($values['date']);
    $values['year'] = intval(date('Y', $start));
    $values['month'] = intval(date('n', $start));
    $values['quarter'] = ceil($values['month'] / 3);
    $values['day'] = intval(date('j', $start));

    $values['roles'] = $values['roles'] ?? [];
    if (empty($values['roles'])) {
        $values['roles'] = ['user'];
    } else {
        $values['roles'] = array_keys($values['roles']);
    }

    $added = $osiris->deadlines->insertOne($values);

    $id = $added->getInsertedId();

    if (strpos($accept, 'application/json') !== false) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'id' => (string)$id]);
        exit;
    }
    $_SESSION['msg'] = lang('Event added successfully.', 'Veranstaltung erfolgreich hinzugefügt.');
    $_SESSION['msg_type'] = 'success';
    header("Location: " . ROOTPATH . "/deadlines/view/$id");
}, 'login');


Route::post('/crud/deadlines/update/(.*)', function ($id) {
    include_once BASEPATH . "/php/init.php";

    $values = $_POST['values'];
    $redirect = false;
    if (isset($values['redirect'])) {
        $redirect = $values['redirect'];
        unset($values['redirect']);
    }
    $values['updated'] = date('Y-m-d');
    $values['updated_by'] = $_SESSION['username'];

    $date = strtotime($values['date']);
    $values['year'] = intval(date('Y', $date));
    $values['month'] = intval(date('n', $date));
    $values['quarter'] = ceil($values['month'] / 3);
    $values['day'] = intval(date('j', $date));

    $values['roles'] = $values['roles'] ?? [];
    if (empty($values['roles'])) {
        $values['roles'] = ['user'];
    } else {
        $values['roles'] = array_keys($values['roles']);
    }

    $updated = $osiris->deadlines->updateOne(
        ['_id' => $DB::to_ObjectID($id)],
        ['$set' => $values]
    );

    $_SESSION['msg'] = lang('Deadline updated successfully.', 'Deadline erfolgreich aktualisiert.');
    $_SESSION['msg_type'] = 'success';
    header("Location: " . ROOTPATH . "/deadlines/view/$id");
}, 'login');


Route::post('/crud/deadlines/delete/(.*)', function ($id) {
    include_once BASEPATH . "/php/init.php";
    if (!$Settings->featureEnabled('deadlines', false)) {
        abortwith(500, lang('Deadlines are not enabled.', "Deadlines sind nicht aktiviert."));
    }
    $data = $osiris->deadlines->findOne(['_id' => DB::to_ObjectID($id)]);
    if (!$data) {
        abortwith(404, lang('Event', "Veranstaltung"), '/deadlines');
    }
    if ($data['created_by'] != $_SESSION['username'] && !$Settings->hasPermission('deadlines.delete')) {
        abortwith(403, lang('You do not have permission to delete this event.', 'Sie haben keine Berechtigung, diese Veranstaltung zu löschen.'));
    }
    $osiris->deadlines->deleteOne(['_id' => DB::to_ObjectID($id)]);
    $_SESSION['msg'] = lang('Deadline deleted successfully.', 'Deadline erfolgreich gelöscht.');
    $_SESSION['msg_type'] = 'success';
    header("Location: " . ROOTPATH . '/deadlines');
}, 'login');

Route::get('/deadline/ics/(.*)', function ($id) {
    include BASEPATH . '/php/ICS.php';
    include_once BASEPATH . "/php/init.php";

    $conf_id = DB::to_ObjectID($id);
    // get conference
    $conf = $osiris->deadlines->findOne(['_id' => $conf_id]);

    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename=deadline.ics');

    $ics = new ICS(array(
        'location' => $conf['url'],
        'summary' => $conf['title'],
        'dtstart' => $conf['date'],
        'dtend' => $conf['date'],
        'description' => $conf['description'] ?? '',
    ));

    echo $ics->to_string();
});
