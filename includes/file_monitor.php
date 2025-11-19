<?php
// includes/file_monitor.php

class FileMonitor {
    private $monitor_file = __DIR__ . '/../temp/file_monitor.json';
    private $scan_interval = 300; // 5 minutes
    
    public function __construct() {
        // Create temp directory if not exists
        $temp_dir = dirname($this->monitor_file);
        if (!is_dir($temp_dir)) {
            mkdir($temp_dir, 0755, true);
        }
    }
    
    public function shouldScan() {
        if (!file_exists($this->monitor_file)) {
            return true;
        }
        
        $data = json_decode(file_get_contents($this->monitor_file), true);
        $last_scan = $data['last_scan'] ?? 0;
        
        return (time() - $last_scan) > $this->scan_interval;
    }
    
    public function updateScanTime() {
        $data = [
            'last_scan' => time(),
            'last_scan_date' => date('Y-m-d H:i:s')
        ];
        
        file_put_contents($this->monitor_file, json_encode($data));
    }
    
    public function getLastScanInfo() {
        if (!file_exists($this->monitor_file)) {
            return 'Never scanned';
        }
        
        $data = json_decode(file_get_contents($this->monitor_file), true);
        return $data['last_scan_date'] ?? 'Unknown';
    }
}

// Usage in config.php
function checkAutoScan() {
    if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
        return;
    }
    
    $monitor = new FileMonitor();
    
    if ($monitor->shouldScan()) {
        scanAndAddNewFiles();
        $monitor->updateScanTime();
    }
}
?>