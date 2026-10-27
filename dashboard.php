<?php


require_once 'config/auth.php';

$businessName =
    $_SESSION['business_name']
    ?? 'System';

$fullName =
    $_SESSION['full_name']
    ?? 'User';

$role =
    $_SESSION['role']
    ?? 'User';

$branchName =
    $_SESSION['branch_name']
    ?? 'Main Branch';
?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        <?= htmlspecialchars($businessName) ?> Management System
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
    >

    <link
        rel="stylesheet"
        href="assets/css/style.css"
    >

</head>

<body>

<div class="app-wrapper">


    <!-- ================= SIDEBAR ================= -->

    <aside class="sidebar">

        <div class="brand">

            <div class="brand-icon">

                <i class="fa-solid fa-boxes-stacked"></i>

            </div>

            <div>

                <h4>
                    <?= htmlspecialchars(strtoupper($businessName)) ?>
                </h4>

                <small>
                    Management System
                </small>

            </div>

        </div>


        <div class="sidebar-menu">


            <div class="menu-title">
                MAIN
            </div>


            <a
                href="dashboard.php"
                class="menu-link active"
            >

                <i class="fa-solid fa-house"></i>

                <span>
                    Dashboard
                </span>

            </a>


            <div class="menu-title">
                INVENTORY
            </div>


            <a href="#" class="menu-link">

                <i class="fa-solid fa-box"></i>

                <span>
                    Products
                </span>

            </a>


            <a href="#" class="menu-link">

                <i class="fa-solid fa-cart-shopping"></i>

                <span>
                    Purchases
                </span>

            </a>


            <a href="#" class="menu-link">

                <i class="fa-solid fa-arrow-rotate-left"></i>

                <span>
                    Purchase Returns
                </span>

            </a>


            <a href="#" class="menu-link">

                <i class="fa-solid fa-chart-column"></i>

                <span>
                    Sales
                </span>

            </a>


            <a href="#" class="menu-link">

                <i class="fa-solid fa-warehouse"></i>

                <span>
                    Stock
                </span>

            </a>


            <div class="menu-title">
                PARTIES
            </div>


            <a href="#" class="menu-link">

                <i class="fa-solid fa-users"></i>

                <span>
                    Customers
                </span>

            </a>


            <a href="#" class="menu-link">

                <i class="fa-solid fa-truck"></i>

                <span>
                    Suppliers
                </span>

            </a>


            <div class="menu-title">
                FINANCE
            </div>


            <a href="#" class="menu-link">

                <i class="fa-solid fa-wallet"></i>

                <span>
                    Expenses
                </span>

            </a>


            <a href="#" class="menu-link">

                <i class="fa-solid fa-money-check-dollar"></i>

                <span>
                    Payroll
                </span>

            </a>


            <a href="#" class="menu-link">

                <i class="fa-solid fa-building-columns"></i>

                <span>
                    Accounting
                </span>

            </a>


            <div class="menu-title">
                REPORTS
            </div>


            <a href="#" class="menu-link">

                <i class="fa-solid fa-file-lines"></i>

                <span>
                    Reports
                </span>

            </a>


            <div class="menu-title">
                MANAGEMENT
            </div>


            <a href="#" class="menu-link">

                <i class="fa-solid fa-code-branch"></i>

                <span>
                    Branches
                </span>

            </a>


            <a href="#" class="menu-link">

                <i class="fa-solid fa-user-shield"></i>

                <span>
                    Users & Permissions
                </span>

            </a>


            <a href="#" class="menu-link">

                <i class="fa-solid fa-clock-rotate-left"></i>

                <span>
                    Audit Trail
                </span>

            </a>


        </div>

    </aside>



    <!-- ================= MAIN ================= -->

    <main class="main-content">


        <!-- TOP BAR -->

        <header class="topbar">

            <div class="topbar-left">

                <button
                    class="sidebar-toggle"
                    type="button"
                >

                    <i class="fa-solid fa-bars"></i>

                </button>


                <div class="search-box">

                    <i class="fa-solid fa-magnifying-glass"></i>

                    <input
                        type="text"
                        placeholder="Search anything..."
                    >

                </div>

            </div>


            <div class="topbar-right">


                <div class="branch-box">

                    <i class="fa-solid fa-store"></i>

                    <?= htmlspecialchars($branchName) ?>

                </div>


                <button class="notification">

                    <i class="fa-regular fa-bell"></i>

                    <span>
                        3
                    </span>

                </button>


                <div class="user-profile">

                    <div class="avatar">

                        <?= strtoupper(substr($fullName, 0, 1)) ?>

                    </div>

                    <div>

                        <strong>
                            <?= htmlspecialchars($fullName) ?>
                        </strong>

                        <small>
                            <?= htmlspecialchars($role) ?>
                        </small>

                    </div>

                    <i class="fa-solid fa-chevron-down"></i>

                </div>


            </div>

        </header>



        <!-- CONTENT -->

        <section class="page-content">


            <div class="page-heading">

                <div>

                    <h2>

                        Welcome back,
                        <?= htmlspecialchars($fullName) ?> 👋

                    </h2>

                    <p>

                        Here's what's happening with
                        <?= htmlspecialchars($businessName) ?>
                        today.

                    </p>

                </div>


                <button class="btn btn-primary">

                    <i class="fa-solid fa-arrows-rotate me-2"></i>

                    Refresh

                </button>

            </div>



            <!-- STAT CARDS -->

            <div class="row g-3 mb-4">


                <div class="col-xl-3 col-md-6">

                    <div class="stat-card">

                        <div class="stat-icon blue">

                            <i class="fa-solid fa-cart-shopping"></i>

                        </div>

                        <div>

                            <small>
                                Total Purchases
                            </small>

                            <h3>
                                $0.00
                            </h3>

                            <span>
                                This month
                            </span>

                        </div>

                    </div>

                </div>



                <div class="col-xl-3 col-md-6">

                    <div class="stat-card">

                        <div class="stat-icon green">

                            <i class="fa-solid fa-bag-shopping"></i>

                        </div>

                        <div>

                            <small>
                                Total Sales
                            </small>

                            <h3>
                                $0.00
                            </h3>

                            <span>
                                This month
                            </span>

                        </div>

                    </div>

                </div>



                <div class="col-xl-3 col-md-6">

                    <div class="stat-card">

                        <div class="stat-icon orange">

                            <i class="fa-solid fa-chart-line"></i>

                        </div>

                        <div>

                            <small>
                                Gross Profit
                            </small>

                            <h3>
                                $0.00
                            </h3>

                            <span>
                                This month
                            </span>

                        </div>

                    </div>

                </div>



                <div class="col-xl-3 col-md-6">

                    <div class="stat-card">

                        <div class="stat-icon purple">

                            <i class="fa-solid fa-boxes-stacked"></i>

                        </div>

                        <div>

                            <small>
                                Stock Value
                            </small>

                            <h3>
                                $0.00
                            </h3>

                            <span>
                                Current stock
                            </span>

                        </div>

                    </div>

                </div>


            </div>



            <!-- SECOND ROW -->

            <div class="row g-4">


                <div class="col-xl-8">

                    <div class="content-card">

                        <div class="card-heading">

                            <div>

                                <h5>
                                    Sales Overview
                                </h5>

                                <small>
                                    Monthly sales performance
                                </small>

                            </div>

                            <select class="form-select">

                                <option>
                                    This Month
                                </option>

                                <option>
                                    This Year
                                </option>

                            </select>

                        </div>


                        <div class="empty-chart">

                            <i class="fa-solid fa-chart-line"></i>

                            <h5>
                                Sales chart will appear here
                            </h5>

                            <p>
                                Transactions will automatically update this chart.
                            </p>

                        </div>

                    </div>

                </div>



                <div class="col-xl-4">

                    <div class="content-card">

                        <div class="card-heading">

                            <div>

                                <h5>
                                    Stock Summary
                                </h5>

                                <small>
                                    Current inventory
                                </small>

                            </div>

                        </div>


                        <div class="stock-item">

                            <span>

                                <span class="stock-dot green-dot"></span>

                                In Stock

                            </span>

                            <strong>
                                0
                            </strong>

                        </div>


                        <div class="stock-item">

                            <span>

                                <span class="stock-dot orange-dot"></span>

                                Low Stock

                            </span>

                            <strong>
                                0
                            </strong>

                        </div>


                        <div class="stock-item">

                            <span>

                                <span class="stock-dot red-dot"></span>

                                Out of Stock

                            </span>

                            <strong>
                                0
                            </strong>

                        </div>


                        <div class="stock-item">

                            <span>

                                <span class="stock-dot purple-dot"></span>

                                Expiring Soon

                            </span>

                            <strong>
                                0
                            </strong>

                        </div>


                    </div>

                </div>


            </div>



            <!-- BOTTOM -->

            <div class="row g-4 mt-1">


                <div class="col-xl-7">

                    <div class="content-card">

                        <div class="card-heading">

                            <div>

                                <h5>
                                    Recent Sales
                                </h5>

                                <small>
                                    Latest customer transactions
                                </small>

                            </div>

                            <a href="#">
                                View All
                            </a>

                        </div>


                        <div class="table-responsive">

                            <table class="table align-middle">

                                <thead>

                                <tr>

                                    <th>
                                        Invoice
                                    </th>

                                    <th>
                                        Customer
                                    </th>

                                    <th>
                                        Amount
                                    </th>

                                    <th>
                                        Status
                                    </th>

                                </tr>

                                </thead>

                                <tbody>

                                <tr>

                                    <td
                                        colspan="4"
                                        class="text-center py-5 text-muted"
                                    >

                                        No sales yet

                                    </td>

                                </tr>

                                </tbody>

                            </table>

                        </div>


                    </div>

                </div>



                <div class="col-xl-5">

                    <div class="content-card">

                        <div class="card-heading">

                            <div>

                                <h5>
                                    Recent Activities
                                </h5>

                                <small>
                                    Latest system activity
                                </small>

                            </div>

                        </div>


                        <div class="empty-activity">

                            <i class="fa-solid fa-clock-rotate-left"></i>

                            <p>
                                No recent activities
                            </p>

                        </div>


                    </div>

                </div>


            </div>


        </section>


        <footer class="footer">

            <span>

                © <?= date('Y') ?>

                <?= htmlspecialchars($businessName) ?>

                Management System

            </span>


            <span>
                Version 1.0.0
            </span>

        </footer>


    </main>


</div>


</body>

</html>