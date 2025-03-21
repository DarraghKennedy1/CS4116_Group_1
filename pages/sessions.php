<?php
session_start();
require_once __DIR__ . '/../includes/db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Get user type and ID
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT user_type FROM Users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();
$is_coach = ($user['user_type'] === 'business');

// Handle session status updates via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    
    try {
        switch ($_POST['action']) {
            case 'update_status':
                if (!isset($_POST['session_id'], $_POST['status'])) {
                    throw new Exception('Missing required parameters');
                }

                // Start transaction before any database operations
                if (!$pdo->inTransaction()) {
                    $pdo->beginTransaction();
                }
                
                // Debug log
                error_log("Attempting to update session: " . $_POST['session_id'] . " by user: " . $user_id);
                
                // First verify the session exists and get its details
                $stmt = $pdo->prepare("
                    SELECT s.*, c.user_id as coach_user_id, u.username as learner_name, u2.username as coach_name
                    FROM Sessions s 
                    JOIN Coaches c ON s.coach_id = c.coach_id
                    JOIN Users u ON s.learner_id = u.user_id
                    JOIN Users u2 ON c.user_id = u2.user_id
                    WHERE s.session_id = ?
                ");
                $stmt->execute([$_POST['session_id']]);
                $session = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$session) {
                    throw new Exception('Session not found');
                }
                
                // Convert IDs to integers for comparison
                $learner_id = (int)$session['learner_id'];
                $coach_user_id = (int)$session['coach_user_id'];
                $current_user_id = (int)$user_id;
                
                // Check if user has permission (either learner or coach)
                if ($learner_id !== $current_user_id && $coach_user_id !== $current_user_id) {
                    throw new Exception(
                        'Permission denied. You must be either the learner or coach for this session. ' .
                        'Current user: ' . $current_user_id
                    );
                }
                
                // Update session status
                $stmt = $pdo->prepare("UPDATE Sessions SET status = ? WHERE session_id = ?");
                $stmt->execute([$_POST['status'], $_POST['session_id']]);
                
                // If completing session and rating provided, save the rating
                if ($_POST['status'] === 'completed' && isset($_POST['rating'])) {
                    // First check if Ratings table exists, if not create it
                    $stmt = $pdo->prepare("
                        CREATE TABLE IF NOT EXISTS Ratings (
                            rating_id INT PRIMARY KEY AUTO_INCREMENT,
                            session_id INT NOT NULL,
                            rating_value INT NOT NULL,
                            feedback TEXT,
                            created_at DATETIME NOT NULL,
                            FOREIGN KEY (session_id) REFERENCES Sessions(session_id)
                        )
                    ");
                    $stmt->execute();
                    
                    // Add average_rating column to Coaches table if it doesn't exist
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) as column_exists 
                        FROM information_schema.COLUMNS 
                        WHERE TABLE_NAME = 'Coaches' 
                        AND COLUMN_NAME = 'average_rating'
                        AND TABLE_SCHEMA = DATABASE()
                    ");
                    $stmt->execute();
                    $column_exists = $stmt->fetch()['column_exists'];

                    if ($column_exists == 0) {
                        $stmt = $pdo->prepare("
                            ALTER TABLE Coaches 
                            ADD COLUMN average_rating DECIMAL(3,2) DEFAULT 0.00
                        ");
                        $stmt->execute();
                    }
                    
                    // Check if a rating already exists for this session
                    $stmt = $pdo->prepare("SELECT rating_id FROM Ratings WHERE session_id = ?");
                    $stmt->execute([$_POST['session_id']]);
                    $existingRating = $stmt->fetch();
                    
                    if ($existingRating) {
                        // Update existing rating
                        $stmt = $pdo->prepare("
                            UPDATE Ratings 
                            SET rating_value = ?, feedback = ?, created_at = NOW() 
                            WHERE session_id = ?
                        ");
                        $stmt->execute([
                            $_POST['rating'],
                            $_POST['feedback'] ?? null,
                            $_POST['session_id']
                        ]);
                    } else {
                        // Insert new rating
                        $stmt = $pdo->prepare("
                            INSERT INTO Ratings (session_id, rating_value, feedback, created_at) 
                            VALUES (?, ?, ?, NOW())
                        ");
                        $stmt->execute([
                            $_POST['session_id'],
                            $_POST['rating'],
                            $_POST['feedback'] ?? null
                        ]);
                    }
                    
                    // Update coach's average rating
                    $stmt = $pdo->prepare("
                        UPDATE Coaches c 
                        SET average_rating = (
                            SELECT COALESCE(AVG(r.rating_value), 0)
                            FROM Ratings r
                            JOIN Sessions s ON r.session_id = s.session_id
                            WHERE s.coach_id = c.coach_id
                        )
                        WHERE coach_id = (
                            SELECT coach_id 
                            FROM Sessions 
                            WHERE session_id = ?
                        )
                    ");
                    $stmt->execute([$_POST['session_id']]);
                }
                
                // Commit the transaction
                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }
                
                $response['success'] = true;
                $response['message'] = 'Session status updated successfully';
                break;
                
            case 'schedule_session':
                if (isset($_POST['coach_id'], $_POST['tier_id'], $_POST['scheduled_time'])) {
                    try {
                        // Create service inquiry first
                        $stmt = $pdo->prepare("INSERT INTO ServiceInquiries (user_id, coach_id, tier_id, status) VALUES (?, ?, ?, 'accepted')");
                        $stmt->execute([$user_id, $_POST['coach_id'], $_POST['tier_id']]);
                        $inquiry_id = $pdo->lastInsertId();
                        
                        // Create the session
                        $stmt = $pdo->prepare("INSERT INTO Sessions (inquiry_id, learner_id, coach_id, tier_id, scheduled_time, status) VALUES (?, ?, ?, ?, ?, 'scheduled')");
                        $stmt->execute([$inquiry_id, $user_id, $_POST['coach_id'], $_POST['tier_id'], $_POST['scheduled_time']]);
                        
                        $response['success'] = true;
                        $response['message'] = 'Session scheduled successfully';
                    } catch (PDOException $e) {
                        $response['message'] = 'Error scheduling session';
                    }
                }
                break;
                
            default:
                throw new Exception('Invalid action');
        }
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $response['success'] = false;
        $response['message'] = $e->getMessage();
        error_log('Error in sessions.php: ' . $e->getMessage());
    }
    
    echo json_encode($response);
    exit;
}

// Get user's sessions
$query = $is_coach 
    ? "SELECT s.*, u.username as learner_name, st.name as tier_name, st.price 
       FROM Sessions s 
       JOIN Users u ON s.learner_id = u.user_id 
       JOIN ServiceTiers st ON s.tier_id = st.tier_id 
       WHERE s.coach_id = ?"
    : "SELECT s.*, u.username as coach_name, st.name as tier_name, st.price 
       FROM Sessions s 
       JOIN Coaches c ON s.coach_id = c.coach_id 
       JOIN Users u ON c.user_id = u.user_id 
       JOIN ServiceTiers st ON s.tier_id = st.tier_id 
       WHERE s.learner_id = ?";

$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$sessions = $stmt->fetchAll();

// Get available coaches and their service tiers for booking
$stmt = $pdo->prepare("
    SELECT c.coach_id, u.username, c.expertise, c.availability, st.tier_id, st.name as tier_name, st.price 
    FROM Coaches c 
    JOIN Users u ON c.user_id = u.user_id 
    JOIN ServiceTiers st ON c.coach_id = st.coach_id
");
$stmt->execute();
$coaches = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<!-- Add FullCalendar CSS -->
<link href='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css' rel='stylesheet' />

<div class="container mt-4">
    <h1 class="mb-4"><?= $is_coach ? 'My Teaching Sessions' : 'My Learning Sessions' ?></h1>
    
    <?php if (!$is_coach): ?>
    <!-- Session Booking Form -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="card-title mb-0">Schedule New Session</h5>
        </div>
        <div class="card-body">
            <form id="scheduleForm">
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="coach" class="form-label">Select Coach</label>
                            <select class="form-select" id="coach" name="coach_id" required>
                                <option value="">Choose a coach...</option>
                                <?php
                                $unique_coaches = array_unique(array_column($coaches, 'coach_id'));
                                foreach ($unique_coaches as $coach_id) {
                                    $coach = current(array_filter($coaches, fn($c) => $c['coach_id'] === $coach_id));
                                    echo "<option value='{$coach['coach_id']}'>{$coach['username']} - {$coach['expertise']}</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="scheduled_time" class="form-label">Session Time</label>
                            <input type="datetime-local" class="form-control" id="scheduled_time" name="scheduled_time" required>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Schedule Session</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Calendar View -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="card-title mb-0">Session Calendar</h5>
        </div>
        <div class="card-body">
            <div id="calendar"></div>
        </div>
    </div>

    <!-- Sessions List -->
    <div class="card">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">Session History</h5>
            <div class="btn-group">
                <button class="btn btn-light btn-sm active" data-filter="all">All</button>
                <button class="btn btn-light btn-sm" data-filter="scheduled">Scheduled</button>
                <button class="btn btn-light btn-sm" data-filter="completed">Completed</button>
                <button class="btn btn-light btn-sm" data-filter="cancelled">Cancelled</button>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th><?= $is_coach ? 'Learner' : 'Coach' ?></th>
                            <th>Service Tier</th>
                            <th>Date & Time</th>
                            <th>Price</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sessions as $session): ?>
                        <tr data-status="<?= $session['status'] ?>">
                            <td><?= htmlspecialchars($is_coach ? $session['learner_name'] : $session['coach_name']) ?></td>
                            <td><?= htmlspecialchars($session['tier_name']) ?></td>
                            <td><?= date('M j, Y g:i A', strtotime($session['scheduled_time'])) ?></td>
                            <td>$<?= number_format($session['price'], 2) ?></td>
                            <td>
                                <span class="badge bg-<?= 
                                    $session['status'] === 'completed' ? 'success' : 
                                    ($session['status'] === 'cancelled' ? 'danger' : 'primary') 
                                ?>">
                                    <?= ucfirst($session['status']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($session['status'] === 'scheduled'): ?>
                                <button class="btn btn-sm btn-success complete-session" data-session-id="<?= $session['session_id'] ?>">
                                    Complete
                                </button>
                                <button class="btn btn-sm btn-danger cancel-session" data-session-id="<?= $session['session_id'] ?>">
                                    Cancel
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Rating Modal -->
<div class="modal fade" id="ratingModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Rate Your Session</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="ratingForm">
                    <input type="hidden" id="session_id_rating" name="session_id">
                    <div class="mb-3">
                        <label class="form-label">Rating</label>
                        <div class="rating-stars mb-3">
                            <i class="bi bi-star-fill fs-3 text-warning" data-rating="1"></i>
                            <i class="bi bi-star-fill fs-3 text-warning" data-rating="2"></i>
                            <i class="bi bi-star-fill fs-3 text-warning" data-rating="3"></i>
                            <i class="bi bi-star-fill fs-3 text-warning" data-rating="4"></i>
                            <i class="bi bi-star-fill fs-3 text-warning" data-rating="5"></i>
                        </div>
                        <input type="hidden" id="rating_value" name="rating" required>
                    </div>
                    <div class="mb-3">
                        <label for="feedback" class="form-label">Feedback (Optional)</label>
                        <textarea class="form-control" id="feedback" name="feedback" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="submitRating">Submit Rating</button>
            </div>
        </div>
    </div>
</div>

<!-- Add Bootstrap Icons CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">

<!-- Add FullCalendar JS and its dependencies -->
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js'></script>
<!-- Add Bootstrap Bundle JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize calendar
    var calendarEl = document.getElementById('calendar');
    var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay'
        },
        events: <?= json_encode(array_map(function($session) use ($is_coach) {
            return [
                'title' => ($is_coach ? $session['learner_name'] : $session['coach_name']) . ' - ' . $session['tier_name'],
                'start' => $session['scheduled_time'],
                'backgroundColor' => $session['status'] === 'completed' ? '#198754' : 
                                   ($session['status'] === 'cancelled' ? '#dc3545' : '#0d6efd'),
                'borderColor' => 'transparent'
            ];
        }, $sessions)) ?>,
        selectable: true,
        select: function(info) {
            if (document.getElementById('scheduled_time')) {
                document.getElementById('scheduled_time').value = info.startStr.slice(0, 16);
            }
        }
    });
    calendar.render();

    // Handle coach selection for service tiers
    const coachSelect = document.getElementById('coach');
    const coaches = <?= json_encode($coaches) ?>;

    // Handle session scheduling
    const scheduleForm = document.getElementById('scheduleForm');
    if (scheduleForm) {
        scheduleForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'schedule_session');
            // Set a default tier_id of 1 since we removed the tier selection
            formData.append('tier_id', '1');
            
            try {
                const response = await fetch('sessions.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success) {
                    location.reload();
                } else {
                    alert(result.message || 'Error scheduling session');
                }
            } catch (error) {
                alert('Error scheduling session');
            }
        });
    }

    // Handle session status updates
    document.querySelectorAll('.complete-session, .cancel-session').forEach(button => {
        button.addEventListener('click', async function(e) {
            e.preventDefault(); // Prevent default button behavior
            const sessionId = this.dataset.sessionId;
            
            if (this.classList.contains('complete-session')) {
                // Show rating modal for complete action
                document.getElementById('session_id_rating').value = sessionId;
                const ratingModal = new bootstrap.Modal(document.getElementById('ratingModal'));
                ratingModal.show();
            } else {
                // Handle cancel action
                if (!confirm('Are you sure you want to cancel this session?')) {
                    return;
                }
                
                const formData = new FormData();
                formData.append('action', 'update_status');
                formData.append('session_id', sessionId);
                formData.append('status', 'cancelled');
                
                try {
                    const response = await fetch('sessions.php', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();
                    
                    if (result.success) {
                        location.reload();
                    } else {
                        alert(result.message || 'Error updating session status');
                    }
                } catch (error) {
                    alert('Error updating session status');
                }
            }
        });
    });

    // Handle session filtering
    document.querySelectorAll('[data-filter]').forEach(button => {
        button.addEventListener('click', function() {
            document.querySelector('[data-filter].active').classList.remove('active');
            this.classList.add('active');
            
            const filter = this.dataset.filter;
            document.querySelectorAll('tbody tr').forEach(row => {
                if (filter === 'all' || row.dataset.status === filter) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    });

    // Rating Modal Functionality
    const ratingStars = document.querySelectorAll('.rating-stars i');
    const ratingValue = document.getElementById('rating_value');
    let currentSessionId = null;

    // Star rating functionality
    ratingStars.forEach(star => {
        star.addEventListener('mouseover', function() {
            const rating = this.dataset.rating;
            highlightStars(rating);
        });

        star.addEventListener('click', function() {
            const rating = this.dataset.rating;
            ratingValue.value = rating;
            highlightStars(rating);
        });
    });

    document.querySelector('.rating-stars').addEventListener('mouseout', function() {
        highlightStars(ratingValue.value);
    });

    function highlightStars(rating) {
        ratingStars.forEach(star => {
            const starRating = star.dataset.rating;
            star.style.opacity = starRating <= rating ? '1' : '0.5';
        });
    }

    // Handle rating submission
    document.getElementById('submitRating').addEventListener('click', async function() {
        if (!ratingValue.value) {
            alert('Please select a rating before submitting');
            return;
        }

        const sessionId = document.getElementById('session_id_rating').value;
        if (!sessionId) {
            alert('Session ID is missing');
            return;
        }

        const submitButton = this;
        submitButton.disabled = true;
        submitButton.textContent = 'Submitting...';

        const formData = new FormData();
        formData.append('action', 'update_status');
        formData.append('status', 'completed');
        formData.append('session_id', sessionId);
        formData.append('rating', ratingValue.value);
        formData.append('feedback', document.getElementById('feedback').value || '');
        
        try {
            const response = await fetch('sessions.php', {
                method: 'POST',
                body: formData
            });
            
            let result;
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('application/json')) {
                result = await response.json();
            } else {
                throw new Error('Server returned non-JSON response');
            }
            
            if (result.success) {
                alert('Rating submitted successfully!');
                const modal = bootstrap.Modal.getInstance(document.getElementById('ratingModal'));
                if (modal) {
                    modal.hide();
                }
                location.reload();
            } else {
                throw new Error(result.message || 'Error updating session status');
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Error submitting rating: ' + error.message);
        } finally {
            submitButton.disabled = false;
            submitButton.textContent = 'Submit Rating';
        }
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?> 
