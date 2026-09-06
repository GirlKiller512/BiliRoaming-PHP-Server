<?php
// 分类
$type = 0;
$cache_type = "web";
// 加载配置
include ($_SERVER['DOCUMENT_ROOT']."/config.php");
// 加载版本
include(ROOT_PATH."utils/version.php");
// 加载functions
include (ROOT_PATH."utils/functions.php");
// 处理用户传入参数
include (ROOT_PATH."utils/process.php");
// 设置host
$host = get_host($type, $cache_type);
// 锁区、web接口、X-From-Biliroaming
include (ROOT_PATH."utils/lock_area.php");
// 鉴权、替换access_key、获取缓存
//// （无）
// 指定ip回源
if (IP_RESOLVE == 1) {
	$host = $hosts[array_rand($hosts)];
	$ip = $ips[array_rand($ips)];
}
// 转发到指定服务器
$url = $host.$path."?".$query;
$agent = @$_SERVER["HTTP_USER_AGENT"];
$headers = get_web_search_headers($agent);
if (IP_RESOLVE == 1) {
	$output = get_webpage($url, $host, $ip, $agent, $headers);
} else {
	$output = get_webpage($url, "", "", $agent, $headers);
}
// Cookie 失效时刷新匿名设备身份并仅重试一次。
if (is_bilibili_412_response($output)) {
	$headers = get_web_search_headers($agent, true);
	if (IP_RESOLVE == 1) {
		$output = get_webpage($url, $host, $ip, $agent, $headers);
	} else {
		$output = get_webpage($url, "", "", $agent, $headers);
	}
}
if (is_bilibili_412_response($output)) {
	http_response_code(412);
	header('Content-Type: application/json; charset=utf-8');
	$output = '{"code":-412,"message":"request was banned","ttl":1}';
}
// 替换内容
include (ROOT_PATH."utils/replace.php");
// 返回内容给用户
print($output);
// 写入缓存
//// （无）
?>
