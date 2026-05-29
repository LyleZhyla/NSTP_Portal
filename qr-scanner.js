// qr-scanner.js - Dedicated QR scanner handler
let scanner = null;
let isScanning = false;
let cameras = [];
let currentCameraIndex = 0;
let scannerInitialized = false;

// Initialize scanner with better error handling
function initScanner() {
    return new Promise((resolve, reject) => {
        showStatus('info', 'Requesting camera access...');
        
        // First check if browser supports getUserMedia
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            showStatus('danger', 'Your browser does not support camera access. Please use Chrome, Firefox, or Edge.');
            reject('Browser not supported');
            return;
        }
        
        // Try to get camera list with better error handling
        Instascan.Camera.getCameras()
            .then(function(availableCameras) {
                cameras = availableCameras;
                
                if (cameras.length > 0) {
                    // Populate camera select
                    const cameraSelect = $('#cameraSelect');
                    cameraSelect.empty();
                    
                    cameras.forEach((camera, index) => {
                        const cameraName = camera.name || `Camera ${index + 1}`;
                        cameraSelect.append(
                            $('<option></option>').val(index).text(cameraName)
                        );
                    });
                    
                    $('#cameraSelectGroup').show();
                    
                    // Auto-select back camera if available
                    const backCameraIndex = cameras.findIndex(camera => 
                        camera.name.toLowerCase().includes('back') || 
                        camera.name.toLowerCase().includes('rear') ||
                        camera.name.toLowerCase().includes('environment') ||
                        camera.name.toLowerCase().includes('1')
                    );
                    
                    if (backCameraIndex !== -1) {
                        currentCameraIndex = backCameraIndex;
                    } else {
                        currentCameraIndex = 0; // Use first camera
                    }
                    
                    cameraSelect.val(currentCameraIndex).trigger('change');
                    
                    showStatus('success', `${cameras.length} camera(s) found. Click "Start Scanner" to begin.`);
                    scannerInitialized = true;
                    resolve(cameras);
                } else {
                    showStatus('danger', 'No cameras found. Please connect a camera device and refresh the page.');
                    reject('No cameras found');
                }
            })
            .catch(function(error) {
                console.error('Camera initialization error:', error);
                
                if (error.name === 'NotAllowedError' || error.message.includes('permission')) {
                    showStatus('danger', 'Camera access denied. Please allow camera access and refresh the page.');
                } else if (error.name === 'NotFoundError' || error.message.includes('not found')) {
                    showStatus('danger', 'No camera device found. Please connect a camera and refresh the page.');
                } else if (error.name === 'NotReadableError' || error.message.includes('in use')) {
                    showStatus('danger', 'Camera is already in use by another application. Please close other apps using the camera.');
                } else {
                    showStatus('danger', `Camera error: ${error.message || 'Unknown error'}`);
                }
                
                reject(error);
            });
    });
}

// Start scanner with improved error handling
function startScanner() {
    if (isScanning) {
        showStatus('info', 'Scanner is already running');
        return;
    }
    
    if (cameras.length === 0) {
        showStatus('warning', 'No cameras available. Please initialize scanner first.');
        initScanner().then(() => {
            startScanner();
        }).catch(() => {
            showStatus('danger', 'Cannot start scanner: No camera available');
        });
        return;
    }
    
    const videoElement = document.getElementById('scannerVideo');
    
    // Stop any existing scanner
    if (scanner) {
        try {
            scanner.stop();
        } catch (e) {
            console.log('Error stopping scanner:', e);
        }
        scanner = null;
    }
    
    // Create new scanner with optimal settings
    scanner = new Instascan.Scanner({ 
        video: videoElement,
        mirror: false,
        captureImage: false,
        backgroundScan: true,
        refractoryPeriod: 5000,
        scanPeriod: 1,
        continuous: true
    });
    
    // Add scan listener
    scanner.addListener('scan', function(content) {
        console.log('QR Code scanned:', content);
        handleScannedQR(content);
    });
    
    // Add active state listener
    scanner.addListener('active', function() {
        console.log('Scanner is active');
        showStatus('success', 'Scanner active - Ready to scan QR codes');
    });
    
    // Start scanning
    scanner.start(cameras[currentCameraIndex])
        .then(() => {
            isScanning = true;
            $('#startBtn').hide();
            $('#stopBtn').show();
            $('#switchBtn').show();
            $('#scannerVideo').css('border-color', '#28a745');
            showStatus('success', 'Scanner active - Position QR code within frame');
        })
        .catch(error => {
            console.error('Scanner start error:', error);
            isScanning = false;
            
            if (error.message.includes('permission')) {
                showStatus('danger', 'Camera permission denied. Please allow camera access and try again.');
            } else if (error.message.includes('not found')) {
                showStatus('danger', 'Camera device not found. Please check your camera connection.');
            } else if (error.message.includes('in use')) {
                showStatus('danger', 'Camera is busy. Please close other applications using the camera.');
            } else {
                showStatus('danger', `Failed to start scanner: ${error.message || 'Unknown error'}`);
            }
            
            $('#startBtn').show();
            $('#stopBtn').hide();
            $('#switchBtn').hide();
        });
}

// Stop scanner
function stopScanner() {
    if (scanner && isScanning) {
        try {
            scanner.stop();
            isScanning = false;
            $('#startBtn').show();
            $('#stopBtn').hide();
            $('#switchBtn').hide();
            $('#scannerVideo').css('border-color', '#007bff');
            showStatus('info', 'Scanner stopped');
        } catch (error) {
            console.error('Error stopping scanner:', error);
        }
    }
}

// Switch camera
function switchCamera() {
    if (cameras.length < 2) {
        showStatus('warning', 'Only one camera available');
        return;
    }
    
    currentCameraIndex = (currentCameraIndex + 1) % cameras.length;
    $('#cameraSelect').val(currentCameraIndex).trigger('change');
    
    if (isScanning) {
        scanner.stop()
            .then(() => {
                return scanner.start(cameras[currentCameraIndex]);
            })
            .catch(error => {
                console.error('Error switching camera:', error);
                showStatus('danger', 'Failed to switch camera');
            });
    }
}

// Test camera directly using getUserMedia
function testCameraDirectly() {
    showStatus('info', 'Testing camera access...');
    
    navigator.mediaDevices.getUserMedia({ video: true })
        .then(function(stream) {
            // Stop all tracks immediately
            stream.getTracks().forEach(track => track.stop());
            showStatus('success', 'Camera access granted! You can now start the scanner.');
            
            // Enable start button
            $('#startBtn').prop('disabled', false);
        })
        .catch(function(error) {
            console.error('Camera test error:', error);
            
            if (error.name === 'NotAllowedError') {
                showStatus('danger', 'Camera access denied. Please allow camera access in your browser settings.');
            } else if (error.name === 'NotFoundError') {
                showStatus('danger', 'No camera found. Please connect a camera to your device.');
            } else {
                showStatus('danger', `Camera error: ${error.message || 'Unknown error'}`);
            }
            
            // Disable start button
            $('#startBtn').prop('disabled', true);
        });
}

// Add this to your document ready
$(document).ready(function() {
    // Test camera access on page load
    setTimeout(() => {
        testCameraDirectly();
    }, 1000);
});