<?php
// 针对的是DSU每日签到插件，路径是plugin.php?id=dsu_paulsign:sign，论坛一般用的都是这个插件
// 推荐每日零点执行一次签到脚本 
// 0 0 * * * php /path/to/your/script/discuzDsuAutoSign.php

$logDir = __DIR__ . '/runtime';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// 单文件日志
$logFile = $logDir . '/' . 'discuz_sign.log';
// 设置为上海时区，避免日志时间与实际时间不符
date_default_timezone_set('Asia/Shanghai');

function writeLog(string $message)
{
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

function getSignStatusField(string $user): string
{
    $normalizedUser = preg_replace('/[^a-zA-Z0-9_-]/', '_', $user);
    if ($normalizedUser === null || $normalizedUser === '') {
        $normalizedUser = 'unknown_user';
    }
    return 'sign_status_' . $normalizedUser;
}

function loadSignStatus(string $statusFile): array
{
    if (!file_exists($statusFile)) {
        return array();
    }

    $raw = file_get_contents($statusFile);
    if ($raw === false || trim($raw) === '') {
        return array();
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        writeLog("签到状态文件解析失败，将重置：{$statusFile}");
        return array();
    }

    return $decoded;
}

function saveSignStatus(string $statusFile, array $status): bool
{
    $json = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        writeLog('签到状态编码失败，未写入状态文件');
        return false;
    }

    return file_put_contents($statusFile, $json . PHP_EOL, LOCK_EX) !== false;
}

function markSignedToday(string $statusFile, string $statusField, string $today, string $message): void
{
    $status = loadSignStatus($statusFile);
    if (!isset($status[$statusField]) || !is_array($status[$statusField])) {
        $status[$statusField] = array();
    }

    $status[$statusField][$today] = array(
        'signed' => true,
        'message' => $message,
        'signed_at' => date('Y-m-d H:i:s'),
    );

    if (!saveSignStatus($statusFile, $status)) {
        writeLog("签到状态写入失败：{$statusFile}");
    }
}

function curlGet(string $url, bool $use = false, bool $save = false, ?string $referer = null, ?array $post_data = null)
{
    global $cookie_file;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    //需要使用cookies
    if ($use) {
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
    }
    //需要保存cookies
    if ($save) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
    }
    //需要referer伪装
    if (isset($referer)) {
        curl_setopt($ch, CURLOPT_REFERER, $referer);
    }

    //需要post数据
    if (is_array($post_data)) {
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    }
    $content = curl_exec($ch);
    return $content;
}

function getFormhash(string $res): string
{
    if (preg_match('/name="formhash" value="(.*?)"/i', $res, $matches)) {
        return $matches[1];
    } else {
        exit('没有找到formhash');
    }
}

//签到代码
$user = 'username'; //用户名
$pwd = 'yourpassword'; //密码
$baseUrl = 'https://www.example.com/'; //论坛首页地址 结尾带上”/”

//心情：开心，难过，郁闷，无聊，怒，擦汗，奋斗，慵懒，衰
//{"kx","ng","ym","wl","nu","ch","fd","yl","shuai"};
$qdxq = 'kx'; //签到时使用的心情
$todaysay = '又是美好的一天～'; //想说的话

//账号登录地址
$loginPageUrl = $baseUrl . 'member.php?mod=logging&action=login';
//账号信息提交地址
$loginSubmitUrl = $baseUrl . 'member.php?mod=logging&action=login&loginsubmit=yes&loginhash=LNvu3';
//签到页面地址
$signPageUrl = $baseUrl . 'plugin.php?id=dsu_paulsign:sign';
//签到信息提交地址
$signSubmitUrl = $baseUrl . 'plugin.php?id=dsu_paulsign:sign&operation=qiandao&infloat=0&inajax=0';

//本地签到状态文件
$statusFile = $logDir . '/discuz_sign_status.json';
$statusField = getSignStatusField($user);
$today = date('Y-m-d');

//存放Cookies的文件
$cookie_file = '';

function cleanupCookieFile()
{
    global $cookie_file;
    if (!empty($cookie_file) && file_exists($cookie_file)) {
        unlink($cookie_file);
        writeLog("清理cookie文件：{$cookie_file}");
    }
}

// 注册脚本结束时的清理函数，确保cookie文件被删除
register_shutdown_function('cleanupCookieFile');

writeLog("开始执行签到，用户：{$user}，论坛：{$baseUrl}");

$signStatus = loadSignStatus($statusFile);
if (
    isset($signStatus[$statusField][$today])
    && is_array($signStatus[$statusField][$today])
    && !empty($signStatus[$statusField][$today]['signed'])
) {
    writeLog("本地状态检查：{$today} 已签到，跳过本次执行");
    exit(0);
}

$cookie_file = tempnam($logDir, 'cookie');
if ($cookie_file === false) {
    writeLog('创建cookie文件失败，脚本终止');
    exit(1);
}

//访问论坛登录页面，保存Cookies
$res = curlGet($loginPageUrl, false, true);
//获取DiscuzX论坛的formhash验证串
$formhash = getFormhash($res);

//构建登录信息
$login_array = array(
    'username' => $user,
    'password' => $pwd,
    'referer' => $baseUrl,
    'questionid' => 0,
    'answer' => '',
    'formhash' => $formhash,
);

//携带cookie提交登录信息
$res = curlGet($loginSubmitUrl, true, true, null, $login_array);
if (strpos($res, '欢迎您回来')) {
    writeLog("登录成功");
    //访问签到页面，获取签到状态
    $res = curlGet($signPageUrl, true, true);
    //根据签到页面上的文字来判断今天是否已经签到
    if (strpos($res, '您今天已经签到过了或者签到时间还未开始')) {
        $resultStr = "今天已签过到\r\n";
        writeLog("今天已签过到，跳过");
        markSignedToday($statusFile, $statusField, $today, 'forum_already_signed');
    } else {
        //获取formhash验证串
        $formhash = getFormhash($res);
        //构造签到信息
        $post_data = array(
            'qdmode' => 1,
            'formhash' => $formhash,
            'qdxq' => $qdxq,
            'fastreply' => 0,
            'todaysay' => $todaysay,
        );
        //提交签到信息
        $res = curlGet($signSubmitUrl, true, true, null, $post_data);
        if (strpos($res, '签到成功')) {
            $resultStr = "签到成功\r\n";
            writeLog("签到成功");
            markSignedToday($statusFile, $statusField, $today, 'sign_success');
        } else {
            $resultStr = "签到失败\r\n";
            writeLog("签到失败，响应内容片段：" . mb_substr(strip_tags($res), 0, 200));
        }
    }
} else {
    $resultStr = "登陆失败\r\n";
    writeLog("登录失败，响应内容片段：" . mb_substr(strip_tags($res), 0, 200));
}
cleanupCookieFile();