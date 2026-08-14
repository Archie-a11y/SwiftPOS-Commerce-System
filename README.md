# 🛒 SwiftPOS-Commerce-System

A high-performance, responsive Point-of-Sale (POS) and inventory management system customized for retail and retail-adjacent markets. Built with PHP, MySQL, and Node.js, it features local ESC/POS thermal printing, customer loyalty programs, real-time analytics, and dual-language compliance (English, 简体中文, Bahasa Melayu).

## ✨ Features

- **Dynamic Cart & Checkout Engine**: Seamless item selection via manual search, on-screen click, or instant physical barcode scanning. Supports flat-rate or percentage vouchers and manual cash discounts.
- **Hardware-Decoupled Printing Agent**: An integrated Node.js-based local print agent (`print-agent.js`) that translates and forwards raw ESC/POS bytes over USB/Serial or TCP networks to drive hardware cash drawers and thermal printers silently.
- **Multi-Location Stock Transfers**: Built-in transfer mechanism allowing seamless physical stock tracking between back warehouse storage, front shelves, and regional sub-branches.
- **Localized SST & Timezone Support**: Fully compliant with Malaysian Sales and Service Tax (SST) receipt regulations. Automatic client-side timezone handshakes ensure synchronized audit timelines.
- **Loyalty & Membership Registry**: Robust membership profiles calculating tier-based automatic discounts and real-time loyalty point tracking without harvesting intrusive personal data.
- **Comprehensive Audit Trails**: Automated activity logger capturing crucial operator behaviors—such as credential adjustments, stock overrides, and administrative modifications—to protect checkout compliance.

## 🏗️ Tech Stack

- **Backend Logic**: PHP (Preprocessed PDO/Prepared Statements), Node.js (ESC/POS Network Socket Agent).
- **Database Engine**: MySQL / MariaDB (Relational design with structured cascade rules).
- **Frontend Presentation**: Bootstrap 5.3 (Fully responsive layouts with high-priority CSS dark mode overrides), FontAwesome 6, vanilla JavaScript.
- **Analytics & Export Core**: Chart.js 4.4, SheetJS (Excel Generation), jsPDF & html2canvas (PDF Engine).

---

## 🖼️ Project Screenshots & User Guide

### 📍 Step 1: Secure Multi-User Login & Auth Sandbox
Access the unified login portal supporting automated timezone synchronization, secure session assignment based on system roles (Administrator/Cashier), and automated credential lockouts during brute-force detection.
<img width="1366" height="604" alt="image" src="https://github.com/user-attachments/assets/d53d9668-3c68-4032-b62c-34e672a2fbb1" />


### 📍 Step 2: Interactive Cash Register (POS) Desk
Operate the high-performance Cashier Terminal. Cashiers can scan EAN-13 barcodes, choose members, apply coupon codes, and calculate tender changes in real-time.
<img width="1366" height="993" alt="image" src="https://github.com/user-attachments/assets/76e32c3a-f1aa-4dd5-97cb-b8ba6d23700b" />


### 📍 Step 3: Hardware ESC/POS Receipt Printing Sandbox
Preview and test silent thermal receipt outputs. SwiftPOS automatically formats receipts to 80mm roll parameters, dynamically lists SST tax numbers, and sends print jobs directly to local print queues.
<img width="1366" height="768" alt="image" src="https://github.com/user-attachments/assets/19e00710-1396-47f6-a728-c60fe2a7d690" />


### 📍 Step 4: Multi-Location Inventory Adjustments & Transfers
Manage stock levels across different physical storage areas. The dashboard allows administrators to review inventory valuation and adjust stock with strict reason logs.
<img width="1350" height="768" alt="image" src="https://github.com/user-attachments/assets/0d408e5e-cabe-45be-8aee-a53e0ea9b3fa" />


### 📍 Step 5: Multi-Metric Administrative Analytics
Review sales performances over time. Administrators can filter through comprehensive transactional analytics, slow-moving dead stock overviews, and export them directly to local sheets.
<img width="1366" height="1645" alt="image" src="https://github.com/user-attachments/assets/1eb7c300-8f2e-456e-b70f-6b7b9cbb4c2a" />


---

## 🛠️ Project Setup

### Prerequisites
- **Web Server Engine**: Apache 2.4 / Nginx (configured with PHP 8.0 or above)
- **Database Service**: MySQL 5.7+ / MariaDB 10.3+
- **Agent Environment (Optional for Hardware Printing)**: Node.js (v16.x or newer installed on the local client PC)

### Installation Steps

1. **Deploy Repository**:
   Clone this repository to your local web server environment (e.g., `htdocs` or `/var/www/html`):
   ```bash
   git clone https://github.com/Archie-a11y/SwiftPOS-Commerce-System.git
   cd SwiftPOS-Commerce-System
   ```

2. **Initialize Relational Database**:
   - Access your database server (e.g., phpMyAdmin, Navicat).
   - Create a new empty database named `pos_db` with `utf8mb4` encoding.
   - Import the schema backup file `database.sql` directly into your newly created database.

3. **Configure Database Connection**:
   Open `config/db.php` and configure your database parameters:
   ```php
   $host = "localhost";
   $user = "YOUR_DATABASE_USER";
   $pass = "YOUR_DATABASE_PASSWORD";
   $dbname = "pos_db";
   ```

4. **Start Local POS Print Agent (Optional)**:
   If physical receipt printers are connected to your local environment:
   ```bash
   cd SwiftPOS-Agent
   npm install
   node print-agent.js
   ```
   *Note: Ensure your Windows printer matching `PRINTER_NAME` env variable matches the exact printer driver label on your machine.*
