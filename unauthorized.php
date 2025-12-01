<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unauthorized Access - Task Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container-fluid vh-100 d-flex justify-content-center align-items-center">
        <div class="text-center">
            <i class="fas fa-exclamation-triangle text-warning mb-4" style="font-size: 4rem;"></i>
            <h1 class="display-4 text-danger">Access Denied</h1>
            <p class="lead">You don't have permission to access this page.</p>
            <p class="text-muted">Please contact your administrator if you believe this is an error.</p>
            <div class="mt-4">
                <a href="dashboard.php" class="btn btn-primary me-2">
                    <i class="fas fa-home"></i> Go to Dashboard
                </a>
                <a href="logout.php" class="btn btn-outline-secondary">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </div>
</body>
<footer class="bg-dark text-light text-center py-3 mt-5">
    <div class="container">
        <p class="mb-0">Developed by APNLAB. 2025.</p>
    </div>
</footer>
</html>