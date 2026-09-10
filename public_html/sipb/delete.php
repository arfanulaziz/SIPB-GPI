<?php
/**
 * delete.php
 * Delete a draft SIPB document and all its items
 * Permission: only the creator or superadmin can delete
 * Restriction: only documents in 'Draft' status can be deleted
 */

session_start();

header('Content-Type: application/json');

// Check authentication
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['error' => 'Unauthorized']));
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/Database.php';

// Use the global $conn from config.php, not Database class (which has protected $conn)
global $conn;
if (!$conn) {
    http_response_code(500);
    die(json_encode(['error' => 'Database connection failed']));
}

$current_user = $_SESSION['user'] ?? [];
$user_id = $_SESSION['user_id'];
$is_admin = ($current_user['role'] === 'superadmin');

// Get SIPB ID from request
$sipb_id = isset($_GET['id']) ? (int)$_GET['id'] : null;
if (!$sipb_id) {
    http_response_code(400);
    die(json_encode(['error' => 'SIPB ID required']));
}

// Fetch SIPB document using prepared statement
$stmt = $conn->prepare("SELECT id, doc_number, status, created_by FROM sipb_documents WHERE id = ?");
if (!$stmt) {
    http_response_code(500);
    die(json_encode(['error' => 'Database error: ' . $conn->error]));
}
$stmt->bind_param('i', $sipb_id);
$stmt->execute();
$result = $stmt->get_result();
$sipb = $result->fetch_assoc();
if (!$sipb) {
    http_response_code(404);
    die(json_encode(['error' => 'SIPB not found']));
}

// Permission check: only creator or admin
if (!$is_admin && $sipb['created_by'] != $user_id) {
    http_response_code(403);
    die(json_encode(['error' => 'Permission denied. Only creator or admin can delete.']));
}

// Status check: only draft documents can be deleted
if ($sipb['status'] !== 'Draft') {
    http_response_code(400);
    die(json_encode(['error' => "Cannot delete. Only 'Draft' documents can be deleted. Current status: {$sipb['status']}"]));
}

// Delete items first (for safety, even if cascade is configured)
$stmt = $conn->prepare("DELETE FROM sipb_items WHERE sipb_id = ?");
if ($stmt) {
    $stmt->bind_param('i', $sipb_id);
    $stmt->execute();
}

// Then delete the document
$stmt = $conn->prepare("DELETE FROM sipb_documents WHERE id = ?");
if (!$stmt) {
    http_response_code(500);
    die(json_encode(['error' => 'Database error: ' . $conn->error]));
}
$stmt->bind_param('i', $sipb_id);
if (!$stmt->execute()) {
    http_response_code(500);
    die(json_encode(['error' => 'Delete failed: ' . $stmt->error]));
}

// Log audit trail (function is already in config.php)
log_audit($conn, $sipb_id, 'sipb_deleted', "SIPB dihapus: {$sipb['doc_number']}", null, null);

// Return success JSON
http_response_code(200);
echo json_encode([
    'success' => true,
    'message' => "SIPB {$sipb['doc_number']} berhasil dihapus",
    'redirect' => 'list.php'
]);
exit;
