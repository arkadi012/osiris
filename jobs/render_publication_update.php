<?php
/**
 * Read-only rendering bridge for update_publications.py. Reads Extended JSON
 * from stdin and returns a prepared patch. Python performs the atomic write.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', 'stderr');
error_reporting(E_ERROR | E_PARSE);

try {
    define('BASEPATH', dirname(__DIR__));
    require_once BASEPATH . '/CONFIG.php';
    require_once BASEPATH . '/CONFIG.fallback.php';
    if (!is_file(BASEPATH . '/vendor/autoload.php')) {
        throw new RuntimeException('missing_dependencies');
    }
    require_once BASEPATH . '/vendor/autoload.php';
    require_once BASEPATH . '/version.php';
    require_once BASEPATH . '/php/DB.php';
    $input = MongoDB\BSON\toPHP(MongoDB\BSON\fromJSON(stream_get_contents(STDIN)), [
        'root' => 'array', 'document' => 'array', 'array' => 'array',
    ]);
    $DB = new DB();
    $osiris = $DB->db;
    if (($input['database'] ?? '') !== $osiris->getDatabaseName()) {
        throw new RuntimeException('database_mismatch');
    }
    $hello = $osiris->command(['hello' => 1])->toArray()[0];
    if (!isset($input['server_id']) || (string)$input['server_id'] !== (string)($hello['topologyVersion']['processId'] ?? '')) {
        throw new RuntimeException('server_mismatch');
    }
    $version = $osiris->system->findOne(['key' => 'version']);
    if (($version['value'] ?? null) !== OSIRIS_VERSION) {
        // init.php can update system metadata on a version mismatch. Require
        // migration first so this bridge remains strictly read-only.
        throw new RuntimeException('migration_required');
    }
    if (($input['action'] ?? '') === 'check') {
        echo json_encode(['database' => $osiris->getDatabaseName(), 'status' => 'ready']);
        exit;
    }
    $doc = $input['document'] ?? [];
    if (!isset($doc['_id']) || ($doc['type'] ?? '') !== 'publication') {
        throw new RuntimeException('invalid_publication');
    }
    $existing = $osiris->activities->findOne(['_id' => $doc['_id']]);
    if (!$existing || ($existing['doi'] ?? null) !== ($doc['doi'] ?? null)) {
        throw new RuntimeException('publication_not_found');
    }
    $_SESSION = [];
    $_GET = [];
    $_COOKIE = [];
    $_SERVER['REQUEST_URI'] = '/jobs/render-publication-update';
    define('CURRENTYEAR', (int)date('Y'));
    define('CURRENTMONTH', (int)date('n'));
    define('CURRENTQUARTER', (int)ceil(CURRENTMONTH / 3));
    function lang($en, $de = null) { return $en; }
    require_once BASEPATH . '/php/Render.php';
    require_once BASEPATH . '/php/Document.php';

    $prepared = renderAuthorUnits($doc, $doc);
    $Format = new Document(true);
    $Format->setDocument($prepared);
    $language = $Settings->get('render_language', 'en');
    $rendered = $doc['rendered'] ?? [];
    $rendered['icon'] = trim($Format->activity_icon());
    $rendered['type'] = $Format->activity_type();
    $rendered['subtype'] = $Format->activity_subtype();
    $rendered['title'] = $Format->getTitle();
    // Imported records may not have been rendered yet. Fill these fields too,
    // otherwise the presence of a partial rendered object prevents cron repair.
    if (!isset($rendered['quarter'])) {
        $sy = (int)($doc['year'] ?? 0);
        $sm = (int)($doc['month'] ?? 0);
        $ey = (int)($doc['end']['year'] ?? $sy);
        $em = (int)($doc['end']['month'] ?? $sm);
        $sq = $sy . 'Q' . ceil($sm / 3);
        $eq = $ey . 'Q' . ceil($em / 3);
        $rendered['quarter'] = $sq === $eq ? $sq : $sq . ' - ' . ($sy === $ey ? 'Q' . ceil($em / 3) : $eq);
        $rendered['active'] = false;
    }
    $Format->usecase = 'print';
    $rendered['print'] = $Format->format($language);
    $rendered['plain'] = strip_tags($rendered['print']);
    $Format->usecase = 'web';
    $rendered['web'] = $Format->formatShort($language);
    $Format->usecase = 'portal';
    $rendered['portfolio'] = $Format->formatPortfolio($language);
    foreach (['authors', 'editors', 'supervisors'] as $role) {
        $rendered[$role] = $Format->getAuthors($role);
    }
    $rendered['users'] = $Format->getUsers(false);
    $rendered['affiliated_users'] = $Format->getUsers(true);
    $patch = ['rendered' => $rendered, 'units' => $prepared['units']];
    foreach (['start_date', 'end_date'] as $field) {
        if (!array_key_exists($field, $doc) && array_key_exists($field, $prepared)) $patch[$field] = $prepared[$field];
    }
    foreach (['authors', 'editors', 'supervisors'] as $role) {
        if (isset($prepared[$role])) $patch[$role] = $prepared[$role];
    }
    $affiliated = [];
    foreach (['authors', 'editors', 'supervisors'] as $role) {
        $affiliated = array_filter($prepared[$role] ?? [], fn($a) => $a['aoi'] ?? false);
        if ($affiliated) break;
    }
    $patch['affiliated'] = !empty($affiliated);
    $patch['affiliated_positions'] = $Format->getAffiliationTypes('authors');
    $patch['cooperative'] = $Format->getCooperationType($patch['affiliated_positions'], $prepared['units']);
    echo MongoDB\BSON\toRelaxedExtendedJSON(MongoDB\BSON\fromPHP([
        'database' => $osiris->getDatabaseName(), 'patch' => $patch,
    ]));
} catch (Throwable $error) {
    // Do not expose database connection details in job reports.
    $knownErrors = ['missing_dependencies', 'database_mismatch', 'server_mismatch',
        'migration_required', 'invalid_publication', 'publication_not_found'];
    $code = in_array($error->getMessage(), $knownErrors, true) ? $error->getMessage() : 'rendering_failed';
    echo json_encode(['error' => $code]);
    exit(1);
}
