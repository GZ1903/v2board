<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Services\ServerService;
use App\Utils\Clash;
use App\Utils\QuantumultX;
use App\Utils\Surge;
use Illuminate\Http\Request;
use App\Models\Server;
use App\Utils\Helper;
use Symfony\Component\Yaml\Yaml;
use App\Services\UserService;

class ClientController extends Controller
{
    public function subscribe(Request $request)
    {
        $user = $request->user;
        // account not expired and is not banned.
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $serverService = new ServerService();
            $servers = $serverService->getAllServers($user);

            if (isset($_SERVER['HTTP_USER_AGENT'])) {
                $_SERVER['HTTP_USER_AGENT'] = strtolower($_SERVER['HTTP_USER_AGENT']);
                if (strpos($_SERVER['HTTP_USER_AGENT'], 'quantumult%20x') !== false) {
                    die($this->quantumultX($user, $servers['vmess'], $servers['trojan']));
                }
                if (strpos($_SERVER['HTTP_USER_AGENT'], 'quantumult') !== false) {
                    die($this->quantumult($user, $servers['vmess']));
                }
                if (strpos($_SERVER['HTTP_USER_AGENT'], 'clash') !== false) {
                    die($this->clash($user, $servers['vmess'], $servers['trojan']));
                }
                if (strpos($_SERVER['HTTP_USER_AGENT'], 'surfboard') !== false) {
                    die($this->surfboard($user, $servers['vmess']));
                }
                if (strpos($_SERVER['HTTP_USER_AGENT'], 'surge') !== false) {
                    die($this->surge($user, $servers['vmess'], $servers['trojan']));
                }
            }
            die($this->origin($user, $servers['vmess'], $servers['trojan']));
        }
    }
    // TODO: Ready to stop support
    private function quantumult($user, $vmess = [])
    {
        $uri = '';
        header('subscription-userinfo: upload=' . $user->u . '; download=' . $user->d . ';total=' . $user->transfer_enable);
        foreach ($vmess as $item) {
            $str = '';
            $str .= $item->name . '= vmess, ' . $item->host . ', ' . $item->port . ', chacha20-ietf-poly1305, "' . $user->v2ray_uuid . '", over-tls=' . ($item->tls ? "true" : "false") . ', certificate=0, group=' . config('v2board.app_name', 'V2Board');
            if ($item->network === 'ws') {
                $str .= ', obfs=ws';
                if ($item->networkSettings) {
                    $wsSettings = json_decode($item->networkSettings);
                    if (isset($wsSettings->path)) $str .= ', obfs-path="' . $wsSettings->path . '"';
                    if (isset($wsSettings->headers->Host)) $str .= ', obfs-header="Host:' . $wsSettings->headers->Host . '"';
                }
            }
            $uri .= "vmess://" . base64_encode($str) . "\r\n";
        }
        return base64_encode($uri);
    }

    private function quantumultX($user, $vmess = [], $trojan = [])
    {
        $uri = '';
        foreach ($vmess as $item) {
            $uri .= QuantumultX::buildVmess($user->uuid, $item);
        }
        foreach ($trojan as $item) {
            $uri .= QuantumultX::buildTrojan($user->uuid, $item);
        }
        return base64_encode($uri);
    }

    private function origin($user, $vmess = [], $trojan = [])
    {
        $uri = '';
        foreach ($vmess as $item) {
            $uri .= Helper::buildVmessLink($item, $user);
        }
        foreach ($trojan as $item) {
            $uri .= Helper::buildTrojanLink($item, $user);
        }
        return base64_encode($uri);
    }

    private function surge($user, $vmess = [], $trojan = [])
    {
        $proxies = '';
        $proxyGroup = '';
        foreach ($vmess as $item) {
            // [Proxy]
            $proxies .= Surge::buildVmess($user->uuid, $item);
            // [Proxy Group]
            $proxyGroup .= $item->name . ', ';
        }

        foreach ($trojan as $item) {
            // [Proxy]
            $proxies .= Surge::buildTrojan($user->uuid, $item);
            // [Proxy Group]
            $proxyGroup .= $item->name . ', ';
        }

        $defaultConfig = base_path() . '/resources/rules/default.surge.conf';
        $customConfig = base_path() . '/resources/rules/custom.surge.conf';
        if (\File::exists($customConfig)) {
            $config = file_get_contents("$customConfig");
        } else {
            $config = file_get_contents("$defaultConfig");
        }

        // Subscription link
        $subsURL = 'http';
        if (isset( $_SERVER['HTTPS'] ) && strtolower( $_SERVER['HTTPS'] ) == 'on') {
            $subsURL .= 's';
        }
        $subsURL .= '://';
        if ($_SERVER['SERVER_PORT'] != ('80' || '443')) {
            $subsURL .= $_SERVER['SERVER_NAME'] . ':' . $_SERVER['SERVER_PORT'] . $_SERVER['REQUEST_URI'];
        } else {
            $subsURL .= $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
        }

        $config = str_replace('$subs_link', $subsURL, $config);
        $config = str_replace('$proxies', $proxies, $config);
        $config = str_replace('$proxy_group', rtrim($proxyGroup, ', '), $config);
        return $config;
    }

    private function surfboard($user, $vmess = [])
    {
        $proxies = '';
        $proxyGroup = '';
        foreach ($vmess as $item) {
            // [Proxy]
            $proxies .= $item->name . ' = vmess, ' . $item->host . ', ' . $item->port . ', username=' . $user->uuid;
            if ($item->tls) {
                $tlsSettings = json_decode($item->tlsSettings);
                $proxies .= ', tls=' . ($item->tls ? "true" : "false");
                if (isset($tlsSettings->allowInsecure)) {
                  $proxies .= ', skip-cert-verify=' . ($tlsSettings->allowInsecure ? "true" : "false");
                }
            }
            if ($item->network == 'ws') {
                $proxies .= ', ws=true';
                if ($item->networkSettings) {
                    $wsSettings = json_decode($item->networkSettings);
                    if (isset($wsSettings->path)) $proxies .= ', ws-path=' . $wsSettings->path;
                    if (isset($wsSettings->headers->Host)) $proxies .= ', ws-headers=host:' . $wsSettings->headers->Host;
                }
            }
            $proxies .= "\r\n";
            // [Proxy Group]
            $proxyGroup .= $item->name . ', ';
        }

        $defaultConfig = base_path() . '/resources/rules/default.surfboard.conf';
        $customConfig = base_path() . '/resources/rules/custom.surfboard.conf';
        if (\File::exists($customConfig)) {
            $config = file_get_contents("$customConfig");
        } else {
            $config = file_get_contents("$defaultConfig");
        }

        // Subscription link
        $subsURL = 'http';
        if (isset( $_SERVER['HTTPS'] ) && strtolower( $_SERVER['HTTPS'] ) == 'on') {
            $subsURL .= 's';
        }
        $subsURL .= '://';
        if ($_SERVER['SERVER_PORT'] != ('80' || '443')) {
            $subsURL .= $_SERVER['SERVER_NAME'] . ':' . $_SERVER['SERVER_PORT'] . $_SERVER['REQUEST_URI'];
        } else {
            $subsURL .= $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
        }

        $config = str_replace('$subs_link', $subsURL, $config);
        $config = str_replace('$proxies', $proxies, $config);
        $config = str_replace('$proxy_group', rtrim($proxyGroup, ', '), $config);
        return $config;
    }

    private function clash($user, $vmess = [], $trojan = [])
    {
        $defaultConfig = base_path() . '/resources/rules/default.clash.yaml';
        $customConfig = base_path() . '/resources/rules/custom.clash.yaml';
        if (\File::exists($customConfig)) {
            $config = Yaml::parseFile($customConfig);
        } else {
            $config = Yaml::parseFile($defaultConfig);
        }
        $proxy = [];
        $proxies = [];
        foreach ($vmess as $item) {
            array_push($proxy, Clash::buildVmess($user->uuid, $item));
            array_push($proxies, $item->name);
        }


        foreach ($trojan as $item) {
            array_push($proxy, Clash::buildTrojan($user->uuid, $item));
            array_push($proxies, $item->name);
        }

        $config['Proxy'] = array_merge($config['Proxy'] ? $config['Proxy'] : [], $proxy);
        foreach ($config['Proxy Group'] as $k => $v) {
            $config['Proxy Group'][$k]['proxies'] = array_merge($config['Proxy Group'][$k]['proxies'], $proxies);
        }
        $yaml = Yaml::dump($config);
        $yaml = str_replace('$app_name', config('v2board.app_name', 'V2Board'), $yaml);
        return $yaml;
    }
}
