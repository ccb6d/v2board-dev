<?php

namespace App\Plugins\Telegram\Commands;

use App\Plugins\Telegram\Telegram;
use Illuminate\Support\Facades\Cache;
use App\Utils\CacheKey;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\V1\Passport\OAuthController;
use Illuminate\Http\Request;

class Login extends Telegram {
    public $command = '/login';
    public $description = '使用哈希值登录或注册网站';

    public function handle($message, $match = []) {
        // 确保是私聊消息
        if (!$message->is_private) return;
        
        // 检查是否提供了哈希值参数
        if (!isset($message->args[0])) {
            $this->sendReply($message, "请提供登录哈希值，格式：/login <哈希值>");
            return;
        }
        
        $hash = $message->args[0];
        $tgId = $message->chat_id;
        
        // 检查用户是否已绑定 Telegram ID
        $user = User::where('telegram_id', $tgId)->first();
        
        if ($user) {
            // 用户已绑定 Telegram ID，这是登录操作
            $this->handleLogin($message, $hash, $user);
        } else {
            // 用户未绑定 Telegram ID，这是注册操作
            $this->handleRegistration($message, $hash, $tgId);
        }
    }
    
    private function handleLogin($message, $hash, $user) {
        // 构造请求数据
        $requestData = [
            'id' => $message->chat_id,
            'hash' => $hash,
            'first_name' => $message->first_name ?? 'Telegram User',
            'message' => $message->text
        ];
        
        // 直接调用 OAuthController 的 handleTelegramBotCallback 方法
        try {
            // 创建一个模拟的 Request 对象
            $request = new Request();
            $request->setMethod('POST');
            $request->request->add($requestData);
            
            // 创建 OAuthController 实例并调用 handleTelegramBotCallback
            $oauthController = new OAuthController();
            $response = $oauthController->handleTelegramBotCallback($request);
            
            // 解析响应
            $responseData = json_decode($response->getContent(), true);
            
            if (isset($responseData['data']) && isset($responseData['data']['token'])) {
                // 登录成功
                $this->sendReply($message, "✅ 登录成功！

您已成功登录到网站。
用户邮箱: {$user->email}");
            } else if (isset($responseData['error'])) {
                // 登录失败
                $this->sendReply($message, "❌ 登录失败: " . $responseData['error']);
            } else {
                // 未知响应格式
                $this->sendReply($message, "❌ 登录过程中发生未知错误，请稍后重试。");
            }
        } catch (\Exception $e) {
            Log::error("Telegram login request failed: " . $e->getMessage());
            $this->sendReply($message, "❌ 处理登录请求时发生错误，请稍后重试。");
        }
    }
    
    private function handleRegistration($message, $hash, $tgId) {
        // 构造请求数据
        $requestData = [
            'id' => $tgId,
            'hash' => $hash,
            'first_name' => $message->first_name ?? 'Telegram User',
            'message' => $message->text
        ];
        
        // 直接调用 OAuthController 的 handleTelegramBotCallback 方法
        try {
            // 创建一个模拟的 Request 对象
            $request = new Request();
            $request->setMethod('POST');
            $request->request->add($requestData);
            
            // 创建 OAuthController 实例并调用 handleTelegramBotCallback
            $oauthController = new OAuthController();
            $response = $oauthController->handleTelegramBotCallback($request);
            
            // 解析响应
            $responseData = json_decode($response->getContent(), true);
            
            if (isset($responseData['data']) && isset($responseData['data']['token'])) {
                // 注册并登录成功
                $token = $responseData['data']['token'];
                
                // 获取用户信息
                $user = User::where('telegram_id', $tgId)->first();
                if ($user) {
                    // 发送账户信息给用户
                    $this->sendReply($message, "✅ 注册成功！

欢迎使用我们的服务！
您的账户信息：
📧 邮箱: {$user->email}
🔑 密码: （系统生成的随机密码）

请妥善保管您的账户信息，您已成功登录到网站。");
                } else {
                    $this->sendReply($message, "✅ 注册成功！

您已成功登录到网站。");
                }
            } else if (isset($responseData['error'])) {
                // 注册失败
                $this->sendReply($message, "❌ 注册失败: " . $responseData['error']);
            } else {
                // 未知响应格式
                $this->sendReply($message, "❌ 注册过程中发生未知错误，请稍后重试。");
            }
        } catch (\Exception $e) {
            Log::error("Telegram registration request failed: " . $e->getMessage());
            $this->sendReply($message, "❌ 处理注册请求时发生错误，请稍后重试。");
        }
    }
    
    private function sendReply($message, $text) {
        try {
            $telegramService = $this->telegramService;
            $telegramService->sendMessage($message->chat_id, $text);
        } catch (\Exception $e) {
            Log::error("Failed to send Telegram message: " . $e->getMessage());
        }
    }
}