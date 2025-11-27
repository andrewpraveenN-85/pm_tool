# Task Manager System

A comprehensive PHP/MySQL based task management system with role-based access control, project management, bug tracking, and advanced reporting.

## Features

- **User Management**: Role-based system (Manager, Developer, QA)
- **Project Management**: Create and manage projects with Git integration
- **Task Management**: Drag-drop Kanban board with multiple statuses
- **Bug Tracking**: Comprehensive bug reporting and tracking
- **Real-time Notifications**: Deadline warnings and assignment notifications
- **Advanced Reporting**: Performance analytics and charts
- **File Uploads**: Profile pictures and attachments
- **Activity Logging**: Complete audit trail
- **Responsive Design**: Mobile-friendly interface

## Installation

1. **Extract** the project files to your web server directory
2. **Create Database**:
   - Import `database_schema.sql` to your MySQL server
   - Update database credentials in `config/database.php`

3. **Configure**:
   - Ensure `uploads/profiles/` directory is writable
   - Set up cron job for notifications (optional):
     ```bash
     0 * * * * /usr/bin/php /path/to/project/cron/check_deadlines.php
     ```

4. **Access**:
   - Navigate to your project URL
   - Login with: `manager@taskmanager.com` / `password`

## Default Login

- **Email**: manager@taskmanager.com
- **Password**: password

## System Requirements

- PHP 7.4 or higher
- MySQL 5.7 or higher
- Web server (Apache/Nginx)
- Modern web browser

## File Structure
