<ul class="nav-menu">
    <li><a href="<?php echo SITE_URL; ?>/admin/dashboard"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
    <li>
        <a href="#"><i class="fas fa-building"></i> Properties <i class="fas fa-chevron-down"></i></a>
        <ul class="submenu">
            <li><a href="<?php echo SITE_URL; ?>/admin/properties">All Properties</a></li>
            <li><a href="<?php echo SITE_URL; ?>/admin/properties/pending">Pending Approval</a></li>
            <li><a href="<?php echo SITE_URL; ?>/admin/properties/reported">Reported Listings</a></li>
        </ul>
    </li>
    <li>
        <a href="#"><i class="fas fa-users"></i> Users <i class="fas fa-chevron-down"></i></a>
        <ul class="submenu">
            <li><a href="<?php echo SITE_URL; ?>/admin/users">All Users</a></li>
            <li><a href="<?php echo SITE_URL; ?>/admin/users/students">Students</a></li>
            <li><a href="<?php echo SITE_URL; ?>/admin/users/owners">Property Owners</a></li>
            <li><a href="<?php echo SITE_URL; ?>/admin/users/admins">Administrators</a></li>
        </ul>
    </li>
    <li>
        <a href="#"><i class="fas fa-file-invoice-dollar"></i> Payments <i class="fas fa-chevron-down"></i></a>
        <ul class="submenu">
            <li><a href="<?php echo SITE_URL; ?>/admin/payments">Transactions</a></li>
            <li><a href="<?php echo SITE_URL; ?>/admin/payments/pending">Pending Payments</a></li>
            <li><a href="<?php echo SITE_URL; ?>/admin/payments/reports">Reports</a></li>
        </ul>
    </li>
    <li><a href="<?php echo SITE_URL; ?>/admin/settings"><i class="fas fa-cog"></i> System Settings</a></li>
</ul>