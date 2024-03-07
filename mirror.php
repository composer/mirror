#!/usr/bin/env php
<?php

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Lock\LockFactory;
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
    private string $target;
    private string $userAgent;
    private bool $verbose;
    /**
     * @var open-resource|null
     */
    private mixed $statsdSocket = null;
    private HttpClientInterface $client;
    private string $url;
    private string $apiUrl;
    private string $hostname;
    private bool $gzipOnly;
    private bool $syncRootOnV2;
    private int $downloaded = 0;

    /**
     * @param array<mixed> $config
     */
    public function __construct(array $config)
    {
        $this->target = $config['target_dir'];
        $this->url = $config['repo_url'] ?? 'repo.packagist.org';
        $this->apiUrl = $config['api_url'] ?? 'packagist.org';
        $this->hostname = $config['repo_hostname'] ?? parse_url($this->url, PHP_URL_HOST);
        $this->userAgent = $config['user_agent'];
        $this->syncRootOnV2 = !($config['has_v1_mirror'] ?? true);
        $this->gzipOnly = $config['gzip_only'] ?? false;

        if (isset($config['statsd']) && is_array($config['statsd'])) {
            $this->statsdConnect($config['statsd'][0], $config['statsd'][1]);
        }

        $this->verbose = in_array('-v', $_SERVER['argv']);
        if ($this->verbose) {
            ini_set('display_errors', 1);
        }
    }

    public function syncRootOnV2(): void
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

        $gzipped = $this->gzencode($rootData, 8);
        $this->write('/packages.json', $rootData, $gzipped, $this->strtotime($rootResp->getHeaders()['last-modified'][0]));
        $this->output('X');

        $this->statsdIncrement('mirror.sync_root');
    }

    public function getV2Timestamp(): int
    {
        $this->initClient();

        $resp = $this->client->request('GET', $this->apiUrl.'/metadata/changes.json', ['headers' => ['Host' => parse_url($this->apiUrl, PHP_URL_HOST)]]);
        $content = json_decode($resp->getContent(false), true);
        if ($resp->getStatusCode() === 400 && null !== $content) {
            return $content['timestamp'];
        }
        throw new \Exception('Failed to fetch timestamp from API, got invalid response '.$resp->getStatusCode().': '.$resp->getContent());
    }

    public function syncV2(): bool
    {
        $this->initClient();

        $this->statsdIncrement('mirror.run');
        $this->downloaded = 0;

        if (!file_exists($this->getTimestampStorePath())) {
            if (!is_writable((string) getcwd())) {
                throw new \UnexpectedValueException('Cannot save last timestamp to last_metadata_timestamp in '.getcwd().'. Make sure the directory is writable.');
            }

            return $this->resync($this->getV2Timestamp());
        } elseif (!is_writable($this->getTimestampStorePath())) {
            throw new \UnexpectedValueException('Cannot save last timestamp to last_metadata_timestamp in '.getcwd().'. Make sure the file is writable.');
        }
        $lastTime = trim((string) file_get_contents($this->getTimestampStorePath()));
        if ($lastTime === '') {
            throw new \RuntimeException('Failed loading the last timestamp from '.$this->getTimestampStorePath());
        }

        $changesResp = $this->client->request('GET', $this->apiUrl.'/metadata/changes.json?since='.$lastTime, ['headers' => ['Host' => parse_url($this->apiUrl, PHP_URL_HOST)]]);
        if ($changesResp->getHeaders()['content-encoding'][0] !== 'gzip') {
            throw new \Exception('Expected gzip encoded responses, something is off');
        }
        $changes = json_decode($changesResp->getContent(), true);

        if ([] === $changes['actions']) {
            $this->output('No work' . PHP_EOL);
            $this->writeLastTimestamp($changes['timestamp']);
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
                $headers = file_exists($this->target.$provPathV2.'.gz') ? ['If-Modified-Since' => gmdate('D, d M Y H:i:s T', (int) filemtime($this->target.$provPathV2.'.gz'))] : [];
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
        $this->writeLastTimestamp($changes['timestamp']);

        return true;
    }

    public function resync(int $timestamp): bool
    {
        $this->output('Resync requested'.PHP_EOL);

        $listingResp = $this->client->request('GET', $this->apiUrl.'/packages/list.json?'.md5(uniqid()), ['headers' => ['Host' => parse_url($this->apiUrl, PHP_URL_HOST)]]);
        if ($listingResp->getHeaders()['content-encoding'][0] !== 'gzip') {
            throw new \Exception('Expected gzip encoded responses, something is off');
        }
        $list = json_decode($listingResp->getContent(), true);

        // clean up existing files in case we still have outdated packages
        if (is_dir($this->target.'/p2')) {
            $finder = Finder::create()->directories()->ignoreVCS(true)->in($this->target.'/p2');
            $names = array_flip($list['packageNames']);

            foreach ($finder as $vendorDir) {
                $globMatches = glob(((string) $vendorDir).'/*.json.gz');
                if (!is_array($globMatches)) {
                    throw new \RuntimeException('Failed globbing '.$vendorDir.'/*.json.gz');
                }
                foreach ($globMatches as $file) {
                    if (!preg_match('{/([^/]+/[^/]+?)(~dev)?\.json.gz$}', strtr($file, '\\', '/'), $match)) {
                        throw new \LogicException('Could not match package name from '.$file);
                    }

                    if (!isset($names[$match[1]])) {
                        unlink((string) $file);
                        // also remove the version without .gz suffix if it exists
                        if (file_exists(substr((string) $file, 0, -3))) {
                            unlink(substr((string) $file, 0, -3));
                        }
                        continue;
                    }

                    // check for corrupted file if it is older than the bugfix date, and remove if they are corrupted
                    if (filemtime((string) $file) < 1606297354) {
                        $contents = $this->decodeFile((string) $file);
                        if (!isset($contents['packages'][$match[1]])) {
                            unlink((string) $file);
                            // also remove the version without .gz suffix if it exists
                            if (file_exists(substr((string) $file, 0, -3))) {
                                unlink(substr((string) $file, 0, -3));
                            }
                            continue;
                        }
                    }
                }
            }
        }

        // download all package data
        $requests = [];
        $appendRequest = function ($path) use (&$requests) {
            $headers = [];
            $filemtime = file_exists($this->target.$path.'.gz') ? filemtime($this->target.$path.'.gz') : 0;
            if ($filemtime) {
                $headers = ['If-Modified-Since' => gmdate('D, d M Y H:i:s T', $filemtime)];
            }
            $userData = ['path' => $path, 'minimumFilemtime' => 0, 'retries' => 0, 'resyncIfNewerThanSource' => true];
            $requests[] = ['GET', $this->url.$path, ['user_data' => $userData, 'headers' => $headers]];
        };

        foreach ($list['packageNames'] as $pkg) {
            $appendRequest('/p2/'.$pkg.'.json');
            $appendRequest('/p2/'.$pkg.'~dev.json');
        }

        $result = $this->downloadV2Files($requests);
        if (!$result) {
            return false;
        }

        $this->output(PHP_EOL);
        $this->output('Downloaded '.$this->downloaded.' files'.PHP_EOL);

        $this->writeLastTimestamp($timestamp);

        $this->statsdIncrement('mirror.resync');

        return true;
    }

    private function getTimestampStorePath(): string
    {
        $timestampStore = __DIR__.'/last_metadata_timestamp';

        // migrate legacy path to new dir if available
        $timestampStoreLegacy = './last_metadata_timestamp';
        if (file_exists($timestampStoreLegacy) && !file_exists($timestampStore)) {
            rename($timestampStoreLegacy, $timestampStore);
        }

        return $timestampStore;
    }

    private function writeLastTimestamp(int $timestamp): void
    {
        if (false === file_put_contents($this->getTimestampStorePath(), $timestamp)) {
            throw new \UnexpectedValueException('Could not save last timestamp to last_metadata_timestamp in '.getcwd().'. Make sure the file/directory is writable');
        }
    }

    /**
     * @param array<array{string, string, array<mixed>}> $requests
     */
    private function downloadV2Files(array $requests): bool
    {
        $hasRetries = false;

        $responseNeedsRetry = function ($response, array $userData) use (&$hasRetries, &$requests): bool {
            $is404 = $response->getStatusCode() === 404;
            if (!$is404) {
                $mtime = $this->strtotime($response->getHeaders(false)['last-modified'][0]);
            }

            // got an outdated file, possibly fetched from a mirror which was not yet up to date, so retry after 2sec
            if ($is404 || $mtime < $userData['minimumFilemtime']) {
                if ($userData['retries'] > 2) {
                    // 404s after 3 retries should be deemed to have really been deleted, so we stop retrying
                    if ($is404) {
                        return false;
                    }
                    throw new \Exception('Too many retries, could not update '.$userData['path'].' as the origin server returns an older file ('.$mtime.', expected '.$userData['minimumFilemtime'].')');
                }
                $hasRetries = true;
                $this->output('R');
                $this->statsdIncrement('mirror.retry_provider_v2');
                $userData['retries']++;
                $headers = file_exists($this->target.$userData['path'].'.gz') ? ['If-Modified-Since' => gmdate('D, d M Y H:i:s T', (int) filemtime($this->target.$userData['path'].'.gz'))] : [];
                $requests[] = ['GET', $this->url.$userData['path'], ['user_data' => $userData, 'headers' => $headers]];

                return true;
            }

            return false;
        };

        $retryFailedReq = function (\Throwable $e, array $userData) use (&$hasRetries, &$requests): bool {
            if ($userData['retries'] > 2) {
                return false;
            }

            $hasRetries = true;
            $this->output('E');
            $this->statsdIncrement('mirror.retry_provider_v2_error');
            $userData['retries']++;
            $headers = file_exists($this->target.$userData['path'].'.gz') ? ['If-Modified-Since' => gmdate('D, d M Y H:i:s T', (int) filemtime($this->target.$userData['path'].'.gz'))] : [];
            array_unshift($requests, ['GET', $this->url.$userData['path'], ['user_data' => $userData, 'headers' => $headers]]);

            return true;
        };

        while ($requests) {
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

                            // retry if the response is an outdated 304 as the mirror we are syncing from
                            // looks outdated still
                            $userData = $response->getInfo('user_data');
                            if ($responseNeedsRetry($response, $userData)) {
                                continue;
                            }

                            $this->output('-');
                            $this->statsdIncrement('mirror.not_modified');
                            continue;
                        }

                        if ($response->getStatusCode() === 404) {
                            $response->cancel();
                            $this->downloaded++;

                            // 404s need to be retried just in case the mirror we are syncing from is not yet up to date
                            // as othwerise this can lead to missing new packages' files as they'll be 404 instead of outdated 304s
                            $userData = $response->getInfo('user_data');
                            if ($responseNeedsRetry($response, $userData)) {
                                continue;
                            }

                            // ignore 404s for all v2 files as the package might have been deleted already
                            $this->output('?');
                            $this->statsdIncrement('mirror.not_found');
                            continue;
                        }
                    }

                    if ($chunk->isLast()) {
                        $this->downloaded++;
                        $userData = $response->getInfo('user_data');

                        $metadata = $response->getContent();
                        $decoded = json_decode($metadata, true);
                        if (null === $decoded) {
                            throw new \Exception('Invalid JSON received for file '.$userData['path']);
                        }

                        // check the response is for the correct package as safety check against corruption
                        preg_match('{^/p2/(.+?)(~dev)?.json$}', $userData['path'], $match);
                        $packageName = $match[1];
                        if (!isset($decoded['packages'][$packageName])) {
                            throw new \Exception('Invalid response for file '.$userData['path'].', '.$packageName.' could not be found in file content: '.substr($metadata, 0, 300));
                        }
                        foreach ($decoded['packages'][$packageName] as $version) {
                            $isDevVersion = (bool) preg_match('{^dev-|-dev$}', $version['version_normalized']);
                            if (($match[2] ?? '') === '~dev' && !$isDevVersion) {
                                throw new \Exception('Invalid response for file '.$userData['path'].', expected dev versions and got non-dev ones: '.substr($metadata, 0, 300));
                            }
                            if (($match[2] ?? '') === '' && $isDevVersion) {
                                throw new \Exception('Invalid response for file '.$userData['path'].', expected non-dev versions and got dev ones: '.substr($metadata, 0, 300));
                            }
                            break;
                        }

                        if ($responseNeedsRetry($response, $userData)) {
                            continue;
                        }

                        $mtime = $this->strtotime($response->getHeaders()['last-modified'][0]);
                        $gzipped = $this->gzencode($metadata, 7);
                        $this->write($userData['path'], $metadata, $gzipped, $mtime);
                        $this->output('M');
                        $this->statsdIncrement('mirror.sync_provider_v2');
                    }
                } catch (\Throwable $e) {
                    // if it can be retried, we skip it for now
                    if ($retryFailedReq($e, $response->getInfo('user_data'))) {
                        $response->cancel();
                        $this->downloaded++;
                        continue;
                    }

                    // abort all responses to avoid triggering any other exception then throw
                    array_map(function ($r) { $r->cancel(); }, $responses);

                    $this->statsdIncrement('mirror.provider_failure');
                    $this->downloaded++;
                    throw $e;
                }
            }
        }

        return true;
    }

    public function sync(): bool
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
            if (file_exists($this->target.'/'.$listing.'.gz')) {
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

                if (file_exists($this->target.$provPath.'.gz')) {
                    continue;
                }

                $userData = [$provPath, $provAltPath, $opts['sha256']];
                $requests[] = ['GET', $this->url.$provPath, ['user_data' => $userData]];
            }

            $listingsToWrite['/'.$listing] = [$listingData, $this->strtotime($listingResp->getHeaders()['last-modified'][0])];
        }

        while ($requests) {
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

                        $mtime = $this->strtotime($response->getHeaders()['last-modified'][0]);
                        $gzipped = $this->gzencode($providerData, 7);
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

                    throw $e;
                }
            }
        }

        foreach ($listingsToWrite as $listing => $listingData) {
            $gzipped = $this->gzencode($listingData[0], 8);
            $this->write($listing, $listingData[0], $gzipped, $listingData[1]);
            $this->output('L');
            $this->statsdIncrement('mirror.sync_listing');
        }

        $gzipped = $this->gzencode($rootData, 8);
        $this->write('/packages.json', $rootData, $gzipped, $this->strtotime($rootResp->getHeaders()['last-modified'][0]));
        $this->output('X');
        $this->statsdIncrement('mirror.sync_root');

        $this->output(PHP_EOL);
        $this->output('Downloaded '.$this->downloaded.' files'.PHP_EOL);

        return true;
    }

    public function gc(): void
    {
        // GC is only for v1 metadata, so abort if v1 is not enabled
        if ($this->syncRootOnV2) {
            return;
        }

        // build up array of safe files/packages
        $safeFiles = [];
        $safePackages = [];

        $rootFile = $this->target.'/packages.json.gz';
        if (!file_exists($rootFile)) {
            return;
        }
        $rootJson = $this->decodeFile($rootFile);

        foreach ($rootJson['provider-includes'] as $listing => $opts) {
            $listing = str_replace('%hash%', $opts['sha256'], $listing).'.gz';
            $safeFiles['/'.$listing] = true;

            $listingJson = $this->decodeFile($this->target.'/'.$listing);
            foreach ($listingJson['providers'] as $pkg => $opts) {
                $provPath = '/p/'.$pkg.'$'.$opts['sha256'].'.json.gz';
                $safeFiles[$provPath] = true;
                $safePackages[(string) $pkg] = true;
            }
        }

        $this->cleanOldFiles($safeFiles, $safePackages);
    }

    /**
     * @param array<string, true> $safeFiles
     * @param array<string, true> $safePackages
     */
    private function cleanOldFiles(array $safeFiles, array $safePackages): void
    {
        $finder = Finder::create()->directories()->ignoreVCS(true)->in($this->target.'/p');
        foreach ($finder as $vendorDir) {
            // clean up hashed provider files which are >10min old and not safe
            $vendorFiles = Finder::create()->files()->ignoreVCS(true)
                ->name('/\$[a-f0-9]+\.json\.gz$/')
                ->date('until 10minutes ago')
                ->in((string) $vendorDir);

            foreach ($vendorFiles as $file) {
                $key = strtr(str_replace($this->target, '', (string) $file), '\\', '/');
                if (!isset($safeFiles[$key])) {
                    unlink((string) $file);
                    // also remove the version without .gz suffix if it exists
                    if (file_exists(substr((string) $file, 0, -3))) {
                        unlink(substr((string) $file, 0, -3));
                    }
                }
            }

            // clean up foo/bar.json static files which are >10min old (to make sure newly synced ones do not get wiped before providers are written) if that package is not found anymore in the listings
            $vendorFiles = Finder::create()->files()->ignoreVCS(true)
                ->name('/^[A-Za-z0-9_.-]+\.json\.gz$/')
                ->date('until 10minutes ago')
                ->in((string) $vendorDir);

            foreach ($vendorFiles as $file) {
                $key = basename($vendorDir).'/'.basename((string) $file, '.json.gz');
                if (!isset($safePackages[$key])) {
                    unlink((string) $file);
                    // also remove the version without .gz suffix if it exists
                    if (file_exists(substr((string) $file, 0, -3))) {
                        unlink(substr((string) $file, 0, -3));
                    }
                }
            }
        }

        // clean up old provider listings
        $finder = Finder::create()->depth(0)->files()->name('provider-*.json.gz')->ignoreVCS(true)->in($this->target.'/p')->date('until 10minutes ago');
        foreach ($finder as $provider) {
            $key = strtr(str_replace($this->target, '', (string) $provider), '\\', '/');
            if (!isset($safeFiles[$key])) {
                unlink((string) $provider);
                // also remove the version without .gz suffix if it exists
                if (file_exists(substr((string) $provider, 0, -3))) {
                    unlink(substr((string) $provider, 0, -3));
                }
            }
        }
    }

    private function output(string $str): void
    {
        if ($this->verbose) {
            echo $str;
        }
    }

    private function getHash(string $file): string|null
    {
        if (file_exists($this->target.$file)) {
            $hash = hash_file('sha256', $this->target.$file);
            if (false === $hash) {
                throw new \RuntimeException('Failed hashing file '.$this->target.$file);
            }

            return $hash;
        }

        return null;
    }

    private function download(string $file): ResponseInterface
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
            throw new \RuntimeException('Failed to fetch '.$file.' => '.$resp->getStatusCode() .' '. $resp->getContent());
        }

        return $resp;
    }

    private function write(string $file, string $content, string $gzipped, int $mtime): void
    {
        $path = $this->target.$file;

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        if (!$this->gzipOnly || $file === '/packages.json') {
            file_put_contents($path.'.tmp', $content);
            touch($path.'.tmp', $mtime);
            rename($path.'.tmp', $path);
        }
        file_put_contents($path.'.gz.tmp', $gzipped);
        touch($path.'.gz.tmp', $mtime);
        rename($path.'.gz.tmp', $path.'.gz');
    }

    private function delete(string $packageName): void
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

    public function statsdIncrement(string $metric): void
    {
        static $loggedStatsdError = null;

        if ($this->statsdSocket) {
            $message = $metric.':1|c';
            try {
                fwrite($this->statsdSocket, $message);
            } catch (\ErrorException $e) {
                if (!$loggedStatsdError) {
                    trigger_error('Statsd not responding: '.$e->getMessage(), E_USER_WARNING);
                    $loggedStatsdError = true;
                }
            }
        }
    }

    private function statsdConnect(string $ip, int $port): void
    {
        try {
            $socket = fsockopen('udp://' . $ip, $port, $errno, $errstr, 1);
        } catch (\ErrorException $e) {
            trigger_error(
                'StatsD server connection failed: ' . $e->getMessage(),
                E_USER_WARNING
            );
            return;
        }
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

    private function initClient(): void
    {
        if (isset($this->client)) {
            return;
        }

        $this->client = HttpClient::create([
            'headers' => [
                'User-Agent' => $this->userAgent,
                'Host' => $this->hostname,
            ],
            'timeout' => 120,
            'max_duration' => 120,
            'http_version' => '2.0',
        ]);
    }

    private function decodeFile(string $path): mixed
    {
        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new \RuntimeException('Failed reading '.$path);
        }
        $contents = gzdecode($contents);
        if (false === $contents) {
            throw new \RuntimeException('Failed gzdecoding '.$path);
        }

        return json_decode($contents, true);
    }

    private function gzencode(string $data, int $level): string
    {
        $gzipped = gzencode($data, $level);
        if (false === $gzipped) {
            throw new \RuntimeException('Failed gzencoding '.$data);
        }

        return $gzipped;
    }

    private function strtotime(string $str): int
    {
        $timestamp = strtotime($str);
        if (false === $timestamp) {
            throw new \RuntimeException('Failed parsing time '.$str);
        }

        return $timestamp;
    }
}

$lockName = 'mirror';
$config = require __DIR__ . '/mirror.config.php';
$isGC = false;
$isV2 = false;
$isV1 = false;
$isResync = false;

if (in_array('--gc', $_SERVER['argv'])) {
    $lockName .= '-gc';
    $isGC = true;
} elseif (in_array('--v2', $_SERVER['argv'])) {
    $lockName .= '-v2';
    $isV2 = true;
} elseif (in_array('--v1', $_SERVER['argv'])) {
    $isV1 = true;
    // default mode
} elseif (in_array('--resync', $_SERVER['argv'])) {
    // resync uses same lock name as --v2 to make sure they can not run in parallel
    $lockName .= '-v2';
    $isResync = true;
} else {
    throw new \RuntimeException('Missing one of --gc, --v1 or --v2 modes');
}

$lockFactory = new LockFactory(new FlockStore(sys_get_temp_dir()));
$lock = $lockFactory->createLock($lockName, 3600);

// if resync is running, we wait for the lock to be
// acquired in case a v2 process is still running
// otherwise abort immediately
if (!$lock->acquire($isResync)) {
    // sleep so supervisor assumes a correct start and we avoid restarting too quickly, then exit
    sleep(3);
    exit(0);
}

try {
    $mirror = new Mirror($config);
    if ($isGC) {
        $mirror->gc();
        $lock->release();
        exit(0);
    }
    if ($isResync) {
        $mirror->resync($mirror->getV2Timestamp());
        $lock->release();
        exit(0);
    }

    $iterations = $config['iterations'];
    $hasSyncedRoot = false;

    while ($iterations--) {
        if ($isV2) {
            // sync root only once in a while as on a v2 only repo it rarely changes
            if (($iterations % 20) === 0 || $hasSyncedRoot === false) {
                $mirror->syncRootOnV2();
                $hasSyncedRoot = true;
            }
            if (!$mirror->syncV2()) {
                $lock->release();
                exit(1);
            }
        } elseif ($isV1) {
            if (!$mirror->sync()) {
                $lock->release();
                exit(1);
            }
        }
        sleep($config['iteration_interval']);
        $lock->refresh();
    }
} catch (\Throwable $e) {
    // sleep so supervisor assumes a correct start and we avoid restarting too quickly, then rethrow
    if (isset($mirror)) {
        $mirror->statsdIncrement('mirror.hard_failure');
    }
    sleep(3);
    echo 'Mirror '.($isV2 ? 'v2' : '').' job failed at '.date('Y-m-d H:i:s').PHP_EOL;
    echo '['.get_class($e).'] '.$e->getMessage().PHP_EOL;
    throw $e;
} finally {
    $lock->release();
}

exit(0);
