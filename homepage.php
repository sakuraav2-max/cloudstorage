<?php
session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
  header('Location: login.php');
  exit;
}

// Ensure per-user uploads directory exists
$userId = (int)$_SESSION['user_id'];
$uploadsRoot = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
$userDir = $uploadsRoot . DIRECTORY_SEPARATOR . $userId;
$starredDir = $userDir . DIRECTORY_SEPARATOR . '.starred';
$trashDir = $userDir . DIRECTORY_SEPARATOR . '.trash';
if (!is_dir($uploadsRoot)) {
  @mkdir($uploadsRoot, 0775, true);
}
if (!is_dir($userDir)) {
  @mkdir($userDir, 0775, true);
}
if (!is_dir($starredDir)) {
  @mkdir($starredDir, 0775, true);
}
if (!is_dir($trashDir)) {
  @mkdir($trashDir, 0775, true);
}

$uploadError = '';
$folderError = '';

// Sorting and view options
$sort = isset($_GET['sort']) ? (string)$_GET['sort'] : 'recent'; // recent|name|size|type|date
$view = isset($_GET['view']) ? (string)$_GET['view'] : 'grid'; // grid|list
$filter = isset($_GET['filter']) ? (string)$_GET['filter'] : 'all'; // all|folders|documents|images|videos|archives|others
$section = isset($_GET['section']) ? (string)$_GET['section'] : 'all'; // all|starred|recent|trash

// Current subdirectory (one level for simplicity)
$currentDirName = '';
if (!empty($_GET['dir'])) {
  $candidate = trim((string)$_GET['dir']);
  // sanitize to prevent traversal
  $candidate = basename($candidate);
  if ($candidate !== '' && is_dir($userDir . DIRECTORY_SEPARATOR . $candidate)) {
    $currentDirName = $candidate;
  }
}

// Determine current directory path based on section
if ($section === 'starred') {
  $currentDirPath = $starredDir;
} elseif ($section === 'trash') {
  $currentDirPath = $trashDir;
} else {
  $currentDirPath = $currentDirName ? ($userDir . DIRECTORY_SEPARATOR . $currentDirName) : $userDir;
}

// Handle multi-actions (download/delete) before output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['selected']) && is_array($_POST['selected'])) {
  $action = $_POST['action'];
  $selected = array_map(function($s){ return basename((string)$s); }, $_POST['selected']);
  if ($action === 'delete_selected') {
    foreach ($selected as $name) {
      $path = $currentDirPath . DIRECTORY_SEPARATOR . $name;
      if ($section === 'trash') {
        // Permanently delete from Trash
        if (is_file($path)) {
          @unlink($path);
        } else if (is_dir($path)) {
          // remove only if empty
          @rmdir($path);
        }
      } else {
        // Move to Trash
        $destBase = $trashDir . DIRECTORY_SEPARATOR . $name;
        $dest = $destBase;
        if (file_exists($dest)) {
          $extPos = strrpos($name, '.');
          if ($extPos !== false && is_file($path)) {
            $base = substr($name, 0, $extPos);
            $ext = substr($name, $extPos);
            $dest = $trashDir . DIRECTORY_SEPARATOR . $base . ' (' . date('Y-m-d_His') . ')' . $ext;
          } else {
            $dest = $trashDir . DIRECTORY_SEPARATOR . $name . ' (' . date('Y-m-d_His') . ')';
          }
        }
        @rename($path, $dest);
      }
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query(['dir'=>$currentDirName,'sort'=>$sort,'view'=>$view,'filter'=>$filter,'section'=>$section]));
    exit;
  } elseif ($action === 'download_selected') {
    $zipName = 'download_' . date('Ymd_His') . '.zip';
    $zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $zipName;
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
      foreach ($selected as $name) {
        $path = $currentDirPath . DIRECTORY_SEPARATOR . $name;
        if (is_file($path)) {
          $zip->addFile($path, $name);
        } elseif (is_dir($path)) {
          // add empty folder entry
          $zip->addEmptyDir($name);
          // optional: skip recursive for simplicity
        }
      }
      $zip->close();
      header('Content-Type: application/zip');
      header('Content-Disposition: attachment; filename="' . $zipName . '"');
      header('Content-Length: ' . filesize($zipPath));
      readfile($zipPath);
      @unlink($zipPath);
      exit;
    }
  } elseif ($action === 'star_selected' && $section !== 'starred') {
    foreach ($selected as $name) {
      $src = $currentDirPath . DIRECTORY_SEPARATOR . $name;
      if (is_file($src)) {
        $dest = $starredDir . DIRECTORY_SEPARATOR . $name;
        if (!@copy($src, $dest)) {
          // ignore copy failures
        }
      }
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query(['dir'=>$currentDirName,'sort'=>$sort,'view'=>$view,'filter'=>$filter,'section'=>$section]));
    exit;
  } elseif ($action === 'unstar_selected' && $section === 'starred') {
    foreach ($selected as $name) {
      $path = $currentDirPath . DIRECTORY_SEPARATOR . $name;
      if (is_file($path)) { @unlink($path); }
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query(['dir'=>$currentDirName,'sort'=>$sort,'view'=>$view,'filter'=>$filter,'section'=>$section]));
    exit;
  }
}
// Handle new folder creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['folder_name'])) {
  $folderName = trim((string)$_POST['folder_name']);
  $folderName = preg_replace('/[^A-Za-z0-9 _.-]/', '_', $folderName);
  if ($folderName === '') {
    $folderError = 'Folder name cannot be empty.';
  } else {
    $targetFolder = $currentDirPath . DIRECTORY_SEPARATOR . $folderName;
    if (is_dir($targetFolder)) {
      $folderError = 'Folder already exists.';
    } else if (!@mkdir($targetFolder, 0775, false)) {
      $folderError = 'Failed to create folder.';
    }
  }
}

// Handle file upload (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
  if (!is_uploaded_file($_FILES['file']['tmp_name'])) {
    $uploadError = 'No file uploaded.';
  } else {
    // Enforce storage quota before saving
    $currentUsage = calculateUserStats($userDir);
    $fileSize = (int)($_FILES['file']['size'] ?? 0);
    if (($currentUsage['size'] + $fileSize) > (2 * 1024 * 1024 * 1024)) {
      $uploadError = 'Upload would exceed your 2GB storage limit.';
    } else {
    $originalName = basename($_FILES['file']['name']);
    $ext = pathinfo($originalName, PATHINFO_EXTENSION);
    $safeBase = preg_replace('/[^A-Za-z0-9_.-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
    $unique = $safeBase . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
    $targetName = $ext ? ($unique . '.' . $ext) : $unique;
    $targetPath = $currentDirPath . DIRECTORY_SEPARATOR . $targetName;
      if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
        $uploadError = 'Failed to save file.';
      }
    }
  }
}

// Helper to map extension to category
function mapCategory($filename) {
  $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
  $docs = ['pdf','doc','docx','txt','md','ppt','pptx','xls','xlsx','csv'];
  $imgs = ['jpg','jpeg','png','gif','webp','svg','bmp'];
  $vids = ['mp4','mov','avi','mkv','webm'];
  $arch = ['zip','rar','7z','gz','tar'];
  if (in_array($ext, $docs, true)) return 'documents';
  if (in_array($ext, $imgs, true)) return 'images';
  if (in_array($ext, $vids, true)) return 'videos';
  if (in_array($ext, $arch, true)) return 'archives';
  return 'others';
}

// Build folders and files list based on section
$folders = [];
$files = [];

if ($section === 'recent') {
  // Get all files from user directory, sorted by recent
  if (is_dir($userDir)) {
    $iterator = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($userDir, RecursiveDirectoryIterator::SKIP_DOTS),
      RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
      if ($file->isFile() && !str_contains($file->getPath(), '.starred') && !str_contains($file->getPath(), '.trash')) {
        $relativePath = str_replace($userDir . DIRECTORY_SEPARATOR, '', $file->getPathname());
        $files[] = [
          'name' => $file->getFilename(),
          'size' => $file->getSize(),
          'path' => 'uploads/' . $userId . '/' . rawurlencode($relativePath),
          'mtime' => $file->getMTime(),
          'category' => mapCategory($file->getFilename())
        ];
      }
    }
    // Sort by most recent and limit to 20
    usort($files, function($a, $b) { return $b['mtime'] <=> $a['mtime']; });
    $files = array_slice($files, 0, 20);
  }
} else {
  // Regular directory listing
  if (is_dir($currentDirPath)) {
    $dirItems = scandir($currentDirPath);
    foreach ($dirItems as $item) {
      if ($item === '.' || $item === '..' || $item === '.starred' || $item === '.trash') { continue; }
      $path = $currentDirPath . DIRECTORY_SEPARATOR . $item;
      if (is_dir($path)) {
        $folders[] = [
          'name' => $item,
          'path' => 'homepage.php?dir=' . rawurlencode($currentDirName ? ($currentDirName . '/' . $item) : $item) . '&section=' . $section,
          'mtime' => filemtime($path)
        ];
      } else if (is_file($path)) {
        $pathPrefix = '';
        if ($section === 'starred') {
          $pathPrefix = 'uploads/' . $userId . '/.starred/';
        } elseif ($section === 'trash') {
          $pathPrefix = 'uploads/' . $userId . '/.trash/';
        } else {
          $pathPrefix = 'uploads/' . $userId . '/' . ($currentDirName ? rawurlencode($currentDirName) . '/' : '');
        }
        
        $files[] = [
          'name' => $item,
          'size' => filesize($path),
          'path' => $pathPrefix . rawurlencode($item),
          'mtime' => filemtime($path),
          'category' => mapCategory($item)
        ];
      }
    }
  }
}

// Sorting (only if not recent section, which is already sorted)
if ($section !== 'recent') {
  if ($sort === 'name') {
    usort($folders, function($a,$b){ return strcasecmp($a['name'],$b['name']); });
    usort($files, function($a,$b){ return strcasecmp($a['name'],$b['name']); });
  } elseif ($sort === 'size') {
    usort($files, function($a,$b){ return ($b['size'] <=> $a['size']); });
    usort($folders, function($a,$b){ return strcasecmp($a['name'],$b['name']); });
  } elseif ($sort === 'type') {
    usort($files, function($a,$b){ 
      $extA = strtolower(pathinfo($a['name'], PATHINFO_EXTENSION));
      $extB = strtolower(pathinfo($b['name'], PATHINFO_EXTENSION));
      return strcasecmp($extA, $extB) ?: strcasecmp($a['name'], $b['name']);
    });
    usort($folders, function($a,$b){ return strcasecmp($a['name'],$b['name']); });
  } elseif ($sort === 'date') {
    usort($folders, function($a, $b) { return $b['mtime'] <=> $a['mtime']; });
    usort($files, function($a, $b) { return $b['mtime'] <=> $a['mtime']; });
  } else { // recent (default)
    usort($folders, function($a, $b) { return $b['mtime'] <=> $a['mtime']; });
    usort($files, function($a, $b) { return $b['mtime'] <=> $a['mtime']; });
  }
}

// Calculate user storage statistics
function calculateUserStats($userDir) {
  $totalFiles = 0;
  $totalSize = 0;
  
  if (!is_dir($userDir)) {
    return ['files' => 0, 'size' => 0];
  }
  
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($userDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
  );
  
  foreach ($iterator as $file) {
    if ($file->isFile()) {
      $totalFiles++;
      $totalSize += $file->getSize();
    }
  }
  
  return ['files' => $totalFiles, 'size' => $totalSize];
}

function humanSize($bytes) {
  $units = ['B','KB','MB','GB','TB'];
  $i = 0;
  while ($bytes >= 1024 && $i < count($units)-1) { $bytes /= 1024; $i++; }
  return sprintf('%s %s', $i >= 2 ? number_format($bytes, 1) : (int)$bytes, $units[$i]);
}

// Get real user statistics
$userStats = calculateUserStats($userDir);
$totalFiles = $userStats['files'];
$usedBytes = $userStats['size'];
$maxBytes = 2 * 1024 * 1024 * 1024; // 2GB limit
$usedPercentage = $maxBytes > 0 ? round(($usedBytes / $maxBytes) * 100, 1) : 0;
$usedHuman = humanSize($usedBytes);
$maxHuman = humanSize($maxBytes);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>CloudBox — Cloud Storage</title>
  <style>
    :root{
      --bg: #071427;
      --panel: #0e2940;
      --muted: #9fb3c8;
      --text: #e6f3fb;
      --accent: #4fc3f7;
      --glass: rgba(255,255,255,0.03);
      --success: #7ee787;
    }
    *{box-sizing:border-box}
    html,body{height:100%;margin:0;font-family:Inter,ui-sans-serif,system-ui,Segoe UI,Roboto,Arial;color:var(--text);background:linear-gradient(180deg,var(--bg),#041225);-webkit-font-smoothing:antialiased}

    .app{width:100%;height:100vh;padding:20px;display:flex;flex-direction:column;margin:0}

    /* Header */
    .topbar{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:18px}
    .brand{display:flex;align-items:center;gap:12px}
    .logo{width:56px;height:56px;border-radius:12px;background:linear-gradient(135deg,var(--accent),#1db7ff);display:flex;align-items:center;justify-content:center;font-weight:800;color:#082232}
    .brand h1{font-size:20px;margin:0}
    .search{flex:1;display:flex;justify-content:center}
    .search input{width:60%;min-width:220px;padding:10px 14px;border-radius:10px;border:1px solid rgba(255,255,255,0.04);background:#ffffff;color:#04202a;outline:none}
    .actions{display:flex;gap:10px;align-items:center}
    .btn{background:var(--accent);border:none;padding:10px 14px;border-radius:10px;color:#04202a;font-weight:700;cursor:pointer;box-shadow:0 8px 30px rgba(79,195,247,0.08)}
    .ghost{background:transparent;border:1px solid rgba(255,255,255,0.04);padding:9px 12px;border-radius:10px;color:var(--muted);cursor:pointer}

    /* Layout */
    .layout{display:grid;grid-template-columns:280px 1fr;gap:24px;flex:1;min-height:0}
    .sidebar{background:var(--panel);padding:18px;border-radius:12px;border:1px solid rgba(255,255,255,0.03)}
    .sidebar .nav{display:flex;flex-direction:column;gap:8px}
    .nav a{display:flex;gap:10px;align-items:center;padding:10px;border-radius:8px;color:var(--muted);text-decoration:none;transition:all .2s}
    .nav a:hover{background:linear-gradient(90deg,rgba(79,195,247,0.06),rgba(79,195,247,0.02));color:var(--text)}
    .nav a.active{background:linear-gradient(90deg,rgba(79,195,247,0.1),rgba(79,195,247,0.04));color:var(--accent);font-weight:600}

    .main{min-height:70vh;display:flex;flex-direction:column}

    /* Quick stats */
    .stats{display:flex;gap:12px;margin-bottom:16px}
    .stat{flex:1;padding:14px;border-radius:12px;background:linear-gradient(180deg, rgba(255,255,255,0.02), transparent);border:1px solid rgba(255,255,255,0.03)}
    .stat h3{margin:0 0 8px}
    .stat p{color:var(--muted);margin:0}

    /* Drive area */
    .drive{background:var(--panel);padding:16px;border-radius:12px;border:1px solid rgba(255,255,255,0.03);flex:1;display:flex;flex-direction:column;min-height:0}
    .drive-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
    .upload{display:flex;gap:10px;align-items:center}
    .upload input[type=file]{display:none}
    .upload label{cursor:pointer}

    .files-container{flex:1;overflow-y:auto;min-height:200px;padding-right:8px}
    .files-container::-webkit-scrollbar{width:8px}
    .files-container::-webkit-scrollbar-track{background:rgba(255,255,255,0.04);border-radius:4px}
    .files-container::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.15);border-radius:4px}
    .files-container::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,0.25)}
    .files-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}
    .list-view .files-grid{display:block}
    .list-item{display:flex;align-items:center;gap:12px;padding:10px;border-radius:10px;border:1px solid rgba(255,255,255,0.03);margin-bottom:8px;background:linear-gradient(180deg, rgba(255,255,255,0.01), transparent);transition:transform .15s,box-shadow .15s}
    .list-item:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(2,6,23,0.4)}
    .list-item .file-thumb{width:48px;height:48px;font-size:12px;display:flex;align-items:center;justify-content:center}
    .list-item .file-meta{flex:1;min-width:0}
    .list-item .file-meta .name{font-size:14px}
    .list-item .file-meta .muted{font-size:12px}
    .checkbox-hidden input[type="checkbox"]{display:none}
    .file-card{background:linear-gradient(180deg, rgba(255,255,255,0.01), transparent);padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,0.03);transition:transform .25s,box-shadow .25s}
    .file-card:hover{transform:translateY(-8px);box-shadow:0 18px 40px rgba(2,6,23,0.6)}
    .file-thumb{height:110px;border-radius:8px;background:linear-gradient(135deg,#09273a,#124e72);display:flex;align-items:center;justify-content:center;font-weight:800}
    .file-meta{margin-top:8px;display:flex;justify-content:space-between;align-items:center;gap:8px}
    .file-meta .name{font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0;flex:1}
    .muted{color:var(--muted)}

    /* Empty state */
    .empty{padding:24px;border-radius:10px;text-align:center;color:var(--muted)}

    /* Pricing card */
    .pricing{display:flex;gap:12px;margin-top:16px}
    .plan{flex:1;padding:18px;border-radius:12px;background:linear-gradient(180deg, rgba(255,255,255,0.02), transparent);border:1px solid rgba(255,255,255,0.03)}
    .plan h4{margin:0 0 8px}

    /* Footer */
    footer{margin-top:20px;color:var(--muted);font-size:13px;text-align:center}

    /* Responsive Design */
    
    /* Large Desktop (1200px+) - Default styles already applied */
    
    /* Desktop/Laptop (768px - 1199px) */
    @media (max-width:1199px){
      .layout{grid-template-columns:260px 1fr;gap:18px}
      .app{padding:16px}
    }
    
    /* Tablet (768px - 979px) */
    @media (max-width:979px){
      .layout{grid-template-columns:1fr;gap:16px}
      .sidebar{order:2;margin-top:16px}
      .main{order:1}
      .search input{width:80%;min-width:200px}
      .brand h1{font-size:18px}
      .app{padding:12px}
      .stats{flex-wrap:wrap}
      .stat{min-width:150px}
      .files-container{min-height:300px}
      .files-grid{grid-template-columns:repeat(auto-fit,minmax(160px,1fr))}
    }
    
    /* Mobile Landscape (640px - 767px) */
    @media (max-width:767px){
      .topbar{flex-wrap:wrap;gap:12px}
      .brand{flex:1;min-width:200px}
      .search{flex:1;min-width:200px}
      .actions{flex:1;justify-content:flex-end;min-width:150px}
      .search input{width:100%}
      .stats{flex-direction:column;gap:8px}
      .stat{width:100%}
      .drive-head{flex-direction:column;align-items:stretch;gap:12px}
      .upload{justify-content:center;flex-wrap:wrap}
      .files-container{min-height:250px;max-height:none!important}
      .files-grid{grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px}
      .list-item{padding:8px;gap:8px}
      .list-item .file-thumb{width:40px;height:40px;font-size:10px}
    }
    
    /* Mobile Portrait (480px - 639px) */
    @media (max-width:639px){
      .topbar{flex-direction:column;align-items:stretch}
      .brand{justify-content:center;text-align:center}
      .search{order:2;width:100%}
      .actions{order:3;justify-content:center;margin-top:8px}
      .search input{width:100%;font-size:16px}
      .layout{gap:12px}
      .sidebar{padding:12px}
      .drive{padding:12px}
      .files-container{max-height:none!important;height:auto!important}
      .files-grid{grid-template-columns:repeat(auto-fill,minmax(120px,1fr))!important;gap:6px}
      .file-card{padding:8px;display:block!important}
      .file-thumb{height:80px;font-size:12px}
      .file-meta .name{font-size:12px}
      .list-view .files-grid{display:block!important}
      .list-item{display:flex!important;flex-direction:row!important;align-items:center!important;text-align:left!important;padding:8px!important;margin-bottom:4px}
      .list-item .file-thumb{width:40px!important;height:40px!important;font-size:10px!important;flex-shrink:0}
      .list-item .file-meta{text-align:left!important;flex:1;margin-left:8px}
      .btn,.ghost{padding:8px 12px;font-size:14px}
      .app{padding:8px}
      .filter-tabs{justify-content:flex-start!important;gap:4px}
      .filter-tabs .ghost{padding:4px 8px;font-size:12px}
      .action-buttons{justify-content:flex-start!important;gap:6px}
    }
    
    /* Small Mobile (320px - 479px) */
    @media (max-width:479px){
      .brand h1{font-size:16px}
      .logo{width:40px;height:40px}
      .files-container{max-height:none!important;height:auto!important;overflow-y:visible!important}
      .files-grid{grid-template-columns:repeat(auto-fill,minmax(100px,1fr))!important;gap:4px}
      .file-card{padding:6px;display:block!important;min-height:auto!important}
      .file-thumb{height:60px;font-size:10px}
      .file-meta .name{font-size:11px}
      .list-item{display:flex!important;flex-direction:row!important;align-items:center!important;padding:6px!important;margin-bottom:2px}
      .list-item .file-thumb{width:32px!important;height:32px!important;font-size:8px!important}
      .list-item .file-meta{margin-left:6px}
      .stat{padding:10px}
      .stat h3{font-size:18px}
      .drive-head h2{font-size:18px}
      .sidebar{font-size:14px}
      .nav a{padding:8px;font-size:14px}
      .btn,.ghost{padding:6px 10px;font-size:13px}
      .app{padding:6px}
    }
    
    /* Extra Small Mobile (below 320px) */
    @media (max-width:319px){
      .files-container{max-height:none!important;height:auto!important;overflow-y:visible!important}
      .files-grid{grid-template-columns:1fr 1fr!important;gap:4px}
      .file-card{display:block!important}
      .file-thumb{height:50px;font-size:8px}
      .list-item{display:flex!important;flex-direction:row!important;align-items:center!important}
      .brand h1{font-size:14px}
      .logo{width:32px;height:32px}
      .btn,.ghost{padding:4px 8px;font-size:12px}
    }
    
    /* Touch-friendly improvements */
    @media (hover: none) and (pointer: coarse) {
      .btn,.ghost,input,select{min-height:44px}
      .file-card,.list-item{min-height:44px}
      .nav a{min-height:40px}
    }
    
    /* High DPI displays */
    @media (-webkit-min-device-pixel-ratio: 2), (min-resolution: 192dpi) {
      .file-thumb{font-weight:900}
    }

    /* subtle animations */
    .pulse{animation:pulse 2.5s infinite}
    @keyframes pulse{0%{box-shadow:0 6px 20px rgba(79,195,247,0.04)}50%{box-shadow:0 14px 30px rgba(79,195,247,0.08)}100%{box-shadow:0 6px 20px rgba(79,195,247,0.04)}}

  </style>
</head>
<body>
  <div class="app">
    <div class="topbar">
      <div class="brand">
        <div class="logo">FS</div>
        <div>
          <h1>FronStorage</h1>
          <div class="muted">Secure cloud storage for your files</div>
        </div>
      </div>

      <div class="search" style="position:relative">
        <input id="searchBox" placeholder="Search files or folders..." autocomplete="off" />
        <div id="suggestions" style="position:absolute;top:44px;left:50%;transform:translateX(-50%);width:60%;min-width:220px;background:var(--panel);border:1px solid rgba(255,255,255,0.06);border-radius:10px;display:none;z-index:10;overflow:hidden"></div>
      </div>

      <div class="actions">
        <a class="ghost" href="logout.php" style="text-decoration:none;display:inline-block">Logout</a>
        <form id="newFolderForm" method="post" style="display:inline">
          <input type="hidden" name="folder_name" id="folderNameHidden">
          <button type="button" class="btn" onclick="createFolder()">New Folder</button>
        </form>
      </div>
    </div>

    <div class="layout">
      <aside class="sidebar">
        <div style="margin-bottom:12px;font-weight:700">My Drive</div>
        <nav class="nav">
          <a href="homepage.php?section=all" class="<?php echo $section==='all'?'active':''; ?>"><span>📁</span>&nbsp; All Files</a>
          <a href="homepage.php?section=starred" class="<?php echo $section==='starred'?'active':''; ?>"><span>⭐</span>&nbsp; Starred</a>
          <a href="homepage.php?section=recent" class="<?php echo $section==='recent'?'active':''; ?>"><span>🕘</span>&nbsp; Recent</a>
          <a href="homepage.php?section=trash" class="<?php echo $section==='trash'?'active':''; ?>"><span>🗑️</span>&nbsp; Trash</a>
        </nav>

        <div style="margin-top:18px">
          <div class="muted" style="font-size:13px;margin-bottom:8px">Storage</div>
          <div style="height:10px;background:rgba(255,255,255,0.04);border-radius:8px;overflow:hidden">
            <div style="width:<?php echo $usedPercentage; ?>%;height:100%;background:linear-gradient(90deg,var(--accent),#2ad0ff)"></div>
          </div>
          <div class="muted" style="margin-top:8px;font-size:13px"><?php echo $usedPercentage; ?>% used • <?php echo $usedHuman; ?> of <?php echo $maxHuman; ?></div>
        </div>

        <div style="margin-top:18px">
          <div class="muted" style="font-size:13px;margin-bottom:8px">Plan</div>
          <div style="display:flex;flex-direction:column;gap:8px;margin-top:6px">
            <div style="padding:10px;border-radius:8px;background:linear-gradient(90deg,rgba(255,255,255,0.02),transparent);border:1px solid rgba(255,255,255,0.03)">Free • 2 GB</div>
          </div>
        </div>

      </aside>

      <main class="main">
        <div class="stats">
          <div class="stat">
            <h3><?php echo $usedHuman; ?></h3>
            <p class="muted">Used storage</p>
          </div>
          <div class="stat">
            <h3><?php echo number_format($totalFiles); ?></h3>
            <p class="muted">Files stored</p>
          </div>
          <div class="stat pulse">
            <h3><?php echo $usedPercentage; ?>%</h3>
            <p class="muted">Storage used</p>
          </div>
        </div>

        <section class="drive">
          <div class="drive-head">
            <div>
              <?php
                $sectionTitles = [
                  'all' => $currentDirName ? ('Folder: ' . htmlspecialchars($currentDirName)) : 'All Files',
                  'starred' => 'Starred Files',
                  'recent' => 'Recent Files',
                  'trash' => 'Trash'
                ];
                $title = $sectionTitles[$section] ?? 'All Files';
              ?>
              <h2 style="margin:0"><?php echo $title; ?></h2>
              <div class="muted">View: <?php echo htmlspecialchars($view); ?> • Sort: <?php echo htmlspecialchars($sort); ?></div>
            </div>
            <div class="upload">
              <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center">
                <label for="uploadFile" class="btn">Upload</label>
                <input id="uploadFile" name="file" type="file" onchange="this.form.submit()">
              </form>
              <button class="ghost" type="button" onclick="toggleSelect(this)" id="selectBtn">Select</button>
              <button class="ghost" type="button" onclick="toggleAll(this)" id="selectAllBtn" style="display:none">Select All</button>
              <button class="ghost" type="submit" name="action" value="delete_selected" id="deleteBtn" style="display:none" form="fileForm">Delete Selected</button>
              <button class="btn" type="submit" name="action" value="download_selected" id="downloadBtn" style="display:none" form="fileForm">Download Selected</button>
              <?php if ($section==='starred'): ?>
                <button class="ghost" type="submit" name="action" value="unstar_selected" id="starBtn" style="display:none" form="fileForm">Unstar Selected</button>
              <?php else: ?>
                <button class="ghost" type="submit" name="action" value="star_selected" id="starBtn" style="display:none" form="fileForm">Star Selected</button>
              <?php endif; ?>
              <div class="ghost" style="display:flex;gap:6px;align-items:center">
                <label class="muted" for="sortSel" style="font-size:13px">Sort</label>
                <select id="sortSel" onchange="applySortView()" style="background:transparent;color:var(--text);border:1px solid rgba(255,255,255,0.06);border-radius:8px;padding:6px">
                  <option value="recent" <?php echo $sort==='recent'?'selected':''; ?>>Recent</option>
                  <option value="name" <?php echo $sort==='name'?'selected':''; ?>>Name (A-Z)</option>
                  <option value="date" <?php echo $sort==='date'?'selected':''; ?>>Date</option>
                  <option value="size" <?php echo $sort==='size'?'selected':''; ?>>Size</option>
                  <option value="type" <?php echo $sort==='type'?'selected':''; ?>>Type</option>
                </select>
              </div>
              <div class="ghost" style="display:flex;gap:6px;align-items:center">
                <label class="muted" for="viewSel" style="font-size:13px">View</label>
                <select id="viewSel" onchange="applySortView()" style="background:transparent;color:var(--text);border:1px solid rgba(255,255,255,0.06);border-radius:8px;padding:6px">
                  <option value="grid" <?php echo $view==='grid'?'selected':''; ?>>Grid</option>
                  <option value="list" <?php echo $view==='list'?'selected':''; ?>>List</option>
                </select>
              </div>
            </div>
          </div>

          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;justify-content:center" class="filter-tabs">
            <?php
              $filters = ['all'=>'All','folders'=>'Folders','documents'=>'Documents','images'=>'Images','videos'=>'Videos','archives'=>'Archives','others'=>'Others'];
              foreach($filters as $key=>$label){
                $qs = ['dir'=>$currentDirName,'sort'=>$sort,'view'=>$view,'filter'=>$key,'section'=>$section];
                $active = $filter === $key ? 'font-weight:700;border-color:rgba(79,195,247,0.6)' : '';
                echo '<a class="ghost" style="text-decoration:none;padding:6px 10px;border-radius:999px;'.$active.'" href="?'.htmlspecialchars(http_build_query($qs)).'">'.htmlspecialchars($label).'</a>';
              }
            ?>
          </div>

          <?php if (!empty($uploadError)): ?>
          <div class="empty" style="border:1px solid rgba(255,255,255,0.05);margin-bottom:12px;color:#ffb4b4">Upload error: <?php echo htmlspecialchars($uploadError); ?></div>
          <?php endif; ?>
          <?php if (!empty($folderError)): ?>
          <div class="empty" style="border:1px solid rgba(255,255,255,0.05);margin-bottom:12px;color:#ffb4b4">Folder error: <?php echo htmlspecialchars($folderError); ?></div>
          <?php endif; ?>

          <div class="files-container">
          <form method="post" id="fileForm">
          <div class="files-grid <?php echo $view==='list'?'list-view':''; ?> checkbox-hidden" id="filesContainer">
            <?php if (empty($files) && empty($folders)): ?>
              <div class="empty">No items yet. Use Upload or New Folder to get started.</div>
            <?php else: ?>
              <?php
                $showFolder = $filter==='all' || $filter==='folders';
                if ($showFolder) {
                  foreach ($folders as $d) {
                    echo '<label class="file-card" style="position:relative;display:block">';
                    echo '<input type="checkbox" name="selected[]" value="'.htmlspecialchars($d['name']).'" style="position:absolute;top:8px;left:8px">';
                    echo '<a href="'.htmlspecialchars($d['path']).'" style="text-decoration:none;color:inherit;display:block">';
                    echo '<div class="file-thumb">DIR</div>';
                    echo '<div class="file-meta"><div class="name">'.htmlspecialchars($d['name']).'</div><div class="muted">Folder</div></div>';
                    echo '</a>';
                    echo '</label>';
                  }
                }
                foreach ($files as $f) {
                  if ($filter!=='all' && $filter!==$f['category']) continue;
                  if ($view==='list') {
                    echo '<label class="list-item" style="position:relative;cursor:pointer">';
                    echo '<input type="checkbox" name="selected[]" value="'.htmlspecialchars($f['name']).'" style="margin-right:8px">';
                    echo '<div class="file-thumb">'.strtoupper(pathinfo($f['name'], PATHINFO_EXTENSION) ?: 'FILE').'</div>';
                    echo '<div class="file-meta"><div class="name">'.htmlspecialchars($f['name']).'</div><div class="muted">'.humanSize($f['size']).' • '.date('M j, Y', $f['mtime']).'</div></div>';
                    echo '<a href="'.htmlspecialchars($f['path']).'" target="_blank" style="color:var(--accent);text-decoration:none;margin-left:auto;padding:4px 8px;border-radius:6px;border:1px solid rgba(79,195,247,0.3)">Open</a>';
                    echo '</label>';
                  } else {
                    echo '<label class="file-card" style="position:relative;display:block">';
                    echo '<input type="checkbox" name="selected[]" value="'.htmlspecialchars($f['name']).'" style="position:absolute;top:8px;left:8px">';
                    echo '<a href="'.htmlspecialchars($f['path']).'" target="_blank" style="text-decoration:none;color:inherit;display:block">';
                    echo '<div class="file-thumb">'.strtoupper(pathinfo($f['name'], PATHINFO_EXTENSION) ?: 'FILE').'</div>';
                    echo '<div class="file-meta"><div class="name">'.htmlspecialchars($f['name']).'</div><div class="muted">'.humanSize($f['size']).'</div></div>';
                    echo '</a>';
                    echo '</label>';
                  }
                }
              ?>
            <?php endif; ?>
          </div>
          </form>
          </div>
          
          <div style="display:flex;gap:8px;margin-top:10px;align-items:center;padding-top:12px;border-top:1px solid rgba(255,255,255,0.06);flex-wrap:wrap;justify-content:center" class="action-buttons"></div>

          <div class="pricing" style="margin-top:18px;justify-content:center;text-align:center">
            <div class="plan" style="text-align:center">
              <h4>Free</h4>
              <div class="muted">2 GB storage • Basic support</div>
            </div>
          </div>

        </section>
      </main>
    </div>

    <footer>
      Built with ♥ • Logged in as User #<?php echo $userId; ?>
    </footer>
  </div>
  <script>
    // Build searchable index from PHP arrays
    const INDEX_ITEMS = [
      // folders first
      <?php foreach ($folders as $d): ?>
      { type:'dir', label: <?php echo json_encode($d['name']); ?>, url: <?php echo json_encode($d['path']); ?> },
      <?php endforeach; ?>
      <?php foreach ($files as $f): ?>
      { type:'file', label: <?php echo json_encode($f['name']); ?>, url: <?php echo json_encode($f['path']); ?> },
      <?php endforeach; ?>
    ];

    const searchBox = document.getElementById('searchBox');
    const sugg = document.getElementById('suggestions');
    function renderSuggestions(items){
      if(!items.length){sugg.style.display='none';sugg.innerHTML='';return;}
      sugg.innerHTML = items.map(it=>`<a href="${it.url}" style="display:block;padding:10px 12px;color:inherit;text-decoration:none;border-bottom:1px solid rgba(255,255,255,0.04)">${it.type==='dir'?'📁':'📄'} ${escapeHtml(it.label)}</a>`).join('');
      sugg.style.display='block';
    }
    function escapeHtml(s){return s.replace(/[&<>"']/g, m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#039;"}[m]));}
    searchBox && searchBox.addEventListener('input', function(){
      const q = this.value.trim().toLowerCase();
      if(!q){ renderSuggestions([]); return; }
      const out = INDEX_ITEMS.filter(it=> it.label.toLowerCase().includes(q)).slice(0,8);
      renderSuggestions(out);
    });
    document.addEventListener('click', function(e){ if(!sugg.contains(e.target) && e.target!==searchBox){ sugg.style.display='none'; }});

    function createFolder(){
      const name = prompt('New folder name');
      if(!name) return;
      document.getElementById('folderNameHidden').value = name;
      document.getElementById('newFolderForm').submit();
    }

    function applySortView(){
      const params = new URLSearchParams(window.location.search);
      const dir = params.get('dir') || '';
      const sortSel = document.getElementById('sortSel').value;
      const viewSel = document.getElementById('viewSel').value;
      const filter = params.get('filter') || 'all';
      const section = params.get('section') || 'all';
      const qs = new URLSearchParams({dir, sort:sortSel, view:viewSel, filter, section});
      window.location.search = qs.toString();
    }

    function toggleSelect(btn){
      const container = document.getElementById('filesContainer');
      const selectAllBtn = document.getElementById('selectAllBtn');
      const deleteBtn = document.getElementById('deleteBtn');
      const downloadBtn = document.getElementById('downloadBtn');
      const starBtn = document.getElementById('starBtn');
      
      if(container.classList.contains('checkbox-hidden')){
        // Show checkboxes and other buttons
        container.classList.remove('checkbox-hidden');
        btn.textContent = 'Cancel';
        selectAllBtn.style.display = 'inline-block';
        deleteBtn.style.display = 'inline-block';
        downloadBtn.style.display = 'inline-block';
        if(starBtn){ starBtn.style.display = 'inline-block'; }
      } else {
        // Hide checkboxes and other buttons
        container.classList.add('checkbox-hidden');
        btn.textContent = 'Select';
        selectAllBtn.style.display = 'none';
        deleteBtn.style.display = 'none';
        downloadBtn.style.display = 'none';
        if(starBtn){ starBtn.style.display = 'none'; }
        // Uncheck all
        const inputs = document.querySelectorAll('#filesContainer input[type=checkbox]');
        inputs.forEach(i=> i.checked = false);
        document.getElementById('selectAllBtn').textContent = 'Select All';
      }
    }

    function toggleAll(btn){
      const inputs = document.querySelectorAll('#filesContainer input[type=checkbox]');
      const allChecked = Array.from(inputs).every(i=>i.checked);
      inputs.forEach(i=> i.checked = !allChecked);
      btn.textContent = allChecked ? 'Select All' : 'Unselect All';
    }
  </script>
</body>
</html>
