<?php
include 'config/database.php';
include 'includes/auth.php';
require_once 'includes/notifications.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireAuth();

$notification = new Notification($db);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['mark_all_read'])) {
        $notification->markAllAsRead($_SESSION['user_id']);
        $success = "All notifications marked as read!";
    } elseif (isset($_POST['delete_read'])) {
        $notification->deleteReadNotifications($_SESSION['user_id']);
        $success = "All read notifications deleted!";
    } elseif (isset($_POST['notification_id'])) {
        $notification->markAsRead($_POST['notification_id'], $_SESSION['user_id']);
        // Redirect to avoid form resubmission
        header("Location: notifications.php");
        exit();
    }
}

// Get all notifications for the user
$query = "SELECT * FROM notifications 
          WHERE user_id = :user_id 
          ORDER BY created_at DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->execute();
$all_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count unread notifications
$unread_count = $notification->getUnreadCount($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Task Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .notification-item {
            border-left: 4px solid transparent;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        .notification-item:hover {
            background-color: #f8f9fa;
            transform: translateX(2px);
        }
        .notification-unread {
            border-left-color: #007bff;
            background-color: #e7f3ff;
        }
        .notification-critical {
            border-left-color: #dc3545;
        }
        .notification-deadline {
            border-left-color: #ffc107;
        }
        .notification-bug {
            border-left-color: #6f42c1;
        }
        .notification-task {
            border-left-color: #28a745;
        }
        .notification-date {
            font-size: 0.85rem;
        }
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #6c757d;
        }
        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            flex-shrink: 0;
        }
        .icon-critical {
            background-color: #dc3545;
            color: white;
        }
        .icon-deadline {
            background-color: #ffc107;
            color: #212529;
        }
        .icon-bug {
            background-color: #6f42c1;
            color: white;
        }
        .icon-task {
            background-color: #28a745;
            color: white;
        }
        .icon-default {
            background-color: #007bff;
            color: white;
        }
        .mark-read-form {
            display: inline;
        }
        .action-buttons {
            display: flex;
            gap: 10px;
        }
        .notification-content {
            flex-grow: 1;
        }
        .no-click {
            pointer-events: none;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>
                        <i class="fas fa-bell"></i> Notifications
                        <?php if ($unread_count > 0): ?>
                            <span class="badge bg-danger ms-2"><?= $unread_count ?> unread</span>
                        <?php endif; ?>
                    </h2>
                    
                    <div class="action-buttons">
                        <?php if ($unread_count > 0): ?>
                        <form method="POST" class="d-inline">
                            <button type="submit" name="mark_all_read" class="btn btn-success btn-sm">
                                <i class="fas fa-check-double"></i> Mark All as Read
                            </button>
                        </form>
                        <?php endif; ?>
                        
                        <?php if (count($all_notifications) > 0): ?>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete all read notifications?');">
                            <button type="submit" name="delete_read" class="btn btn-danger btn-sm">
                                <i class="fas fa-trash"></i> Delete Read
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (isset($success)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?= $success ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-body p-0">
                        <?php if (empty($all_notifications)): ?>
                            <div class="empty-state">
                                <i class="fas fa-bell-slash fa-3x mb-3"></i>
                                <h4>No notifications yet</h4>
                                <p class="text-muted">You don't have any notifications at the moment.</p>
                            </div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($all_notifications as $notif): 
                                    $is_unread = !$notif['is_read'];
                                    $icon_class = '';
                                    $icon_bg = '';
                                    $border_class = '';
                                    
                                    // Determine icon based on notification type
                                    switch ($notif['type']) {
                                        case 'deadline':
                                            $icon_class = 'fas fa-clock';
                                            $icon_bg = 'icon-deadline';
                                            $border_class = 'notification-deadline';
                                            break;
                                        case 'bug_report':
                                        case 'bug_update':
                                        case 'bug_status_update':
                                        case 'overdue':
                                            $icon_class = 'fas fa-bug';
                                            $icon_bg = 'icon-bug';
                                            $border_class = 'notification-bug';
                                            break;
                                        case 'critical':
                                            $icon_class = 'fas fa-exclamation-triangle';
                                            $icon_bg = 'icon-critical';
                                            $border_class = 'notification-critical';
                                            break;
                                        case 'assignment':
                                        case 'task_update':
                                        case 'status_update':
                                            $icon_class = 'fas fa-tasks';
                                            $icon_bg = 'icon-task';
                                            $border_class = 'notification-task';
                                            break;
                                        default:
                                            $icon_class = 'fas fa-info-circle';
                                            $icon_bg = 'icon-default';
                                            $border_class = '';
                                    }
                                ?>
                                <div class="list-group-item notification-item <?= $is_unread ? 'notification-unread' : '' ?> <?= $border_class ?> p-4" 
                                     onclick="markAsRead(<?= $notif['id'] ?>, this)">
                                    <div class="d-flex align-items-start">
                                        <div class="notification-icon <?= $icon_bg ?>">
                                            <i class="<?= $icon_class ?>"></i>
                                        </div>
                                        
                                        <div class="notification-content">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <h6 class="mb-1 <?= $is_unread ? 'fw-bold' : '' ?>">
                                                    <?= htmlspecialchars($notif['title']) ?>
                                                </h6>
                                                <div class="d-flex align-items-center gap-2 no-click">
                                                    <small class="text-muted notification-date">
                                                        <i class="far fa-clock"></i> 
                                                        <?= date('M j, Y g:i A', strtotime($notif['created_at'])) ?>
                                                    </small>
                                                </div>
                                            </div>
                                            
                                            <p class="mb-2"><?= htmlspecialchars($notif['message']) ?></p>
                                            
                                            <div class="d-flex justify-content-between align-items-center no-click">
                                                <small class="text-muted">
                                                    <span class="badge bg-light text-dark border">
                                                        <i class="fas fa-tag me-1"></i> <?= ucfirst(str_replace('_', ' ', $notif['type'])) ?>
                                                    </span>
                                                    <?php if ($is_unread): ?>
                                                        <span class="badge bg-primary ms-2">Unread</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary ms-2">Read</span>
                                                    <?php endif; ?>
                                                </small>
                                                
                                                <?php if (!empty($notif['related_id']) && !empty($notif['related_type'])): ?>
                                                    <a href="<?= htmlspecialchars($notif['related_type']) ?>.php?id=<?= $notif['related_id'] ?>" 
                                                       class="btn btn-sm btn-outline-primary">
                                                        View Details
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Pagination -->
                            <?php if (count($all_notifications) > 20): ?>
                            <div class="card-footer d-flex justify-content-between align-items-center">
                                <small class="text-muted">
                                    Showing <?= count($all_notifications) ?> notifications
                                </small>
                                <nav aria-label="Notification pagination">
                                    <ul class="pagination pagination-sm mb-0">
                                        <li class="page-item disabled">
                                            <a class="page-link" href="#" tabindex="-1">Previous</a>
                                        </li>
                                        <li class="page-item active"><a class="page-link" href="#">1</a></li>
                                        <li class="page-item"><a class="page-link" href="#">2</a></li>
                                        <li class="page-item"><a class="page-link" href="#">3</a></li>
                                        <li class="page-item">
                                            <a class="page-link" href="#">Next</a>
                                        </li>
                                    </ul>
                                </nav>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function markAsRead(notificationId, element) {
            // Don't trigger if clicking on a button or link
            if (event.target.tagName === 'BUTTON' || event.target.tagName === 'A' || 
                event.target.closest('button') || event.target.closest('a')) {
                return;
            }
            
            // Send AJAX request to mark as read
            const formData = new FormData();
            formData.append('notification_id', notificationId);
            
            fetch('notifications.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.ok) {
                    // Update UI
                    element.classList.remove('notification-unread');
                    element.querySelector('.fw-bold')?.classList.remove('fw-bold');
                    element.querySelector('.badge.bg-primary')?.classList.replace('bg-primary', 'bg-secondary');
                    element.querySelector('.badge.bg-primary')?.textContent = 'Read';
                    
                    // Update unread count
                    const unreadBadge = document.querySelector('.badge.bg-danger');
                    if (unreadBadge) {
                        const currentCount = parseInt(unreadBadge.textContent);
                        if (currentCount > 1) {
                            unreadBadge.textContent = currentCount - 1;
                        } else {
                            unreadBadge.remove();
                        }
                    }
                }
            });
        }

        // Auto-refresh notifications every 30 seconds
        setInterval(() => {
            fetch('get_notifications.php')
                .then(response => response.json())
                .then(data => {
                    // Update unread count in header
                    const badge = document.querySelector('.navbar .badge');
                    if (data.unread_count > 0) {
                        if (badge) {
                            badge.textContent = data.unread_count;
                        } else {
                            // Create badge if it doesn't exist
                            const bell = document.querySelector('.navbar .fa-bell');
                            if (bell) {
                                const newBadge = document.createElement('span');
                                newBadge.className = 'badge bg-danger';
                                newBadge.textContent = data.unread_count;
                                bell.parentNode.insertBefore(newBadge, bell.nextSibling);
                            }
                        }
                    } else if (badge) {
                        badge.remove();
                    }
                });
        }, 30000);
    </script>
</body>
<footer class="bg-dark text-light text-center py-3 mt-5">
    <div class="container">
        <p class="mb-0">Developed by APNLAB. 2025.</p>
    </div>
</footer>
</html>