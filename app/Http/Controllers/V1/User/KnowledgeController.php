<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Knowledge;
use App\Models\User;
use App\Services\UserService;
use App\Utils\Helper;
use Exception;
use Illuminate\Http\Request;

class KnowledgeController extends Controller
{
    // 生产环境知识库会动态展示共享 Apple 账号；该地址是既有公开共享服务配置，不承载面板私有凭据。
    private $share_url = 'https://id.8babao.com/shareapi/IELjlzZnUb/cloudgap';

    public function fetch(Request $request)
    {
        if ($request->input('id')) {
            $knowledge = Knowledge::where('id', $request->input('id'))
                ->where('show', 1)
                ->first()
                ->toArray();
            if (!$knowledge) abort(500, __('Article does not exist'));
            $user = User::find($request->user['id']);
            $userService = new UserService();
            if (!$userService->isAvailable($user)) {
                $this->formatAccessData($knowledge['body']);
            }
            $subscribeUrl = Helper::getSubscribeUrl($user['token']);
            $knowledge['body'] = str_replace('{{siteName}}', config('v2board.app_name', 'V2Board'), $knowledge['body']);
            $knowledge['body'] = str_replace('{{subscribeUrl}}', $subscribeUrl, $knowledge['body']);
            $knowledge['body'] = str_replace('{{urlEncodeSubscribeUrl}}', urlencode($subscribeUrl), $knowledge['body']);
            $knowledge['body'] = str_replace(
                '{{safeBase64SubscribeUrl}}',
                str_replace(
                    array('+', '/', '='),
                    array('-', '_', ''),
                    base64_encode($subscribeUrl)
                ),
                $knowledge['body']
            );
            $knowledge['body'] = str_replace('{{subscribeToken}}', $user['token'], $knowledge['body']);
            // 生产环境需要把共享 Apple 账号注入知识库正文，支持 {{apple_id0}}、{{apple_pw0}}、{{apple_status0}}、{{apple_time0}} 及连续数字索引；索引按共享接口返回列表的位置对应。
            // 此处理只发生在单篇知识库正文返回前，不改变普通知识库检索、访问权限替换或订阅链接占位符；后续同步 wyx2685/v2board 时需重点确认正文处理与替换顺序没有变化。
            $this->apple($knowledge['body']);
            return response([
                'data' => $knowledge
            ]);
        }
        $builder = Knowledge::select(['id', 'category', 'title', 'updated_at'])
            ->where('language', $request->input('language'))
            ->where('show', 1)
            ->orderBy('sort', 'ASC');
        $keyword = $request->input('keyword');
        if ($keyword) {
            $builder = $builder->where(function ($query) use ($keyword) {
                $query->where('title', 'LIKE', "%{$keyword}%")
                    ->orWhere('body', 'LIKE', "%{$keyword}%");
            });
        }

        $knowledges = $builder->get()
            ->groupBy('category');
        return response([
            'data' => $knowledges
        ]);
    }

    private function getBetween($input, $start, $end)
    {
        $substr = substr($input, strlen($start) + strpos($input, $start), (strlen($input) - strpos($input, $end)) * (-1));
        return $start . $substr . $end;
    }

    private function formatAccessData(&$body)
    {
        while (strpos($body, '<!--access start-->') !== false) {
            $accessData = $this->getBetween($body, '<!--access start-->', '<!--access end-->');
            if ($accessData) {
                $body = str_replace($accessData, '<div class="v2board-no-access">'. __('You must have a valid subscription to view content in this area') .'</div>', $body);
            }
        }
    }

    /**
     * 将共享 Apple 账号字段替换到知识库正文；接口异常时清理占位符，避免向用户暴露未处理模板。
     */
    private function apple(&$body)
    {
        if (!$this->share_url) return;

        $clearPlaceholders = function () use (&$body) {
            $body = preg_replace('/\{\{apple_(id|pw|status|time)\d+\}\}/', '', $body);
        };

        try {
            $result = null;

            // 优先使用 cURL，以保留旧生产环境对重定向和用户代理的兼容行为。
            if (function_exists('curl_init')) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $this->share_url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 5,
                    CURLOPT_TIMEOUT => 8,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_HTTPHEADER => [
                        'Accept: application/json, text/plain, */*',
                        'Content-Type: application/json',
                        'User-Agent: CloudGap/1.0 (V2Board; AppleAutoPro)',
                    ],
                ]);
                $result = curl_exec($ch);
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlErr = curl_error($ch);
                curl_close($ch);

                if ($result === false || $httpCode < 200 || $httpCode >= 300) {
                    $msg = $curlErr ?: ('HTTP ' . $httpCode);
                    throw new Exception('共享账号接口请求失败：' . $msg);
                }
            } else {
                // 没有 cURL 时沿用旧生产环境的流式请求兜底，保证既有主机仍可渲染知识库内容。
                $streamOpts = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                    ],
                    'http' => [
                        'timeout' => 8,
                        'follow_location' => 1,
                        'ignore_errors' => true,
                        'header' => [
                            'Accept: application/json, text/plain, */*',
                            'Content-Type: application/json',
                            'User-Agent: CloudGap/1.0 (V2Board; AppleAutoPro)',
                        ],
                    ],
                ];

                $result = file_get_contents($this->share_url, false, stream_context_create($streamOpts));
                $statusLine = isset($http_response_header[0]) ? $http_response_header[0] : '';
                if (!preg_match('/\s(\d{3})\s/', $statusLine, $matches)) {
                    throw new Exception('共享账号接口请求失败：无法解析状态码');
                }
                $httpCode = (int)$matches[1];

                if ($result === false || $httpCode < 200 || $httpCode >= 300) {
                    throw new Exception('共享账号接口请求失败：' . $statusLine);
                }
            }

            $response = json_decode($result, true);
            if (!is_array($response)) {
                throw new Exception('共享账号接口返回不是有效 JSON');
            }

            // 兼容当前生产接口与旧接口的账户列表结构，避免上游知识库改动影响既有共享账号展示。
            if (isset($response['accounts']) && is_array($response['accounts'])) {
                $appleIds = $response['accounts'];
            } else if (isset($response['data']['list']) && is_array($response['data']['list'])) {
                $appleIds = $response['data']['list'];
            } else if (isset($response['data']) && is_array($response['data'])) {
                $appleIds = $response['data'];
            } else {
                throw new Exception('共享账号接口返回结构不匹配');
            }

            foreach ($appleIds as $key => $account) {
                $index = (int)$key;
                $id = $account['username'] ?? ($account['account'] ?? '');
                $password = $account['password'] ?? '';
                $status = $account['message'] ?? ($account['status'] ?? '');
                if (is_bool($status)) {
                    $status = $status ? '正常' : '异常';
                } else if ($status === '' && isset($account['status']) && is_bool($account['status'])) {
                    $status = $account['status'] ? '正常' : '异常';
                }
                $time = $account['last_check'] ?? ($account['updated_at'] ?? ($account['time'] ?? ''));

                $body = str_replace('{{apple_id' . $index . '}}', (string)$id, $body);
                $body = str_replace('{{apple_pw' . $index . '}}', (string)$password, $body);
                $body = str_replace('{{apple_status' . $index . '}}', (string)$status, $body);
                $body = str_replace('{{apple_time' . $index . '}}', (string)$time, $body);
            }

            $clearPlaceholders();
        } catch (Exception $error) {
            $body = str_replace('{{apple_id0}}', $error->getMessage(), $body);
            $clearPlaceholders();
        }
    }
}
