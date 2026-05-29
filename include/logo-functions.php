<?php
// Save as include/logo-functions.php

function getFaviconTags() {
    return '
    <link rel="icon" type="image/png" href="include/logo.png">
    <link rel="shortcut icon" href="include/logo.png">
    <link rel="apple-touch-icon" href="include/logo.png">
    ';
}

function getLoginLogoHtml() {
    return '
    <div class="logo-container" style="text-align: center; margin-bottom: 20px;">
        <div style="display: inline-block; background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%); padding: 20px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
            <img src="include/logo.png" alt="System Logo" style="width: 150px; height: auto; border-radius: 10px;">
            <div style="color: white; margin-top: 15px; font-weight: bold; font-size: 18px; letter-spacing: 2px;">
                NSTP • CUTS • ROTC • LTS
            </div>
            <div style="color: rgba(255,255,255,0.8); font-size: 12px; margin-top: 5px;">
                QR Attendance System
            </div>
        </div>
    </div>';
}

function getNavbarLogoHtml() {
    return '
    <div class="d-flex align-items-center">
        <img src="include/logo.png" alt="Logo" style="width: 40px; height: 40px; border-radius: 8px; margin-right: 10px;">
        <span style="font-weight: 600; color: #2c3e50;">NSTP CUTS ROTC LTS</span>
    </div>';
}

function getSidebarLogoHtml() {
    return '
    <div class="brand-link d-flex align-items-center" style="background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%); padding: 15px;">
        <img src="include/logo.png" alt="Logo" style="width: 45px; height: 45px; border-radius: 10px; margin-right: 10px; border: 2px solid rgba(255,255,255,0.2);">
        <div style="color: white;">
            <span style="font-weight: bold; font-size: 16px;">NSTP CUTS</span><br>
            <span style="font-size: 12px; opacity: 0.9;">ROTC • LTS</span>
        </div>
    </div>';
}
?>