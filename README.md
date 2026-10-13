<div align="center">
  
  <h1>📊 Accounts Management System</h1>
  <p><b>Free & Comprehensive Accounts & Expense Management System</b></p>
  
  <a href="https://services.smartjoans.space/projects"><img src="https://img.shields.io/badge/View_Demo_Video-smartjoans.space-blue?style=for-the-badge&logo=youtube" alt="Demo Video"></a>
  <a href="https://services.smartjoans.space/contact"><img src="https://img.shields.io/badge/Support-Contact_Us-orange?style=for-the-badge&logo=minutemailer" alt="Contact"></a>
  <img src="https://img.shields.io/badge/License-Free-success?style=for-the-badge&logo=open-source-initiative" alt="Free">
</div>

<br>

## 📋 Project Overview
The **Accounts Management System** is a free, powerful, and easy-to-use platform designed to track daily expenses, monitor cash flow across multiple channels, and manage vehicle fuel consumption. Built for simplicity and efficiency, it helps you keep a clean ledger of all your financial activities.

🎥 **[Watch the Workflow Video here!](https://services.smartjoans.space/projects)**

---

## ⛽ Core Features (The Workflow)

### 💰 Financial Management
- **Daily Expense Logging:** Easily input general expenditures and categorize them by payment type (Cash, Bank Transfer, Online).
- **Multi-Channel Monitoring:** Maintain separate ledgers and views for:
  - Cash Flow
  - Bank Account Balances
  - Online Payments
- **Bank Withdrawal Tracking:** A dedicated module to accurately record, monitor, and reconcile bank withdrawal transactions.

### 🚗 Vehicle Fuel Monitoring Module (New!)
A specialized module to track vehicle fuel expenses and calculate efficiency.
- **Detailed Logging:** Track every single fill-up transaction, including:
  - Vehicle Name / ID
  - Fuel Type (Petrol, Diesel, etc.)
  - Quantity filled (in Liters or Gallons)
  - Current Odometer reading
- **Integrated Payment Tracking:** Record exactly how the fuel was paid for (Cash, Bank/Debit Card, Credit Card).
- **Efficiency Metrics:** Automatically calculates vehicle mileage/efficiency (e.g., km/L or MPG) based on historical odometer readings.

---

## 🛠️ Installation and Setup Guide

Follow these simple steps to get the project running on your local machine using XAMPP.

### 1️⃣ Prerequisites
- **XAMPP:** Installed and operational (Make sure Apache and MySQL services are running).
- **Composer:** PHP's dependency manager installed on your system.

### 2️⃣ Project File Setup
<details>
<summary><b>Click to view File Setup instructions</b></summary>
<br>

1. **Download the Project:** Download the project files as a ZIP archive from the GitHub repository.
2. **Extract Files:** Unzip the downloaded file.
3. **Copy to Web Root:** Move all contents from the unzipped folder into your XAMPP web root directory. 
   *(Example: `C:\xampp\htdocs\Accounts_Management`)*
4. **Database Import (Crucial):** Open `phpMyAdmin`, create a new database, and import the SQL file located inside the project's `database` folder.

</details>

### 3️⃣ PHP Configuration (Custom Setup)
This project requires a dedicated PHP version placed directly within the project directory.

<details>
<summary><b>Click to view PHP Setup instructions</b></summary>
<br>

1. **Obtain Custom PHP:** Download the required PHP files from this secure link: 
   👉 **[Download PHP Archive (Mega.nz)](https://mega.nz/file/fo5gURKS#ZD_BKn7TX-qPz3y1A_BWtikBhBh38GI6xIB0T_IrcVk)**
2. **Create PHP Folder:** Navigate to your project root folder (e.g., `.../htdocs/Accounts_Management`) and create a new folder named `php`.
3. **Extract & Move:** Extract the downloaded PHP ZIP archive and copy all its contents into the newly created `php` folder.

</details>

### 4️⃣ Database Configuration
1. Launch the **XAMPP Control Panel**.
2. Click **Start** for both the `Apache` and `MySQL` modules.
3. Open your browser and go to `http://localhost/phpmyadmin`.
4. Create a new database corresponding to the project.
5. Import the provided `.sql` file (found in the `database` folder of the downloaded repository) into your new database.

---

## 🔐 Default Login Credentials

Use the following default credentials for your initial login. 

| Field | Value |
| :--- | :--- |
| **Username** | `admin` |
| **Password** | `12345678` |

> ⚠️ **SECURITY RECOMMENDATION:** Please change the default password immediately after logging in for the first time to secure your accounts.

---

## 🤝 Support & Contact

If you need any help with the installation, configuration, or have feature requests, feel free to reach out!

📩 **Contact Support:** [https://services.smartjoans.space/contact](https://services.smartjoans.space/contact)
