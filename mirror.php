#!/usr/bin/env php
<?php

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Lock\Factory;
use Symfony\Component\Lock\Store\FlockStore;

ini_set('memory_limit', '3G');
ini_set('display_errors', true);
ini_set('error_reporting', -1);
ini_set('date.timezone', 'UTC');

if (!extension_loaded('zlib')) {
    echo 'This requires the zlib/zip extension to be loaded';
    exit(1);
}

if (!extension_loaded('curl')) {
    echo 'This requires the curl extension to be loaded';
    exit(1);
}

require __DIR__ . '/vendor/autoload.php';

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    throw new \ErrorException($errstr, $errno, E_ERROR, $errfile, $errline);
});

class Mirror {
    private $target;
    private $context;
    private $userAgent;
    private $verbose;
    private $statsdSocket;
    private $client;
    private $url;
    private $apiUrl;
    private $hostname;
    private $syncRootOnV2;
    private $downloaded = 0;

    public function __construct(array $config)
    {
        $this->target = $config['target_dir'];
        $this->url = $config['repo_url'] ?? 'repo.packagist.org';
        $this->apiUrl = $config['api_url'] ?? 'packagist.org';
        $this->hostname = $config['repo_hostname'] ?? parse_url($this->url, PHP_URL_HOST);
        $this->userAgent = $config['user_agent'];
        $this->syncRootOnV2 = !($config['has_v1_mirror'] ?? true);

        if (isset($config['statsd']) && is_array($config['statsd'])) {
            $this->statsdConnect($config['statsd'][0], $config['statsd'][1]);
        }

        $this->verbose = in_array('-v', $_SERVER['argv']);
    }

    public function syncRootOnV2()
    {
        if (!$this->syncRootOnV2) {
            return;
        }

        $this->initClient();

        $rootResp = $this->download('/packages.json');
        if ($rootResp->getHeaders()['content-encoding'][0] !== 'gzip') {
            throw new \Exception('Expected gzip encoded responses, something is off');
        }
        $rootData = $rootResp->getContent();
        $hash = hash('sha256', $rootData);

        if ($hash === $this->getHash('/packages.json')) {
            return;
        }

        $gzipped = gzencode($rootData, 8);
        $this->write('/packages.json', $rootData, $gzipped, strtotime($rootResp->getHeaders()['last-modified'][0]));
        $this->output('R');
    }

    public function syncV2()
    {
        $this->initClient();

        $this->statsdIncrement('mirror.run');
        $this->downloaded = 0;

        $timestampStore = './last_metadata_timestamp';
        if (!file_exists($timestampStore)) {
            $resp = $this->client->request('GET', $this->apiUrl.'/metadata/changes.json', ['headers' => ['Host' => parse_url($this->apiUrl, PHP_URL_HOST)]]);
            $content = json_decode($resp->getContent(false), true);
            if ($resp->getStatusCode() === 400 && null !== $content) {
                return $this->resync($content['timestamp']);
            }
            throw new \Exception('Failed to fetch timestamp from API, got invalid response '.$resp->getStatusCode().': '.$resp->getContent());
        }
        $lastTime = trim(file_get_contents($timestampStore));

        $changesResp = $this->client->request('GET', $this->apiUrl.'/metadata/changes.json?since='.$lastTime, ['headers' => ['Host' => parse_url($this->apiUrl, PHP_URL_HOST)]]);
        if ($changesResp->getHeaders()['content-encoding'][0] !== 'gzip') {
            throw new \Exception('Expected gzip encoded responses, something is off');
        }
        $changes = json_decode($changesResp->getContent(), true);

        if ([] === $changes['actions']) {
            $this->output('No work' . PHP_EOL);
            file_put_contents($timestampStore, $changes['timestamp']);
            return true;
        }

        if ($changes['actions'][0]['type'] === 'resync') {
            return $this->resync($changes['timestamp']);
        }

        $requests = [];
        foreach ($changes['actions'] as $action) {
            if ($action['type'] === 'update') {
                // package here can be foo/bar or foo/bar~dev, not strictly a package name
                $pkg = $action['package'];
                $provPathV2 = '/p2/'.$pkg.'.json';
                $headers = file_exists($this->target.$provPathV2) ? ['If-Modified-Since' => gmdate('D, d M Y H:i:s T', filemtime($this->target.$provPathV2))] : [];
                $userData = ['path' => $provPathV2, 'minimumFilemtime' => $action['time'], 'retries' => 0];
                $requests[] = ['GET', $this->url.$provPathV2, ['user_data' => $userData, 'headers' => $headers]];
            } elseif ($action['type'] === 'delete') {
                $this->delete($action['package']);
            }
        }

        $result = $this->downloadV2Files($requests);
        if (!$result) {
            return false;
        }

        $this->output(PHP_EOL);
        $this->output('Downloaded '.$this->downloaded.' files'.PHP_EOL);
        file_put_contents($timestampStore, $changes['timestamp']);

        return true;
    }

    private function resync(int $timestamp)
    {
        $this->output('Resync requested'.PHP_EOL);

        $listingResp = $this->client->request('GET', $this->apiUrl.'/packages/list.json', ['headers' => ['Host' => parse_url($this->apiUrl, PHP_URL_HOST)]]);
        if ($listingResp->getHeaders()['content-encoding'][0] !== 'gzip') {
            throw new \Exception('Expected gzip encoded responses, something is off');
        }
        $list = json_decode($listingResp->getContent(), true);

        // clean up existing files in case we still have outdated packages
        if (is_dir($this->target.'/p2')) {
            $finder = Finder::create()->directories()->ignoreVCS(true)->in($this->target.'/p2');
            $names = array_flip($list['packageNames']);

            foreach ($finder as $vendorDir) {
                foreach (glob(((string) $vendorDir).'/*.json') as $file) {
                    if (!preg_match('{/([^/]+/[^/]+?)(~dev)?\.json$}', strtr($file, '\\', '/'), $match)) {
                        throw new \LogicException('Could not match package name from '.$path);
                    }

                    if (!isset($names[$match[1]])) {
                        unlink((string) $file);
                        if (file_exists(((string) $file).'.gz')) {
                            unlink(((string) $file).'.gz');
                        }
                    }
                }
            }
        }

        // download all package data
        $requests = [];
        foreach ($list['packageNames'] as $pkg) {
            $provPathV2 = '/p2/'.$pkg.'.json';
            $headers = file_exists($this->target.$provPathV2) ? ['If-Modified-Since' => gmdate('D, d M Y H:i:s T', filemtime($this->target.$provPathV2))] : [];
            $userData = ['path' => $provPathV2, 'minimumFilemtime' => 0, 'retries' => 0];
            $requests[] = ['GET', $this->url.$provPathV2, ['user_data' => $userData, 'headers' => $headers]];

            $provPathV2Dev = '/p2/'.$pkg.'~dev.json';
            $headers = file_exists($this->target.$provPathV2Dev) ? ['If-Modified-Since' => gmdate('D, d M Y H:i:s T', filemtime($this->target.$provPathV2Dev))] : [];
            $userData = ['path' => $provPathV2Dev, 'minimumFilemtime' => 0, 'retries' => 0];
            $requests[] = ['GET', $this->url.$provPathV2Dev, ['user_data' => $userData, 'headers' => $headers]];
        }

        $result = $this->downloadV2Files($requests);
        if (!$result) {
            return false;
        }

        $this->output(PHP_EOL);
        $this->output('Downloaded '.$this->downloaded.' files'.PHP_EOL);

        $timestampStore = './last_metadata_timestamp';
        file_put_contents($timestampStore, $timestamp);

        return true;
    }

    private function downloadV2Files(array $requests)
    {
        $hasFailedRequests = false;
        $hasRetries = false;

        $handleRetry = function ($response, array $userData) use (&$hasRetries, &$requests): bool {
            $mtime = strtotime($response->getHeaders(false)['last-modified'][0]);
            // got an outdated file, possibly fetched from a mirror which was not yet up to date, so retry after 2sec
            if ($mtime < $userData['minimumFilemtime']) {
                if ($userData['retries'] > 2) {
                    throw new \Exception('Too many retries, could not update '.$userData['path'].' as the origin server returns an older file ('.$mtime.', expected '.$userData['minimumFilemtime'].')');
                }
                $hasRetries = true;
                $this->output('R');
                $this->statsdIncrement('mirror.retry_provider_v2');
                $userData['retries']++;
                $headers = file_exists($this->target.$userData['path']) ? ['If-Modified-Since' => gmdate('D, d M Y H:i:s T', filemtime($this->target.$userData['path']))] : [];
                $requests[] = ['GET', $this->url.$userData['path'], ['user_data' => $userData, 'headers' => $headers]];

                return true;
            }

            return false;
        };

        while ($requests && !$hasFailedRequests) {
            if ($hasRetries) {
                sleep(2);
                $hasRetries = false;
            }

            $responses = [];
            foreach (array_splice($requests, 0, 200) as $req) {
                $responses[] = $this->client->request(...$req);
            }

            foreach ($this->client->stream($responses) as $response => $chunk) {
                try {
                    if ($chunk->isFirst()) {
                        if ($response->getStatusCode() === 304) {
                            $response->cancel();
                            $this->downloaded++;

                            $userData = $response->getInfo('user_data');
                            if ($handleRetry($response, $userData)) {
                                continue;
                            }

                            $this->output('-');
                            $this->statsdIncrement('mirror.not_modified');
                            continue;
                        }

                        if ($response->getStatusCode() === 404) {
                            // ignore 404s for all v2 files as the package might have been deleted already
                            $this->output('?');
                            $this->statsdIncrement('mirror.not_found');
                            $response->cancel();
                            $this->downloaded++;
                            continue;
                        }
                    }

                    if ($chunk->isLast()) {
                        $this->downloaded++;
                        $userData = $response->getInfo('user_data');

                        $metadata = $response->getContent();
                        if (null === json_decode($metadata, true)) {
                            throw new \Exception('Invalid JSON received for file '.$userData['path']);
                        }

                        if ($handleRetry($response, $userData)) {
                            continue;
                        }

                        $mtime = strtotime($response->getHeaders()['last-modified'][0]);
                        $gzipped = gzencode($metadata, 7);
                        $this->write($userData['path'], $metadata, $gzipped, $mtime);
                        $this->output('M');
                        $this->statsdIncrement('mirror.sync_provider_v2');
                    }
                } catch (\Throwable $e) {
                    // abort all responses to avoid triggering any other exception then throw
                    array_map(function ($r) { $r->cancel(); }, $responses);

                    $this->statsdIncrement('mirror.provider_failure');
                    $this->downloaded++;
                    var_dump(date('Y-m-d H:i:s').' '.get_class($e).' '.$e->getMessage());

                    $hasFailedRequests = true;
                }
            }
        }

        return !$hasFailedRequests;
    }

    public function sync()
    {
        $this->initClient();

        $this->statsdIncrement('mirror.run');
        $this->downloaded = 0;

        $rootResp = $this->download('/packages.json');
        if ($rootResp->getHeaders()['content-encoding'][0] !== 'gzip') {
            throw new \Exception('Expected gzip encoded responses, something is off');
        }
        $rootData = $rootResp->getContent();
        $hash = hash('sha256', $rootData);

        if ($hash === $this->getHash('/packages.json')) {
            $this->output('No work' . PHP_EOL);
            return true;
        }

        $rootJson = json_decode($rootData, true);
        if (null === $rootJson) {
            throw new \Exception('Invalid JSON received for file /packages.json: '.$rootData);
        }

        $requests = [];
        $listingsToWrite = [];

        foreach ($rootJson['provider-includes'] as $listing => $opts) {
            $listing = str_replace('%hash%', $opts['sha256'], $listing);
            if (file_exists($this->target.'/'.$listing)) {
                continue;
            }

            $listingResp = $this->download('/'.$listing);

            $listingData = $listingResp->getContent();
            if (hash('sha256', $listingData) !== $opts['sha256']) {
                throw new \Exception('Invalid hash received for file /'.$listing);
            }

            $listingJson = json_decode($listingData, true);
            if (null === $listingJson) {
                throw new \Exception('Invalid JSON received for file /'.$listing.': '.$listingData);
            }

            foreach ($listingJson['providers'] as $pkg => $opts) {
                $provPath = '/p/'.$pkg.'$'.$opts['sha256'].'.json';
                $provAltPath = '/p/'.$pkg.'.json';

                if (file_exists($this->target.$provPath)) {
                    continue;
                }

                $userData = [$provPath, $provAltPath, $opts['sha256']];
                $requests[] = ['GET', $this->url.$provPath, ['user_data' => $userData]];
            }

            $listingsToWrite['/'.$listing] = [$listingData, strtotime($listingResp->getHeaders()['last-modified'][0])];
        }

        $hasFailedRequests = false;

        while ($requests && !$hasFailedRequests) {
            $responses = [];
            foreach (array_splice($requests, 0, 200) as $req) {
                $responses[] = $this->client->request(...$req);
            }

            foreach ($this->client->stream($responses) as $response => $chunk) {
                try {
                    if ($chunk->isFirst()) {
                        if ($response->getStatusCode() === 304) {
                            $this->output('-');
                            $this->statsdIncrement('mirror.not_modified');
                            $response->cancel();
                            $this->downloaded++;
                            continue;
                        }
                    }

                    if ($chunk->isLast()) {
                        $this->downloaded++;
                        $userData = $response->getInfo('user_data');

                        // provider v1
                        $providerData = $response->getContent();
                        if (null === json_decode($providerData, true)) {
                            throw new \Exception('Invalid JSON received for file '.$userData[0]);
                        }
                        if (hash('sha256', $providerData) !== $userData[2]) {
                            throw new \Exception('Invalid hash received for file '.$userData[0]);
                        }

                        $mtime = strtotime($response->getHeaders()['last-modified'][0]);
                        $gzipped = gzencode($providerData, 7);
                        $this->write($userData[0], $providerData, $gzipped, $mtime);
                        $this->write($userData[1], $providerData, $gzipped, $mtime);
                        $this->output('P');
                        $this->statsdIncrement('mirror.sync_provider');
                    }
                } catch (\Throwable $e) {
                    // abort all responses to avoid triggering any other exception then throw
                    array_map(function ($r) { $r->cancel(); }, $responses);

                    $this->statsdIncrement('mirror.provider_failure');
                    $this->downloaded++;
                    var_dump(date('Y-m-d H:i:s').' '.get_class($e).' '.$e->getMessage());

                    $hasFailedRequests = true;
                }
            }
        }

        if ($hasFailedRequests) {
            return false;
        }

        foreach ($listingsToWrite as $listing => $listingData) {
            $gzipped = gzencode($listingData[0], 8);
            $this->write($listing, $listingData[0], $gzipped, $listingData[1]);
            $this->output('L');
            $this->statsdIncrement('mirror.sync_listing');
        }

        $gzipped = gzencode($rootData, 8);
        $this->write('/packages.json', $rootData, $gzipped, strtotime($rootResp->getHeaders()['last-modified'][0]));
        $this->output('R');
        $this->statsdIncrement('mirror.sync_root');

        $this->output(PHP_EOL);
        $this->output('Downloaded '.$this->downloaded.' files'.PHP_EOL);

        return true;
    }

    public function gc()
    {
        // build up array of safe files
        $safeFiles = [];

        $rootFile = $this->target.'/packages.json';
        if (!file_exists($rootFile)) {
            return;
        }
        $rootJson = json_decode(file_get_contents($rootFile), true);

        foreach ($rootJson['provider-includes'] as $listing => $opts) {
            $listing = str_replace('%hash%', $opts['sha256'], $listing);
            $safeFiles['/'.$listing] = true;

            $listingJson = json_decode(file_get_contents($this->target.'/'.$listing), true);
            foreach ($listingJson['providers'] as $pkg => $opts) {
                $provPath = '/p/'.$pkg.'$'.$opts['sha256'].'.json';
                $safeFiles[$provPath] = true;
            }
        }

        $this->cleanOldFiles($safeFiles);
    }

    private function cleanOldFiles(array $safeFiles)
    {
        $finder = Finder::create()->directories()->ignoreVCS(true)->in($this->target.'/p');
        foreach ($finder as $vendorDir) {
            $vendorFiles = Finder::create()->files()->ignoreVCS(true)
                ->name('/\$[a-f0-9]+\.json$/')
                ->date('until 10minutes ago')
                ->in((string) $vendorDir);

            foreach ($vendorFiles as $file) {
                $key = strtr(str_replace($this->target, '', $file), '\\', '/');
                if (!isset($safeFiles[$key])) {
                    unlink((string) $file);
                    if (file_exists(((string) $file).'.gz')) {
                        unlink(((string) $file).'.gz');
                    }
                }
            }
        }

        // clean up old provider listings
        $finder = Finder::create()->depth(0)->files()->name('provider-*.json')->ignoreVCS(true)->in($this->target.'/p')->date('until 10minutes ago');
        foreach ($finder as $provider) {
            $key = strtr(str_replace($this->target, '', $provider), '\\', '/');
            if (!isset($safeFiles[$key])) {
                unlink((string) $provider);
                if (file_exists(((string) $provider).'.gz')) {
                    unlink(((string) $provider).'.gz');
                }
            }
        }
    }

    private function output($str)
    {
        if ($this->verbose) {
            echo $str;
        }
    }

    private function getHash($file)
    {
        if (file_exists($this->target.$file)) {
            return hash_file('sha256', $this->target.$file);
        }
    }

    private function download($file)
    {
        $this->downloaded++;

        try {
            $resp = $this->client->request('GET', $this->url.$file);
            // trigger throws if needed
            $resp->getContent();
        } catch (TransportException $e) {
            // retry once
            usleep(10000);
            $resp = $this->client->request('GET', $this->url.$file);
            // trigger throws if needed
            $resp->getContent();
        }

        if ($resp->getStatusCode() >= 300) {
            throw new \RuntimeException('Failed to fetch '.$file.' => '.$response->getStatusCode() .' '. $response->getContent());
        }

        return $resp;
    }

    private function write($file, $content, $gzipped, $mtime)
    {
        $path = $this->target.$file;

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path.'.tmp', $content);
        file_put_contents($path.'.gz.tmp', $gzipped);
        touch($path.'.tmp', $mtime);
        touch($path.'.gz.tmp', $mtime);
        rename($path.'.tmp', $path);
        rename($path.'.gz.tmp', $path.'.gz');
    }

    private function delete($packageName)
    {
        $this->output('D');
        $files = [
            $this->target.'/p2/'.$packageName.'.json',
            $this->target.'/p2/'.$packageName.'.json.gz',
            $this->target.'/p2/'.$packageName.'~dev.json',
            $this->target.'/p2/'.$packageName.'~dev.json.gz',
        ];
        foreach ($files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    public function statsdIncrement($metric)
    {
        if ($this->statsdSocket) {
            $message = $metric.':1|c';
            @fwrite($this->statsdSocket, $message);
        }
    }

    private function statsdConnect(string $ip, int $port)
    {
        $socket = @fsockopen('udp://' . $ip, $port, $errno, $errstr, 1);
        if ($socket === false) {
            trigger_error(
                'StatsD server connection failed (' . $errno . ') ' . $errstr,
                E_USER_WARNING
            );
            return;
        }

        stream_set_timeout($socket, 1);

        $this->statsdSocket = $socket;
    }

    private function initClient()
    {
        if ($this->client) {
            return;
        }

        $this->client = HttpClient::create([
            'headers' => [
                'User-Agent' => $this->userAgent,
                'Host' => $this->hostname,
            ],
            'timeout' => 10,
            'max_duration' => 15,
            'http_version' => '2.0',
        ]);
    }
}

$lockName = 'mirror';
$config = require __DIR__ . '/mirror.config.php';
$isGC = false;
$isV2 = false;

if (in_array('--gc', $_SERVER['argv'])) {
    $lockName .= '-gc';
    $isGC = true;
} elseif (in_array('--v2', $_SERVER['argv'])) {
    $lockName .= '-v2';
    $isV2 = true;
} elseif (in_array('--v1', $_SERVER['argv'])) {
    // default mode
} else {
    throw new \RuntimeException('Missing one of --gc, --v1 or --v2 modes');
}

$lockFactory = new Factory(new FlockStore(sys_get_temp_dir()));
$lock = $lockFactory->createLock($lockName);

if (!$lock->acquire()) {
    exit(0);
}

$mirror = new Mirror($config);
if ($isGC) {
    $mirror->gc();
    $lock->release();
    exit(0);
}

try {
    $iterations = $config['iterations'];
    if ($isV2) {
        // sync root only once per script run as on a v2 only repo it rarely changes
        $mirror->syncRootOnV2();
    }

    while ($iterations--) {
        if ($isV2) {
            if (!$mirror->syncV2()) {
                $lock->release();
                exit(1);
            }
        } else {
            if (!$mirror->sync()) {
                $lock->release();
                exit(1);
            }
        }
        sleep($config['iteration_interval']);
    }
} catch (\Throwable $e) {
    // sleep so supervisor assumes a correct start and we avoid restarting too quickly, then rethrow
    $mirror->statsdIncrement('mirror.hard_failure');
    sleep(3);
    echo 'Mirror '.($isV2 ? 'v2' : '').' job failed at '.date('Y-m-d H:i:s');
    throw $e;
} finally {
    $lock->release();
}

exit(0);
