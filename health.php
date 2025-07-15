<?php
require_once 'vendor/autoload.php';

use AliMPay\Core\CodePay;
use AliMPay\Utils\Logger;
use AliMPay\Core\AlipayClient;
use AliMPay\Core\BillQuery;
use AliMPay\Core\PaymentMonitor;

// 设置北京时间
date_default_timezone_set('Asia/Shanghai');

// 设置响应头
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$action = $_GET['action'] ?? 'status';

try {
    switch ($action) {
        case 'status':
            checkSystemStatus();
            break;
        case 'monitor':
            runMonitoringCheck();
            break;
        case 'force-start':
            forceStartMonitoring();
            break;
        case 'cleanup':
            cleanupServices();
            break;
        default:
            respondError('Invalid action');
    }
} catch (Exception $e) {
    respondError($e->getMessage());
}

/**
 * 检查系统整体状态
 */
function checkSystemStatus() {
    $status = [
        'timestamp' => date('Y-m-d H:i:s'),
        'system' => 'CodePay Container Monitor',
        'status' => 'ok',
        'services' => [],
        'counters' => [],
        'suggestions' => []
    ];
    
    try {
        // 1. 检查数据库
        $codePay = new CodePay();
        $db = $codePay->getDb();
        $orderCount = $db->count('codepay_orders');
        $unpaidCount = $db->count('codepay_orders', ['status' => 0]);
        
        $status['services']['database'] = [
            'status' => 'healthy',
            'total_orders' => $orderCount,
            'unpaid_orders' => $unpaidCount
        ];
        
        // 2. 检查支付宝API
        $alipayClient = new AlipayClient();
        $alipayStatus = $alipayClient->validateConfig() ? 'healthy' : 'error';
        $status['services']['alipay_api'] = ['status' => $alipayStatus];
        
        // 3. 检查监控服务
        $monitorStatus = checkMonitoringService();
        $status['services']['monitoring'] = $monitorStatus;
        
        // 4. 检查订单清理功能
        $config = require __DIR__ . '/config/alipay.php';
        $autoCleanup = $config['payment']['auto_cleanup'] ?? true;
        $orderTimeout = $config['payment']['order_timeout'] ?? 300;
        
        // 查询即将过期的订单数量
        $expiredThreshold = date('Y-m-d H:i:s', time() - $orderTimeout);
        $expiredCount = $db->count('codepay_orders', [
            'status' => 0,
            'add_time[<]' => $expiredThreshold
        ]);
        
        $status['services']['order_cleanup'] = [
            'status' => $autoCleanup ? 'enabled' : 'disabled',
            'timeout_seconds' => $orderTimeout,
            'expired_orders_count' => $expiredCount,
            'last_cleanup' => 'Runs with monitoring cycle'
        ];
        
        // 5. 统计信息
        $status['counters'] = [
            'total_orders' => $orderCount,
            'unpaid_orders' => $unpaidCount,
            'paid_orders' => $orderCount - $unpaidCount,
            'system_uptime' => getSystemUptime()
        ];
        
        // 6. 建议
        if ($monitorStatus['status'] !== 'running') {
            $status['suggestions'][] = 'Monitoring service is not running. Call /health.php?action=force-start to restart.';
        }
        
        if ($unpaidCount > 10) {
            $status['suggestions'][] = "Many unpaid orders ({$unpaidCount}). Check if payments are being processed correctly.";
        }
        
        if (!$autoCleanup && $expiredCount > 0) {
            $status['suggestions'][] = "Auto cleanup is disabled and there are {$expiredCount} expired orders. Consider enabling auto cleanup.";
        }
        
        if ($expiredCount > 5) {
            $status['suggestions'][] = "High number of expired orders ({$expiredCount}). Check if monitoring service is running properly.";
        }
        
        // 7. 整体状态判断
        if ($alipayStatus !== 'healthy' || $monitorStatus['status'] === 'error') {
            $status['status'] = 'degraded';
        }
        
    } catch (Exception $e) {
        $status['status'] = 'error';
        $status['error'] = $e->getMessage();
    }
    
    respondSuccess($status);
}

/**
 * 运行监控检查
 */
function runMonitoringCheck() {
    $result = [
        'timestamp' => date('Y-m-d H:i:s'),
        'action' => 'monitoring_check',
        'status' => 'completed'
    ];
    
    try {
        $codePay = new CodePay();
        $db = $codePay->getDb();
        $merchantInfo = $codePay->getMerchantInfo();
        
        $alipayClient = new AlipayClient();
        $billQuery = new BillQuery($alipayClient);
        $paymentMonitor = new PaymentMonitor($billQuery, $db, $merchantInfo);
        
        // 运行一次监控周期
        $paymentMonitor->runMonitoringCycle();
        
        $result['message'] = 'Monitoring cycle completed successfully';
        
        // 更新监控状态文件
        $statusFile = __DIR__ . '/data/monitor_status.json';
        $statusData = [
            'last_run' => time(),
            'last_success' => time(),
            'status' => 'healthy'
        ];
        file_put_contents($statusFile, json_encode($statusData));
        
    } catch (Exception $e) {
        $result['status'] = 'error';
        $result['error'] = $e->getMessage();
        
        // 记录错误状态
        $statusFile = __DIR__ . '/data/monitor_status.json';
        $statusData = [
            'last_run' => time(),
            'last_error' => $e->getMessage(),
            'status' => 'error'
        ];
        file_put_contents($statusFile, json_encode($statusData));
    }
    
    respondSuccess($result);
}

/**
 * 强制启动监控服务
 */
function forceStartMonitoring() {
    $result = [
        'timestamp' => date('Y-m-d H:i:s'),
        'action' => 'force_start_monitoring'
    ];
    
    try {
        // 清理旧的锁文件
        $lockFiles = [
            __DIR__ . '/data/monitor.lock',
            __DIR__ . '/data/container_monitor.lock'
        ];
        
        foreach ($lockFiles as $lockFile) {
            if (file_exists($lockFile)) {
                unlink($lockFile);
            }
        }
        
        // 启动监控
        include_once __DIR__ . '/api.php';
        
        $result['status'] = 'started';
        $result['message'] = 'Monitoring service force started';
        
    } catch (Exception $e) {
        $result['status'] = 'error';
        $result['error'] = $e->getMessage();
    }
    
    respondSuccess($result);
}

/**
 * 清理服务文件
 */
function cleanupServices() {
    $result = [
        'timestamp' => date('Y-m-d H:i:s'),
        'action' => 'cleanup',
        'cleaned_files' => []
    ];
    
    $filesToClean = [
        __DIR__ . '/data/monitor.lock',
        __DIR__ . '/data/container_monitor.lock',
        __DIR__ . '/container_monitor.php'
    ];
    
    foreach ($filesToClean as $file) {
        if (file_exists($file)) {
            unlink($file);
            $result['cleaned_files'][] = basename($file);
        }
    }
    
    $result['status'] = 'completed';
    $result['message'] = 'Cleanup completed';
    
    respondSuccess($result);
}

/**
 * 检查监控服务状态
 */
function checkMonitoringService() {
    $status = [
        'status' => 'stopped',
        'last_run' => null,
        'uptime' => 0
    ];
    
    // 检查锁文件
    $lockFile = __DIR__ . '/data/monitor.lock';
    $containerLockFile = __DIR__ . '/data/container_monitor.lock';
    
    if (file_exists($lockFile)) {
        $lastRun = filemtime($lockFile);
        $timeSinceLastRun = time() - $lastRun;
        
        $status['last_run'] = date('Y-m-d H:i:s', $lastRun);
        $status['seconds_since_last_run'] = $timeSinceLastRun;
        
        if ($timeSinceLastRun < 60) {
            $status['status'] = 'running';
        } else {
            $status['status'] = 'stale';
        }
    }
    
    if (file_exists($containerLockFile)) {
        $startTime = filemtime($containerLockFile);
        $status['uptime'] = time() - $startTime;
        $status['container_monitor'] = 'running';
    }
    
    // 检查状态文件
    $statusFile = __DIR__ . '/data/monitor_status.json';
    if (file_exists($statusFile)) {
        $statusData = json_decode(file_get_contents($statusFile), true);
        if ($statusData) {
            $status = array_merge($status, $statusData);
        }
    }
    
    return $status;
}

/**
 * 获取系统运行时间
 */
function getSystemUptime() {
    $configFile = __DIR__ . '/config/codepay.json';
    if (file_exists($configFile)) {
        $config = json_decode(file_get_contents($configFile), true);
        if (isset($config['created_at'])) {
            $startTime = strtotime($config['created_at']);
            return time() - $startTime;
        }
    }
    return 0;
}

/**
 * 成功响应
 */
function respondSuccess($data) {
    echo json_encode([
        'success' => true,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * 错误响应
 */
function respondError($message) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
?> 