<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file docs/licenses/LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/afl-3.0.php
 *
 * Orphan Image Cleaner for PrestaShop 1.7 & 8.x
 * Scans /img/p/ for images not referenced in ps_image table.
 * Groups results by image ID with expandable format details.
 *
 * @author    PROGERANCE.COM <support@progerance.com>
 * @copyright PROGERANCE - Dubois Arnaud
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License ("AFL") v. 3.0
 */

// ============================================================================
// CONFIGURATION
// ============================================================================

$perPage = isset($_GET['per_page']) ? max(10, min(500, (int) $_GET['per_page'])) : 50;
$securityToken = 'SECURITY_TOKEN';

// ============================================================================
// BOOTSTRAP PRESTASHOP
// ============================================================================

include(__DIR__ . '/config/config.inc.php');
include(__DIR__ . '/init.php');

// ============================================================================
// SÉCURITÉ
// ============================================================================

if (!isset($_GET['token']) || $_GET['token'] !== $securityToken) {
    header('HTTP/1.1 403 Forbidden');
    die('Access denied. Use ?token=YOUR_TOKEN');
}

// ============================================================================
// FONCTIONS
// ============================================================================

function loadAllImageIds(): array
{
    $sql = 'SELECT id_image FROM ' . _DB_PREFIX_ . 'image';
    $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
    $map = [];
    if ($rows) {
        foreach ($rows as $row) {
            $map[(int) $row['id_image']] = true;
        }
    }
    return $map;
}

function extractImageId(string $filename): ?int
{
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $parts = explode('-', $base, 2);
    $id = $parts[0];
    if (ctype_digit($id) && (int) $id > 0) {
        return (int) $id;
    }
    return null;
}

function extractFormatName(string $filename): string
{
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $parts = explode('-', $base, 2);
    return isset($parts[1]) && $parts[1] !== '' ? $parts[1] : 'original';
}

function collectOrphanImages(string $directory, array $imageIds, array &$orphans): void
{
    $entries = @scandir($directory);
    if ($entries === false) {
        return;
    }
    static $imageExtensions = ['jpg' => 1, 'jpeg' => 1, 'png' => 1, 'gif' => 1, 'webp' => 1, 'avif' => 1];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $fullPath = $directory . '/' . $entry;
        if (is_dir($fullPath)) {
            collectOrphanImages($fullPath, $imageIds, $orphans);
            continue;
        }
        $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        if (!isset($imageExtensions[$ext])) {
            continue;
        }
        $baseName = pathinfo($entry, PATHINFO_FILENAME);
        if (in_array($baseName, ['index', 'fileType'], true)) {
            continue;
        }
        $imageId = extractImageId($entry);
        if ($imageId === null) {
            continue;
        }
        if (isset($imageIds[$imageId])) {
            continue;
        }
        $size = @filesize($fullPath) ?: 0;
        $mtime = @filemtime($fullPath) ?: 0;
        $orphans[] = [
            'path'     => $fullPath,
            'relative' => str_replace(_PS_ROOT_DIR_ . '/', '', $fullPath),
            'filename' => $entry,
            'image_id' => $imageId,
            'format'   => extractFormatName($entry),
            'size'     => $size,
            'mtime'    => $mtime,
        ];
    }
}

function groupOrphansByImageId(array $orphans): array
{
    $groups = [];
    foreach ($orphans as $orphan) {
        $id = $orphan['image_id'];
        if (!isset($groups[$id])) {
            $groups[$id] = [
                'image_id'   => $id,
                'files'      => [],
                'total_size' => 0,
                'count'      => 0,
                'mtime'      => 0,
            ];
        }
        $groups[$id]['files'][] = $orphan;
        $groups[$id]['total_size'] += $orphan['size'];
        $groups[$id]['count']++;
        if ($orphan['mtime'] > $groups[$id]['mtime']) {
            $groups[$id]['mtime'] = $orphan['mtime'];
        }
    }
    foreach ($groups as &$group) {
        usort($group['files'], fn($a, $b) => $a['size'] <=> $b['size']);
        $group['thumb'] = $group['files'][0];
    }
    unset($group);
    ksort($groups);
    return array_values($groups);
}

function formatSize(int $bytes): string
{
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2, ',', ' ') . ' Go';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2, ',', ' ') . ' Mo';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 2, ',', ' ') . ' Ko';
    }
    return $bytes . ' o';
}

function buildPageUrl(int $page): string
{
    $params = $_GET;
    $params['page'] = $page;
    return '?' . http_build_query($params);
}

// ============================================================================
// TRAITEMENT SUPPRESSION (POST)
// ============================================================================

$deleteResults = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['token']) || $_POST['token'] !== $securityToken) {
        header('HTTP/1.1 403 Forbidden');
        die('Invalid token.');
    }

    $filesToDelete = [];

    if ($_POST['action'] === 'delete_selected' && !empty($_POST['files'])) {
        $filesToDelete = $_POST['files'];
    } elseif ($_POST['action'] === 'delete_all') {
        $imageIds = loadAllImageIds();
        $allOrphans = [];
        collectOrphanImages(_PS_ROOT_DIR_ . '/img/p', $imageIds, $allOrphans);
        $filesToDelete = array_column($allOrphans, 'relative');
    }

    $imgPDir = realpath(_PS_ROOT_DIR_ . '/img/p');

    foreach ($filesToDelete as $relPath) {
        $fullPath = _PS_ROOT_DIR_ . '/' . $relPath;
        $realPath = realpath($fullPath);

        if ($realPath === false || $imgPDir === false || strpos($realPath, $imgPDir) !== 0) {
            $deleteResults[] = ['path' => $relPath, 'ok' => false, 'reason' => 'invalid_path'];
            continue;
        }

        if (@unlink($realPath)) {
            $deleteResults[] = ['path' => $relPath, 'ok' => true];
            // Walk up the directory tree and remove empty folders up to /img/p/
            $parentDir = dirname($realPath);
            while ($parentDir !== $imgPDir && strlen($parentDir) > strlen($imgPDir)) {
                $remaining = @scandir($parentDir);
                if ($remaining !== false && count($remaining) <= 2) {
                    @rmdir($parentDir);
                    $parentDir = dirname($parentDir);
                } else {
                    break;
                }
            }
        } else {
            $deleteResults[] = ['path' => $relPath, 'ok' => false, 'reason' => 'unlink_failed'];
        }
    }
}

// ============================================================================
// EXECUTION PRINCIPALE
// ============================================================================

$timeStart = microtime(true);

$imageIds = loadAllImageIds();
$totalDbImages = count($imageIds);

$orphans = [];
$imgDir = _PS_ROOT_DIR_ . '/img/p';

if (!is_dir($imgDir)) {
    die('Directory /img/p not found: ' . $imgDir);
}

collectOrphanImages($imgDir, $imageIds, $orphans);

$totalFiles = count($orphans);
$totalSize = array_sum(array_column($orphans, 'size'));

$groups = groupOrphansByImageId($orphans);
$totalGroups = count($groups);

$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$totalPages = max(1, (int) ceil($totalGroups / $perPage));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $perPage;
$pageGroups = array_slice($groups, $offset, $perPage);

$timeEnd = microtime(true);
$duration = round($timeEnd - $timeStart, 2);

?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orphan Image Cleaner - PrestaShop</title>
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f5f5f5;color:#333;padding:20px}
        .container{max-width:1300px;margin:0 auto}
        .header-row{display:flex;align-items:center;gap:12px;margin-bottom:6px}
        h1{color:#2c3e50;font-size:24px}
        .lang-switcher{display:flex;gap:4px;margin-left:auto}
        .lang-btn{cursor:pointer;font-size:22px;opacity:.4;transition:all .15s;background:none;border:2px solid transparent;border-radius:6px;padding:3px 5px;line-height:1}
        .lang-btn:hover{opacity:.7}
        .lang-btn.active{opacity:1;border-color:#3498db;background:#eaf2f8}
        .subtitle{color:#7f8c8d;margin-bottom:20px;font-size:13px}
        .stats{display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap}
        .stat-card{background:#fff;border-radius:8px;padding:14px 18px;box-shadow:0 1px 3px rgba(0,0,0,.08);min-width:150px}
        .stat-card .label{font-size:11px;text-transform:uppercase;color:#95a5a6;letter-spacing:.5px}
        .stat-card .value{font-size:22px;font-weight:700;margin-top:2px}
        .stat-card.danger .value{color:#e74c3c}
        .stat-card.success .value{color:#27ae60}
        .stat-card.info .value{color:#2980b9}
        .toolbar{display:flex;gap:10px;align-items:center;margin-bottom:15px;flex-wrap:wrap}
        .search-box{padding:8px 14px;border:1px solid #ddd;border-radius:6px;font-size:13px;width:380px}
        .search-box:focus{outline:none;border-color:#3498db;box-shadow:0 0 0 2px rgba(52,152,219,.15)}
        .toolbar-right{margin-left:auto;display:flex;gap:8px;align-items:center}
        .per-page{font-size:12px;color:#7f8c8d}
        .per-page select{padding:4px 8px;border:1px solid #ddd;border-radius:4px;font-size:12px}
        .btn{padding:8px 16px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all .15s}
        .btn:hover{transform:translateY(-1px)}
        .btn-danger{background:#e74c3c;color:#fff}
        .btn-danger:hover{background:#c0392b}
        .btn-danger:disabled{background:#bdc3c7;cursor:not-allowed;transform:none}
        .btn-outline{background:#fff;color:#e74c3c;border:1px solid #e74c3c}
        .btn-outline:hover{background:#fdecea}
        .btn-sm{padding:5px 10px;font-size:12px}
        .btn-ghost{background:#ecf0f1;color:#333}
        .btn-ghost:hover{background:#d5dbdb}
        .delete-results{margin-bottom:20px;padding:15px;border-radius:8px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.08)}
        .delete-results h3{margin-bottom:10px;font-size:14px}
        .result-ok{color:#27ae60;font-size:13px}
        .result-err{color:#e74c3c;font-size:13px}
        .delete-summary{display:flex;gap:15px;margin-bottom:8px}
        table{width:100%;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08);border-collapse:collapse}
        th{background:#34495e;color:#fff;padding:10px 14px;text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap}
        td{padding:8px 14px;border-bottom:1px solid #ecf0f1;font-size:13px;vertical-align:middle}
        .cb{width:16px;height:16px;cursor:pointer;accent-color:#3498db}
        .group-row{cursor:pointer;transition:background .1s}
        .group-row:hover{background:#f7f9fb}
        .group-row td{padding:10px 14px}
        .thumb{max-width:60px;max-height:60px;border-radius:4px;border:1px solid #ddd}
        .id-col{font-weight:700;font-size:15px;color:#2c3e50}
        .size-col{white-space:nowrap}
        .path{font-family:"Courier New",monospace;font-size:11px;word-break:break-all;color:#555}
        .badge{display:inline-flex;align-items:center;gap:4px;background:#eaf2f8;color:#2980b9;font-size:11px;font-weight:700;padding:3px 8px;border-radius:10px;white-space:nowrap}
        .badge.single{background:#f0f0f0;color:#7f8c8d}
        .toggle-icon{display:inline-block;width:20px;font-size:16px;color:#95a5a6;transition:transform .2s;text-align:center;user-select:none}
        .group-row.open .toggle-icon{transform:rotate(90deg);color:#3498db}
        .detail-row{display:none}
        .detail-row.visible{display:table-row}
        .detail-row td{background:#fafbfc;padding:6px 14px 6px 56px;font-size:12px;border-bottom:1px solid #f0f0f0}
        .format-name{display:inline-block;background:#f0f0f0;color:#555;font-size:11px;font-weight:600;padding:2px 8px;border-radius:4px;font-family:"Courier New",monospace;min-width:110px;text-align:center}
        .detail-thumb{max-width:40px;max-height:40px;border-radius:3px;border:1px solid #e0e0e0;vertical-align:middle;margin-right:8px}
        .lightest-tag{font-size:10px;color:#27ae60;font-weight:600;margin-left:4px}
        .empty{text-align:center;padding:40px;color:#95a5a6;background:#fff;border-radius:8px}
        .pagination{display:flex;justify-content:center;align-items:center;gap:4px;margin-top:20px;flex-wrap:wrap}
        .pagination a,.pagination span{padding:7px 12px;border-radius:6px;font-size:13px;text-decoration:none;transition:all .15s}
        .pagination a{background:#fff;color:#333;border:1px solid #ddd}
        .pagination a:hover{background:#3498db;color:#fff;border-color:#3498db}
        .pagination .current{background:#3498db;color:#fff;border:1px solid #3498db;font-weight:700}
        .pagination .dots{color:#95a5a6;border:none}
        .pagination .nav-btn{font-weight:600}
        .pagination .disabled{color:#bdc3c7;pointer-events:none;border-color:#ecf0f1}
        .page-info{text-align:center;margin-top:10px;font-size:12px;color:#95a5a6}
        .modal-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:1000;justify-content:center;align-items:center}
        .modal-overlay.active{display:flex}
        .modal{background:#fff;border-radius:12px;padding:25px;max-width:450px;width:90%;box-shadow:0 10px 40px rgba(0,0,0,.2)}
        .modal h3{margin-bottom:10px;color:#e74c3c}
        .modal p{margin-bottom:18px;font-size:14px;color:#555;line-height:1.5}
        .modal-actions{display:flex;gap:10px;justify-content:flex-end}
        .btn-cancel{background:#ecf0f1;color:#333}
        .btn-cancel:hover{background:#d5dbdb}
        .footer{margin-top:20px;font-size:11px;color:#95a5a6;text-align:center}
        tr.search-hidden{display:none!important}
    </style>
</head>
<body>
<div class="container">
    <div class="header-row">
        <h1>&#x1F9F9; <span data-i18n="title">Nettoyage des images orphelines</span></h1>
        <div class="lang-switcher">
            <button type="button" class="lang-btn" data-lang="fr" onclick="setLang('fr')" title="Fran&ccedil;ais">&#x1F1EB;&#x1F1F7;</button>
            <button type="button" class="lang-btn" data-lang="en" onclick="setLang('en')" title="English">&#x1F1EC;&#x1F1E7;</button>
        </div>
    </div>
    <p class="subtitle">PrestaShop &mdash; <span data-i18n="subtitle_scan">Analyse de</span> <code>/img/p/</code> &mdash; <?= date('d/m/Y H:i:s') ?></p>

    <?php if (!empty($deleteResults)): ?>
        <?php
        $delOk = count(array_filter($deleteResults, fn($r) => $r['ok']));
        $delErr = count($deleteResults) - $delOk;
        ?>
        <div class="delete-results">
            <h3 data-i18n="delete_results_title">R&eacute;sultat de la suppression</h3>
            <div class="delete-summary">
                <span class="result-ok">&#10003; <span data-i18n="files_deleted" data-count="<?= $delOk ?>"><?= $delOk ?> fichier(s) supprim&eacute;(s)</span></span>
                <?php if ($delErr > 0): ?>
                    <span class="result-err">&#10007; <span data-i18n="errors_count" data-count="<?= $delErr ?>"><?= $delErr ?> erreur(s)</span></span>
                <?php endif; ?>
            </div>
            <?php if ($delErr > 0): ?>
                <?php foreach ($deleteResults as $r): ?>
                    <?php if (!$r['ok']): ?>
                        <div class="result-err" style="font-size:12px">&#10007; <?= htmlspecialchars($r['path']) ?> &mdash; <span data-i18n="err_<?= htmlspecialchars($r['reason'] ?? 'unknown') ?>"></span></div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="stats">
        <div class="stat-card info">
            <div class="label" data-i18n="stat_db_images">Images en DB</div>
            <div class="value"><?= number_format($totalDbImages, 0, ',', ' ') ?></div>
        </div>
        <div class="stat-card danger">
            <div class="label" data-i18n="stat_orphan_ids">IDs orphelins</div>
            <div class="value"><?= number_format($totalGroups, 0, ',', ' ') ?></div>
        </div>
        <div class="stat-card danger">
            <div class="label" data-i18n="stat_orphan_files">Fichiers orphelins</div>
            <div class="value"><?= number_format($totalFiles, 0, ',', ' ') ?></div>
        </div>
        <div class="stat-card info">
            <div class="label" data-i18n="stat_recoverable">Espace r&eacute;cup&eacute;rable</div>
            <div class="value"><span data-i18n-size data-bytes="<?= $totalSize ?>"><?= formatSize($totalSize) ?></span></div>
        </div>
        <div class="stat-card">
            <div class="label" data-i18n="stat_duration">Dur&eacute;e scan</div>
            <div class="value"><?= $duration ?>s</div>
        </div>
    </div>

    <?php if ($totalGroups === 0): ?>
        <div class="empty">
            <p style="font-size:18px">&#10004; <span data-i18n="empty_title">Aucune image orpheline trouv&eacute;e !</span></p>
            <p style="margin-top:8px"><span data-i18n="empty_subtitle_before">Le dossier</span> <code>/img/p/</code> <span data-i18n="empty_subtitle_after">est propre.</span></p>
        </div>
    <?php else: ?>

        <div class="toolbar">
            <input type="text" class="search-box" id="searchBox" data-i18n-placeholder="search_placeholder" placeholder="&#x1F50D; Filtrer par ID ou nom de fichier (ex: 174986*.avif)" autocomplete="off" />
            <button type="button" class="btn btn-sm btn-ghost" onclick="toggleAll(true)">&#9660; <span data-i18n="expand_all">Tout d&eacute;plier</span></button>
            <button type="button" class="btn btn-sm btn-ghost" onclick="toggleAll(false)">&#9650; <span data-i18n="collapse_all">Tout replier</span></button>
            <span class="per-page">
                <span data-i18n="show">Afficher</span>
                <select id="perPageSelect" onchange="changePerPage(this.value)">
                    <?php foreach ([25, 50, 100, 200] as $pp): ?>
                        <option value="<?= $pp ?>" <?= $pp === $perPage ? 'selected' : '' ?>><?= $pp ?></option>
                    <?php endforeach; ?>
                </select>
                / page
            </span>
            <div class="toolbar-right">
                <button type="button" class="btn btn-danger btn-sm" id="btnDeleteSelected" disabled onclick="confirmDeleteSelected()">
                    &#x1F5D1; <span data-i18n="delete_selection">Supprimer la s&eacute;lection</span> (<span id="selectedCount">0</span>)
                </button>
                <button type="button" class="btn btn-outline btn-sm" onclick="confirmDeleteAll()">
                    &#9888; <span data-i18n="delete_all_btn" data-count="<?= $totalFiles ?>">Tout supprimer (<?= number_format($totalFiles, 0, ',', ' ') ?> fichiers)</span>
                </button>
            </div>
        </div>

        <form method="POST" id="deleteForm">
            <input type="hidden" name="token" value="<?= htmlspecialchars($securityToken) ?>" />
            <input type="hidden" name="action" id="formAction" value="delete_selected" />

            <table>
                <thead>
                    <tr>
                        <th style="width:30px"><input type="checkbox" class="cb" id="checkAll" data-i18n-title="check_all" title="Tout cocher" /></th>
                        <th style="width:24px"></th>
                        <th style="width:70px" data-i18n="th_preview">Aper&ccedil;u</th>
                        <th style="width:80px">ID</th>
                        <th>Formats</th>
                        <th style="width:130px" data-i18n="th_total_size">Taille totale</th>
                        <th style="width:140px" data-i18n="th_last_modified">Derni&egrave;re modif.</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <?php foreach ($pageGroups as $gIdx => $group): ?>
                    <?php $gId = 'g' . $gIdx; ?>

                    <?php
                    $searchFileNames = array_map(fn($f) => basename($f['relative']), $group['files']);
                    $searchData = $group['image_id'] . ' ' . implode(' ', $searchFileNames);
                    ?>
                    <tr class="group-row" data-group="<?= $gId ?>" data-search="<?= htmlspecialchars($searchData) ?>">
                        <td onclick="event.stopPropagation()">
                            <input type="checkbox" class="cb group-cb" data-group="<?= $gId ?>" onchange="toggleGroupCheckboxes(this)" />
                        </td>
                        <td><span class="toggle-icon">&#x203A;</span></td>
                        <td>
                            <img class="thumb" src="/<?= htmlspecialchars($group['thumb']['relative']) ?>" alt="<?= $group['image_id'] ?>" loading="lazy" onerror="this.style.display='none'" />
                        </td>
                        <td class="id-col"><?= $group['image_id'] ?></td>
                        <td>
                            <span class="badge <?= $group['count'] === 1 ? 'single' : '' ?>" data-i18n="file_count" data-count="<?= $group['count'] ?>">
                                <?= $group['count'] ?> fichier<?= $group['count'] > 1 ? 's' : '' ?>
                            </span>
                            <?php
                            $formats = array_map(fn($f) => $f['format'], $group['files']);
                            $shortList = implode(', ', array_slice($formats, 0, 5));
                            if (count($formats) > 5) {
                                $shortList .= ', ...';
                            }
                            ?>
                            <span style="font-size:11px;color:#95a5a6;margin-left:6px"><?= htmlspecialchars($shortList) ?></span>
                        </td>
                        <td class="size-col"><strong><span data-i18n-size data-bytes="<?= $group['total_size'] ?>"><?= formatSize($group['total_size']) ?></span></strong></td>
                        <td class="date-col" style="font-size:12px;color:#555;white-space:nowrap"><?= $group['mtime'] > 0 ? date('d/m/Y H:i', $group['mtime']) : '-' ?></td>
                    </tr>

                    <?php foreach ($group['files'] as $fIdx => $file): ?>
                    <tr class="detail-row" data-group="<?= $gId ?>" data-search="<?= $group['image_id'] ?> <?= htmlspecialchars(basename($file['relative'])) ?>">
                        <td onclick="event.stopPropagation()" style="padding-left:30px">
                            <input type="checkbox" class="cb row-cb" name="files[]" data-group="<?= $gId ?>" value="<?= htmlspecialchars($file['relative']) ?>" />
                        </td>
                        <td></td>
                        <td>
                            <img class="detail-thumb" src="/<?= htmlspecialchars($file['relative']) ?>" loading="lazy" onerror="this.style.display='none'" />
                        </td>
                        <td></td>
                        <td>
                            <span class="format-name"><?= htmlspecialchars($file['format']) ?></span>
                            <span class="path" style="margin-left:10px"><?= htmlspecialchars($file['relative']) ?></span>
                        </td>
                        <td class="size-col">
                            <span data-i18n-size data-bytes="<?= $file['size'] ?>"><?= formatSize($file['size']) ?></span>
                            <?php if ($fIdx === 0 && $group['count'] > 1): ?>
                                <span class="lightest-tag">&#x25CF; <span data-i18n="lightest">le + l&eacute;ger</span></span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:11px;color:#777;white-space:nowrap"><?= $file['mtime'] > 0 ? date('d/m/Y H:i', $file['mtime']) : '-' ?></td>
                    </tr>
                    <?php endforeach; ?>

                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>

        <?php if ($totalPages > 1): ?>
        <nav class="pagination">
            <?php if ($currentPage > 1): ?>
                <a href="<?= buildPageUrl(1) ?>" class="nav-btn">&laquo;</a>
                <a href="<?= buildPageUrl($currentPage - 1) ?>" class="nav-btn">&lsaquo;</a>
            <?php else: ?>
                <span class="nav-btn disabled">&laquo;</span>
                <span class="nav-btn disabled">&lsaquo;</span>
            <?php endif; ?>

            <?php
            $range = 2;
            $startPage = max(1, $currentPage - $range);
            $endPage = min($totalPages, $currentPage + $range);
            if ($startPage > 1): ?>
                <a href="<?= buildPageUrl(1) ?>">1</a>
                <?php if ($startPage > 2): ?><span class="dots">&hellip;</span><?php endif; ?>
            <?php endif;
            for ($p = $startPage; $p <= $endPage; $p++):
                if ($p === $currentPage): ?>
                    <span class="current"><?= $p ?></span>
                <?php else: ?>
                    <a href="<?= buildPageUrl($p) ?>"><?= $p ?></a>
                <?php endif;
            endfor;
            if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?><span class="dots">&hellip;</span><?php endif; ?>
                <a href="<?= buildPageUrl($totalPages) ?>"><?= $totalPages ?></a>
            <?php endif; ?>

            <?php if ($currentPage < $totalPages): ?>
                <a href="<?= buildPageUrl($currentPage + 1) ?>" class="nav-btn">&rsaquo;</a>
                <a href="<?= buildPageUrl($totalPages) ?>" class="nav-btn">&raquo;</a>
            <?php else: ?>
                <span class="nav-btn disabled">&rsaquo;</span>
                <span class="nav-btn disabled">&raquo;</span>
            <?php endif; ?>
        </nav>
        <p class="page-info">
            Page <?= $currentPage ?> / <?= $totalPages ?>
            &mdash; IDs <?= number_format($offset + 1, 0, ',', ' ') ?> <span data-i18n="page_to">&agrave;</span> <?= number_format(min($offset + $perPage, $totalGroups), 0, ',', ' ') ?>
            <span data-i18n="page_of">sur</span> <?= number_format($totalGroups, 0, ',', ' ') ?>
        </p>
        <?php endif; ?>

    <?php endif; ?>

    <p class="footer">Progerance &mdash; Clean Orphan Images v3.2</p>
</div>

<div class="modal-overlay" id="confirmModal">
    <div class="modal">
        <h3>&#9888; <span data-i18n="modal_title">Confirmation</span></h3>
        <p id="modalMessage"></p>
        <div class="modal-actions">
            <button type="button" class="btn btn-cancel" onclick="closeModal()" data-i18n="modal_cancel">Annuler</button>
            <button type="button" class="btn btn-danger" id="modalConfirmBtn" data-i18n="modal_confirm">Confirmer</button>
        </div>
    </div>
</div>

<script>
/* ================================================================
   I18N - Translation System
   ================================================================ */
var I18N={
fr:{
    title:"Nettoyage des images orphelines",
    subtitle_scan:"Analyse de",
    delete_results_title:"R\u00e9sultat de la suppression",
    files_deleted:"{0} fichier(s) supprim\u00e9(s)",
    errors_count:"{0} erreur(s)",
    err_invalid_path:"Chemin invalide",
    err_unlink_failed:"\u00c9chec de la suppression",
    err_unknown:"Erreur inconnue",
    stat_db_images:"Images en DB",
    stat_orphan_ids:"IDs orphelins",
    stat_orphan_files:"Fichiers orphelins",
    stat_recoverable:"Espace r\u00e9cup\u00e9rable",
    stat_duration:"Dur\u00e9e scan",
    empty_title:"Aucune image orpheline trouv\u00e9e !",
    empty_subtitle_before:"Le dossier",
    empty_subtitle_after:"est propre.",
    search_placeholder:"\uD83D\uDD0D Filtrer par ID ou nom de fichier (ex: 174986*.avif)",
    expand_all:"Tout d\u00e9plier",
    collapse_all:"Tout replier",
    show:"Afficher",
    delete_selection:"Supprimer la s\u00e9lection",
    delete_all_btn:"Tout supprimer ({0} fichiers)",
    check_all:"Tout cocher",
    th_preview:"Aper\u00e7u",
    th_total_size:"Taille totale",
    th_last_modified:"Derni\u00e8re modif.",
    file_count_one:"{0} fichier",
    file_count_many:"{0} fichiers",
    lightest:"le + l\u00e9ger",
    page_to:"\u00e0",
    page_of:"sur",
    modal_title:"Confirmation",
    modal_cancel:"Annuler",
    modal_confirm:"Confirmer",
    confirm_selected:"Supprimer {0} fichier(s) orphelin(s) s\u00e9lectionn\u00e9(s) ? Cette action est irr\u00e9versible.",
    confirm_all:"Supprimer TOUS les {0} fichiers orphelins ({1} IDs) ? Cette action est irr\u00e9versible !",
    size_b:"o",size_kb:"Ko",size_mb:"Mo",size_gb:"Go",
    size_dec:",",size_th:" "
},
en:{
    title:"Orphan Image Cleanup",
    subtitle_scan:"Scanning",
    delete_results_title:"Deletion Results",
    files_deleted:"{0} file(s) deleted",
    errors_count:"{0} error(s)",
    err_invalid_path:"Invalid path",
    err_unlink_failed:"Unlink failed",
    err_unknown:"Unknown error",
    stat_db_images:"DB Images",
    stat_orphan_ids:"Orphan IDs",
    stat_orphan_files:"Orphan Files",
    stat_recoverable:"Recoverable Space",
    stat_duration:"Scan Duration",
    empty_title:"No orphan images found!",
    empty_subtitle_before:"The",
    empty_subtitle_after:"folder is clean.",
    search_placeholder:"\uD83D\uDD0D Filter by ID or filename (e.g.: 174986*.avif)",
    expand_all:"Expand All",
    collapse_all:"Collapse All",
    show:"Show",
    delete_selection:"Delete Selection",
    delete_all_btn:"Delete All ({0} files)",
    check_all:"Check All",
    th_preview:"Preview",
    th_total_size:"Total Size",
    th_last_modified:"Last Modified",
    file_count_one:"{0} file",
    file_count_many:"{0} files",
    lightest:"lightest",
    page_to:"to",
    page_of:"of",
    modal_title:"Confirmation",
    modal_cancel:"Cancel",
    modal_confirm:"Confirm",
    confirm_selected:"Delete {0} selected orphan file(s)? This action is irreversible.",
    confirm_all:"Delete ALL {0} orphan files ({1} IDs)? This action is irreversible!",
    size_b:"B",size_kb:"KB",size_mb:"MB",size_gb:"GB",
    size_dec:".",size_th:","
}
};

var curLang=localStorage.getItem('orphan_cleaner_lang')||'fr';

function t(key,params){
    var s=(I18N[curLang]&&I18N[curLang][key])||(I18N.fr[key])||key;
    if(params){for(var i=0;i<params.length;i++) s=s.replace('{'+i+'}',params[i]);}
    return s;
}

function fmtSize(bytes){
    var d=t('size_dec'),th=t('size_th');
    function f(n,p){
        var s=n.toFixed(p),parts=s.split('.');
        parts[0]=parts[0].replace(/\B(?=(\d{3})+(?!\d))/g,th);
        return p>0?parts[0]+d+parts[1]:parts[0];
    }
    if(bytes>=1073741824) return f(bytes/1073741824,2)+' '+t('size_gb');
    if(bytes>=1048576) return f(bytes/1048576,2)+' '+t('size_mb');
    if(bytes>=1024) return f(bytes/1024,2)+' '+t('size_kb');
    return bytes+' '+t('size_b');
}

function setLang(lang){
    curLang=lang;
    localStorage.setItem('orphan_cleaner_lang',lang);
    document.documentElement.lang=lang;

    /* Flag buttons */
    document.querySelectorAll('.lang-btn').forEach(function(b){
        b.classList.toggle('active',b.dataset.lang===lang);
    });

    /* data-i18n text */
    document.querySelectorAll('[data-i18n]').forEach(function(el){
        var key=el.dataset.i18n;
        var count=el.dataset.count;

        if(key==='file_count'&&count!==undefined){
            var n=parseInt(count);
            el.textContent=t(n>1?'file_count_many':'file_count_one',[n]);
        } else if((key==='files_deleted'||key==='errors_count')&&count!==undefined){
            el.textContent=t(key,[count]);
        } else if(key==='delete_all_btn'&&count!==undefined){
            el.textContent=t(key,[Number(count).toLocaleString()]);
        } else {
            el.textContent=t(key);
        }
    });

    /* data-i18n-placeholder */
    document.querySelectorAll('[data-i18n-placeholder]').forEach(function(el){
        el.placeholder=t(el.dataset.i18nPlaceholder);
    });

    /* data-i18n-title */
    document.querySelectorAll('[data-i18n-title]').forEach(function(el){
        el.title=t(el.dataset.i18nTitle);
    });

    /* data-i18n-size (bytes formatting) */
    document.querySelectorAll('[data-i18n-size]').forEach(function(el){
        var b=parseInt(el.dataset.bytes);
        if(!isNaN(b)) el.textContent=fmtSize(b);
    });
}

/* Apply saved language immediately */
setLang(curLang);

/* ================================================================
   Toggle groups
   ================================================================ */
document.querySelectorAll('.group-row').forEach(function(row){
    row.addEventListener('click',function(e){
        if(e.target.type==='checkbox')return;
        toggleGroup(this.dataset.group);
    });
});

function toggleGroup(gid){
    var gr=document.querySelector('.group-row[data-group="'+gid+'"]');
    var ds=document.querySelectorAll('.detail-row[data-group="'+gid+'"]');
    var open=gr.classList.toggle('open');
    ds.forEach(function(d){d.classList.toggle('visible',open)});
}

function toggleAll(open){
    document.querySelectorAll('.group-row').forEach(function(row){
        if(row.classList.contains('search-hidden'))return;
        var gid=row.dataset.group;
        var ds=document.querySelectorAll('.detail-row[data-group="'+gid+'"]');
        if(open){row.classList.add('open');ds.forEach(function(d){d.classList.add('visible')})}
        else{row.classList.remove('open');ds.forEach(function(d){d.classList.remove('visible')})}
    });
}

/* ================================================================
   Checkboxes
   ================================================================ */
function toggleGroupCheckboxes(gcb){
    var gid=gcb.dataset.group;
    document.querySelectorAll('.row-cb[data-group="'+gid+'"]').forEach(function(cb){cb.checked=gcb.checked});
    updateSelectedCount();
}

var ca=document.getElementById('checkAll');
if(ca){ca.addEventListener('change',function(){
    var c=this.checked;
    document.querySelectorAll('.group-cb').forEach(function(cb){
        if(!cb.closest('tr').classList.contains('search-hidden')){cb.checked=c;toggleGroupCheckboxes(cb)}
    });
    updateSelectedCount();
})}

document.addEventListener('change',function(e){
    if(e.target.classList.contains('row-cb')){
        var gid=e.target.dataset.group;
        var rcs=document.querySelectorAll('.row-cb[data-group="'+gid+'"]');
        var gc=document.querySelector('.group-cb[data-group="'+gid+'"]');
        if(gc)gc.checked=Array.from(rcs).every(function(c){return c.checked});
        updateSelectedCount();
    }
});

function updateSelectedCount(){
    var n=document.querySelectorAll('.row-cb:checked').length;
    document.getElementById('selectedCount').textContent=n;
    document.getElementById('btnDeleteSelected').disabled=n===0;
}

/* ================================================================
   Search with wildcard * support
   ================================================================ */
function buildSearchMatcher(query){
    query=query.trim().toLowerCase();
    if(query==='')return null;
    if(query.indexOf('*')!==-1){
        var escaped=query.replace(/([.+?^${}()|[\]\\])/g,'\\$1').replace(/\*/g,'.*');
        return new RegExp(escaped);
    }
    return query;
}
function matchSearch(text,matcher){
    if(matcher===null)return true;
    text=text.toLowerCase();
    if(typeof matcher==='string')return text.indexOf(matcher)!==-1;
    return matcher.test(text);
}
var sb=document.getElementById('searchBox');
if(sb){sb.addEventListener('input',function(){
    var matcher=buildSearchMatcher(this.value);
    document.querySelectorAll('.group-row').forEach(function(row){
        var m=matchSearch(row.dataset.search||'',matcher);
        var gid=row.dataset.group;
        var ds=document.querySelectorAll('.detail-row[data-group="'+gid+'"]');
        row.classList.toggle('search-hidden',!m);
        if(!m){ds.forEach(function(d){d.classList.remove('visible');d.classList.add('search-hidden')});row.classList.remove('open')}
        else{ds.forEach(function(d){d.classList.remove('search-hidden')})}
    });
})}

/* ================================================================
   Modals (translated)
   ================================================================ */
function confirmDeleteSelected(){
    var n=document.querySelectorAll('.row-cb:checked').length;
    if(n===0)return;
    document.getElementById('modalMessage').textContent=t('confirm_selected',[n]);
    document.getElementById('modalConfirmBtn').onclick=function(){document.getElementById('formAction').value='delete_selected';document.getElementById('deleteForm').submit()};
    document.getElementById('confirmModal').classList.add('active');
}

function confirmDeleteAll(){
    document.getElementById('modalMessage').textContent=t('confirm_all',['<?= $totalFiles ?>','<?= $totalGroups ?>']);
    document.getElementById('modalConfirmBtn').onclick=function(){document.getElementById('formAction').value='delete_all';document.getElementById('deleteForm').submit()};
    document.getElementById('confirmModal').classList.add('active');
}

function closeModal(){document.getElementById('confirmModal').classList.remove('active')}
document.getElementById('confirmModal').addEventListener('click',function(e){if(e.target===this)closeModal()});

/* ================================================================
   Per page
   ================================================================ */
function changePerPage(v){var u=new URL(window.location);u.searchParams.set('per_page',v);u.searchParams.set('page','1');window.location=u.toString()}
</script>
</body>
</html>
