<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Only redirect to dashboard if the user explicitly navigated to the login route or script.
// The public landing page (/) remains viewable even if staff is logged in.
$reqUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$isLoginRoute = preg_match('#/(login|login_page|login\.php)$#i', (string)$reqUri);
if (isLoggedIn() && $isLoginRoute) {
    redirect('dashboard');
}

$errors = [];

// Handle Login Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    try {
        csrfVerify();
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $errors[] = 'Please enter both email and password.';
        } else {
            $stmt = $pdo->prepare("SELECT u.*, b.branch_id AS branch_id, b.branch_name AS branch_name
                FROM users u
                LEFT JOIN employees e ON e.user_id = u.user_id
                LEFT JOIN branches b ON b.branch_id = e.branch_id
                WHERE u.email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password_hash'])) {
                $errors[] = 'Invalid email or password.';
                if (function_exists('logAudit')) {
                    logAudit($pdo, null, 'LOGIN_FAILED', 'Auth', 'Email attempted: ' . $email);
                }
            } elseif ($user['status'] !== 'ACTIVE') {
                $errors[] = 'Your account is ' . strtolower($user['status']) . '. Please contact the HR department.';
            } elseif ((int)$user['role_id'] === ROLE_CUSTOMER) {
                // Kiosk customer accounts have no staff/dashboard access at all -
                // they belong on the self-order kiosk (pos/kiosk_login.php), not here.
                $errors[] = 'This account type cannot access the staff system.';
            } elseif ((int)$user['role_id'] === ROLE_CASHIER && cashierOnApprovedLeaveToday($pdo, (int)$user['user_id'])) {
                // HRMS -> POS integration: an approved leave for today blocks
                // POS login even with correct credentials.
                $errors[] = 'Access Denied: You are currently on official leave.';
            } else {
                loginUser($user);
                $pdo->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?")->execute([$user['user_id']]);
                
                // Safely clear temporary password only if the column exists in the database
                try {
                    $pdo->prepare("UPDATE applicants SET temp_login_password = NULL WHERE user_id = ?")->execute([$user['user_id']]);
                } catch (PDOException $pe) {
                    // Ignore error if column 'temp_login_password' does not exist in applicants table
                }
                
                if (function_exists('logAudit')) {
                    logAudit($pdo, $user['user_id'], 'LOGIN_SUCCESS', 'Auth', '');
                }
                
                // If submitted via AJAX, return JSON response
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'redirect' => BASE_URL . 'dashboard']);
                    exit;
                }

                setFlash('success', 'Login successful! Welcome back, ' . e($user['first_name'] ?? 'User') . '.');
                redirect('dashboard');
            }
        }
    } catch (Throwable $e) {
        // Clear session if exception occurs after loginUser()
        session_unset();
        session_destroy();
        
        $errors[] = 'An error occurred during login: ' . $e->getMessage();
    }

    // Return JSON errors if AJAX request
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'errors' => $errors]);
        exit;
    }
}

// Public "Now Hiring" jobs
$openJobs = $pdo->query("SELECT jv.*, d.department_name
    FROM job_vacancies jv LEFT JOIN departments d ON jv.department_id = d.department_id
    WHERE jv.status = 'OPEN' AND (jv.closing_date IS NULL OR jv.closing_date >= CURDATE())
    ORDER BY jv.posted_at DESC LIMIT 6")->fetchAll();
$openJobsCount = $pdo->query("SELECT COUNT(*) FROM job_vacancies WHERE status = 'OPEN' AND (closing_date IS NULL OR closing_date >= CURDATE())")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="<?= e(APP_TAGLINE) ?>">
    <title>Login | <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/x-icon" href="assets/hrms/img/favicon.ico">
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    
    <link rel="stylesheet" href="assets/hrms/css/grayscale.css?v=<?= file_exists(__DIR__.'/assets/hrms/css/grayscale.css') ? filemtime(__DIR__.'/assets/hrms/css/grayscale.css') : time() ?>">
    <link rel="stylesheet" href="assets/hrms/css/landing-theme.css?v=<?= file_exists(__DIR__.'/assets/hrms/css/landing-theme.css') ? filemtime(__DIR__.'/assets/hrms/css/landing-theme.css') : time() ?>">
<link rel="stylesheet" href="assets/shared/responsive.css?v=<?= defined('ASSET_VER') ? ASSET_VER : time() ?>">
</head>
<body id="page-top" class="grayscale-page" data-bs-spy="scroll" data-bs-target="#mainNav" data-bs-offset="70">

    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-light fixed-top" id="mainNav">
        <div class="container px-4 px-lg-5 justify-content-center">
            <button class="navbar-toggler navbar-toggler-right ms-auto" type="button" data-bs-toggle="collapse" data-bs-target="#navbarResponsive" aria-controls="navbarResponsive" aria-expanded="false" aria-label="Toggle navigation">
                Menu <i class="fas fa-bars"></i>
            </button>
            <div class="collapse navbar-collapse" id="navbarResponsive">
                <ul class="navbar-nav mx-auto">
                    <li class="nav-item"><a class="nav-link" href="#home">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="#about">About</a></li>
                    <li class="nav-item"><a class="nav-link" href="#careers">We're Hiring</a></li>
                    <li class="nav-item"><a class="nav-link" href="#login">Staff Login</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="app-view" id="appView">

    <!-- Masthead -->
    <header class="masthead page-section" id="home">
        <div class="container px-4 px-lg-5 d-flex h-100 align-items-center justify-content-center">
            <div class="d-flex justify-content-center">
                <div class="text-center">
                    <h1 class="mx-auto my-0 text-uppercase"><?= e(APP_NAME) ?></h1>
                    <h2 class="text-white-50 mx-auto mt-3 mb-4">Freshly brewed, ready when you are.</h2>
                    <a href="<?= BASE_URL ?>pos/kiosk" class="btn btn-primary btn-lg order-now-cta" aria-label="Order now from the customer kiosk">
                        <i class="fas fa-utensils me-2" aria-hidden="true"></i>Order Now
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- About Section -->
    <section class="about-section page-section text-center" id="about">
        <div class="container px-4 px-lg-5">
            <div class="row gx-4 gx-lg-5 justify-content-center">
                <div class="col-lg-8">
                    <img src="assets/hrms/img/about-cafe.jpg" alt="About <?= e(APP_NAME) ?>" class="img-fluid rounded-4 shadow-sm mb-4" style="max-height: 350px; object-fit: cover; width: 100%;">
                    <h2 class="mb-4">About <?= e(APP_NAME) ?></h2>
                    <p class="mb-3">
                        Welcome to <?= e(APP_NAME) ?>, your cozy neighborhood retreat where great coffee and warm conversations meet. We take pride in serving carefully crafted espresso, freshly baked pastries, and comforting meals made from the finest ingredients. Whether you're stopping by for a quick morning pick-me-up, a productive work session, or a relaxing afternoon break, our welcoming space is designed to feel just like home. Every cup we brew is a testament to our passion for quality, community, and the simple joy of a perfect coffee.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Careers Section -->
    <section class="projects-section page-section" id="careers">
        <div class="container px-4 px-lg-5">
            <div class="careers-heading text-center mb-5">
                <div class="careers-icon-badge mb-3"><i class="fas fa-bullhorn"></i></div>
                <h4>We're Hiring!</h4>
                <p class="mb-3">Join our cafe team &mdash; <?= (int)$openJobsCount ?> open position<?= $openJobsCount == 1 ? '' : 's' ?> right now.</p>
                <a href="<?= BASE_URL ?>check-status" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-search me-1"></i> Check Your Application Status
                </a>
            </div>

            <?php if (empty($openJobs)): ?>
                <div class="alert alert-info text-center">No open positions at the moment. Please check back soon!</div>
            <?php else: ?>
                <div class="row g-4 justify-content-center">
                    <?php foreach ($openJobs as $index => $job): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="job-card h-100">
                                <div class="job-card-icon mb-3"><i class="fas fa-user-tie"></i></div>
                                <h4><?= e($job['title']) ?></h4>
                                <p class="mb-3 small">
                                    <i class="fas fa-sitemap"></i> <?= e($job['department_name'] ?? 'General') ?> &middot;
                                    <?= e(str_replace('_', ' ', $job['employment_type'])) ?> &middot;
                                    <i class="fas fa-users"></i> <?= (int)$job['slots'] ?> slot(s)
                                </p>
                                <a href="<?= BASE_URL ?>apply?job_id=<?= (int)$job['job_id'] ?>" class="btn btn-primary btn-sm w-100">Apply Now</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Staff Login Section -->
    <section class="signup-section page-section" id="login">
        <div class="container px-4 px-lg-5">
            <div class="row gx-4 gx-lg-5">
                <div class="col-md-10 col-lg-6 mx-auto text-center">
                    <div class="glass-panel" style="background: rgba(255, 255, 255, 0.7); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);">
                    <i class="fas fa-right-to-bracket fa-2x mb-2 text-primary"></i>
                    <h2 class="text-primary-dark mb-4">Staff Login</h2>
                    <p class="text-muted mb-4">Sign in to your <?= e(APP_NAME) ?> account</p>

                    <!-- Dynamic Error Container -->
                    <div id="errorAlertContainer">
                        <?php if (isset($_GET['error']) && $_GET['error'] === 'session_expired'): ?>
                            <div class="alert alert-warning small text-start">Your session has expired. Please log in again.</div>
                        <?php endif; ?>
                        <?php if (isset($_GET['registered'])): ?>
                            <div class="alert alert-success small text-start">Registration successful! You may now log in.</div>
                        <?php endif; ?>
                        <?php foreach ($errors as $err): ?>
                            <div class="alert alert-danger small text-start"><?= htmlspecialchars($err) ?></div>
                        <?php endforeach; ?>
                    </div>

                    <form class="form-signup text-start" id="loginForm" method="POST" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <div class="mb-3">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" class="form-control" required
                                   value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <div class="text-end mb-3">
                            <a href="<?= BASE_URL ?>forgot-password" class="small text-primary">Forgot password?</a>
                        </div>
                        <button type="submit" id="btnLoginSubmit" class="btn btn-primary w-100">
                            <span id="btnText">Login</span>
                            <span id="btnSpinner" class="spinner-border spinner-border-sm d-none" role="status"></span>
                        </button>
                    </form>
                    </div>
                </div>
            </div>
        </div>
    </section>

    </div><!-- /.app-view -->

    <!-- Footer -->
    <footer class="footer landing-footer site-footer small text-center text-white-50">
        <div class="container px-4 px-lg-5">Copyright &copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.</div>
    </footer>

    <!-- 1. LOGIN SUCCESSFUL MODAL -->
    <div class="modal fade" id="loginSuccessModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
            <div class="modal-content text-center border-0 shadow rounded-4 p-4">
                <div class="modal-body p-0 py-2">
                    <div class="d-inline-flex align-items-center justify-content-center mb-3 rounded-circle" style="width: 70px; height: 70px; background-color: #e8f5e9;">
                        <i class="fas fa-check text-success" style="font-size: 2.2rem;"></i>
                    </div>
                    
                    <h5 class="fw-bold text-dark mb-2">Login Successful</h5>
                    <p class="text-muted small mb-3">Access granted. Redirecting to your dashboard...</p>
                    
                    <div class="spinner-border text-success spinner-border-sm" role="status"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. LOGOUT SUCCESSFUL MODAL -->
    <?php if (isset($_GET['logout']) && $_GET['logout'] === 'success'): ?>
    <div class="modal fade" id="logoutSuccessModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
            <div class="modal-content text-center border-0 shadow rounded-4 p-4">
                <div class="modal-body p-0 py-2">
                    <div class="d-inline-flex align-items-center justify-content-center mb-3 rounded-circle" style="width: 70px; height: 70px; background-color: #fee2e2;">
                        <i class="fas fa-right-from-bracket text-danger" style="font-size: 2rem;"></i>
                    </div>
                    
                    <h5 class="fw-bold text-dark mb-2">Logout Successful</h5>
                    <p class="text-muted small mb-3">You have been safely signed out of your account.</p>

                    <div class="spinner-border text-danger spinner-border-sm" role="status"></div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Bootstrap & Theme JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/hrms/js/grayscale.js"></script>

    <!-- Scripts for Login Submission & Auto Logout Modal -->
    <script>

    // Navbar shrink function
    var navbarShrink = function () {
        const mainNav = document.body.querySelector('#mainNav');
        if (!mainNav) {
            return;
        }
        if (window.scrollY === 0) {
            mainNav.classList.remove('navbar-shrink')
        } else {
            mainNav.classList.add('navbar-shrink')
        }
    };
    navbarShrink();
    document.addEventListener('scroll', navbarShrink);

    // AJAX Login Form Handler
    document.getElementById('loginForm').addEventListener('submit', function(e) {
        e.preventDefault();

        const form = this;
        const submitBtn = document.getElementById('btnLoginSubmit');
        const btnText = document.getElementById('btnText');
        const btnSpinner = document.getElementById('btnSpinner');
        const errorContainer = document.getElementById('errorAlertContainer');

        submitBtn.disabled = true;
        btnText.innerText = 'Verifying...';
        btnSpinner.classList.remove('d-none');
        errorContainer.innerHTML = '';

        const formData = new FormData(form);

        fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => {
            return response.text().then(text => {
                try {
                    return JSON.parse(text);
                } catch (err) {
                    throw new Error("Server returned non-JSON response: " + text);
                }
            });
        })
        .then(data => {
            if (data.success) {
                const successModal = new bootstrap.Modal(document.getElementById('loginSuccessModal'));
                successModal.show();

                setTimeout(() => {
                    window.location.href = data.redirect || '<?= BASE_URL ?>dashboard';
                }, 1200);
            } else {
                submitBtn.disabled = false;
                btnText.innerText = 'Login';
                btnSpinner.classList.add('d-none');

                if (data.errors && data.errors.length > 0) {
                    data.errors.forEach(err => {
                        errorContainer.innerHTML += `<div class="alert alert-danger small text-start">${err}</div>`;
                    });
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
            submitBtn.disabled = false;
            btnText.innerText = 'Login';
            btnSpinner.classList.add('d-none');
            errorContainer.innerHTML = `<div class="alert alert-danger small text-start">An unexpected error occurred. Please try again.</div>`;
        });
    });

    // Auto-show Logout Success Modal if redirected from logout.php
    <?php if (isset($_GET['logout']) && $_GET['logout'] === 'success'): ?>
    document.addEventListener('DOMContentLoaded', function() {
        const logoutModal = new bootstrap.Modal(document.getElementById('logoutSuccessModal'), {
            backdrop: 'static',
            keyboard: false
        });
        logoutModal.show();

        // Auto-dismiss after a short delay, same as the login modal - no click needed
        setTimeout(() => {
            logoutModal.hide();
        }, 1200);

        // Clean up URL parameter when modal is closed
        document.getElementById('logoutSuccessModal').addEventListener('hidden.bs.modal', function () {
            window.history.replaceState({}, document.title, window.location.pathname);
        });
    });
    <?php endif; ?>
    </script>
</body>
</html>
