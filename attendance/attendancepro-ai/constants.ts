
export const DEFAULT_SETTINGS = {
  startTime: "09:00",
  endTime: "17:00",
  officeIpAddress: "192.168.1.100",
  gracePeriodMinutes: 15
};

export const SQL_SCHEMA = `
-- Database Schema for Employee Attendance System

CREATE TABLE employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'user') DEFAULT 'user',
    hourly_rate DECIMAL(10, 2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    date DATE NOT NULL,
    time_in TIME NOT NULL,
    time_out TIME DEFAULT NULL,
    status ENUM('Late', 'On Time', 'Early') DEFAULT 'On Time',
    overtime_hours DECIMAL(5, 2) DEFAULT 0.00,
    total_hours DECIMAL(5, 2) DEFAULT 0.00,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

CREATE TABLE settings (
    id INT PRIMARY KEY DEFAULT 1,
    start_time TIME DEFAULT '09:00:00',
    end_time TIME DEFAULT '17:00:00',
    office_ip_address VARCHAR(45) NOT NULL,
    CHECK (id = 1)
);

-- Initial Setup
INSERT INTO settings (id, start_time, end_time, office_ip_address) 
VALUES (1, '09:00:00', '17:00:00', '192.168.1.100');
`;

export const PHP_DB_CONN = `
<?php
// db.php
$host = 'localhost';
$db   = 'attendance_system';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\\PDOException $e) {
     throw new \\PDOException($e->getMessage(), (int)$e->getCode());
}
?>
`;
