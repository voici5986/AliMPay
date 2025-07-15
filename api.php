<?php

// Start output buffering to prevent header issues
ob_start();

// Set headers early to prevent header issues
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    ob_end_clean();
    exit(0);
}

require_once 'vendor/autoload.php';

use AliMPay\Core\CodePay;
use AliMPay\Utils\Logger;
use AliMPay\Core\BackgroundTaskManager;
use AliMPay\Core\PaymentMonitor;

/**
 * 容器友好的监控服务启动器
 * 在每次API请求时检查并启动监控服务
 */
function startMonitoringServiceIfNeeded() {
    $lockFile = __DIR__ . '/data/monitor.lock';
    $monitorInterval = 300; // 5分钟锁定时间
    
    try {
        // 检查是否需要启动监控服务
        $shouldStart = false;
        
        if (!file_exists($lockFile)) {
            $shouldStart = true;
        } else {
            $lastRun = filemtime($lockFile);
            $timeSinceLastRun = time() - $lastRun;
            
            // 如果超过300秒（5分钟）没有运行，认为服务可能已停止
            if ($timeSinceLastRun > 300) {
                $shouldStart = true;
            }
        }
        
        if ($shouldStart) {
            $taskManager = new BackgroundTaskManager($lockFile, $monitorInterval);
            
            if ($taskManager->acquireLock()) {
                try {
                    $logger = Logger::getInstance();
                    $logger->info('Container monitoring service triggered by API request');

                    $alipayConfig = require __DIR__ . '/config/alipay.php';
                    $billQuery = new \AliMPay\Core\BillQuery(new \AliMPay\Core\AlipayClient($alipayConfig));
                    $logger->info('Alipay client for container monitoring initialized');
                    
                    $codePay = new CodePay();
                    $db = $codePay->getDb();
                    $merchantConfig = $codePay->getMerchantInfo();

                    $paymentMonitor = new PaymentMonitor($billQuery, $db, $merchantConfig);
                    $paymentMonitor->runMonitoringCycle();
                    $logger->info('Container monitoring cycle completed successfully');
                    
                    // 在后台异步启动持续监控
                    startBackgroundMonitoring();
                    
                } catch (Exception $e) {
                    Logger::getInstance()->error('Container monitoring failed', ['error' => $e->getMessage()]);
                } finally {
                    $taskManager->releaseLock();
                }
            }
        }
    } catch (Exception $e) {
        Logger::getInstance()->error('Error in container monitoring startup', ['error' => $e->getMessage()]);
    }
}

/**
 * 启动后台持续监控（异步）
 */
function startBackgroundMonitoring() {
    $backgroundScript = __DIR__ . '/container_monitor.php';
    
    // 检查后台监控脚本是否存在
    if (!file_exists($backgroundScript)) {
        createBackgroundMonitorScript($backgroundScript);
    }
    
    // 在后台启动监控脚本
    if (PHP_OS_FAMILY !== 'Windows') {
        // Linux/Unix 环境
        $command = "nohup php {$backgroundScript} > /dev/null 2>&1 &";
    } else {
        // Windows 环境
        $command = "start /B php {$backgroundScript}";
    }
    
    exec($command);
    
    Logger::getInstance()->info('Background monitoring process started', ['script' => $backgroundScript]);
}

/**
 * 创建容器专用的后台监控脚本
 */
function createBackgroundMonitorScript($scriptPath) {
    $script = '<?php
require_once __DIR__ . \'/vendor/autoload.php\';

use AliMPay\Core\AlipayClient;
use AliMPay\Core\BillQuery;
use AliMPay\Core\PaymentMonitor;
use AliMPay\Core\CodePay;
use AliMPay\Utils\Logger;

// 设置北京时间
date_default_timezone_set(\'Asia/Shanghai\');

$logger = Logger::getInstance();
$logger->info("Container background monitor started");

$lockFile = __DIR__ . \'/data/container_monitor.lock\';
$monitorInterval = 30; // 30秒间隔
$maxRunTime = 3600; // 最多运行1小时后自动退出，让API重新启动

// 创建锁文件
file_put_contents($lockFile, getmypid());

// 注册清理函数
register_shutdown_function(function() use ($lockFile) {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
});

$startTime = time();
$cycleCount = 0;

try {
    $alipayClient = new AlipayClient();
    $billQuery = new BillQuery($alipayClient);
    $codePay = new CodePay();
    $db = $codePay->getDb();
    $merchantInfo = $codePay->getMerchantInfo();
    
    $paymentMonitor = new PaymentMonitor($billQuery, $db, $merchantInfo);
    
    while (true) {
        $currentTime = time();
        $runTime = $currentTime - $startTime;
        
        // 如果运行时间超过最大时间，退出让API重新启动
        if ($runTime >= $maxRunTime) {
            $logger->info("Container monitor reached max runtime, exiting gracefully", [
                "run_time" => $runTime,
                "cycles_completed" => $cycleCount
            ]);
            break;
        }
        
        $cycleCount++;
        
        try {
            $paymentMonitor->runMonitoringCycle();
            $logger->debug("Container monitor cycle completed", ["cycle" => $cycleCount]);
        } catch (Exception $e) {
            $logger->error("Container monitor cycle failed", [
                "cycle" => $cycleCount,
                "error" => $e->getMessage()
            ]);
        }
        
        sleep($monitorInterval);
    }
    
} catch (Exception $e) {
    $logger->error("Container monitor initialization failed", ["error" => $e->getMessage()]);
}

// 清理锁文件
if (file_exists($lockFile)) {
    unlink($lockFile);
}

$logger->info("Container background monitor stopped", ["total_cycles" => $cycleCount]);
?>';
    
    file_put_contents($scriptPath, $script);
    Logger::getInstance()->info('Container monitor script created', ['path' => $scriptPath]);
}

function redirectToSubmitPage(array $paymentResult) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>正在跳转到支付页面...</title>
        <meta charset="utf-t">
        <style>
            body {
                font-family: sans-serif;
                text-align: center;
                padding-top: 50px;
                background-color: #f5f5f5;
            }
            .loading-spinner {
                border: 4px solid #f3f3f3;
                border-top: 4px solid #3498db;
                border-radius: 50%;
                width: 40px;
                height: 40px;
                animation: spin 1s linear infinite;
                margin: 20px auto;
            }
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        </style>
    </head>
    <body onload="document.getElementById('redirectForm').submit();">
        <form id="redirectForm" method="post" action="submit.php">
            <input type="hidden" name="payment_result" value="<?php echo htmlspecialchars(json_encode($paymentResult)); ?>">
            <?php foreach ($paymentResult as $key => $value): ?>
                <?php if (!is_array($value)): ?>
                    <input type="hidden" name="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($value); ?>">
                <?php endif; ?>
            <?php endforeach; ?>
        </form>
        <h2>正在为您跳转到支付页面，请稍候...</h2>
        <div class="loading-spinner"></div>
        <p>如果您的浏览器没有自动跳转，请点击下方的按钮</p>
        <button onclick="document.getElementById('redirectForm').submit();">继续</button>
    </body>
    </html>
    <?php
    exit;
}

// 在每次API请求时触发监控检查
startMonitoringServiceIfNeeded();

try {
    $codePay = new CodePay();
    $logger = Logger::getInstance();
    
    // Get action parameter (CodePay protocol), compatible with 'act' and 'action'
    $action = $_GET['act'] ?? $_GET['action'] ?? $_POST['act'] ?? $_POST['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    // 如果是创建支付的请求，并且没有指定输出格式，则重定向到submit.php
    $isPaymentRequest = ($method === 'POST' && empty($action)) || in_array($action, ['submit', 'create']);
    $outputFormat = $_GET['format'] ?? $_POST['format'] ?? 'html';

    if ($isPaymentRequest && $outputFormat !== 'json') {
        $params = [
            'pid' => $_POST['pid'] ?? '',
            'type' => $_POST['type'] ?? '',
            'out_trade_no' => $_POST['out_trade_no'] ?? '',
            'notify_url' => $_POST['notify_url'] ?? '',
            'return_url' => $_POST['return_url'] ?? '',
            'name' => $_POST['name'] ?? '',
            'money' => $_POST['money'] ?? '',
            'sitename' => $_POST['sitename'] ?? '',
            'sign' => $_POST['sign'] ?? '',
            'sign_type' => $_POST['sign_type'] ?? 'MD5'
        ];
        
        $paymentResult = $codePay->createPayment($params);
        redirectToSubmitPage($paymentResult);
        exit;
    }

    if (empty($action)) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['code' => -1, 'msg' => 'Missing action parameter'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $logger->info('CodePay API Request', ['action' => $action, 'method' => $method, 'ip' => $ip]);
    
    switch ($action) {
        case 'query':
            // Query merchant information - CodePay protocol
            $pid = $_GET['pid'] ?? $_POST['pid'] ?? '';
            $key = $_GET['key'] ?? $_POST['key'] ?? '';
            
            if (empty($pid) || empty($key)) {
                ob_end_clean();
                http_response_code(400);
                echo json_encode([
                    'code' => -1,
                    'msg' => 'Missing required parameters: pid, key'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            $result = $codePay->queryMerchant($pid, $key);
            break;
            
        case 'order':
            // Query single order - CodePay protocol
            $pid = $_GET['pid'] ?? $_POST['pid'] ?? '';
            $key = $_GET['key'] ?? $_POST['key'] ?? '';
            $outTradeNo = $_GET['out_trade_no'] ?? $_POST['out_trade_no'] ?? '';
            
            if (empty($pid) || empty($outTradeNo)) {
                ob_end_clean();
                http_response_code(400);
                echo json_encode([
                    'code' => -1,
                    'msg' => 'Missing required parameters for order query'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // Allow keyless query for frontend status check
            $validateKey = !empty($key);
            $result = $codePay->queryOrder($pid, $key, $outTradeNo, $validateKey);
            break;

        case 'orders':
            // Query multiple orders - CodePay protocol
            $pid = $_GET['pid'] ?? $_POST['pid'] ?? '';
            $key = $_GET['key'] ?? $_POST['key'] ?? '';
            $limit = (int)($_GET['limit'] ?? $_POST['limit'] ?? 20);
            
            if (empty($pid) || empty($key)) {
                ob_end_clean();
                http_response_code(400);
                echo json_encode([
                    'code' => -1,
                    'msg' => 'Missing required parameters: pid, key'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            $result = $codePay->queryOrders($pid, $key, $limit);
            break;
            
        case 'submit':
        case 'create':
            // Create payment request - CodePay protocol
            $params = [
                'pid' => $_POST['pid'] ?? '',
                'type' => $_POST['type'] ?? '',
                'out_trade_no' => $_POST['out_trade_no'] ?? '',
                'notify_url' => $_POST['notify_url'] ?? '',
                'return_url' => $_POST['return_url'] ?? '',
                'name' => $_POST['name'] ?? '',
                'money' => $_POST['money'] ?? '',
                'sitename' => $_POST['sitename'] ?? '',
                'sign' => $_POST['sign'] ?? '',
                'sign_type' => $_POST['sign_type'] ?? 'MD5'
            ];
            
            $result = $codePay->createPayment($params);
            break;
            
        case 'notify':
            // Process payment notification - CodePay protocol
            $params = $_POST;
            $result = $codePay->processNotification($params);
            
            // The response for notification should be plain text "success" or "fail"
            ob_end_clean();
            if ($result['code'] === 1) {
                echo 'success';
            } else {
                echo 'fail';
            }
            exit;

        case 'health':
            // Health check
            $result = [
                'code' => 1,
                'msg' => 'System is healthy',
                'timestamp' => date('Y-m-d H:i:s')
            ];
            break;

        default:
            ob_end_clean();
            http_response_code(400);
            echo json_encode([
                'code' => -1,
                'msg' => 'Invalid action'
            ], JSON_UNESCAPED_UNICODE);
            exit;
    }
    
    ob_end_clean();
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    if (isset($logger)) {
        $logger->error('CodePay API Error', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
    
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'code' => -1,
        'msg' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} 