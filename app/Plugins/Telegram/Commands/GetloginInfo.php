<?php

namespace App\Plugins\Telegram\Commands;

use App\Plugins\Telegram\Telegram;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class GetLoginInfo extends Telegram
{
    public $command = '/GetLoginInfo';
    public $description = ''; // 不在前台菜单显示

    public function handle($message, $match = [])
    {
        $telegramService = $this->telegramService;
        
        // 如果不是私聊，则直接返回
        if (!$message->is_private) {
            return;
        }

        // 获取当前用户信息
        $user = User::where('telegram_id', $message->chat_id)->first();

        // 如果用户不存在，则提示绑定账号
        if (!$user) {
            $telegramService->sendMessage($message->chat_id, '❌ 没有查询到您的用户信息，请先绑定账号', 'markdown');
            return;
        }
        
        // 判断是否为管理员
        if (!$user->is_admin) {
            $telegramService->sendMessage($message->chat_id, '❌ 您不是管理员，无权执行此操作', 'markdown');
            return;
        }

        // 查询最近24小时内登录的用户
        $oneDayAgo = time() - 86400; // 86400秒 = 24小时
        
        $recentLogins = User::select(['id', 'email', 'last_login_at', 'last_login_ip'])
            ->whereNotNull('last_login_at')
            ->where('last_login_at', '>=', $oneDayAgo)
            ->orderBy('last_login_at', 'DESC')
            ->limit(50) // 限制最多显示50条
            ->get();

        // 如果没有登录记录
        if ($recentLogins->isEmpty()) {
            $text = "📊 最近24小时登录信息\n";
            $text .= "━━━━━━━━━━━━━━━━\n\n";
            $text .= "暂无用户登录记录";
            $telegramService->sendMessage($message->chat_id, $text, 'markdown');
            return;
        }

        // 统计信息
        $totalCount = $recentLogins->count();
        $telegramLoginCount = $recentLogins->where('last_login_ip', 'Telegram_login')->count();
        $normalLoginCount = $totalCount - $telegramLoginCount;

        // 构建消息文本
        $text = "📊 *最近24小时登录信息*\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\n";
        $text .= "📈 总登录次数：`{$totalCount}` 次\n";
        $text .= "🌐 普通登录：`{$normalLoginCount}` 次\n";
        $text .= "✈️ Telegram登录：`{$telegramLoginCount}` 次\n";
        $text .= "\n";
        
        // 添加表格
        $text .= "```\n";
        $text .= "序号 用户邮箱            登录时间      登录IP          地理位置\n";
        $text .= str_repeat("=", 70) . "\n";
        
        // 添加登录记录
        foreach ($recentLogins as $index => $login) {
            // 格式化时间
            $loginTime = date('m-d H:i', $login->last_login_at);
            
            // 格式化邮箱（处理长度，支持中英文）
            $email = $login->email;
            $emailLen = $this->getStringDisplayLength($email);
            if ($emailLen > 18) {
                $email = $this->truncateString($email, 15) . '...';
                $emailLen = 18;
            }
            $emailPadding = str_repeat(' ', 18 - $emailLen);
            
            // 格式化IP（如果是Telegram登录则显示特殊标识）
            $ip = $login->last_login_ip ?? '未知';
            $isTelegramLogin = false;
            if ($ip === 'Telegram_login') {
                $ip = 'TG_Login';
                $isTelegramLogin = true;
            } elseif (strlen($ip) > 15) {
                $ip = substr($ip, 0, 13) . '..';
            }
            $ipPadding = str_repeat(' ', 15 - strlen($ip));
            
            // 获取地理位置
            $location = '-';
            if (!$isTelegramLogin && $login->last_login_ip && $login->last_login_ip !== '未知') {
                $locationInfo = $this->getIpLocation($login->last_login_ip);
                if ($locationInfo) {
                    $location = $locationInfo;
                }
            }
            
            $text .= sprintf("%4d %s%s %s  %s%s %s\n", 
                $index + 1,
                $email,
                $emailPadding,
                $loginTime,
                $ip,
                $ipPadding,
                $location
            );
            
            // 每15条添加一个分隔线
            if (($index + 1) % 15 === 0 && $index + 1 < $totalCount) {
                $text .= str_repeat("-", 70) . "\n";
            }
        }
        
        $text .= "```\n";
        $text .= "\n💡 *提示*：仅显示最近50条登录记录\n";
        $text .= "⏰ 查询时间：" . date('Y-m-d H:i:s');

        // 发送消息给 Telegram 用户
        $telegramService->sendMessage($message->chat_id, $text, 'markdown');
    }

    /**
     * 计算字符串显示长度（中文算2个字符，英文算1个字符）
     */
    private function getStringDisplayLength($str)
    {
        $length = 0;
        $strLen = mb_strlen($str, 'UTF-8');
        for ($i = 0; $i < $strLen; $i++) {
            $char = mb_substr($str, $i, 1, 'UTF-8');
            // 判断是否为中文字符
            if (preg_match('/[\x{4e00}-\x{9fa5}]/u', $char)) {
                $length += 2;
            } else {
                $length += 1;
            }
        }
        return $length;
    }

    /**
     * 截取字符串到指定显示长度
     */
    private function truncateString($str, $maxDisplayLength)
    {
        $result = '';
        $currentLength = 0;
        $strLen = mb_strlen($str, 'UTF-8');
        
        for ($i = 0; $i < $strLen; $i++) {
            $char = mb_substr($str, $i, 1, 'UTF-8');
            $charLength = preg_match('/[\x{4e00}-\x{9fa5}]/u', $char) ? 2 : 1;
            
            if ($currentLength + $charLength > $maxDisplayLength) {
                break;
            }
            
            $result .= $char;
            $currentLength += $charLength;
        }
        
        return $result;
    }

    /**
     * 获取IP地理位置信息
     */
    private function getIpLocation($ip)
    {
        if (empty($ip) || $ip === '未知' || $ip === 'Telegram_login') {
            return '-';
        }

        try {
            $api_url = "http://ip-api.com/json/{$ip}?fields=520191&lang=zh-CN";
            
            // 设置超时时间，避免请求过长
            $context = stream_context_create([
                'http' => [
                    'timeout' => 3 // 3秒超时
                ]
            ]);
            
            $response = @file_get_contents($api_url, false, $context);
            
            if ($response === false) {
                return '-';
            }
            
            $location_data = json_decode($response, true);
            
            if ($location_data && isset($location_data['status']) && $location_data['status'] === 'success') {
                // 优先使用城市，如果没有则使用地区
                $city = $location_data['city'] ?? '';
                $regionName = $location_data['regionName'] ?? '';
                $country = $location_data['country'] ?? '';
                
                if (!empty($city)) {
                    return $city;
                } elseif (!empty($regionName)) {
                    return $regionName;
                } elseif (!empty($country)) {
                    return $country;
                }
            }
            
            return '-';
        } catch (\Exception $e) {
            return '-';
        }
    }
}

