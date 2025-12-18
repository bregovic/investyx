<?php
/**
 * Deployment Hook
 * Called by GitHub Actions after successful deployment.
 * ?token=...&commit_msg=...&commit_sha=...&author=...
 */
header("Content-Type: application/json; charset=utf-8");

$paths = [__DIR__ . '/env.local.php', __DIR__ . '/env.php'];
$loaded = false;
foreach ($paths as $p) { if (file_exists($p)) { require_once $p; $loaded = true; break; } }
if (!$loaded) { echo json_encode(['error' => 'env not found']); exit; }

// Simple security
$token = $_GET['token'] ?? '';
$expectedToken = defined('DEPLOY_TOKEN') ? DEPLOY_TOKEN : 'investyx_secret_123';
if ($token !== $expectedToken) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $msg = $_GET['commit_msg'] ?? 'Deployment';
    $sha = $_GET['commit_sha'] ?? '';
    $author = $_GET['author'] ?? 'GitHub Actions';
    
    // 1. Log to development_history
    $title = "Nasazení verze " . substr($sha, 0, 7);
    $description = $msg . "\n\nAutor: " . $author . "\nCommit: " . $sha;
    
    $stmt = $pdo->prepare("INSERT INTO development_history (date, title, description, category) VALUES (NOW(), ?, ?, 'deployment')");
    $stmt->execute([$title, $description]);
    $historyId = $pdo->lastInsertId();

    // 2. Scan for #ID in commit message to update Helpdesk
    preg_match_all('/#(\d+)/', $msg, $matches);
    $updatedTasks = [];
    if (!empty($matches[1])) {
        foreach ($matches[1] as $taskId) {
            $taskId = (int)$taskId;
            // Check if task exists and update status to 'Done'
            $check = $pdo->prepare("SELECT id, status FROM changerequest_log WHERE id = ?");
            $check->execute([$taskId]);
            $task = $check->fetch();
            
            if ($task) {
                $oldStatus = $task['status'];
                $newStatus = 'Done';
                
                if ($oldStatus !== $newStatus) {
                    $update = $pdo->prepare("UPDATE changerequest_log SET status = ?, updated_at = NOW() WHERE id = ?");
                    $update->execute([$newStatus, $taskId]);
                    
                    // Log to history
                    $log = $pdo->prepare("INSERT INTO changerequest_history (request_id, user_id, username, change_type, old_value, new_value) VALUES (?, 0, 'System', 'status', ?, ?)");
                    $log->execute([$taskId, $oldStatus, $newStatus]);
                    
                    // Link history to task
                    $pdo->prepare("UPDATE development_history SET related_task_id = ? WHERE id = ?")->execute([$taskId, $historyId]);
                    
                    $updatedTasks[] = $taskId;
                }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'history_id' => $historyId,
        'updated_tasks' => $updatedTasks
    ]);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
